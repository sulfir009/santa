<?php
function svb_render_form() {
    // === 1. ЛОГІКА ЗАМОВЛЕННЯ (ORDER SYSTEM) ===
    // Ініціалізуємо або отримуємо існуюче замовлення для цього користувача
    $order_data = svb_init_user_order();
    $order_id = $order_data['order_id'];
    
    $welcome_msg = "Раді бачити вас знову! Ваше замовлення №<strong>{$order_id}</strong>.";
    $video_ready_html = '';
    
    // Перевірка наявності попереднього відео
    if (!empty($order_data['video_generated']) && !empty($order_data['video_url'])) {
        // Перевіряємо, чи файл все ще існує на диску
        $phys_path = str_replace(site_url('/'), ABSPATH, $order_data['video_url']);
        // Або використовуємо збережений video_path, якщо він коректний
        if (!empty($order_data['video_path']) && file_exists($order_data['video_path'])) {
            // Відео існує фізично -> Даємо кнопку скачати
            $video_ready_html = '
            <div style="margin-top:10px; padding:10px; background:#d4edda; color:#155724; border-radius:8px; border:1px solid #c3e6cb;">
                <strong>🎥 Ваше відео готове!</strong><br>
                <div style="margin-top:5px; display:flex; gap:10px; align-items:center;">
                    <a href="'.$order_data['video_url'].'" download class="svb-btn primary" style="padding:6px 12px; font-size:12px; text-decoration:none; color:white;">⬇ Завантажити</a>
                    <span style="font-size:11px; color:#666;">або створіть нове нижче</span>
                </div>
            </div>';
        } elseif (!empty($order_data['video_generated'])) {
            // Файл видалено
             $video_ready_html = '
            <div style="margin-top:10px; padding:10px; background:#fff3cd; color:#856404; border-radius:8px; border:1px solid #ffeeba;">
                <strong>⚠️ Термін дії відео закінчився.</strong><br>
                Будь ласка, створіть нове відео. Ваші дані (Ім\'я/E-mail) збережені.
            </div>';
        }
    }
    // ============================================

    $defs = svb_get_definitions();
    
    $video_templates = [];
    $template_timings = [];

    foreach ($defs as $vid => $cfg) {
        $video_templates[$vid] = [
            'label'  => $cfg['label'],
            'file'   => basename($cfg['file']),
            'url'    => $cfg['url'],
            'image'  => isset($cfg['image']) ? $cfg['image'] : '', 
            'scenes' => $cfg['scenes'],
            'for_children' => isset($cfg['for_children']) ? $cfg['for_children'] : [1] // Default to 1 if not set
        ];
        $template_timings[$vid] = $cfg['scenes'];
    }
    
    $selected_video_id = isset($_POST['selected_video_id']) ? 
        sanitize_text_field($_POST['selected_video_id']) : 'video1';

    if (!isset($video_templates[$selected_video_id])) {
        $selected_video_id = 'video1';
    }

    $template_url = $video_templates[$selected_video_id]['url'];
    
    $default_segments = $template_timings[$selected_video_id];

    $audio_catalog = svb_scan_audio_catalog();
    $ajax_url = admin_url('admin-ajax.php');
    $nonce = wp_create_nonce('svb_nonce');

    $ffmpeg_path = svb_exec_find('ffmpeg');
    $preview_caps = [
        'perspective' => $ffmpeg_path ? svb_ff_has_filter($ffmpeg_path, 'perspective') : false,
    ];
    $is_admin = is_user_logged_in() && current_user_can('manage_options');


    $user_segments = [];

    if ( ! empty( $_POST['svb_segments'] ) ) {
        $raw = wp_unslash( $_POST['svb_segments'] ); 
        $decoded = json_decode( $raw, true );
        if ( is_array( $decoded ) ) {
            $user_segments = $decoded;
        }
    }

    $segments = array_merge( $default_segments, $user_segments );

    $P_CHILD1  = $segments['child1'] ?? [];
    $P_CHILD2  = $segments['child2'] ?? [];
    // FIX: Берем parent1, так как ключа parents в конфиге нет
    $P_PARENTS = $segments['parents'] ?? ($segments['parent1'] ?? []);

    $to_sec = function($pairs){
        return array_map(function($a){
            // Проверяем, в каком формате пришли данные (start/end или 0/1)
            $s = isset($a['start']) ? $a['start'] : (isset($a[0]) ? $a[0] : 0);
            $e = isset($a['end'])   ? $a['end']   : (isset($a[1]) ? $a[1] : 0);
            return [ svb_ts_to_seconds($s), svb_ts_to_seconds($e) ];
        }, $pairs);
    };
    $OVER = [
        'child1'  => $to_sec($P_CHILD1),
        'child2'  => $to_sec($P_CHILD2),
        'parent1' => $to_sec($P_PARENTS),
        'parent2' => $to_sec($P_PARENTS),
    ];

    ob_start(); ?>
    <link  href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.css" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.js"></script>
<style>

    
  input.svb-input {
    border-radius: 25px;
    font-size: 11px;
}
.svb-req-trigger-link {
    background: antiquewhite;
    border-radius: 25px;
}

/* === Основна розмітка === */
.svb-wrap { max-width: 1290px; margin: 40px auto; }
.svb-card { background:#fff; border-radius:16px; box-shadow:0 10px 30px rgba(0,0,0,.08); padding:25px 15px 25px 15px; }
.svb-header { display:flex; align-items:center; gap:16px; margin-bottom:16px; }
.svb-stepper { display:flex; gap:8px; align-items:center; }
.svb-dot { width:28px; height:28px; border-radius:50%; display:inline-flex; justify-content:center; align-items:center; font-weight:700; }
.svb-dot.active { background:#D62828; color:#fff; }
.svb-dot.muted { background:#f0f0f0; color:#999; }
.svb-title { font-size:28px; font-weight:800; margin:0; }
.svb-actions { display:flex; gap:12px; margin-top:20px; }

/* === Кнопки (Загальні) === */
.svb-btn { appearance:none; border:none; border-radius:10px; padding:12px 18px; font-weight:700; cursor:pointer; transition:.2s ease; }
.svb-btn.primary { background:#D62828; color:#fff; }
.svb-btn.primary:hover { background:#B81F1F; }
.svb-btn.ghost { background:#f3f3f3; padding: 6px 10px; font-size: 13px; }

/* === Сітка та поля === */
.svb-grid { display:grid; gap:16px; }
@media (min-width: 760px){ .svb-grid.cols-2 { grid-template-columns: 1fr 1fr; } }
@media (min-width: 1024px){ .svb-grid.cols-3 { grid-template-columns: repeat(3, 1fr); } }

.svb-field { display:flex; flex-direction:column; gap:8px; }
.svb-label { font-weight:700; font-size:14px; }
.svb-input, .svb-select { border:1px solid #E3E3E3; border-radius:50px; padding:10px 16px; font-size:15px; }
.svb-range { border:1px solid #E3E3E3; border-radius: 12px; padding: 0; height: 22px; }

.svb-controls { display:grid; gap:8px; grid-template-columns: 1fr 1fr 1fr; }
.svb-controls label { display: flex; align-items: center; gap: 8px; font-size: 13px; }
.svb-controls .svb-val { font-weight: 700; min-width: 30px; text-align: right; }
.svb-controls .svb-val-input { border: 1px solid #E3E3E3; border-radius: 6px; padding: 2px 6px; font-size: 12px; width: 70px; text-align: right; }

.svb-note { color:#666; font-size:12px; }
.svb-audio-row { display:flex; align-items:center; gap:8px; }
.svb-play { border:none; border-radius:50%; width:36px; height:36px; display:flex; align-items:center; justify-content:center; background:#EEE; cursor:pointer; }

.svb-step { display:none; }
.svb-step.active { display:block; }

.svb-photo-grid { display:grid; gap:18px; }
@media (min-width: 760px){ .svb-photo-grid { grid-template-columns: repeat(2, 1fr); } }

.svb-drop { background: #fdfdfd; border-radius: 16px; border: 1px solid #ececec; padding: 14px; display:flex; flex-direction:column; gap:12px; }
@media (min-width: 1024px) { .svb-drop { padding: 18px; } }

.svb-result { background:#F8F8F8; border-radius:12px; padding:16px; margin-top:16px; }
.svb-spinner { width:22px; height:22px; border:3px solid #ddd; border-top-color:#D62828; border-radius:50%; animation:spin 1s linear infinite; display:inline-block; vertical-align:middle; margin-right:8px; }
@keyframes spin{ to { transform:rotate(360deg); } }

.svb-suggest { position: relative; }
#svb-name-suggest, #svb-name-suggest-2, #svb-name-suggest-3 {
  position: absolute; top: 100%; left: 0; right: 0; background: #fff; border: 1px solid #E3E3E3; border-radius: 10px;
  box-shadow: 0 8px 24px rgba(0,0,0,.08); z-index: 9999; max-height: 240px; overflow: auto; display: none;
}
.svb-suggest-item{ padding: 10px 12px; cursor: pointer; }
.svb-suggest-item:hover, .svb-suggest-item.active{ background: #F6F6F6; }

.svb-screenlock{ position: fixed; inset: 0; z-index: 999999; background: rgba(255,255,255,.92); display: none; align-items: center; justify-content: center; flex-direction: column; backdrop-filter: blur(2px); }
.svb-screenlock__spinner{ width:48px; height:48px; border:5px solid #e1e1e1; border-top-color:#D62828; border-radius:50%; animation: spin 1s linear infinite; }
#svb-lock-percent { position: absolute; font-size: 14px; font-weight: 700; color: #333; }
.svb-screenlock__txt{ margin-top:14px; font-weight:800; font-size:18px; color:#333; text-align:center; }

.svb-vid-preview { position: relative; background: #000; border-radius: 12px; overflow: hidden; width: 100%; max-width: 854px; margin: 0 auto; aspect-ratio: 16 / 9; }
.svb-vid-preview video { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: contain; }
.svb-vid-preview img { position: absolute; top: 0; left: 0; transform-origin: center center; width: 0; height: auto; }
.svb-ovbox{ position:absolute; left:0; top:0; width:0; height:0; z-index:2; }
.svb-ovbox > img{ position:absolute; left:0; top:0; width:auto; height:auto; transform-origin:50% 50%; }

.svb-vid-preview img:not([src]) { display: flex; align-items: center; justify-content: center; min-height: 50px; background: rgba(255, 255, 255, 0.4); backdrop-filter: blur(2px); border: 1px dashed #fff; color: white; font-weight: bold; font-size: 14px; text-shadow: 0 1px 2px rgba(0,0,0,0.5); box-sizing: border-box; }
.svb-vid-preview img:not([src])::after { content: attr(alt); }
.svb-vid-preview img[src] { background: transparent; backdrop-filter: none; color: transparent; border: none; min-height: 0; }

.svb-vid-controls { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
.svb-vid-controls input[type="range"] { margin-left: 4px; padding: 0; width: 80px; }
.svb-vid-seek-bar-container { padding: 8px 0; }
.svb-seek-bar { width: 100%; padding: 0 !important; margin: 0 !important; }
#svb-vid-time-child1 { font-size: 12px; min-width: 70px; }

.svb-admin-only { margin-top: 10px; padding: 10px 12px; border-radius: 12px; background: #fff7f7; border: 1px dashed #f4c2c2; }
.svb-admin-only .svb-controls { grid-template-columns: 1fr 1fr; }
@media (min-width: 1200px) { .svb-admin-only .svb-controls { grid-template-columns: repeat(3, 1fr); } }

.svb-intervals { margin-top: 8px; padding: 8px 10px; border-radius: 10px; background: #fafafa; border: 1px dashed #e1e1e1; font-size: 12px; }
.svb-intervals-rows { display: flex; flex-direction: column; gap: 6px; margin-top: 6px; }
.svb-int-row { display: flex; align-items: center; gap: 6px; }
.svb-int-row .svb-int-start, .svb-int-row .svb-int-end { flex: 0 0 90px; padding: 4px 6px; font-size: 12px; }
.svb-int-row .svb-int-del { padding: 4px 8px; font-size: 11px; }

.svb-child-count-wrap { display:flex; gap:10px; margin-bottom:16px; justify-content: center; }
.svb-child-radio { display:none; }
.svb-child-label { padding: 10px 20px; border: 1px solid #E3E3E3; border-radius: 50px; cursor: pointer; font-weight: 600; color: #666; transition: all 0.2s; }
.svb-child-radio:checked + .svb-child-label { background: #D62828; color: #fff; border-color: #D62828; }

/* ========================================================================= */
/* ===  ДИЗАЙН КАРТОК (ВИПРАВЛЕНИЙ ПІД ВАШ ЗАПИТ) === */
/* ========================================================================= */

.svb-video-option {
    display: flex;
    flex-direction: column;
    position: relative;
    
    /* Основна форма */
    border-radius: 15px;
    overflow: hidden; 
    cursor: pointer;
    background: #fff;
    
    /* Дефолтна рамка прозора (щоб не стрибало) */
    outline: 5px solid transparent; 
    outline-offset: -5px;
    
    /* Тінь */
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
    
    transition: transform 0.2s ease, outline-color 0.2s ease;
}

/* === ХОВЕР: ЧЕРВОНА РАМКА === */
/* Коли наводимо мишкою, рамка стає червоною */
.svb-video-option:hover {
    outline-color: #D62828;
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(0,0,0,0.15);
}

/* === АКТИВНА: ЖОВТА РАМКА === */
/* Коли картка обрана, рамка стає жовтою */
.svb-video-option.active {
    outline-color: #FACC15;
}

/* Верхня частина: Картинка */
.svb-video-thumb-box {
    position: relative;
    width: 100%;
    aspect-ratio: 16 / 10; 
    background: #000;
}

.svb-video-thumb-box img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
    opacity: 0.9;
}

.svb-video-btn-preview {
    position: absolute;
    bottom: 6px; 
    left: 50%;
    transform: translateX(-50%); 
    
    width: 80%;
    max-width: 180px;
    height: 24px;
    
    background-color: #FFC107; 
    color: #333333;
    font-family: Roboto, sans-serif;
    font-weight: 500;
    font-size: 11px;
    line-height: 24px;
    
    border-radius: 20px;
    border: none;
    cursor: pointer;
    z-index: 10;
    box-shadow: 0 2px 8px rgba(0,0,0,0.3);
    
    /* === ВАЖНО: МЕНЯЕМ display: flex на display: none === */
    display: none; 
    
    align-items: center;
    justify-content: center;
    gap: 6px;
    white-space: nowrap;
    transition: background 0.2s;
}

/* 2. Добавляем новое правило: показывать кнопку только у АКТИВНОЙ карточки */
.svb-video-option.active .svb-video-btn-preview {
    display: flex; /* Возвращаем flex, чтобы кнопка появилась */
    animation: fadeIn 0.3s ease-in-out; /* Плавное появление (опционально) */
}

/* Опционально: анимация появления */
@keyframes fadeIn {
    from { opacity: 0; transform: translate(-50%, 5px); }
    to { opacity: 1; transform: translate(-50%, 0); }
}

.svb-video-btn-preview::before {
    content: '👁';
    font-size: 14px;
    line-height: 1;
}

.svb-video-btn-preview:hover {
    background-color: #FFD54F;
}

/* === Нижня плашка з назвою === */
.svb-video-label-bar {
    width: 100%;
    height: 32px;
    display: flex;
    align-items: center;
    justify-content: center;
    
    color: white;
    font-size: 13px;
    font-family: Roboto, sans-serif;
    font-weight: 700;
    text-align: center;
    text-transform: none;
    
    /* Дефолтний колір фону - ЧЕРВОНИЙ (якщо не активна) */
    background-color: #D62828;
    transition: background-color 0.2s ease;
}

/* === АКТИВНА ПЛАШКА: ЖОВТА === */
/* Коли картка обрана, фон плашки стає жовтим */
.svb-video-option.active .svb-video-label-bar {
    background-color: #FACC15;
}

/* Специфічне правило для першої картки, якщо ви хочете, щоб вона була жовтою за замовчуванням
   але за вашим описом логіка "активна = жовта" універсальна.
   Якщо "Фабрика Іграшок" має бути жовтою ЗАВЖДИ, розкоментуйте нижче:
*/
/*
.svb-video-option.theme-yellow .svb-video-label-bar {
    background-color: #FACC15;
}
.svb-video-option.theme-yellow.active {
    outline-color: #FACC15;
}
*/

/* === Модальне вікно === */
.svb-modal { position: fixed; inset: 0; z-index: 999999; background: rgba(0,0,0,0.9); display: none; align-items: center; justify-content: center; opacity: 0; transition: opacity 0.3s ease; }
.svb-modal.active { display: flex; opacity: 1; }
.svb-modal-content { position: relative; width: 90%; max-width: 1000px; aspect-ratio: 16/9; background: #000; border-radius: 12px; box-shadow: 0 10px 40px rgba(0,0,0,0.5); }
.svb-modal-close { position: absolute; top: -40px; right: 0; color: #fff; font-size: 36px; line-height: 1; cursor: pointer; opacity: 0.8; transition: opacity 0.2s; z-index: 1000; }
.svb-modal-close:hover { opacity: 1; }
.svb-modal video { width: 100%; height: 100%; border-radius: 12px; object-fit: contain; }
@media (max-width: 768px) {
    .svb-modal-close { top: 10px; right: 10px; background: rgba(0,0,0,0.6); width: 36px; height: 36px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 24px; }
}
.svb-video-option-label {

text-align: center;

color: #FFFFFF;

background: #D62828;

font-size: 11px;

font-family: Roboto;

font-weight: 700;

line-height: 16.50px;

word-wrap: break-word

}
</style>

<style>
/* === 1. ПРАВИЛА СКРЫТИЯ (ТОЛЬКО ДЛЯ ОБЫЧНЫХ ЮЗЕРОВ) === */
<?php if ( ! $is_admin ) : ?>
  .svb-admin-only, 
  [id^="svb-dbg-"] {
    display: none !important;
  }
<?php endif; ?>
/* === ВСТАВИТЬ ЭТО В БЛОК STYLE === */
.svb-names-row {
    grid-column: 1 / -1; 
    display: flex;        
    gap: 16px;            
    width: 100%;
}
.svb-names-row .svb-field {
    flex: 1;             
    min-width: 0;
}

@media (max-width: 768px) {
    .svb-names-row {
        flex-direction: column;
    }
}
/* === ВСТАВИТЬ В НАЧАЛО БЛОКА <style> (примерно строка 280) === */

.svb-input-wrapper {
    display: flex;
    align-items: center;
    gap: 8px;
    position: relative;
    width: 100%; /* Добавлено для надежности */
}

/* Важно: input должен сжиматься, чтобы дать место кнопке */
.svb-input-wrapper .svb-input {
    flex: 1;
    min-width: 0; /* Важно для Grid/Flex контейнеров */
}

/* Стили кнопки Play */
.svb-name-play {
    display: none; /* Скрыта пока не выбрано имя */
    width: 36px;
    height: 36px;
    border-radius: 50%;
    background: #D62828;
    color: #fff;
    border: none;
    cursor: pointer;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    transition: background 0.2s;
    font-size: 14px;
    padding: 0;
}
.svb-name-play:hover {
    background: #B81F1F;
}
/* Если кнопка активна (display: flex), центруем иконку */
.svb-name-play[style*="display: flex"] {
    display: flex !important;
}

/* === Виджет запроса имени === */
.svb-req-widget {
    margin-top: 16px;
    background: #FFF6F0;
    border-radius: 10px;
    position: relative;
    overflow: hidden;
    transition: all 0.3s ease;
}

.svb-req-closed {
    padding: 10px 12px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    cursor: pointer;
}

.svb-req-text {
    color: #333; font-size: 13px; font-family: Roboto, sans-serif; font-weight: 500; line-height: 1.2;
}
.svb-req-link {
    text-decoration: underline; cursor: pointer;
}
.svb-req-icon {
    font-weight: 500; font-size: 16px; color: #333;
}

.svb-req-opened {
    padding: 16px;
    display: none; /* Скрыто по умолчанию */
    flex-direction: column;
    gap: 12px;
}

.svb-req-desc {
    color: #333; font-size: 11px; font-family: Roboto, sans-serif; font-weight: 400; line-height: 1.4;
    margin-bottom: 8px;
}

.svb-req-input {
    width: 100%;
    height: 36px;
    background: white;
    border-radius: 43px;
    border: 1px solid #666;
    padding: 0 14px;
    font-size: 12px;
    font-family: Roboto, sans-serif;
    color: #333;
    outline: none;
}
.svb-req-input::placeholder { color: #757575; }

.svb-req-btn {
    width: 100%;
    height: 36px;
    background: #D62828;
    border-radius: 50px;
    border: none;
    color: white;
    font-size: 13px;
    font-family: Roboto, sans-serif;
    font-weight: 600;
    cursor: pointer;
    transition: background 0.2s;
}
.svb-req-btn:hover { background: #b81f1f; }
.svb-req-btn:disabled { background: #ccc; cursor: not-allowed; }

/* Анимация открытия */
.svb-req-widget.active .svb-req-closed { display: none; }
.svb-req-widget.active .svb-req-opened { display: flex; }

/* === Нове посилання та попап запиту імені === */
.svb-req-trigger-link {
    font-size: 12px;
    color: #D62828;
    cursor: pointer;
    margin-top: 6px;
    display: inline-block;
    font-weight: 500;
}
.svb-req-trigger-link:hover {
    color: #B81F1F;
}

/* Стилі для контенту попапа запиту (перевизначаємо загальні стилі модалки) */
.svb-req-popup-content {
    background: #fff !important; /* Білий фон замість чорного */
    max-width: 450px !important;
    width: 90% !important;
    aspect-ratio: auto !important; /* Прибираємо 16/9 */
    padding: 25px !important;
    display: flex;
    flex-direction: column;
    gap: 15px;
    color: #333;
    border-radius: 16px;
}
.svb-req-popup-content h3 {
    margin: 0 0 5px 0;
    font-size: 20px;
    text-align: center;
}
.svb-req-popup-content .svb-close-black {
    color: #333 !important;
    background: #f0f0f0 !important;
    top: 10px !important;
    right: 10px !important;
    width: 30px; 
    height: 30px;
    font-size: 20px;
}
/* === Стилі для точок слайдера (пагінація) === */
.svb-slider-dots {
    display: none; /* На комп'ютері приховано */
    justify-content: center;
    align-items: center;
    gap: 8px;
    margin-top: 10px;
    padding-bottom: 5px;
}

.svb-slider-dot {
    width: 8px;
    height: 8px;
    background-color: #E3E3E3;
    border-radius: 50%;
    transition: all 0.3s ease;
}

/* Активна точка - довша і жовта (під колір активної картки) */
.svb-slider-dot.active {
    background-color: #FACC15; /* Жовтий */
    width: 24px;
    border-radius: 12px;
}

/* Показуємо тільки на мобільних */
@media (max-width: 768px) {
    .svb-slider-dots {
        display: flex;
    }
}

/* Додайте це в блок <style> */

.svb-select {
    /* 1. Прибираємо стандартну системну стрілку */
    -webkit-appearance: none;
    -moz-appearance: none;
    appearance: none;

    /* 2. Малюємо свою стрілку (SVG код) */
    background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' width='24' height='24' viewBox='0 0 24 24' fill='none' stroke='%23333333' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6 9 12 15 18 9'%3e%3c/polyline%3e%3c/svg%3e");
    background-repeat: no-repeat;
    background-size: 16px; /* Розмір самої стрілочки */

    /* 3. ЯК ПОСУНУТИ СТРІЛКУ: */
    /* right 20px center -> означає 20px від правого краю. */
    /* Змініть 20px на 30px або 10px, щоб посунути її */
    background-position: right 20px center;

    /* 4. Важливий відступ справа, щоб текст не наліз на стрілку */
    padding-right: 45px; 
}
/* === Стиль кнопки запиту імені (Дизайн #FFF6F0) === */
.svb-req-trigger-link {
    /* Розміри та відступи */
        width: 230px;
    box-sizing: border-box; /* Щоб padding не збільшував ширину */
    padding: 10px 12px;      /* Відступи, як top:7px left:7px у дизайні */
    margin-top: 1px;        /* Відступ зверху від інпуту */
    
    /* Фон та рамка */
    background: #FFF6F0;
    border-radius: 10px;
    
    /* Вирівнювання (Flexbox замість absolute) */
    display: flex;
    justify-content: space-between; /* Текст зліва, плюс справа */
    align-items: center;            /* Центрування по вертикалі */
    
    /* Курсор та переходи */
    cursor: pointer;
    text-decoration: none; /* Прибираємо стандартне підкреслення блоку */
    transition: background-color 0.2s ease;
}

/* Ефект наведення */
.svb-req-trigger-link:hover {
    background: #ffebe0;
}

/* Стиль тексту */
.svb-req-text {
    color: #333333;
    font-size: 12px; /* 10px у дизайні, 11px краще для читабельності */
    font-family: 'Roboto', sans-serif;
    font-weight: 500;
    line-height: 14px;
}

/* Стиль підкресленої частини "Натисніть тут" */
.svb-req-text u {
    text-decoration: underline;
    text-underline-offset: 2px; /* Відступ лінії для краси */
}

/* Стиль плюсика (+) */
.svb-req-plus {
    color: #333333;
    font-size: 14px;
    font-family: 'Roboto', sans-serif;
    font-weight: 500;
    line-height: 14px;
}
/* === НОВЫЕ СТИЛИ ДЛЯ КНОПОК С ЦЕНАМИ === */
.svb-child-count-wrap { 
    display: flex; 
    gap: 10px; 
    margin-bottom: 16px; 
    justify-content: center; 
    flex-wrap: wrap; 
}

.svb-child-radio { display: none; }

/* Основной контейнер кнопки (текст + цена) */
.svb-child-label {
    display: inline-flex;
    align-items: center;
    justify-content: space-between;
    padding: 4px 4px 4px 20px; /* Отступ слева больше для текста */
    border: 1px solid #E3E3E3;
    border-radius: 50px;
    cursor: pointer;
    font-family: 'Roboto', sans-serif;
    font-weight: 700;
    font-size: 18px; /* Размер шрифта как на макете */
    color: #333;
    background: #fff;
    transition: all 0.2s ease;
    box-shadow: 0 2px 5px rgba(0,0,0,0.05);
}

/* Активное состояние (выбранная кнопка) */
.svb-child-radio:checked + .svb-child-label {
    background: #C0392B; /* Красный цвет */
    color: #fff;
    border-color: #C0392B;
    box-shadow: 0 4px 10px rgba(192, 57, 43, 0.3);
}

/* Желтый бейдж с ценой */
.svb-c-price {
    background: #F4D03F; /* Желтый цвет */
    color: #000; /* Текст внутри желтого всегда черный */
    border-radius: 30px;
    padding: 6px 14px;
    margin-left: 12px;
    font-size: 14px;
    display: flex;
    align-items: center;
    gap: 6px;
    line-height: 1.1;
    font-weight: 800;
}

/* Старая цена (зачеркнутая) */
.svb-c-old {
    color: #6b5c21;
    font-size: 11px;
    text-decoration: line-through;
    opacity: 0.7;
    font-weight: 500;
}

/* Новая цена */
.svb-c-new {
    color: #000;
}

/* Мобильная адаптация кнопок */
@media (max-width: 480px) {
    .svb-child-label {
        font-size: 15px;
        padding-left: 15px;
        width: 100%; /* На мобильном кнопки на всю ширину */
        justify-content: space-between;
    }
    .svb-child-count-wrap {
        flex-direction: column;
        gap: 8px;
    }
}
/* ========================================= */
/* === СТИЛИ ДЛЯ ПК (Сетка) === */
/* ========================================= */
.svb-desktop-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
    margin-bottom: 24px;
}

/* Карточка ПК */
.svb-video-option {
    position: relative;
    border-radius: 16px;
    overflow: hidden;
    cursor: pointer;
    background: #fff;
    border: 3px solid transparent;
    transition: all 0.2s ease;
    box-shadow: 0 4px 12px rgba(0,0,0,0.08);
}
.svb-video-option:hover {
    border-color: #D62828;
    transform: translateY(-2px);
}
.svb-video-option.active {
    border-color: #FACC15;
    box-shadow: 0 8px 20px rgba(0,0,0,0.15);
}

/* Картинка ПК */
.svb-video-thumb-box {
    position: relative;
    width: 100%; aspect-ratio: 16/9; background: #000;
}
.svb-video-thumb-box img {
    width: 100%; height: 100%; object-fit: cover; display: block;
}

/* Кнопка "Дивитися" на ПК */
.svb-video-btn-preview {
    display: none; 
    position: absolute; bottom: 10px; left: 50%; transform: translateX(-50%);
    background: #FACC15; color: #333; border: none; padding: 6px 14px;
    border-radius: 20px; font-size: 11px; font-weight: 700; cursor: pointer;
    align-items: center; gap: 5px; white-space: nowrap;
}
.svb-video-btn-preview::before { content: '👁'; }
.svb-video-option.active .svb-video-btn-preview { display: flex; }

/* Плашка названия ПК */
.svb-video-label-bar {
    background-color: #D62828; color: #fff; font-weight: 700;
    text-align: center; padding: 10px 5px; font-size: 13px; text-transform: uppercase;
}
.svb-video-option.active .svb-video-label-bar {
    background-color: #FACC15; color: #333;
}

/* Контейнер, який займає одну клітинку гріда, але всередині розбитий на два стовпчики */
.svb-customer-cell {
    display: flex;
    gap: 15px; /* Відстань між Іменем та Емейлом */
    align-items: flex-start;
    width: 100%;
}

/* Стовпчики всередині (Ім'я зліва, Емейл справа) */
.svb-customer-col {
    flex: 1; /* Змушує їх займати рівну ширину (50% на 50%) */
    display: flex;
    flex-direction: column;
    gap: 8px;
    min-width: 0; /* Важливо для flexbox, щоб інпути не вилазили */
}

/* Стилізація посилання на промокод */
.svb-promo-link {
    font-size: 12px;
    font-weight: 600;
    color: #A5402D; /* Темно-червоний колір як на скріншоті */
    text-decoration: none;
    margin-top: 6px;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    line-height: 1.2;
}
.svb-promo-link span u {
    text-decoration: underline;
}
.svb-promo-link:hover {
    opacity: 0.8;
}

/* Адаптація для мобільних: стовпчики стають один під одним */
@media (max-width: 480px) {
    .svb-customer-cell {
        flex-direction: column;
        gap: 10px;
    }
}
/* ========================================= */
/* === СТИЛИ ДЛЯ МОБИЛЬНОГО (Featured + Slider) === */
/* ========================================= */
@media (max-width: 768px) {
    /* Основной контейнер мобилки */
    .svb-template-section {
        margin-bottom: 20px;
        padding-bottom: 10px;
    }

    /* 1. БОЛЬШОЕ ВИДЕО (Featured) */
    .svb-featured-container {
        width: 100%;
        margin-bottom: 15px;
    }
    .svb-feat-card {
        border-radius: 16px;
        overflow: hidden;
        background: #FACC15; /* Желтый фон */
        padding-bottom: 0;
        box-shadow: 0 4px 12px rgba(0,0,0,0.1);
    }
    .svb-feat-img-wrap {
        position: relative;
        margin: 4px 4px 0 4px; /* Желтая рамка */
        border-top-left-radius: 12px;
        border-top-right-radius: 12px;
        overflow: hidden;
    }
    .svb-feat-thumb {
        width: 100%; aspect-ratio: 16/9; object-fit: cover; display: block;
    }
    .svb-feat-btn {
        position: absolute; bottom: 10px; left: 50%; transform: translateX(-50%);
        background: #FACC15; color: #333; font-weight: 700; border: none;
        padding: 8px 16px; border-radius: 20px; font-size: 12px; cursor: pointer;
        display: flex; gap: 6px; box-shadow: 0 2px 8px rgba(0,0,0,0.3);
    }
    .svb-feat-btn::before { content: '👁'; }
    
    .svb-feat-label {
        text-align: center; color: #333; font-weight: 800; font-size: 14px;
        padding: 8px 0; text-transform: uppercase;
    }

    /* 2. ЗАГОЛОВОК "Інші відео" */
    .svb-section-title {
        margin: 0 0 10px 0;
        font-size: 16px; font-weight: 700; color: #333;
    }

    /* 3. СЛАЙДЕР (Others) */
    .svb-others-slider {
        display: flex;
        gap: 12px;
        overflow-x: auto;
        padding-bottom: 10px;
        scroll-snap-type: x mandatory;
        scrollbar-width: none; /* Firefox */
    }
    .svb-others-slider::-webkit-scrollbar { display: none; }

    .svb-small-card {
        flex: 0 0 160px; /* Ширина маленькой карточки */
        border-radius: 12px;
        overflow: hidden;
        position: relative;
        cursor: pointer;
        scroll-snap-align: start;
        box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    }
    .svb-small-thumb {
        width: 100%; height: 90px; object-fit: cover; display: block;
    }
    .svb-small-label {
        background: #D62828; color: #fff; font-size: 10px; font-weight: 700;
        text-align: center; padding: 6px 4px; white-space: nowrap;
        overflow: hidden; text-overflow: ellipsis;
    }
}

/* Стилі для вікна обрізки (Green Header Style) */
.svb-crop-content {
    background: #fff;
    border-radius: 12px;
    overflow: hidden;
    width: 90%;
    max-width: 500px;
    aspect-ratio: auto !important; /* Перебиваємо старий стиль 16/9 */
    display: flex;
    flex-direction: column;
    padding: 0 !important;
}

.svb-crop-header {
    background: #008631; /* Зелений як на фото */
    padding: 15px;
    display: flex;
    justify-content: center; /* Центруємо заголовок */
    align-items: center;
    position: relative;
}

.svb-crop-header h3 {
    color: #fff;
    margin: 0;
    font-size: 18px;
    font-family: 'Roboto', sans-serif;
    font-weight: 700;
}

.svb-crop-close {
    position: absolute;
    right: 15px;
    top: 50%;
    transform: translateY(-50%);
    color: #fff;
    font-size: 24px;
    cursor: pointer;
    line-height: 1;
}

.svb-crop-body {
    padding: 20px;
    background: #f0f0f0;
    display: flex;
    justify-content: center;
    align-items: center;
}

.svb-crop-img-container {
    max-height: 60vh; /* Обмеження висоти */
    max-width: 100%;
    overflow: hidden;
}

/* Переконаємось, що картинка не вилазить */
#svb-crop-target {
    max-width: 100%;
}

.svb-crop-actions {
    padding: 15px;
    background: #fff;
    text-align: center;
    border-top: 1px solid #eee;
}

#svb-crop-save {
    background: #D62828; /* Червона кнопка Save */
    border-radius: 50px;
    padding: 10px 40px;
    font-size: 16px;
}
</style>


<div class="svb-wrap">
      <div class="svb-card">
        
        <div style="margin-bottom:15px; padding:10px 15px; background:#f0f8ff; border-radius:10px; font-size:13px; color:#333;">
            <?php echo $welcome_msg; ?>
            <?php echo $video_ready_html; ?>
        </div>
        <div id="svb-dynamic-container"></div>

        <div class="svb-header">
            <div class="svb-stepper">
                <span class="svb-dot active" id="svb-dot-1">1</span>
                <span class="svb-dot muted" id="svb-dot-2">2</span>
                <span class="svb-dot muted" id="svb-dot-3">3</span>
            </div>
            <h2 class="svb-title" id="svb-title">Крок 1 — Дані дитини</h2>
        </div>

        <form id="svb-form" enctype="multipart/form-data">
            <input type="hidden" name="selected_video_id" id="selected_video_id" value="<?php echo esc_attr($selected_video_id); ?>" />

      <input type="hidden" name="_svb_nonce" value="<?php echo esc_attr($nonce); ?>" />
      <input type="hidden" name="svb_segments" id="svb_segments" value="" />

      <section class="svb-step active" data-step="1">
        
        <div class="svb-field" style="text-align:center;">
    <label class="svb-label" style="margin-bottom:10px;">Для скількох дітей відео?</label>
    
    <div class="svb-child-count-wrap">
        <input type="radio" name="child_count" id="cc1" value="1" class="svb-child-radio" checked>
        <label for="cc1" class="svb-child-label">
            <span class="svb-c-text">1 дитина</span>
            <span class="svb-c-price">
                <s class="svb-c-old">350</s>
                <span class="svb-c-new">249 грн</span>
            </span>
        </label>
        
        <input type="radio" name="child_count" id="cc2" value="2" class="svb-child-radio">
        <label for="cc2" class="svb-child-label">
            <span class="svb-c-text">2 дитини</span>
            <span class="svb-c-price">
                <s class="svb-c-old">350</s>
                <span class="svb-c-new">249 грн</span>
            </span>
        </label>
        
        <input type="radio" name="child_count" id="cc3" value="3" class="svb-child-radio">
        <label for="cc3" class="svb-child-label">
            <span class="svb-c-text">3 дитини</span>
            <span class="svb-c-price">
                <s class="svb-c-old">350</s>
                <span class="svb-c-new">249 грн</span>
            </span>
        </label>
    </div>
</div>

        <div class="svb-grid cols-2">
          
          <div class="svb-names-row">
              <div class="svb-field svb-suggest">
                <label class="svb-label">Імʼя (Дитина 1)</label>
                <div class="svb-input-wrapper">
                    <input class="svb-input" type="text" name="name_text" placeholder="Почніть вводити ім'я..." autocomplete="off" />
                    <button class="svb-play svb-name-play" type="button" id="svb-name-play-1" title="Прослухати ім'я">▶</button>
                </div>
                <div id="svb-name-suggest"></div>
                <select name="name_audio" style="display:none;"></select> 
                <div style="margin-top:5px; font-size:12px; color:#666;" id="svb-name-display-1">Озвучка не обрана</div>
                
                <div class="svb-req-trigger-link" onclick="svbOpenNamePopup()">
    <span class="svb-req-text">✨ Не знайшли ім'я? <u>Натисніть тут</u></span>
    <span class="svb-req-plus">+</span>
</div>
              </div>

              <div class="svb-field svb-suggest field-child-2" style="display:none;">
                 <label class="svb-label">Імʼя (Дитина 2)</label>
                 <div class="svb-input-wrapper">
                     <input class="svb-input" type="text" name="name_text_2" placeholder="Ім'я другої дитини..." autocomplete="off" />
                     <button class="svb-play svb-name-play" type="button" id="svb-name-play-2" title="Прослухати ім'я">▶</button>
                 </div>
                 <div id="svb-name-suggest-2" class="svb-suggest-box"></div>
                 <select name="name_audio_2" style="display:none;"></select>
                 <div style="margin-top:5px; font-size:12px; color:#666;" id="svb-name-display-2">Озвучка не обрана</div>

              </div>

              <div class="svb-field svb-suggest field-child-3" style="display:none;">
                 <label class="svb-label">Імʼя (Дитина 3)</label>
                 <div class="svb-input-wrapper">
                     <input class="svb-input" type="text" name="name_text_3" placeholder="Ім'я третьої дитини..." autocomplete="off" />
                     <button class="svb-play svb-name-play" type="button" id="svb-name-play-3" title="Прослухати ім'я">▶</button>
                 </div>
                 <div id="svb-name-suggest-3" class="svb-suggest-box"></div>
                 <select name="name_audio_3" style="display:none;"></select>
                 <div style="margin-top:5px; font-size:12px; color:#666;" id="svb-name-display-3">Озвучка не обрана</div>

              </div>
          </div>
          
          <div class="svb-field" id="svb-age-block">
            <label class="svb-label">Вік</label>
            <div class="svb-audio-row">
              <select class="svb-select" name="age_audio" data-cat="age">
                  <option value="">Оберіть вік...</option>
              </select>
              <button class="svb-play" type="button" data-play="age" title="Прослухати">▶</button>
            </div>
          </div>

          <div class="svb-field">
            <label class="svb-label">Факти з життя</label>
            <div class="svb-audio-row">
              <select class="svb-select" name="facts_audio" data-cat="facts"></select>
              <button class="svb-play" type="button" data-play="facts" title="Прослухати">▶</button>
            </div>
          </div>

          <div class="svb-field">
            <label class="svb-label">Захоплення</label>
            <div class="svb-audio-row">
              <select class="svb-select" name="hobby_audio" data-cat="hobby"></select>
              <button class="svb-play" type="button" data-play="hobby" title="Прослухати">▶</button>
            </div>
          </div>

          <div class="svb-field">
            <label class="svb-label">За що похвалити</label>
            <div class="svb-audio-row">
              <select class="svb-select" name="praise_audio" data-cat="praise"></select>
              <button class="svb-play" type="button" data-play="praise" title="Прослухати">▶</button>
            </div>
          </div>

          <div class="svb-field">
            <label class="svb-label">Особливе прохання</label>
            <div class="svb-audio-row">
              <select class="svb-select" name="request_audio" data-cat="request"></select>
              <button class="svb-play" type="button" data-play="request" title="Прослухати">▶</button>
            </div>
          </div>

          <div class="svb-customer-cell">
              
              <div class="svb-customer-col">
                  <label class="svb-label">Ваше ім’я</label>
                  <input class="svb-input" type="text" name="customer_name" placeholder="Ваше ім’я" value="<?php echo esc_attr($order_data['customer_name'] ?? ''); ?>">
                  
                  <a href="#" class="svb-promo-link" onclick="return false;">
                      <span>🎁 Маєте промокод? <u>Натисніть тут</u></span>
                  </a>
              </div>

              <div class="svb-customer-col">
                  <label class="svb-label">Ваш e-mail</label>
                  <input class="svb-input" type="email" name="customer_email_step1" placeholder="E-mail" value="<?php echo esc_attr($order_data['customer_email'] ?? ''); ?>">
              </div>

          </div>
          

        <div class="svb-actions">
          <button class="svb-btn primary" type="button" id="svb-next-1">Далі &nbsp; &rarr;</button>
        </div>
      </section>

      <section class="svb-step" data-step="2">
        <div class="svb-photo-grid">
          
          <div class="svb-drop" data-photo="child1">
            <div class="svb-field">
              <span class="svb-label">Фото дитини 1</span>
              <input class="svb-input" type="file" name="photo_child1" accept="image/*" required>
            </div>
            
            <div class="svb-vid-preview" id="svb-vid-preview-child1">
              <video id="svb-video-child1" src="<?php echo esc_url($template_url); ?>" playsinline loop></video>
              <img id="img-child1" alt="Фото тут" />
            </div>
            
            <div class="svb-vid-seek-bar-container">
              <input
                type="range"
                class="svb-range svb-seek-bar"
                data-vid-ctrl="seek"
                data-key="child1"
                min="0"
                value="0"
                step="0.001"
              >
              <div style="margin-top: 4px; display: flex; align-items: center; gap: 6px; font-size: 12px;">
                <span>Час, мс:</span>
                <input
                  type="number"
                  class="svb-time-input"
                  data-key="child1"
                  value="0"
                  min="0"
                  step="1"
                  style="width: 90px; padding: 2px 4px; font-size: 12px;"
                >
              </div>
            </div>

            <div class="svb-vid-controls">
              <button type="button" class="svb-btn ghost" data-vid-ctrl="play" data-key="child1">► Play</button>
              <button type="button" class="svb-btn ghost" data-vid-ctrl="pause" data-key="child1" style="display:none;">❚❚ Pause</button>
              <div id="svb-vid-time-child1" class="svb-btn ghost">00:00 / 00:00</div> 
              
              <button type="button" class="svb-btn ghost" data-vid-ctrl="mute" data-key="child1">🔇 Mute</button>
              <button type="button" class="svb-btn ghost" data-vid-ctrl="unmute" data-key="child1" style="display:none;">🔈 Unmute</button>
              <input type="range" class="svb-range" data-vid-ctrl="volume" data-key="child1" min="0" max="1" step="0.05" value="0.8">
              <button type="button" class="svb-btn ghost" data-vid-ctrl="log" data-key="child1">📌 Log frame</button>
            </div>

            <div class="svb-note" style="margin-top: 4px;">
              <b>Керування:</b> Фокус на слайдерах. <b>Стрілки</b> (X/Y), <b>+ / -</b> (Scale), <b>[ / ]</b> (Radius), <b>, / .</b> (Angle).
            </div>
<div class="svb-admin-only">
<div style="margin-top: 15px; padding-top: 10px; border-top: 1px solid #f4c2c2; text-align: right;">
    <button type="button" id="svb-save-settings-btn" class="svb-btn primary" style="background: #2271b1; font-size: 13px;">
        💾 Зберегти налаштування (для всіх)
    </button>
</div>

    <div style="margin-bottom:10px; padding:6px; background:#eef; border-radius:6px; display:flex; align-items:center; gap:8px;">
    <strong>Налаштування сцени:</strong>
    <select class="svb-scene-select svb-input" data-key="child1" style="padding:4px; font-size:13px;">
        <option value="0">Сцена 1</option>
    </select>
    <button type="button" class="svb-btn ghost svb-scene-jump" data-key="child1" title="Перейти до часу сцени">👁</button>
</div>
            <div class="svb-controls" style="grid-template-columns: 1fr; gap: 12px;">
              <label>
                X
                <input
                  class="svb-val svb-val-input"
                  id="val-child1-x"
                  type="number"
                  value="785"
                  min="-1000"
                  max="2500"
                  step="5"
                  data-range-name="child1_x"
                />
                <input
                  class="svb-range svb-key-control"
                  type="range"
                  name="child1_x"
                  value="785"
                  min="-1000"
                  max="2500"
                  step="5"
                  data-val-id="val-child1-x"
                  data-key-up="ArrowRight"
                  data-key-down="ArrowLeft"
                />
              </label>

              <label>
                Y
                <input
                  class="svb-val svb-val-input"
                  id="val-child1-y"
                  type="number"
                  value="315"
                  min="-1000"
                  max="2000"
                  step="5"
                  data-range-name="child1_y"
                />
                <input
                  class="svb-range svb-key-control"
                  type="range"
                  name="child1_y"
                  value="315"
                  min="-1000"
                  max="2000"
                  step="5"
                  data-val-id="val-child1-y"
                  data-key-up="ArrowDown"
                  data-key-down="ArrowUp"
                />
              </label>

              <label>
                Scale
                <input
                  class="svb-val svb-val-input"
                  id="val-child1-scale"
                  type="number"
                  value="29"
                  min="10"
                  max="200"
                  step="1"
                  data-range-name="child1_scale"
                />
                %
                <input
                  class="svb-range svb-key-control"
                  type="range"
                  name="child1_scale"
                  value="29"
                  min="10"
                  max="200"
                  step="1"
                  data-val-id="val-child1-scale"
                  data-key-up="="
                  data-key-down="-"
                />
              </label>
<label>
                Scale X
                <input
                  class="svb-val svb-val-input"
                  id="val-child1-scale-x"
                  type="number"
                  value="100"
                  min="10"
                  max="300"
                  step="1"
                  data-range-name="child1_scale_x"
                />
                %
                <input
                  class="svb-range svb-key-control"
                  type="range"
                  name="child1_scale_x"
                  value="100"
                  min="10"
                  max="300"
                  step="1"
                  data-val-id="val-child1-scale-x"
                />
              </label>
              <label>
                Scale Y
                <input
                  class="svb-val svb-val-input"
                  id="val-child1-scale-y"
                  type="number"
                  value="100"
                  min="10"
                  max="200"
                  step="1"
                  data-range-name="child1_scale_y"
                />
                %
                <input
                  class="svb-range svb-key-control"
                  type="range"
                  name="child1_scale_y"
                  value="100"
                  min="10"
                  max="200"
                  step="1"
                  data-val-id="val-child1-scale-y"
                />
              </label>

              <label>
                Skew X
                <input
                  class="svb-val svb-val-input"
                  id="val-child1-skew"
                  type="number"
                  value="0"
                  min="-45"
                  max="45"
                  step="1"
                  data-range-name="child1_skew"
                />
                °
                <input
                  class="svb-range svb-key-control"
                  type="range"
                  name="child1_skew"
                  value="0"
                  min="-45"
                  max="45"
                  step="1"
                  data-val-id="val-child1-skew"
                />
              </label>

              <label>
                Skew Y
                <input
                  class="svb-val svb-val-input"
                  id="val-child1-skew-y"
                  type="number"
                  value="0"
                  min="-45"
                  max="45"
                  step="1"
                  data-range-name="child1_skew_y"
                />
                °
                <input
                  class="svb-range svb-key-control"
                  type="range"
                  name="child1_skew_y"
                  value="0"
                  min="-45"
                  max="45"
                  step="1"
                  data-val-id="val-child1-skew-y"
                />
              </label>

              <label>
                Angle
                <input
                  class="svb-val svb-val-input"
                  id="val-child1-angle"
                  type="number"
                  value="4"
                  min="-45"
                  max="45"
                  step="1"
                  data-range-name="child1_angle"
                />
                °
                <input
                  class="svb-range svb-key-control"
                  type="range"
                  name="child1_angle"
                  value="4"
                  min="-45"
                  max="45"
                  step="1"
                  data-val-id="val-child1-angle"
                  data-key-up="."
                  data-key-down=","
                />
              </label>

              <label>
                Radius
                <input
                  class="svb-val svb-val-input"
                  id="val-child1-radius"
                  type="number"
                  value="30"
                  min="0"
                  max="200"
                  step="1"
                  data-range-name="child1_radius"
                />
                px
                <input
                  class="svb-range svb-key-control"
                  type="range"
                  name="child1_radius"
                  value="30"
                  min="0"
                  max="200"
                  step="1"
                  data-val-id="val-child1-radius"
                  data-key-up="]"
                  data-key-down="["
                />
              </label>

              <label>
                Прозорість:
                <input
                  class="svb-val svb-val-input"
                  id="val-child1-opacity"
                  type="number"
                  value="100"
                  min="30"
                  max="100"
                  step="1"
                  data-range-name="child1_opacity"
                />
                %
                <input
                  class="svb-range svb-key-control"
                  type="range"
                  name="child1_opacity"
                  min="30"
                  max="100"
                  step="1"
                  value="100"
                  data-val-id="val-child1-opacity"
                />
              </label>

              <label>
                Світлі краї:
                <input
                  class="svb-val svb-val-input"
                  id="val-child1-glow"
                  type="number"
                  value="0"
                  min="0"
                  max="100"
                  step="1"
                  data-range-name="child1_glow"
                />
                %
                <input
                  class="svb-range svb-key-control"
                  type="range"
                  name="child1_glow"
                  min="0"
                  max="100"
                  step="1"
                  value="0"
                  data-val-id="val-child1-glow"
                />
              </label>
              <label>
                Str L (Left)
                <input
                  class="svb-val svb-val-input"
                  id="val-child1-pleft"
                  type="number"
                  value="0"
                  min="-100"
                  max="100"
                  step="1"
                  data-range-name="child1_pleft"
                />
                px
                <input
                  class="svb-range svb-key-control"
                  type="range"
                  name="child1_pleft"
                  value="0"
                  min="-100"
                  max="100"
                  step="1"
                  data-val-id="val-child1-pleft"
                />
              </label>

              <label>
                Str R (Right)
                <input
                  class="svb-val svb-val-input"
                  id="val-child1-pright"
                  type="number"
                  value="0"
                  min="-100"
                  max="100"
                  step="1"
                  data-range-name="child1_pright"
                />
                px
                <input
                  class="svb-range svb-key-control"
                  type="range"
                  name="child1_pright"
                  value="0"
                  min="-100"
                  max="100"
                  step="1"
                  data-val-id="val-child1-pright"
                />
              </label>
            </div>

            <div class="svb-intervals" data-key="child1">
  <div class="svb-label">Інтервали накладання (MM:SS:CC)</div>
  <div class="svb-intervals-rows"></div>
  <div class="svb-note">Можна вказати кілька діапазонів. Формат: хвилини:секунди:соті.</div>
  <div style="margin-top:6px; display:flex; gap:6px; flex-wrap:wrap;">
    <button type="button" class="svb-btn ghost svb-int-add" data-key="child1">+ Додати інтервал</button>
    <button type="button" class="svb-btn ghost svb-int-reset" data-key="child1">Скинути за замовчуванням</button>
  </div>
</div>
</div>

          </div>

          <div class="svb-drop" data-photo="child2">
            <div class="svb-field">
              <span class="svb-label">Фото дитини 2</span>
              <input class="svb-input" type="file" name="photo_child2" accept="image/*" required>
            </div>

            <div class="svb-vid-preview" id="svb-vid-preview-child2">
              <video id="svb-video-child2" src="<?php echo esc_url($template_url); ?>" playsinline loop></video>
              <img id="img-child2" alt="Фото тут" />
            </div>

            <div class="svb-vid-seek-bar-container">
              <input
                type="range"
                class="svb-range svb-seek-bar"
                data-vid-ctrl="seek"
                data-key="child2"
                min="0"
                value="0"
                step="0.001"
              >
              <div style="margin-top: 4px; display: flex; align-items: center; gap: 6px; font-size: 12px;">
                <span>Час, мс:</span>
                <input
                  type="number"
                  class="svb-time-input"
                  data-key="child2"
                  value="0"
                  min="0"
                  step="1"
                  style="width: 90px; padding: 2px 4px; font-size: 12px;"
                >
              </div>
            </div>

            <div class="svb-vid-controls">
              <button type="button" class="svb-btn ghost" data-vid-ctrl="play" data-key="child2">► Play</button>
              <button type="button" class="svb-btn ghost" data-vid-ctrl="pause" data-key="child2" style="display:none;">❚❚ Pause</button>
              <div id="svb-vid-time-child2" class="svb-btn ghost">00:00 / 00:00</div>

              <button type="button" class="svb-btn ghost" data-vid-ctrl="mute" data-key="child2">🔇 Mute</button>
              <button type="button" class="svb-btn ghost" data-vid-ctrl="unmute" data-key="child2" style="display:none;">🔈 Unmute</button>
              <input type="range" class="svb-range" data-vid-ctrl="volume" data-key="child2" min="0" max="1" step="0.05" value="0.8">
              <button type="button" class="svb-btn ghost" data-vid-ctrl="log" data-key="child2">📌 Log frame</button>
            </div>

            <div class="svb-note" style="margin-top: 4px;">
              <b>Керування:</b> Фокус на слайдерах. <b>Стрілки</b> (X/Y), <b>+ / -</b> (Scale), <b>[ / ]</b> (Radius), <b>, / .</b> (Angle).
            </div>
<div class="svb-admin-only">
    <div style="margin-bottom:10px; padding:6px; background:#eef; border-radius:6px; display:flex; align-items:center; gap:8px;">
    <strong>Налаштування сцени:</strong>
    <select class="svb-scene-select svb-input" data-key="child2" style="padding:4px; font-size:13px;">
        <option value="0">Сцена 1</option>
    </select>
    <button type="button" class="svb-btn ghost svb-scene-jump" data-key="child2" title="Перейти до часу сцени">👁</button>
</div>
            <div class="svb-controls" style="grid-template-columns: 1fr; gap: 12px;">
              <label>
                X
                <input
                  class="svb-val svb-val-input"
                  id="val-child2-x"
                  type="number"
                  value="1156"
                  min="-1000"
                  max="2500"
                  step="5"
                  data-range-name="child2_x"
                />
                <input
                  class="svb-range svb-key-control"
                  type="range"
                  name="child2_x"
                  value="1156"
                  min="-1000"
                  max="2500"
                  step="5"
                  data-val-id="val-child2-x"
                  data-key-up="ArrowRight"
                  data-key-down="ArrowLeft"
                />
              </label>

              <label>
                Y
                <input
                  class="svb-val svb-val-input"
                  id="val-child2-y"
                  type="number"
                  value="250"
                  min="-1000"
                  max="2000"
                  step="5"
                  data-range-name="child2_y"
                />
                <input
                  class="svb-range svb-key-control"
                  type="range"
                  name="child2_y"
                  value="250"
                  min="-1000"
                  max="2000"
                  step="5"
                  data-val-id="val-child2-y"
                  data-key-up="ArrowDown"
                  data-key-down="ArrowUp"
                />
              </label>

              <label>
                Scale
                <input
                  class="svb-val svb-val-input"
                  id="val-child2-scale"
                  type="number"
                  value="33"
                  min="10"
                  max="200"
                  step="1"
                  data-range-name="child2_scale"
                />
                %
                <input
                  class="svb-range svb-key-control"
                  type="range"
                  name="child2_scale"
                  value="33"
                  min="10"
                  max="200"
                  step="1"
                  data-val-id="val-child2-scale"
                  data-key-up="="
                  data-key-down="-"
                />
              </label>
<label>
                Scale X
                <input
                  class="svb-val svb-val-input"
                  id="val-child2-scale-x"
                  type="number"
                  value="100"
                  min="10"
                  max="300"
                  step="1"
                  data-range-name="child2_scale_x"
                />
                %
                <input
                  class="svb-range svb-key-control"
                  type="range"
                  name="child2_scale_x"
                  value="100"
                  min="10"
                  max="300"
                  step="1"
                  data-val-id="val-child2-scale-x"
                />
              </label>
              <label>
                Scale Y
                <input
                  class="svb-val svb-val-input"
                  id="val-child2-scale-y"
                  type="number"
                  value="100"
                  min="10"
                  max="200"
                  step="1"
                  data-range-name="child2_scale_y"
                />
                %
                <input
                  class="svb-range svb-key-control"
                  type="range"
                  name="child2_scale_y"
                  value="100"
                  min="10"
                  max="200"
                  step="1"
                  data-val-id="val-child2-scale-y"
                />
              </label>

              <label>
                Skew X
                <input
                  class="svb-val svb-val-input"
                  id="val-child2-skew"
                  type="number"
                  value="0"
                  min="-45"
                  max="45"
                  step="1"
                  data-range-name="child2_skew"
                />
                °
                <input
                  class="svb-range svb-key-control"
                  type="range"
                  name="child2_skew"
                  value="0"
                  min="-45"
                  max="45"
                  step="1"
                  data-val-id="val-child2-skew"
                />
              </label>

              <label>
                Skew Y
                <input
                  class="svb-val svb-val-input"
                  id="val-child2-skew-y"
                  type="number"
                  value="0"
                  min="-45"
                  max="45"
                  step="1"
                  data-range-name="child2_skew_y"
                />
                °
                <input
                  class="svb-range svb-key-control"
                  type="range"
                  name="child2_skew_y"
                  value="0"
                  min="-45"
                  max="45"
                  step="1"
                  data-val-id="val-child2-skew-y"
                />
              </label>

              <label>
                Angle
                <input
                  class="svb-val svb-val-input"
                  id="val-child2-angle"
                  type="number"
                  value="10"
                  min="-45"
                  max="45"
                  step="1"
                  data-range-name="child2_angle"
                />
                °
                <input
                  class="svb-range svb-key-control"
                  type="range"
                  name="child2_angle"
                  value="10"
                  min="-45"
                  max="45"
                  step="1"
                  data-val-id="val-child2-angle"
                  data-key-up="."
                  data-key-down=","
                />
              </label>

              <label>
                Radius
                <input
                  class="svb-val svb-val-input"
                  id="val-child2-radius"
                  type="number"
                  value="0"
                  min="0"
                  max="200"
                  step="1"
                  data-range-name="child2_radius"
                />
                px
                <input
                  class="svb-range svb-key-control"
                  type="range"
                  name="child2_radius"
                  value="0"
                  min="0"
                  max="200"
                  step="1"
                  data-val-id="val-child2-radius"
                  data-key-up="]"
                  data-key-down="["
                />
              </label>

              <label>
                Прозорість:
                <input
                  class="svb-val svb-val-input"
                  id="val-child2-opacity"
                  type="number"
                  value="100"
                  min="30"
                  max="100"
                  step="1"
                  data-range-name="child2_opacity"
                />
                %
                <input
                  class="svb-range svb-key-control"
                  type="range"
                  name="child2_opacity"
                  min="30"
                  max="100"
                  step="1"
                  value="100"
                  data-val-id="val-child2-opacity"
                />
              </label>

              <label>
                Світлі краї:
                <input
                  class="svb-val svb-val-input"
                  id="val-child2-glow"
                  type="number"
                  value="0"
                  min="0"
                  max="100"
                  step="1"
                  data-range-name="child2_glow"
                />
                %
                <input
                  class="svb-range svb-key-control"
                  type="range"
                  name="child2_glow"
                  min="0"
                  max="100"
                  step="1"
                  value="0"
                  data-val-id="val-child2-glow"
                />
              </label>
              <label>
                Str L (Left)
                <input
                  class="svb-val svb-val-input"
                  id="val-child2-pleft"
                  type="number"
                  value="0"
                  min="-100"
                  max="100"
                  step="1"
                  data-range-name="child2_pleft"
                />
                px
                <input
                  class="svb-range svb-key-control"
                  type="range"
                  name="child2_pleft"
                  value="0"
                  min="-100"
                  max="100"
                  step="1"
                  data-val-id="val-child2-pleft"
                />
              </label>

              <label>
                Str R (Right)
                <input
                  class="svb-val svb-val-input"
                  id="val-child2-pright"
                  type="number"
                  value="0"
                  min="-100"
                  max="100"
                  step="1"
                  data-range-name="child2_pright"
                />
                px
                <input
                  class="svb-range svb-key-control"
                  type="range"
                  name="child2_pright"
                  value="0"
                  min="-100"
                  max="100"
                  step="1"
                  data-val-id="val-child2-pright"
                />
              </label>
            </div>

            <div class="svb-intervals" data-key="child2">
  <div class="svb-label">Інтервали накладання (MM:SS:CC)</div>
  <div class="svb-intervals-rows"></div>
  <div class="svb-note">За замовчуванням: сцена дитини в середині відео.</div>
  <div style="margin-top:6px; display:flex; gap:6px; flex-wrap:wrap;">
    <button type="button" class="svb-btn ghost svb-int-add" data-key="child2">+ Додати інтервал</button>
    <button type="button" class="svb-btn ghost svb-int-reset" data-key="child2">Скинути за замовчуванням</button>
  </div>
</div>
</div>

          </div>

          <div class="svb-drop" data-photo="parent1">
            <div class="svb-field">
              <span class="svb-label">Фото батька</span>
              <input class="svb-input" type="file" name="photo_parent1" accept="image/*" required>
            </div>

            <div class="svb-vid-preview" id="svb-vid-preview-parent1">
              <video id="svb-video-parent1" src="<?php echo esc_url($template_url); ?>" playsinline loop></video>
              <img id="img-parent1" alt="Фото тут" />
            </div>

            <div class="svb-vid-seek-bar-container">
              <input
                type="range"
                class="svb-range svb-seek-bar"
                data-vid-ctrl="seek"
                data-key="parent1"
                min="0"
                value="0"
                step="0.001"
              >
              <div style="margin-top: 4px; display: flex; align-items: center; gap: 6px; font-size: 12px;">
                <span>Час, мс:</span>
                <input
                  type="number"
                  class="svb-time-input"
                  data-key="parent1"
                  value="0"
                  min="0"
                  step="1"
                  style="width: 90px; padding: 2px 4px; font-size: 12px;"
                >
              </div>
            </div>

            <div class="svb-vid-controls">
              <button type="button" class="svb-btn ghost" data-vid-ctrl="play" data-key="parent1">► Play</button>
              <button type="button" class="svb-btn ghost" data-vid-ctrl="pause" data-key="parent1" style="display:none;">❚❚ Pause</button>
              <div id="svb-vid-time-parent1" class="svb-btn ghost">00:00 / 00:00</div>

              <button type="button" class="svb-btn ghost" data-vid-ctrl="mute" data-key="parent1">🔇 Mute</button>
              <button type="button" class="svb-btn ghost" data-vid-ctrl="unmute" data-key="parent1" style="display:none;">🔈 Unmute</button>
              <input type="range" class="svb-range" data-vid-ctrl="volume" data-key="parent1" min="0" max="1" step="0.05" value="0.8">
              <button type="button" class="svb-btn ghost" data-vid-ctrl="log" data-key="parent1">📌 Log frame</button>
            </div>

            <div class="svb-note" style="margin-top: 4px;">
              <b>Керування:</b> Фокус на слайдерах. <b>Стрілки</b> (X/Y), <b>+ / -</b> (Scale), <b>[ / ]</b> (Radius), <b>, / .</b> (Angle).
            </div>
<div class="svb-admin-only">
    <div style="margin-bottom:10px; padding:6px; background:#eef; border-radius:6px; display:flex; align-items:center; gap:8px;">
    <strong>Налаштування сцени:</strong>
    <select class="svb-scene-select svb-input" data-key="parent1" style="padding:4px; font-size:13px;">
        <option value="0">Сцена 1</option>
    </select>
    <button type="button" class="svb-btn ghost svb-scene-jump" data-key="parent1" title="Перейти до часу сцени">👁</button>
</div>
            <div class="svb-controls" style="grid-template-columns: 1fr; gap: 12px;">
              <label>
                X
                <input
                  class="svb-val svb-val-input"
                  id="val-parent1-x"
                  type="number"
                  value="166"
                  min="-1000"
                  max="2500"
                  step="5"
                  data-range-name="parent1_x"
                />
                <input
                  class="svb-range svb-key-control"
                  type="range"
                  name="parent1_x"
                  value="166"
                  min="-1000"
                  max="2500"
                  step="5"
                  data-val-id="val-parent1-x"
                  data-key-up="ArrowRight"
                  data-key-down="ArrowLeft"
                />
              </label>

              <label>
                Y
                <input
                  class="svb-val svb-val-input"
                  id="val-parent1-y"
                  type="number"
                  value="0"
                  min="-1000"
                  max="2000"
                  step="5"
                  data-range-name="parent1_y"
                />
                <input
                  class="svb-range svb-key-control"
                  type="range"
                  name="parent1_y"
                  value="0"
                  min="-1000"
                  max="2000"
                  step="5"
                  data-val-id="val-parent1-y"
                  data-key-up="ArrowDown"
                  data-key-down="ArrowUp"
                />
              </label>

              <label>
                Scale
                <input
                  class="svb-val svb-val-input"
                  id="val-parent1-scale"
                  type="number"
                  value="75"
                  min="10"
                  max="200"
                  step="1"
                  data-range-name="parent1_scale"
                />
                %
                <input
                  class="svb-range svb-key-control"
                  type="range"
                  name="parent1_scale"
                  value="75"
                  min="10"
                  max="200"
                  step="1"
                  data-val-id="val-parent1-scale"
                  data-key-up="="
                  data-key-down="-"
                />
              </label>
<label>
                Scale X
                <input
                  class="svb-val svb-val-input"
                  id="val-parent1-scale-x"
                  type="number"
                  value="100"
                  min="10"
                  max="300"
                  step="1"
                  data-range-name="parent1_scale_x"
                />
                %
                <input
                  class="svb-range svb-key-control"
                  type="range"
                  name="parent1_scale_x"
                  value="100"
                  min="10"
                  max="300"
                  step="1"
                  data-val-id="val-parent1-scale-x"
                />
              </label>
              <label>
                Scale Y
                <input
                  class="svb-val svb-val-input"
                  id="val-parent1-scale-y"
                  type="number"
                  value="100"
                  min="10"
                  max="200"
                  step="1"
                  data-range-name="parent1_scale_y"
                />
                %
                <input
                  class="svb-range svb-key-control"
                  type="range"
                  name="parent1_scale_y"
                  value="100"
                  min="10"
                  max="200"
                  step="1"
                  data-val-id="val-parent1-scale-y"
                />
              </label>

              <label>
                Skew X
                <input
                  class="svb-val svb-val-input"
                  id="val-parent1-skew"
                  type="number"
                  value="0"
                  min="-45"
                  max="45"
                  step="1"
                  data-range-name="parent1_skew"
                />
                °
                <input
                  class="svb-range svb-key-control"
                  type="range"
                  name="parent1_skew"
                  value="0"
                  min="-45"
                  max="45"
                  step="1"
                  data-val-id="val-parent1-skew"
                />
              </label>

              <label>
                Skew Y
                <input
                  class="svb-val svb-val-input"
                  id="val-parent1-skew-y"
                  type="number"
                  value="0"
                  min="-45"
                  max="45"
                  step="1"
                  data-range-name="parent1_skew_y"
                />
                °
                <input
                  class="svb-range svb-key-control"
                  type="range"
                  name="parent1_skew_y"
                  value="0"
                  min="-45"
                  max="45"
                  step="1"
                  data-val-id="val-parent1-skew-y"
                />
              </label>

              <label>
                Angle
                <input
                  class="svb-val svb-val-input"
                  id="val-parent1-angle"
                  type="number"
                  value="0"
                  min="-45"
                  max="45"
                  step="1"
                  data-range-name="parent1_angle"
                />
                °
                <input
                  class="svb-range svb-key-control"
                  type="range"
                  name="parent1_angle"
                  value="0"
                  min="-45"
                  max="45"
                  step="1"
                  data-val-id="val-parent1-angle"
                  data-key-up="."
                  data-key-down=","
                />
              </label>

              <label>
                Radius
                <input
                  class="svb-val svb-val-input"
                  id="val-parent1-radius"
                  type="number"
                  value="0"
                  min="0"
                  max="200"
                  step="1"
                  data-range-name="parent1_radius"
                />
                px
                <input
                  class="svb-range svb-key-control"
                  type="range"
                  name="parent1_radius"
                  value="0"
                  min="0"
                  max="200"
                  step="1"
                  data-val-id="val-parent1-radius"
                  data-key-up="]"
                  data-key-down="["
                />
              </label>

              <label>
                Прозорість:
                <input
                  class="svb-val svb-val-input"
                  id="val-parent1-opacity"
                  type="number"
                  value="100"
                  min="30"
                  max="100"
                  step="1"
                  data-range-name="parent1_opacity"
                />
                %
                <input
                  class="svb-range svb-key-control"
                  type="range"
                  name="parent1_opacity"
                  min="30"
                  max="100"
                  step="1"
                  value="100"
                  data-val-id="val-parent1-opacity"
                />
              </label>

              <label>
                Світлі краї:
                <input
                  class="svb-val svb-val-input"
                  id="val-parent1-glow"
                  type="number"
                  value="0"
                  min="0"
                  max="100"
                  step="1"
                  data-range-name="parent1_glow"
                />
                %
                <input
                  class="svb-range svb-key-control"
                  type="range"
                  name="parent1_glow"
                  min="0"
                  max="100"
                  step="1"
                  value="0"
                  data-val-id="val-parent1-glow"
                />
              </label>
              <label>
                Str L (Left)
                <input
                  class="svb-val svb-val-input"
                  id="val-parent1-pleft"
                  type="number"
                  value="0"
                  min="-100"
                  max="100"
                  step="1"
                  data-range-name="parent1_pleft"
                />
                px
                <input
                  class="svb-range svb-key-control"
                  type="range"
                  name="parent1_pleft"
                  value="0"
                  min="-100"
                  max="100"
                  step="1"
                  data-val-id="val-parent1-pleft"
                />
              </label>

              <label>
                Str R (Right)
                <input
                  class="svb-val svb-val-input"
                  id="val-parent1-pright"
                  type="number"
                  value="0"
                  min="-100"
                  max="100"
                  step="1"
                  data-range-name="parent1_pright"
                />
                px
                <input
                  class="svb-range svb-key-control"
                  type="range"
                  name="parent1_pright"
                  value="0"
                  min="-100"
                  max="100"
                  step="1"
                  data-val-id="val-parent1-pright"
                />
              </label>
            </div>

            <div class="svb-intervals" data-key="parents">
  <div class="svb-label">Інтервали для обох батьків (MM:SS:CC)</div>
  <div class="svb-intervals-rows"></div>
  <div class="svb-note">Ці інтервали застосовуються одночасно до фото батька і матері.</div>
  <div style="margin-top:6px; display:flex; gap:6px; flex-wrap:wrap;">
    <button type="button" class="svb-btn ghost svb-int-add" data-key="parents">+ Додати інтервал</button>
    <button type="button" class="svb-btn ghost svb-int-reset" data-key="parents">Скинути за замовчуванням</button>
  </div>
</div>
</div>
          </div>

          <div class="svb-drop" data-photo="parent2">
            <div class="svb-field">
              <span class="svb-label">Фото матері</span>
              <input class="svb-input" type="file" name="photo_parent2" accept="image/*" required>
            </div>

            <div class="svb-vid-preview" id="svb-vid-preview-parent2">
              <video id="svb-video-parent2" src="<?php echo esc_url($template_url); ?>" playsinline loop></video>
              <img id="img-parent2" alt="Фото тут" />
            </div>

            <div class="svb-vid-seek-bar-container">
              <input
                type="range"
                class="svb-range svb-seek-bar"
                data-vid-ctrl="seek"
                data-key="parent2"
                min="0"
                value="0"
                step="0.001"
              >
              <div style="margin-top: 4px; display: flex; align-items: center; gap: 6px; font-size: 12px;">
                <span>Час, мс:</span>
                <input
                  type="number"
                  class="svb-time-input"
                  data-key="parent2"
                  value="0"
                  min="0"
                  step="1"
                  style="width: 90px; padding: 2px 4px; font-size: 12px;"
                >
              </div>
            </div>

            <div class="svb-vid-controls">
              <button type="button" class="svb-btn ghost" data-vid-ctrl="play" data-key="parent2">► Play</button>
              <button type="button" class="svb-btn ghost" data-vid-ctrl="pause" data-key="parent2" style="display:none;">❚❚ Pause</button>
              <div id="svb-vid-time-parent2" class="svb-btn ghost">00:00 / 00:00</div>

              <button type="button" class="svb-btn ghost" data-vid-ctrl="mute" data-key="parent2">🔇 Mute</button>
              <button type="button" class="svb-btn ghost" data-vid-ctrl="unmute" data-key="parent2" style="display:none;">🔈 Unmute</button>
              <input type="range" class="svb-range" data-vid-ctrl="volume" data-key="parent2" min="0" max="1" step="0.05" value="0.8">
              <button type="button" class="svb-btn ghost" data-vid-ctrl="log" data-key="parent2">📌 Log frame</button>
            </div>

            <div class="svb-note" style="margin-top: 4px;">
              <b>Керування:</b> Фокус на слайдерах. <b>Стрілки</b> (X/Y), <b>+ / -</b> (Scale), <b>[ / ]</b> (Radius), <b>, / .</b> (Angle).
            </div>
<div class="svb-admin-only">
    <div style="margin-bottom:10px; padding:6px; background:#eef; border-radius:6px; display:flex; align-items:center; gap:8px;">
    <strong>Налаштування сцени:</strong>
    <select class="svb-scene-select svb-input" data-key="parent2" style="padding:4px; font-size:13px;">
        <option value="0">Сцена 1</option>
    </select>
    <button type="button" class="svb-btn ghost svb-scene-jump" data-key="parent2" title="Перейти до часу сцени">👁</button>
</div>
            <div class="svb-controls" style="grid-template-columns: 1fr; gap: 12px;">
              <label>
                X
                <input
                  class="svb-val svb-val-input"
                  id="val-parent2-x"
                  type="number"
                  value="166"
                  min="-1000"
                  max="2500"
                  step="5"
                  data-range-name="parent2_x"
                />
                <input
                  class="svb-range svb-key-control"
                  type="range"
                  name="parent2_x"
                  value="166"
                  min="-1000"
                  max="2500"
                  step="5"
                  data-val-id="val-parent2-x"
                  data-key-up="ArrowRight"
                  data-key-down="ArrowLeft"
                />
              </label>

              <label>
                Y
                <input
                  class="svb-val svb-val-input"
                  id="val-parent2-y"
                  type="number"
                  value="0"
                  min="-1000"
                  max="2000"
                  step="5"
                  data-range-name="parent2_y"
                />
                <input
                  class="svb-range svb-key-control"
                  type="range"
                  name="parent2_y"
                  value="0"
                  min="-1000"
                  max="2000"
                  step="5"
                  data-val-id="val-parent2-y"
                  data-key-up="ArrowDown"
                  data-key-down="ArrowUp"
                />
              </label>

              <label>
                Scale
                <input
                  class="svb-val svb-val-input"
                  id="val-parent2-scale"
                  type="number"
                  value="75"
                  min="10"
                  max="200"
                  step="1"
                  data-range-name="parent2_scale"
                />
                %
                <input
                  class="svb-range svb-key-control"
                  type="range"
                  name="parent2_scale"
                  value="75"
                  min="10"
                  max="200"
                  step="1"
                  data-val-id="val-parent2-scale"
                  data-key-up="="
                  data-key-down="-"
                />
              </label>
<label>
                Scale X
                <input
                  class="svb-val svb-val-input"
                  id="val-parent2-scale-x"
                  type="number"
                  value="100"
                  min="10"
                  max="300"
                  step="1"
                  data-range-name="parent2_scale_x"
                />
                %
                <input
                  class="svb-range svb-key-control"
                  type="range"
                  name="parent2_scale_x"
                  value="100"
                  min="10"
                  max="300"
                  step="1"
                  data-val-id="val-parent2-scale-x"
                />
              </label>
              <label>
                Scale Y
                <input
                  class="svb-val svb-val-input"
                  id="val-parent2-scale-y"
                  type="number"
                  value="100"
                  min="10"
                  max="200"
                  step="1"
                  data-range-name="parent2_scale_y"
                />
                %
                <input
                  class="svb-range svb-key-control"
                  type="range"
                  name="parent2_scale_y"
                  value="100"
                  min="10"
                  max="200"
                  step="1"
                  data-val-id="val-parent2-scale-y"
                />
              </label>

              <label>
                Skew X
                <input
                  class="svb-val svb-val-input"
                  id="val-parent2-skew"
                  type="number"
                  value="0"
                  min="-45"
                  max="45"
                  step="1"
                  data-range-name="parent2_skew"
                />
                °
                <input
                  class="svb-range svb-key-control"
                  type="range"
                  name="parent2_skew"
                  value="0"
                  min="-45"
                  max="45"
                  step="1"
                  data-val-id="val-parent2-skew"
                />
              </label>

              <label>
                Skew Y
                <input
                  class="svb-val svb-val-input"
                  id="val-parent2-skew-y"
                  type="number"
                  value="0"
                  min="-45"
                  max="45"
                  step="1"
                  data-range-name="parent2_skew_y"
                />
                °
                <input
                  class="svb-range svb-key-control"
                  type="range"
                  name="parent2_skew_y"
                  value="0"
                  min="-45"
                  max="45"
                  step="1"
                  data-val-id="val-parent2-skew-y"
                />
              </label>

              <label>
                Angle
                <input
                  class="svb-val svb-val-input"
                  id="val-parent2-angle"
                  type="number"
                  value="0"
                  min="-45"
                  max="45"
                  step="1"
                  data-range-name="parent2_angle"
                />
                °
                <input
                  class="svb-range svb-key-control"
                  type="range"
                  name="parent2_angle"
                  value="0"
                  min="-45"
                  max="45"
                  step="1"
                  data-val-id="val-parent2-angle"
                  data-key-up="."
                  data-key-down=","
                />
              </label>

              <label>
                Radius
                <input
                  class="svb-val svb-val-input"
                  id="val-parent2-radius"
                  type="number"
                  value="0"
                  min="0"
                  max="200"
                  step="1"
                  data-range-name="parent2_radius"
                />
                px
                <input
                  class="svb-range svb-key-control"
                  type="range"
                  name="parent2_radius"
                  value="0"
                  min="0"
                  max="200"
                  step="1"
                  data-val-id="val-parent2-radius"
                  data-key-up="]"
                  data-key-down="["
                />
              </label>

              <label>
                Прозорість:
                <input
                  class="svb-val svb-val-input"
                  id="val-parent2-opacity"
                  type="number"
                  value="100"
                  min="30"
                  max="100"
                  step="1"
                  data-range-name="parent2_opacity"
                />
                %
                <input
                  class="svb-range svb-key-control"
                  type="range"
                  name="parent2_opacity"
                  min="30"
                  max="100"
                  step="1"
                  value="100"
                  data-val-id="val-parent2-opacity"
                />
              </label>

              <label>
                Світлі краї:
                <input
                  class="svb-val svb-val-input"
                  id="val-parent2-glow"
                  type="number"
                  value="0"
                  min="0"
                  max="100"
                  step="1"
                  data-range-name="parent2_glow"
                />
                %
                <input
                  class="svb-range svb-key-control"
                  type="range"
                  name="parent2_glow"
                  min="0"
                  max="100"
                  step="1"
                  value="0"
                  data-val-id="val-parent2-glow"
                />
              </label>
              <label>
                Str L (Left)
                <input
                  class="svb-val svb-val-input"
                  id="val-parent2-pleft"
                  type="number"
                  value="0"
                  min="-100"
                  max="100"
                  step="1"
                  data-range-name="parent2_pleft"
                />
                px
                <input
                  class="svb-range svb-key-control"
                  type="range"
                  name="parent2_pleft"
                  value="0"
                  min="-100"
                  max="100"
                  step="1"
                  data-val-id="val-parent2-pleft"
                />
              </label>

              <label>
                Str R (Right)
                <input
                  class="svb-val svb-val-input"
                  id="val-parent2-pright"
                  type="number"
                  value="0"
                  min="-100"
                  max="100"
                  step="1"
                  data-range-name="parent2_pright"
                />
                px
                <input
                  class="svb-range svb-key-control"
                  type="range"
                  name="parent2_pright"
                  value="0"
                  min="-100"
                  max="100"
                  step="1"
                  data-val-id="val-parent2-pright"
                />
              </label>
            </div>

            <div class="svb-intervals" data-key="parents">
  <div class="svb-label">Інтервали для обох батьків (MM:SS:CC)</div>
  <div class="svb-intervals-rows"></div>
  <div class="svb-note">Ці інтервали застосовуються одночасно до фото батька і матері.</div>
  <div style="margin-top:6px; display:flex; gap:6px; flex-wrap:wrap;">
    <button type="button" class="svb-btn ghost svb-int-add" data-key="parents">+ Додати інтервал</button>
    <button type="button" class="svb-btn ghost svb-int-reset" data-key="parents">Скинути за замовчуванням</button>
  </div>
</div>
</div>
          </div>

          </div>

        <div class="svb-actions">
          <button class="svb-btn ghost" type="button" id="svb-back-2">Назад</button>
          <button class="svb-btn primary" type="button" id="svb-next-2">Далі &nbsp; &rarr;</button>
        </div>
      </section>

      <section class="svb-step" data-step="3">
        <p><span class="svb-spinner" id="svb-spin" style="display:none"></span><strong id="svb-status">Починаємо збірку відео…</strong></p>
        <div class="svb-field" style="margin-top:16px;">
          <label class="svb-label">Email для отримання посилання</label>
          <input class="svb-input" type="email" name="email" id="svb-email" placeholder="you@example.com" required />
        </div>
        <div class="svb-actions">
          <button class="svb-btn ghost" type="button" id="svb-back-3">Назад</button>
          <button class="svb-btn primary" type="button" id="svb-finish" disabled>Отримати відео</button>
        </div>
        <div class="svb-result" id="svb-result" style="display:none"></div>
      </section>
    </form>
  </div>
</div>

<div class="svb-screenlock" id="svb-lock">
  <div class="svb-screenlock__spinner" id="svb-spinner-box">
    <span id="svb-lock-percent">0%</span>
  </div>
  <div class="svb-screenlock__txt" id="svb-lock-text">Формуємо відео… будь ласка, не закривайте сторінку</div>
</div>

<div class="svb-modal" id="svb-video-modal" onclick="svbCloseModal(event)">
  <div class="svb-modal-content">
    <div class="svb-modal-close" onclick="svbCloseModal(event, true)">&times;</div>
    <video id="svb-modal-video" controls controlsList="nodownload" oncontextmenu="return false;"></video>
  </div>
</div>

<div class="svb-modal" id="svb-name-popup" onclick="svbCloseNamePopup(event)">
  <div class="svb-modal-content svb-req-popup-content">
    <div class="svb-modal-close svb-close-black" onclick="svbCloseNamePopup(event, true)">&times;</div>
    
    <h3>Запит на ім'я</h3>
    <div class="svb-note" style="text-align:center; margin-bottom:10px;">
        Шукайте тільки українською мовою. Уважно перегляньте весь перелік імен та спробуйте знайти інші форми потрібного імені. Якщо ж його все одно не виявиться, надішліть нам запит, і ми додамо це ім’я протягом найближчих 2–3 діб (зазвичай швидше).
    </div>

    <div class="svb-field">
        <label class="svb-label">Ім'я, стать, наголос</label>
        <input type="text" id="popup_req_name" class="svb-input" placeholder="Наприклад: Оленка (дівчинка), наголос на Е">
    </div>

    <div class="svb-field">
        <label class="svb-label">Ваш Email (для сповіщення)</label>
        <input type="email" id="popup_req_email" class="svb-input" placeholder="mail@example.com">
    </div>

    <button type="button" id="popup_req_submit" class="svb-btn primary" style="justify-content: center;">Надіслати запит</button>
  </div>
</div>
<div class="svb-modal" id="svb-crop-modal" style="display:none;">
    <div class="svb-modal-content svb-crop-content">
        <div class="svb-crop-header">
            <h3>Add a photo of the child:</h3> <div class="svb-crop-close" onclick="svbCloseCrop()">&times;</div>
        </div>
        
        <div class="svb-crop-body">
            <div class="svb-crop-img-container">
                <img id="svb-crop-target" src="" alt="Crop Preview">
            </div>
        </div>

        <div class="svb-crop-actions">
            <button type="button" class="svb-btn primary" id="svb-crop-save">Save</button>
        </div>
    </div>
</div>
<script>
/* === CSS MATRIX3D SOLVER (Для точної імітації FFmpeg Perspective) === */
function svbSolveHomography(src, dst) {
    let t = 0;
    let i = 0;
    let x = 0;
    let y = 0;
    let u = 0;
    let v = 0;
    let a = [];
    let b = [];
    let A = [];
    let B = [];
    let X = [];

    for (i = 0; i < 4; i++) {
        x = src[i][0];
        y = src[i][1];
        u = dst[i][0];
        v = dst[i][1];
        a.push([x, y, 1, 0, 0, 0, -x * u, -y * u]);
        a.push([0, 0, 0, x, y, 1, -x * v, -y * v]);
        b.push(u);
        b.push(v);
    }

    // Gaussian elimination
    const n = 8;
    for (i = 0; i < n; i++) {
        let maxEl = Math.abs(a[i][i]);
        let maxRow = i;
        for (let k = i + 1; k < n; k++) {
            if (Math.abs(a[k][i]) > maxEl) {
                maxEl = Math.abs(a[k][i]);
                maxRow = k;
            }
        }

        for (let k = i; k < n; k++) {
            let tmp = a[maxRow][k];
            a[maxRow][k] = a[i][k];
            a[i][k] = tmp;
        }
        let tmp = b[maxRow];
        b[maxRow] = b[i];
        b[i] = tmp;

        for (let k = i + 1; k < n; k++) {
            let c = -a[k][i] / a[i][i];
            for (let j = i; j < n; j++) {
                if (i === j) {
                    a[k][j] = 0;
                } else {
                    a[k][j] += c * a[i][j];
                }
            }
            b[k] += c * b[i];
        }
    }

    X = new Array(n);
    for (i = n - 1; i > -1; i--) {
        X[i] = b[i] / a[i][i];
        for (let k = i - 1; k > -1; k--) {
            b[k] -= a[k][i] * X[i];
        }
    }
    
    // Формуємо matrix3d(a, b, 0, c, d, e, 0, f, 0, 0, 1, 0, g, h, 0, 1)
    // CSS Matrix:
    // 0:h0  1:h3  2:0   3:h6
    // 4:h1  5:h4  6:0   7:h7
    // 8:0   9:0   10:1  11:0
    // 12:h2 13:h5 14:0  15:1  <-- h8=1 implicitly in solution but X[8] is 1
    
    return [
        X[0], X[3], 0, X[6],
        X[1], X[4], 0, X[7],
        0,    0,    1, 0,
        X[2], X[5], 0, 1
    ];
}



function svbArrToCssMatrix(m) {
    return 'matrix3d(' + m.map(v => v.toFixed(6)).join(',') + ')';
}

// Глобальні змінні стану
let svbCurrentChildCount = 1;
// === УНИВЕРСАЛЬНЫЙ РЕНДЕР (ПК vs МОБИЛКА) ===
function svbRenderUI() {
    const container = document.getElementById('svb-dynamic-container');
    if (!container) return;

    // Сохраняем текущие значения перед удалением (особенно важно для Select-ов)
    const currentValues = {};
    container.querySelectorAll('select, input').forEach(el => {
        if (el.name && el.type !== 'file' && el.type !== 'radio') {
            currentValues[el.name] = el.value;
        }
    });

    container.innerHTML = ''; // Очистка

    // ... (код логики availableVideos оставляем без изменений) ...
    const allTemplates = Object.entries(SVB_VIDEO_TEMPLATES);
    const availableVideos = allTemplates.filter(([vidId, data]) => {
        const allowed = data.for_children || [1];
        return allowed.includes(svbCurrentChildCount);
    });

    const isSelectedValid = availableVideos.some(([vidId]) => vidId === SVB_SELECTED_VIDEO_ID);
    if (!isSelectedValid && availableVideos.length > 0) {
        SVB_SELECTED_VIDEO_ID = availableVideos[0][0];
        const hInput = document.getElementById('selected_video_id');
        if(hInput) hInput.value = SVB_SELECTED_VIDEO_ID;
        svbSelectVideoTemplate(SVB_SELECTED_VIDEO_ID, false);
    }

    const isMobile = window.innerWidth <= 768;

    if (isMobile) {
        // === МОБИЛЬНАЯ ВЕРСИЯ ===
        const tplSection = document.createElement('div');
        tplSection.className = 'svb-template-section';

        const [activeId, activeData] = availableVideos.find(([vidId]) => vidId === SVB_SELECTED_VIDEO_ID) || availableVideos[0];
        const activePoster = activeData.image || 'https://placehold.co/600x340';

        const featHTML = `
            <div id="svb-featured-container" class="svb-featured-container">
                <div class="svb-feat-card">
                    <div class="svb-feat-img-wrap">
                        <img src="${activePoster}" class="svb-feat-thumb" alt="${activeData.label}">
                        <button type="button" class="svb-feat-btn" onclick="svbPreviewModal('${activeData.url}')">
                            Дивитися приклад
                        </button>
                    </div>
                    <div class="svb-feat-label">${activeData.label}</div>
                </div>
            </div>
            <h3 class="svb-section-title">Інші відео</h3>
            <div id="svb-others-slider" class="svb-others-slider"></div>
        `;
        tplSection.innerHTML = featHTML;
        container.appendChild(tplSection);

        const sliderContainer = tplSection.querySelector('#svb-others-slider');
        
        availableVideos.forEach(([vidId, data]) => {
            if (vidId === activeId) return; 

            const smallCard = document.createElement('div');
            smallCard.className = 'svb-small-card';
            const poster = data.image || 'https://placehold.co/600x340';

            smallCard.innerHTML = `
                <div style="position:relative;">
                    <img src="${poster}" class="svb-small-thumb" alt="${data.label}">
                </div>
                <div class="svb-small-label">${data.label}</div>
            `;

            smallCard.onclick = () => {
                SVB_SELECTED_VIDEO_ID = vidId;
                const hInput = document.getElementById('selected_video_id');
                if(hInput) hInput.value = vidId;
                svbSelectVideoTemplate(vidId, false);
                svbRenderUI(); 
            };

            sliderContainer.appendChild(smallCard);
        });

    } else {
        // === ПК ВЕРСИЯ ===
        const grid = document.createElement('div');
        grid.className = 'svb-desktop-grid';

        availableVideos.forEach(([vidId, data]) => {
            const isActive = (vidId === SVB_SELECTED_VIDEO_ID);
            const poster = data.image || 'https://placehold.co/600x340';

            const card = document.createElement('div');
            card.className = `svb-video-option ${isActive ? 'active' : ''}`;
            
            card.innerHTML = `
                <div class="svb-video-thumb-box">
                    <img src="${poster}" alt="${data.label}">
                    <button type="button" class="svb-video-btn-preview" onclick="event.stopPropagation(); svbPreviewModal('${data.url}')">
                        Дивитися приклад
                    </button>
                </div>
                <div class="svb-video-label-bar">${data.label}</div>
            `;

            card.onclick = () => {
                SVB_SELECTED_VIDEO_ID = vidId;
                const hInput = document.getElementById('selected_video_id');
                if(hInput) hInput.value = vidId;
                svbSelectVideoTemplate(vidId, false);
                svbRenderUI(); 
            };

            grid.appendChild(card);
        });
        
        container.appendChild(grid);
    }

    // === ВАЖНО: ПЕРЕЗАПУСК ЛОГИКИ ПОСЛЕ РЕНДЕРА ===
    svbReinitAfterRender(currentValues);
}

// Добавляем слушатель изменения размера экрана, чтобы перестраивать верстку на лету
window.addEventListener('resize', svbRenderUI);

// 2. Оновлена логіка вибору шаблону (завантаження даних у форми)
function svbSelectVideoTemplate(videoId, shouldRenderUI = true) {
    SVB_SELECTED_VIDEO_ID = videoId;
    
    // Оновлюємо hidden-поле
    const hiddenInput = document.getElementById('selected_video_id');
    if (hiddenInput) hiddenInput.value = videoId;

    // Завантажуємо відео в прев'ю на 2-му кроці
    const tpl = SVB_VIDEO_TEMPLATES[videoId];
    if (tpl && tpl.url) {
        document.querySelectorAll('.svb-vid-preview video').forEach(video => {
            try { video.pause(); } catch(e) {}
            video.src = tpl.url;
            video.currentTime = 0;
            video.load();
        });
    }

    // Оновлюємо таймінги
    if (typeof svbUpdateTimingsForVideo === 'function') {
        svbUpdateTimingsForVideo(videoId);
    }
    
    // Якщо викликано вручну (не з рендера), то оновити UI
    if (shouldRenderUI) {
        svbRenderUI();
    }
}

// 3. Оновлена логіка перемикача дітей
function svbBindChildCount() {
    const radios = document.querySelectorAll('input[name="child_count"]');
    const ageBlock = document.getElementById('svb-age-block');
    const field2 = document.querySelector('.field-child-2');
    const field3 = document.querySelector('.field-child-3');

    const handleCountChange = () => {
        const checked = document.querySelector('input[name="child_count"]:checked');
        svbCurrentChildCount = checked ? parseInt(checked.value) : 1;

        // Показуємо/ховаємо поля
        if (svbCurrentChildCount > 1) {
            if (ageBlock) ageBlock.style.display = 'none';
        } else {
            if (ageBlock) ageBlock.style.display = 'block';
        }
        if (field2) field2.style.display = (svbCurrentChildCount >= 2) ? 'block' : 'none';
        if (field3) field3.style.display = (svbCurrentChildCount >= 3) ? 'block' : 'none';

        // Оновлюємо селекти аудіо (щоб сховати "Я один" для груп)
        if (typeof svbPopulateSelects === 'function') svbPopulateSelects();

        // ГОЛОВНЕ: Перемальовуємо відео-селектор
        svbRenderUI();
    };

    radios.forEach(r => r.addEventListener('change', handleCountChange));
    
    // Ініціалізація при старті
    handleCountChange();
}

// Допоміжна функція для модалки прев'ю
function svbPreviewModal(url) {
    const modal = document.getElementById('svb-video-modal');
    const v = document.getElementById('svb-modal-video');
    v.src = url;
    modal.classList.add('active');
    v.play().catch(()=>{});
}

// Функция перезапуска всех привязок после перерисовки HTML
function svbReinitAfterRender(savedValues = {}) {
    // 1. Заполняем списки заново (иначе они пустые)
    svbPopulateSelects(); 

    // 2. Восстанавливаем выбранные значения, если они были
    Object.entries(savedValues).forEach(([name, val]) => {
        const el = document.querySelector(`[name="${name}"]`);
        if (el) el.value = val;
    });

    // 3. Заново вешаем обработчики событий
    svbBindAudioPreview();
    // Обработчики фото вешаем только если элементы существуют (шаг 2 может быть скрыт, но inputs в DOM есть)
    svbBindPhotoInputs(); 
    svbEnsureWrappers();
    svbBindNumericControls();
    svbInitIntervalUi();
    svbBindIntervalUi();
    svbBindRealtimeControls();

    // 4. Поиск имен (самое важное для имен)
    svbBindNameSuggestUniversal('name_text', 'svb-name-suggest', 'name_audio', 'svb-name-display-1', 'svb-name-play-1');
    svbBindNameSuggestUniversal('name_text_2', 'svb-name-suggest-2', 'name_audio_2', 'svb-name-display-2', 'svb-name-play-2');
    svbBindNameSuggestUniversal('name_text_3', 'svb-name-suggest-3', 'name_audio_3', 'svb-name-display-3', 'svb-name-play-3');

    // 5. Кнопки прослушивания имен
    document.querySelectorAll('.svb-name-play').forEach(btn => {
        // Удаляем старые, чтобы не двоились (на всякий случай)
        const newBtn = btn.cloneNode(true); 
        btn.parentNode.replaceChild(newBtn, btn);
        
        newBtn.addEventListener('click', () => {
            const url = newBtn.dataset.audioUrl;
            if(!url) return;
            if(svbCurrentSampleAudio) {
                svbCurrentSampleAudio.pause();
                svbCurrentSampleAudio = null;
            }
            const a = new Audio(url);
            a.play();
            svbCurrentSampleAudio = a;
        });
    });
}

document.addEventListener('DOMContentLoaded', () => {
    console.log('🚀 SVB Init started (Final Fix Rebind)');

    // 1. Ініціалізуємо змінну кількості дітей перед усім іншим
    const checked = document.querySelector('input[name="child_count"]:checked');
    svbCurrentChildCount = checked ? parseInt(checked.value) : 1;

    // 2. Навішуємо обробник на перемикач кількості дітей
    // Це головний тригер: при зміні він викликає svbRenderUI, який перебудовує все
    const radios = document.querySelectorAll('input[name="child_count"]');
    radios.forEach(r => r.addEventListener('change', () => {
        const c = document.querySelector('input[name="child_count"]:checked');
        svbCurrentChildCount = c ? parseInt(c.value) : 1;
        
        // Керування видимістю полів (для надійності дублюємо тут, хоча reinit теж це робить)
        const ageBlock = document.getElementById('svb-age-block');
        const field2 = document.querySelector('.field-child-2');
        const field3 = document.querySelector('.field-child-3');

        if (svbCurrentChildCount > 1) {
            if (ageBlock) ageBlock.style.display = 'none';
        } else {
            if (ageBlock) ageBlock.style.display = 'block';
        }
        if (field2) field2.style.display = (svbCurrentChildCount >= 2) ? 'block' : 'none';
        if (field3) field3.style.display = (svbCurrentChildCount >= 3) ? 'block' : 'none';

        // Перемальовуємо інтерфейс (це автоматично викличе svbReinitAfterRender)
        svbRenderUI(); 
    }));

    // 3. Первинний рендер інтерфейсу при завантаженні сторінки
    // Ця функція тепер сама викличе svbReinitAfterRender() і все підключить
    svbRenderUI();

    // 4. Логіка попапу запиту імені (статичний елемент, тому залишається тут)
    const reqSubmit = document.getElementById('popup_req_submit');
    if (reqSubmit) {
        reqSubmit.addEventListener('click', () => {
            const nameVal = document.getElementById('popup_req_name').value.trim();
            const emailVal = document.getElementById('popup_req_email').value.trim();

            if (!nameVal || !emailVal) {
                alert('Будь ласка, заповніть обидва поля (Ім\'я та Email).');
                return;
            }

            const originalBtnText = reqSubmit.textContent;
            reqSubmit.disabled = true;
            reqSubmit.textContent = 'Відправка...';

            const fd = new FormData();
            fd.append('action', 'svb_request_name');
            fd.append('_svb_nonce', SVB_AJAX.nonce);
            fd.append('name_req', nameVal);
            fd.append('email_req', emailVal);

            fetch(SVB_AJAX.url, { method: 'POST', body: fd })
                .then(r => r.json())
                .then(res => {
                    if (res.success) {
                        alert(res.data);
                        document.getElementById('svb-name-popup').classList.remove('active');
                        document.getElementById('popup_req_name').value = '';
                        document.getElementById('popup_req_email').value = '';
                    } else {
                        alert('Помилка: ' + (res.data || 'Unknown error'));
                    }
                }) 
                .catch(err => { 
                    alert('Помилка з\'єднання'); 
                    console.error(err);
                })
                .finally(() => {
                    reqSubmit.disabled = false;
                    reqSubmit.textContent = originalBtnText;
                });
        });
    }

    // 5. Страховка: примусово оновлюємо прев'ю через мить, щоб переконатися, що DOM готовий
    setTimeout(() => {
        // Вибираємо правильне відео
        if (typeof SVB_SELECTED_VIDEO_ID !== 'undefined' && SVB_SELECTED_VIDEO_ID) {
            svbSelectVideoTemplate(SVB_SELECTED_VIDEO_ID, false);
        } else {
            svbSelectVideoTemplate('video1', false);
        }
        
        // Імітуємо подію зміни, щоб коректно відпрацювала видимість полів
        const event = new Event('change');
        if(checked) checked.dispatchEvent(event);
        
        // Оновлюємо трансформації прев'ю картинок (щоб вони стали на місця)
        ['child1', 'child2', 'parent1', 'parent2'].forEach(key => {
            svbUpdatePreviewTransform(key);
        });
    }, 200);
});
// Авто-скрол до активного відео на мобільному
const activeCard = document.querySelector('.svb-video-option.active');
if(activeCard && window.innerWidth < 768) {
    activeCard.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'center' });
}

const SVB_AUDIO = <?php echo wp_json_encode($audio_catalog, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); ?>;

const SVB_VIDEO_TEMPLATES = <?php echo wp_json_encode($video_templates, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); ?>;

const SVB_TEMPLATE_TIMINGS = <?php echo wp_json_encode($template_timings, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); ?>;

/* === НАЛАШТУВАННЯ ПРОПОРЦІЙ (ASPECT RATIO) === */
const SVB_ASPECT_RATIOS = {
    // ЗАМЕНИТЕ NaN на конкретные пропорции, чтобы запретить изменение рамки!
    
    // 1. Фабрика Іграшок
    video1: { child1: 1/1, child2: 1/1, parent1: 3/4, parent2: 3/4 },
    
    // 2. Чарівна Кухня
    video2: { child1: 16/9, child2: 16/10, parent1: 16/9, parent2: 16/9 },
    
    // 3. Казкові Санчата
    video3: { child1: 16/9,  child2: 16/9,   parent1: 3/4, parent2: 3/4 },
    
    // 4. Містечко Чудес
    video4: { child1: 3/4, child2: 3/4, parent1: 3/4, parent2: 3/4 },

    // Групові відео
    video5: { child1: 1/1, child2: 1/1, parent1: 3/4, parent2: 3/4 }, 
    video6: { child1: 16/9, child2: 16/10, parent1: 16/9, parent2: 16/9 },
    video7: { child1: 16/9, child2: 16/10, parent1: 3/4, parent2: 3/4 },
    video8: { child1: 3/4, child2: 3/4, parent1: 3/4, parent2: 3/4 },
};

let SVB_SCENES_DATA = {}; 
let SVB_CURRENT_SCENE_INDEX = {
    child1: 0, child2: 0, parent1: 0, parent2: 0
};
function svbSwitchScene(key, index) {
    const oldIdx = SVB_CURRENT_SCENE_INDEX[key];
    svbSaveInputsToScene(key, oldIdx);

    SVB_CURRENT_SCENE_INDEX[key] = index;

    svbLoadInputsFromScene(key, index);
    
    if (SVB_SCENES_DATA[key] && SVB_SCENES_DATA[key][index]) {
        const timeStr = SVB_SCENES_DATA[key][index].start; 
        const vid = document.getElementById('svb-video-'+key);
        
        if (vid && timeStr) {
            const seconds = svbTSToSeconds(timeStr); 
            if (seconds !== null) {
                vid.currentTime = seconds; 
            }
        }
    }
    svbUpdatePreviewTransform(key);
}

function svbSaveInputsToScene(key, index) {
    if (!SVB_SCENES_DATA[key] || !SVB_SCENES_DATA[key][index]) return;
    const data = SVB_SCENES_DATA[key][index];
    
    // Добавили pleft и pright в список
    ['x','y','scale','scale_x','scale_y','skew','skew_y','angle','radius','opacity','glow','pleft','pright'].forEach(param => {
        const inp = document.querySelector(`input[name="${key}_${param}"]`);
        if(inp) data[param] = parseFloat(inp.value) || 0;
    });
}

function svbLoadInputsFromScene(key, index) {
    if (!SVB_SCENES_DATA[key] || !SVB_SCENES_DATA[key][index]) return;
    const data = SVB_SCENES_DATA[key][index];

    // Добавили pleft и pright
    ['x','y','scale','scale_x','scale_y','skew','skew_y','angle','radius','opacity','glow','pleft','pright'].forEach(param => {
        const val = data[param] !== undefined ? data[param] : 0;
        
        const numInp = document.getElementById(`val-${key}-${param.replace('_','-')}`);
        if(numInp) numInp.value = val;
        
        const rangeInp = document.querySelector(`input[name="${key}_${param}"]`);
        if(rangeInp) rangeInp.value = val;
    });
    
    svbUpdatePreviewTransform(key);
}
function svbUpdateTimingsForVideo(videoId) {
    const tpl = SVB_VIDEO_TEMPLATES[videoId];
    if (!tpl || !tpl.scenes) return;

    console.log('🎬 Loading scenes for:', videoId);

    // Очищаем, используем let переменную
    SVB_OVERLAY_WINDOWS = {};
    SVB_SCENES_DATA = {};

    ['child1','child2','parent1','parent2'].forEach(key => {
        const scenes = tpl.scenes[key] || [];
        
        // 1. Обновляем тайминги (для плеера)
        SVB_OVERLAY_WINDOWS[key] = scenes.map(s => [
            svbTSToSeconds(s.start), 
            svbTSToSeconds(s.end)
        ]);

        // 2. Инициализируем геометрию (X, Y, Scale...) из конфига
        SVB_SCENES_DATA[key] = scenes.map(s => ({
            // Если в конфиге есть значение, берем его, иначе дефолт 0 или 100
            x:       s.x !== undefined ? parseFloat(s.x) : 0,
            y:       s.y !== undefined ? parseFloat(s.y) : 0,
            scale:   s.scale !== undefined ? parseFloat(s.scale) : 100,
            scale_x: s.scale_x !== undefined ? parseFloat(s.scale_x) : 100,
            scale_y: s.scale_y !== undefined ? parseFloat(s.scale_y) : 100,
            skew:    s.skew !== undefined ? parseFloat(s.skew) : 0,
            skew_y:  s.skew_y !== undefined ? parseFloat(s.skew_y) : 0,
            angle:   s.angle !== undefined ? parseFloat(s.angle) : 0,
            radius:  s.radius !== undefined ? parseFloat(s.radius) : 0,
            opacity: s.opacity !== undefined ? parseFloat(s.opacity) : 100,
            glow:    s.glow !== undefined ? parseFloat(s.glow) : 0,
            
            // Сохраняем оригинальные строки времени для JSON
            start: svbSecondsToTS(svbTSToSeconds(s.start)),
            end:   svbSecondsToTS(svbTSToSeconds(s.end)),
            label: s.label
        }));

        // 3. Обновляем UI селектора сцен (Сцена 1, Сцена 2)
        const sel = document.querySelector(`.svb-scene-select[data-key="${key}"]`);
        if (sel) {
            sel.innerHTML = scenes.map((s, i) => 
                `<option value="${i}">${s.label || ('Сцена '+(i+1))} (${s.start})</option>`
            ).join('');
            sel.value = 0;
            SVB_CURRENT_SCENE_INDEX[key] = 0;
            
            const newSel = sel.cloneNode(true);
            sel.parentNode.replaceChild(newSel, sel);
            newSel.addEventListener('change', (e) => {
                svbSwitchScene(key, parseInt(e.target.value));
            });
        }
        
        // Бинд кнопки "глаз"
        const jumpBtn = document.querySelector(`.svb-scene-jump[data-key="${key}"]`);
        if(jumpBtn) {
             const newJump = jumpBtn.cloneNode(true);
             jumpBtn.parentNode.replaceChild(newJump, jumpBtn);
             newJump.addEventListener('click', () => {
                 const idx = SVB_CURRENT_SCENE_INDEX[key];
                 const time = SVB_SCENES_DATA[key][idx].start;
                 const vid = document.getElementById('svb-video-'+key);
                 // start хранится как строка, конвертируем для плеера
                 if(vid && time) vid.currentTime = svbTSToSeconds(time);
             });
        }
    });

    // === ГЛАВНОЕ ИСПРАВЛЕНИЕ: ПЕРЕРИСОВАТЬ ИНТЕРВАЛЫ ===
    // Теперь, когда SVB_OVERLAY_WINDOWS обновлен, обновляем HTML инпуты
    svbInitIntervalUi();
    svbBindIntervalUi();

    // Применяем значения первой сцены к инпутам (X, Y...)
    Object.keys(SVB_CURRENT_SCENE_INDEX).forEach(key => svbLoadInputsFromScene(key, 0));
}

// Функція закриття модального вікна
function svbCloseModal(e, force = false) {
    const modal = document.getElementById('svb-video-modal');
    const content = document.querySelector('.svb-modal-content');
    
    // Закриваємо, якщо клік по фону (modal) або примусово (хрестик)
    // e.target === modal гарантує, що ми не закриємо, якщо клікнули по самому відео
    if (force || e.target === modal) {
        const v = document.getElementById('svb-modal-video');
        v.pause(); // Зупиняємо відео
        modal.classList.remove('active');
    }
}


// Текущее выбранное видео
let SVB_SELECTED_VIDEO_ID = <?php echo wp_json_encode($selected_video_id); ?>;

const SVB_AJAX  = {
    url: <?php echo wp_json_encode($ajax_url); ?>,
    nonce: <?php echo wp_json_encode($nonce); ?>,
    video_template: <?php echo wp_json_encode($template_url); ?>
};

const SVB_PROCESSED_PHOTO_SIZE = 709; 
const SVB_PREVIEW_CAPS = <?php echo wp_json_encode($preview_caps, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); ?>;

// ИСПРАВЛЕНИЕ: Используем let, так как эта переменная перезаписывается при смене шаблона
let SVB_OVERLAY_WINDOWS = <?php echo wp_json_encode($OVER, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); ?>;

const SVB_OVERLAY_WINDOWS_DEFAULTS = JSON.parse(JSON.stringify(SVB_OVERLAY_WINDOWS));
const SVB_INTERVAL_UI_KEYS = ['child1', 'child2', 'parents'];

// === ФУНКЦІЇ ЧАСУ (3 ЗНАКИ МІЛІСЕКУНД) ===
function svbSecondsToTS(sec) {
    sec = Math.max(0, Number(sec) || 0);
    const totalMs = Math.round(sec * 1000);
    const ms = totalMs % 1000; // 3 знаки
    const totalSec = (totalMs - ms) / 1000;
    const mm = Math.floor(totalSec / 60);
    const ss = totalSec % 60;
    // Вивід: 00:54:677
    return String(mm).padStart(2, '0') + ':' +
           String(ss).padStart(2, '0') + ':' +
           String(ms).padStart(3, '0');
}

function svbTSToSeconds(str) {
    if (!str) return null;
    const s = String(str).trim();
    
    // Підтримка MM:SS.mmm (крапка - стандарт)
    let m = s.match(/^(\d{1,2}):(\d{2})\.(\d+)$/);
    if (m) {
        // ділимо на 10 в степені довжини (щоб .5 = 500ms, .05 = 50ms)
        let msPart = parseFloat("0." + m[3]);
        return parseInt(m[1])*60 + parseInt(m[2]) + msPart;
    }

    // Підтримка MM:SS:mmm (3 знаки після двокрапки)
    m = s.match(/^(\d{1,2}):(\d{2}):(\d{3})$/);
    if (m) {
        return parseInt(m[1])*60 + parseInt(m[2]) + parseInt(m[3])/1000;
    }
    
    // Старий формат (2 знаки - соті)
    m = s.match(/^(\d{1,2}):(\d{2}):(\d{2})$/);
    if (m) {
        return parseInt(m[1])*60 + parseInt(m[2]) + parseInt(m[3])/100;
    }
    
    // Просто секунди
    if (!isNaN(parseFloat(s)) && isFinite(s)) {
        return parseFloat(s);
    }

    return 0;
}

function svbCreateIntervalRow(startStr, endStr) {
    const row = document.createElement('div');
    row.className = 'svb-int-row';
    // Додаємо приклад з мілісекундами у placeholder
    row.innerHTML = `
        <input type="text" class="svb-input svb-int-start" placeholder="00:54:20.500">
        <span>–</span>
        <input type="text" class="svb-input svb-int-end" placeholder="00:58:25.000">
        <button type="button" class="svb-btn ghost svb-int-del">✕</button>
    `;
    if (typeof startStr === 'string') {
        row.querySelector('.svb-int-start').value = startStr;
    }
    if (typeof endStr === 'string') {
        row.querySelector('.svb-int-end').value = endStr;
    }
    return row;
}

function svbInitIntervalUi() {
    SVB_INTERVAL_UI_KEYS.forEach(uiKey => {
        const box = document.querySelector(`.svb-intervals[data-key="${uiKey}"]`);
        if (!box) return;
        const rowsWrap = box.querySelector('.svb-intervals-rows');
        if (!rowsWrap) return;
        rowsWrap.innerHTML = '';

        // UI ключ 'parents' соответствует данным в 'parent1' (или 'parents' если мы его добавили выше)
        let srcKey = uiKey;
        if (uiKey === 'parents') srcKey = 'parent1'; 

        const arr = (SVB_OVERLAY_WINDOWS && SVB_OVERLAY_WINDOWS[srcKey]) || [];

        if (!arr.length) {
            rowsWrap.appendChild(svbCreateIntervalRow());
        } else {
            arr.forEach(pair => {
                rowsWrap.appendChild(
                    svbCreateIntervalRow(
                        svbSecondsToTS(pair[0] || 0),
                        svbSecondsToTS(pair[1] || 0)
                    )
                );
            });
        }
    });
}

function svbRebuildWindowsFromUi(uiKey) {
    const box = document.querySelector(`.svb-intervals[data-key="${uiKey}"]`);
    if (!box) return;
    const rows = box.querySelectorAll('.svb-int-row');
    const parsed = [];

    rows.forEach(row => {
        const startStr = row.querySelector('.svb-int-start').value;
        const endStr   = row.querySelector('.svb-int-end').value;
        const s = svbTSToSeconds(startStr);
        const e = svbTSToSeconds(endStr);
        if (s !== null && e !== null && e > s) {
            parsed.push([s, e]);
        }
    });

    if (!parsed.length) {
        if (uiKey === 'parents') {
            SVB_OVERLAY_WINDOWS.parent1 = JSON.parse(JSON.stringify(SVB_OVERLAY_WINDOWS_DEFAULTS.parent1 || []));
            SVB_OVERLAY_WINDOWS.parent2 = JSON.parse(JSON.stringify(SVB_OVERLAY_WINDOWS_DEFAULTS.parent2 || []));
        } else if (SVB_OVERLAY_WINDOWS_DEFAULTS[uiKey]) {
            SVB_OVERLAY_WINDOWS[uiKey] = JSON.parse(JSON.stringify(SVB_OVERLAY_WINDOWS_DEFAULTS[uiKey]));
        }
        return;
    }

    if (uiKey === 'parents') {
        SVB_OVERLAY_WINDOWS.parent1 = parsed;
        SVB_OVERLAY_WINDOWS.parent2 = parsed.map(p => [p[0], p[1]]);
    } else {
        SVB_OVERLAY_WINDOWS[uiKey] = parsed;
    }
}

function svbBindIntervalUi() {
    SVB_INTERVAL_UI_KEYS.forEach(uiKey => {
        const box = document.querySelector(`.svb-intervals[data-key="${uiKey}"]`);
        if (!box || box.__svb_bound) return;
        const rowsWrap = box.querySelector('.svb-intervals-rows');
        const addBtn   = box.querySelector(`.svb-int-add[data-key="${uiKey}"]`);
        const resetBtn = box.querySelector(`.svb-int-reset[data-key="${uiKey}"]`);

        if (addBtn) {
            addBtn.addEventListener('click', () => {
                if (!rowsWrap) return;
                rowsWrap.appendChild(svbCreateIntervalRow());
            });
        }
        if (resetBtn) {
            resetBtn.addEventListener('click', () => {
                if (uiKey === 'parents') {
                    SVB_OVERLAY_WINDOWS.parent1 = JSON.parse(JSON.stringify(SVB_OVERLAY_WINDOWS_DEFAULTS.parent1 || []));
                    SVB_OVERLAY_WINDOWS.parent2 = JSON.parse(JSON.stringify(SVB_OVERLAY_WINDOWS_DEFAULTS.parent2 || []));
                } else if (SVB_OVERLAY_WINDOWS_DEFAULTS[uiKey]) {
                    SVB_OVERLAY_WINDOWS[uiKey] = JSON.parse(JSON.stringify(SVB_OVERLAY_WINDOWS_DEFAULTS[uiKey]));
                }
                svbInitIntervalUi();
            });
        }

        box.addEventListener('input', (e) => {
            if (e.target.classList.contains('svb-int-start') ||
                e.target.classList.contains('svb-int-end')) {
                svbRebuildWindowsFromUi(uiKey);
            }
        });

        if (rowsWrap) {
            rowsWrap.addEventListener('click', (e) => {
                if (e.target.classList.contains('svb-int-del')) {
                    const row = e.target.closest('.svb-int-row');
                    if (row) {
                        row.remove();
                        svbRebuildWindowsFromUi(uiKey);
                    }
                }
            });
        }

        box.__svb_bound = true;
    });
}

function svbSerializeSegmentsToField() {
    const segments = {
        child1: [],
        child2: [],
        parents: [],
    };

    const map = {
        child1: 'child1',
        child2: 'child2',
        parents: 'parent1', // общий для обоих родителей
    };

    Object.keys(map).forEach(uiKey => {
        const srcKey = map[uiKey];
        const arr = (SVB_OVERLAY_WINDOWS && SVB_OVERLAY_WINDOWS[srcKey]) || [];
        segments[uiKey] = arr.map(pair => [
            svbSecondsToTS(pair[0] || 0),
            svbSecondsToTS(pair[1] || 0)
        ]);
    });

    const field = document.getElementById('svb_segments');
    if (field) {
        field.value = JSON.stringify(segments);
    }
}



const $  = (sel,root=document) => root.querySelector(sel);
const $$ = (sel,root=document) => Array.from(root.querySelectorAll(sel));

let svbCurrentSampleAudio = null;
function svbFormatTime(seconds) {
    const totalMs = Math.round(seconds * 1000);
    const ms = totalMs % 1000;
    const totalSec = (totalMs - ms) / 1000;
    const min = Math.floor(totalSec / 60);
    const sec = totalSec % 60;
    return (
        `${min.toString().padStart(2, '0')}:` +
        `${sec.toString().padStart(2, '0')}.` +
        `${ms.toString().padStart(3, '0')}`
    );
}



function svbSetStep(n){
  $$('.svb-step').forEach(s=>s.classList.remove('active'));
  $(`.svb-step[data-step="${n}"]`).classList.add('active');
  for(let i=1;i<=3;i++){
    const dot = $(`#svb-dot-${i}`);
    dot.classList.toggle('active', i===n);
    dot.classList.toggle('muted', i!==n);
  }
  const titles = {1:'Крок 1 — Дані дитини', 2:'Крок 2 — Фото', 3:'Крок 3 — Підтвердження та отримання'};
  $('#svb-title').textContent = titles[n] || '';
}
// === ФУНКЦИЯ ФИЛЬТРАЦИИ АУДИО (ФИНАЛЬНАЯ) ===
function svbPopulateSelects() {
    // 1. Получаем выбранное количество детей
    const radios = document.querySelector('input[name="child_count"]:checked');
    const count = radios ? parseInt(radios.value) : 1;
    
    // Режим группы: если выбрано 2 или 3 ребенка
    const isGroup = count > 1; 

    // Отладка в консоль
    console.log(`🎬 SVB: Перерисовка списков. Детей: ${count}, Режим группы: ${isGroup}`);

    // 2. Ищем все выпадающие списки (кроме имен)
    const selects = document.querySelectorAll('select[data-cat]');
    
    selects.forEach(sel => {
        const cat = sel.getAttribute('data-cat');
        if (cat === 'name') return; // Имена пропускаем
        
        // Берем данные из глобальной переменной
        const allItems = (typeof SVB_AUDIO !== 'undefined' && SVB_AUDIO[cat]) ? SVB_AUDIO[cat] : [];
        
        // 3. ФИЛЬТРАЦИЯ
        const filteredItems = allItems.filter(item => {
            // Если type не указан, считаем его 'single'
            const itemType = item.type ? item.type : 'single';

            if (isGroup) {
                // Если группа (2-3) -> показываем ТОЛЬКО type="group"
                return itemType === 'group';
            } else {
                // Если 1 ребенок -> показываем ТОЛЬКО type="single" (или пустой)
                return itemType !== 'group';
            }
        });

        // 4. Отрисовка
        const currentVal = sel.value; // Запоминаем выбор
        sel.innerHTML = ''; // Очищаем

        if (filteredItems.length > 0) {
            // Пустая опция в начале
            const defaultOpt = document.createElement('option');
            defaultOpt.value = "";
            defaultOpt.textContent = "Оберіть варіант...";
            sel.appendChild(defaultOpt);

            filteredItems.forEach(i => {
                const opt = document.createElement('option');
                opt.value = i.file;
                opt.textContent = i.label;
                if (i.file === currentVal) opt.selected = true;
                sel.appendChild(opt);
            });
        } else {
            const opt = document.createElement('option');
            opt.textContent = "— немає варіантів —";
            sel.appendChild(opt);
        }

        // Если старый выбор исчез из-за фильтра, сбрасываем
        if (sel.selectedIndex === -1) sel.selectedIndex = 0;
    });
}

function svbBindAudioPreview(){
  $$('.svb-play').forEach(btn=>{
    btn.addEventListener('click', ()=>{
      const cat = btn.getAttribute('data-play');
      const sel = document.querySelector(`select[name="${cat}_audio"]`);
      if(!sel) return;
      
      const file = sel.value;
      
      // ВИПРАВЛЕННЯ: Тут теж міняємо на getAllNames()
      let items = (cat === 'name') ? getAllNames() : (SVB_AUDIO[cat]||[]);
      
      const item = items.find(i=>i.file===file);
      if(item){ 
        if(svbCurrentSampleAudio) {
            svbCurrentSampleAudio.pause();
            svbCurrentSampleAudio = null;
        }
        const a = new Audio(item.url); 
        a.play();
        svbCurrentSampleAudio = a; 
      }
    });
  });
}

let svbCropper = null;
let svbCurrentInput = null;
let svbCurrentKey = null;

function svbCloseCrop() {
    const modal = document.getElementById('svb-crop-modal');
    modal.style.display = 'none';
    modal.classList.remove('active');
    if (svbCropper) {
        svbCropper.destroy();
        svbCropper = null;
    }
    // Очистити input, якщо скасували, щоб можна було вибрати той самий файл знову
    if (svbCurrentInput && !svbCurrentInput.dataset.cropped) {
        svbCurrentInput.value = ''; 
    }
}
function svbBindPhotoInputs() {
    console.log('🚀 svbBindPhotoInputs init');

    // 1. Биндим числовые инпуты (X, Y, Scale...)
    ['child1', 'child2', 'parent1', 'parent2'].forEach(key => {
        if (typeof svbMarkTouched === 'function') svbMarkTouched(key);

        ['x', 'y', 'scale','scale_x', 'scale_y', 'skew', 'skew_y', 'angle', 'radius', 'opacity', 'glow', 'pleft', 'pright'].forEach(k => {
            const ctrl = document.querySelector(`input[name="${key}_${k}"]`);
            if (ctrl) {
                ctrl.addEventListener('input', (e) => {
                    const valId = e.target.dataset.valId;
                    if (valId) {
                        const valEl = document.getElementById(valId);
                        if (valEl) {
                            if (valEl.tagName === 'INPUT') valEl.value = e.target.value;
                            else valEl.textContent = e.target.value;
                        }
                    }
                    const currentIdx = (typeof SVB_CURRENT_SCENE_INDEX !== 'undefined') ? SVB_CURRENT_SCENE_INDEX[key] : 0;
                    if (typeof SVB_SCENES_DATA !== 'undefined' && SVB_SCENES_DATA[key] && SVB_SCENES_DATA[key][currentIdx]) {
                        const val = parseFloat(e.target.value);
                        SVB_SCENES_DATA[key][currentIdx][k] = isNaN(val) ? 0 : val;
                    }
                    if (typeof svbUpdatePreviewTransform === 'function') svbUpdatePreviewTransform(key);
                    if (typeof svbDebugPrint === 'function') svbDebugPrint(key);
                });
            }
        });
    });

    // 2. Биндим загрузку файлов и Кроппер
    ['child1', 'child2', 'parent1', 'parent2'].forEach(key => {
        const input = document.querySelector(`input[name="photo_${key}"]`);
        if (!input) return;

        // Удаляем старые слушатели, чтобы не дублировать
        const newInput = input.cloneNode(true);
        input.parentNode.replaceChild(newInput, input);

        newInput.addEventListener('change', function(e) {
            const files = e.target.files;
            if (!files || !files.length) return;
            if (newInput.dataset.processing === 'true') return;

            const file = files[0];
            svbCurrentInput = newInput;
            svbCurrentKey = key;

            const reader = new FileReader();
            reader.onload = function(evt) {
                const modal = document.getElementById('svb-crop-modal');
                const image = document.getElementById('svb-crop-target');
                const headerTitle = modal.querySelector('h3');

                if (headerTitle) {
                    headerTitle.textContent = key.includes('child') ? "Фото дитини (обрізка):" : "Фото дорослого (обрізка):";
                }

                image.src = evt.target.result;
                modal.style.display = 'flex';
                modal.classList.add('active');

                if (svbCropper) {
                    svbCropper.destroy();
                    svbCropper = null;
                }

                // === ЛОГИКА ПРОПОРЦИЙ (Fixed) ===
                
                // 1. Получаем ID видео из hidden input, так надежнее
                let currentVidId = 'video1';
                const hiddenInput = document.getElementById('selected_video_id');
                if (hiddenInput && hiddenInput.value) {
                    currentVidId = hiddenInput.value;
                } else if (typeof SVB_SELECTED_VIDEO_ID !== 'undefined') {
                    currentVidId = SVB_SELECTED_VIDEO_ID;
                }

                // 2. Ищем пропорции
                let ratio = NaN; // По умолчанию Free
                let debugSource = "Default (NaN)";

                if (typeof SVB_ASPECT_RATIOS !== 'undefined') {
                    let map = SVB_ASPECT_RATIOS[currentVidId];
                    // Если для текущего видео нет настроек, пробуем video1
                    if (!map) {
                        map = SVB_ASPECT_RATIOS['video1'];
                        debugSource = "Fallback to Video1";
                    } else {
                        debugSource = "Config found for " + currentVidId;
                    }

                    if (map && map[key] !== undefined) {
                        ratio = map[key];
                        debugSource += ` -> Key ${key} found: ${ratio}`;
                    } else {
                        debugSource += ` -> Key ${key} NOT found`;
                    }
                }

                // Лог в консоль (яркий)
                console.log(`%c ✂️ CROPPER INIT: ${key} | Video: ${currentVidId} | Ratio: ${ratio} | Msg: ${debugSource}`, 'background: #222; color: #bada55; font-size: 12px; padding: 4px;');

                svbCropper = new Cropper(image, {
                    viewMode: 1,
                    dragMode: 'move',
                    autoCropArea: 0.9,
                    aspectRatio: ratio, // <--- ЗДЕСЬ ПРИМЕНЯЕТСЯ РАСЧЕТ
                    restore: false,
                    guides: true,
                    center: true,
                    highlight: false,
                    cropBoxMovable: true,
                    cropBoxResizable: true,
                    toggleDragModeOnDblclick: false
                });
            };
            reader.readAsDataURL(file);
            newInput.value = ''; 
        });
    });

    // 3. Кнопка SAVE (обновленная)
    const cropModalSaveBtn = document.getElementById('svb-crop-save');
    if (cropModalSaveBtn) {
        const newBtn = cropModalSaveBtn.cloneNode(true);
        cropModalSaveBtn.parentNode.replaceChild(newBtn, cropModalSaveBtn);

        newBtn.addEventListener('click', () => {
            if (!svbCropper || !svbCurrentInput || !svbCurrentKey) return;

            // Генерируем с максимальным качеством, но БЕЗ width/height чтобы сохранить ratio
            const canvas = svbCropper.getCroppedCanvas({
                maxWidth: 2048,
                maxHeight: 2048,
                imageSmoothingEnabled: true,
                imageSmoothingQuality: 'high',
            });

            if (!canvas) return;

            canvas.toBlob((blob) => {
                if (!blob) return;
                const newFile = new File([blob], "cropped_image.webp", { type: "image/webp" });
                const dataTransfer = new DataTransfer();
                dataTransfer.items.add(newFile);

                svbCurrentInput.files = dataTransfer.files;
                svbCurrentInput.dataset.processing = 'true';
                svbCurrentInput.dataset.cropped = 'true';

                const imgEl = document.getElementById('img-' + svbCurrentKey);
                if (imgEl) {
                    const url = URL.createObjectURL(blob);
                    if (imgEl.src) URL.revokeObjectURL(imgEl.src);
                    imgEl.onload = () => {
                        if (typeof svbUpdatePreviewTransform === 'function') svbUpdatePreviewTransform(svbCurrentKey);
                        if (typeof svbDebugPrint === 'function') svbDebugPrint(svbCurrentKey);
                    };
                    imgEl.src = url;
                }

                if (typeof svbCloseCrop === 'function') svbCloseCrop();
                else {
                    document.getElementById('svb-crop-modal').style.display = 'none';
                    document.getElementById('svb-crop-modal').classList.remove('active');
                    if (svbCropper) svbCropper.destroy();
                }

                setTimeout(() => {
                    if (svbCurrentInput) svbCurrentInput.dataset.processing = 'false';
                }, 100);

            }, 'image/webp', 0.9);
        });
    }
}
  // --- НОВА ЛОГІКА INPUT FILE ---
  ['child1', 'child2', 'parent1', 'parent2'].forEach(key => {
    const input = document.querySelector(`input[name="photo_${key}"]`);
    if(!input) return;

    input.addEventListener('change', function(e) {
        const files = e.target.files;
        if (!files || !files.length) return;

        // Якщо це вже оброблений файл (прапорець), нічого не робимо
        if (input.dataset.processing === 'true') {
            return;
        }

        const file = files[0];
        svbCurrentInput = input;
        svbCurrentKey = key;

        // 1. Читаємо файл
        const reader = new FileReader();
        reader.onload = function(evt) {
            // 2. Відкриваємо модалку
            const modal = document.getElementById('svb-crop-modal');
            const image = document.getElementById('svb-crop-target');
            const headerTitle = modal.querySelector('h3');
            
            // Змінюємо заголовок залежно від ключа (як ви просили)
            if (key.includes('child')) {
                headerTitle.textContent = "Add a photo of the child:";
            } else {
                headerTitle.textContent = "Add a photo of the parent:";
            }

            image.src = evt.target.result;
            modal.style.display = 'flex'; // Flex для центрування
            modal.classList.add('active');

            // 3. Ініціалізуємо Cropper
            if (svbCropper) svbCropper.destroy();
            
            // Налаштування Aspect Ratio
            // Для батьків (темне фото 3) та дітей (темне фото 2) ви хотіли певні розміри.
            // Зазвичай це портретний формат. 
            // Але оскільки ваш PHP код ріже під 709x709, 
            // безпечніше дати користувачу квадрат 1:1, або Free.
            // Я ставлю NaN (Free), щоб користувач сам вирішував, 
            // але можна розкоментувати aspectRatio: 9/16.
            
            svbCropper = new Cropper(image, {
                viewMode: 1,
                dragMode: 'move',
                autoCropArea: 0.9,
                restore: false,
                guides: true,
                center: true,
                highlight: false,
                cropBoxMovable: true,
cropBoxResizable: true,
                toggleDragModeOnDblclick: false,
                aspectRatio: ratio, 
            });
        };
        reader.readAsDataURL(file);
        
        // Скидаємо value, щоб change спрацював, навіть якщо вибрали той самий файл (але ми його підмінимо пізніше)
        input.value = ''; 
    });
  });

  // Логіка кнопки SAVE у модалці
  // ВИПРАВЛЕННЯ: Перейменували saveBtn -> cropModalSaveBtn, щоб уникнути конфлікту
  const cropModalSaveBtn = document.getElementById('svb-crop-save');
  
  if (cropModalSaveBtn) {
      // Видаляємо старі слухачі (щоб не дублювалися при перерендеренгу)
      const newBtn = cropModalSaveBtn.cloneNode(true);
      cropModalSaveBtn.parentNode.replaceChild(newBtn, cropModalSaveBtn);
      
      newBtn.addEventListener('click', () => {
          if (!svbCropper || !svbCurrentInput || !svbCurrentKey) return;

          // Отримуємо Canvas з обрізкою
          const canvas = svbCropper.getCroppedCanvas({
              maxWidth: 1920, 
              maxHeight: 1920,
              imageSmoothingEnabled: true,
              imageSmoothingQuality: 'high',
          });

          // Конвертуємо у WebP
          canvas.toBlob((blob) => {
              if (!blob) return;

              // 1. Створюємо новий файл
              const newFile = new File([blob], "cropped_image.webp", { type: "image/webp" });

              // 2. Підміняємо файл у input (через DataTransfer)
              const dataTransfer = new DataTransfer();
              dataTransfer.items.add(newFile);
              
              svbCurrentInput.files = dataTransfer.files;
              svbCurrentInput.dataset.processing = 'true'; // Прапорець, щоб не викликати loop
              svbCurrentInput.dataset.cropped = 'true';

              // 3. Оновлюємо прев'ю на сторінці
              const imgEl = document.getElementById('img-' + svbCurrentKey);
              if (imgEl) {
                  const url = URL.createObjectURL(blob);
                  if (imgEl.src) URL.revokeObjectURL(imgEl.src);
                  imgEl.onload = () => { 
                      if (typeof svbUpdatePreviewTransform === 'function') svbUpdatePreviewTransform(svbCurrentKey); 
                      if (typeof svbDebugPrint === 'function') svbDebugPrint(svbCurrentKey); 
                  };
                  imgEl.src = url;
              }

              // Закриваємо модалку
              if (typeof svbCloseCrop === 'function') svbCloseCrop();
              else {
                  document.getElementById('svb-crop-modal').style.display = 'none';
                  document.getElementById('svb-crop-modal').classList.remove('active');
                  if(svbCropper) svbCropper.destroy();
              }
              
              // Знімаємо прапорець через мить
              setTimeout(() => { 
                  if(svbCurrentInput) svbCurrentInput.dataset.processing = 'false'; 
              }, 100);

          }, 'image/webp', 0.9); // Якість WebP 0.9
      });
  }
function svbBindNumericControls() {
    $$('.svb-val-input').forEach(inp => {
        const rangeName = inp.dataset.rangeName;
        if (!rangeName) return;
        const range = document.querySelector(`input[name="${rangeName}"]`);
        if (!range) return;

        inp.addEventListener('input', () => {
            let v = parseFloat(inp.value);
            if (!Number.isFinite(v)) return;

            const min = parseFloat(range.min);
            const max = parseFloat(range.max);
            if (Number.isFinite(min)) v = Math.max(min, v);
            if (Number.isFinite(max)) v = Math.min(max, v);

            const step = parseFloat(range.step) || 1;
            v = Math.round(v / step) * step;

            range.value = v;
            range.dispatchEvent(new Event('input', { bubbles: true }));
        });
    });
}


const SVB_MODEL_W = 854;   
const SVB_MODEL_H = 480;   
const PROCESSED_SQUARE = (typeof SVB_PROCESSED_PHOTO_SIZE === 'number' && SVB_PROCESSED_PHOTO_SIZE > 0)
  ? SVB_PROCESSED_PHOTO_SIZE
  : 709;

const toEvenUp = v => { const n = Math.ceil(v); return (n & 1) ? (n + 1) : n; };
const clamp01 = v => Math.max(0, Math.min(1, v));

/* === ЗАМЕНИТЬ ПОЛНОСТЬЮ ФУНКЦИЮ svbCalculateGeomFromParams === */
function svbCalculateGeomFromParams(params, imgRatio = 1) {
    const p = params || {};
    // Если пропорция не передана, считаем 1.0
    const R = (typeof imgRatio === 'number' && imgRatio > 0) ? imgRatio : 1.0;

    const baseScalePct = p.scale !== undefined ? p.scale : 100;
    const strXPct      = p.scale_x !== undefined ? p.scale_x : 100;
    const strYPct      = p.scale_y !== undefined ? p.scale_y : 100;

    const skewXdeg  = p.skew    !== undefined ? p.skew : 0;
    const skewYdeg  = p.skew_y  !== undefined ? p.skew_y : 0;
    const angleDeg  = p.angle   !== undefined ? p.angle : 0;
    const radiusPx  = p.radius  !== undefined ? p.radius : 0;
    const xRaw      = p.x       !== undefined ? p.x : 0;
    const yRaw      = p.y       !== undefined ? p.y : 0;
    const glowVal   = p.glow    !== undefined ? p.glow : 0;

    // === FIX 1: Компенсация Padding от Glow ===
    // PHP добавляет отступы внутрь картинки. Чтобы визуальный размер фото остался прежним,
    // мы должны увеличить общий контейнер на коэффициент потери площади.
    let scaleMultiplier = 1.0;
    if (glowVal > 0) {
        // Формула из PHP: sigma = (glow/100)*30; padding = ceil(sigma*3 + 2)
        const sigma = (glowVal / 100.0) * 30.0;
        const paddingPx = Math.ceil((sigma * 3) + 2);
        
        // Базовый размер фото в обработчике PHP (стандарт Imagick ~1152px)
        const refW = 1152; 
        const contentW = refW - (paddingPx * 2);
        
        // Вычисляем, во сколько раз нужно увеличить картинку, чтобы контент вернулся к норме
        if (contentW > 0) {
            scaleMultiplier = refW / contentW;
        }
    }

    // Базовые коэффициенты масштаба
    const S  = Math.max(10, Math.min(200, baseScalePct)) / 100.0;
    const SX = Math.max(10, Math.min(300, strXPct))      / 100.0;
    const SY = Math.max(10, Math.min(200, strYPct))      / 100.0;

    // 1. Применяем мультипликатор к размерам контейнера
    const w_content = Math.max(2, Math.round(SVB_MODEL_W * S * SX * scaleMultiplier));
    
    // Высота считается от ширины с учетом пропорций
    const baseW_for_H = SVB_MODEL_W * S;
    const h_content   = Math.max(2, Math.round((baseW_for_H / R) * SY * scaleMultiplier));

    // 2. Координаты (PHP использует top-left, JS transform-origin center)
    const xBase = (xRaw / 1920) * SVB_MODEL_W;
    const yBase = (yRaw / 1080) * SVB_MODEL_H;

    // 3. Расчет визуального центра
    // Важно: scaleMultiplier увеличивает картинку во все стороны, центр остается на месте
    // относительно координат xRaw/yRaw, если мы считаем, что xRaw/yRaw указывают на верхний левый угол
    // ВИДИМОЙ части, а не паддинга. Но FFmpeg overlay позиционирует ВЕСЬ слой.
    
    // Чтобы синхронизировать, мы считаем центр "раздутого" контейнера
    const cx_model = xBase + (Math.round(SVB_MODEL_W * S * SX) / 2); // Центр без учета glow-раздутия
    const cy_model = yBase + (Math.round((baseW_for_H / R) * SY) / 2);

    // Возвращаем данные
    return {
        w_content, h_content,
        cx_norm: cx_model / SVB_MODEL_W,
        cy_norm: cy_model / SVB_MODEL_H,
        
        scale: baseScalePct,
        scaleX: strXPct,
        scaleY: strYPct,
        skew: skewXdeg,
        skewY: skewYdeg,
        angle: angleDeg,
        radius: radiusPx,
        glow: glowVal, // Передаем значение glow дальше
        pleft: p.pleft !== undefined ? p.pleft : 0,
        pright: p.pright !== undefined ? p.pright : 0,
        
        // Данные для JSON (Бэкенд)
        w_pred: w_content, 
        h_pred: h_content,
        video: { w: SVB_MODEL_W, h: SVB_MODEL_H },
        source_png: { square: 709 }
    };
}

/* === ЗАМЕНИТЬ ПОЛНОСТЬЮ ФУНКЦИЮ svbUpdatePreviewTransform === */
function svbUpdatePreviewTransform(key) {
    const img = document.getElementById('img-' + key);
    const preview = document.getElementById('svb-vid-preview-' + key);
    if (!img || !preview) return;

    const wrap = img.parentElement && img.parentElement.classList.contains('svb-ovbox')
        ? img.parentElement
        : null;
    if (!wrap) return;

    const geom = svbComputeOverlayGeom(key);
    const rect = preview.getBoundingClientRect();

    const kx = (rect.width || SVB_MODEL_W) / SVB_MODEL_W;
    const ky = (rect.height || SVB_MODEL_H) / SVB_MODEL_H;

    // --- Padding для Perspective (Str L/R) ---
    let padTop = 0;
    let extraH = 0;
    if (geom.pleft !== 0 || geom.pright !== 0) {
        padTop = Math.max(0, geom.pleft, geom.pright);
        const padBottom = Math.max(0, geom.pleft, geom.pright);
        if (padTop > 0 || padBottom > 0) extraH = padTop + padBottom;
    }

    // Размеры контейнера (уже с учетом раздутия от Glow из функции calculate)
    const w_canvas_orig = geom.w_content;
    const h_canvas_orig = geom.h_content;
    const h_canvas_padded = h_canvas_orig + extraH; 

    // Применяем размеры к картинке и обертке
    img.style.width  = Math.floor(w_canvas_orig * kx) + 'px';
    img.style.height = Math.floor(h_canvas_orig * ky) + 'px';
    img.style.left   = '0px'; 
    // Сдвигаем картинку вниз, если есть perspective padding
    img.style.top    = Math.floor(padTop * ky) + 'px';
    img.style.position = 'absolute';

    wrap.style.width  = Math.floor(w_canvas_orig * kx) + 'px';
    wrap.style.height = Math.floor(h_canvas_padded * ky) + 'px';
    
    // Позиционируем Wrap по центру координат
    const centerX = geom.cx_norm * rect.width;
    const centerY = geom.cy_norm * rect.height;
    
    wrap.style.left = (centerX - (parseFloat(wrap.style.width)/2)) + 'px';
    wrap.style.top  = (centerY - (parseFloat(wrap.style.height)/2)) + 'px';

    // === FIX 2: Порядок трансформаций CSS ===
    // Мы строим строку трансформации так, чтобы она соответствовала FFmpeg.
    // FFmpeg: Perspective -> Shear -> Rotate.
    // CSS применяется справа налево (или от вложенного к внешнему).
    // Поэтому порядок добавления в строку: rotate -> skew -> matrix.
    
    const t = [];

    // 1. Rotate (Внешнее вращение)
    if (geom.angle) t.push(`rotate(${geom.angle}deg)`);

    // 2. Skew (Наклон)
    if (geom.skew || geom.skewY) {
        t.push(`skew(${geom.skew}deg, ${geom.skewY}deg)`);
    }

    // 3. Perspective (Matrix3d)
    if (geom.pleft !== 0 || geom.pright !== 0) {
        const w = w_canvas_orig;
        const h_src = h_canvas_orig;
        
        // Логика проекции как в PHP
        const factorL = (h_src + 2.0 * geom.pleft) / h_src;
        const y0_calc = padTop * (1.0 - factorL) - geom.pleft;
        const y2_calc = factorL * (h_src + padTop) + padTop - geom.pleft;

        const factorR = (h_src + 2.0 * geom.pright) / h_src;
        const y1_calc = padTop * (1.0 - factorR) - geom.pright;
        const y3_calc = factorR * (h_src + padTop) + padTop - geom.pright;

        // Исходный прямоугольник (весь канвас с паддингом)
        const src = [[0, 0], [w, 0], [0, h_canvas_padded], [w, h_canvas_padded]];
        // Целевая трапеция
        const dst = [[0, y0_calc], [w, y1_calc], [0, y2_calc], [w, y3_calc]];

        const mat = svbSolveHomography(src, dst); // Использует твою функцию солвера
        t.push(svbArrToCssMatrix(mat));
    }

    wrap.style.transformOrigin = '50% 50%';
    wrap.style.transform = t.length ? t.join(' ') : 'none';
    
    // Сбрасываем трансформации на самой картинке (все делает wrap)
    img.style.transform = 'none';

    // Radius
    img.style.borderRadius = geom.radius > 0 ? Math.floor(geom.radius * kx) + 'px' : '0px';

    // === Визуальная имитация Glow Padding ===
    // Тут мы делаем так, чтобы картинка на фронте визуально уменьшилась внутри контейнера,
    // так же, как это делает PHP Imagick.
    if (geom.glow > 0) {
        const pxGlow = Math.round((geom.glow / 100) * 30);
        img.style.filter = `drop-shadow(0px 0px ${pxGlow}px rgba(255, 255, 255, 0.9))`;
        
        const sigma = (geom.glow / 100.0) * 30.0;
        let backendPaddingPx = Math.ceil(sigma * 3);
        if (backendPaddingPx < 2) backendPaddingPx = 2;
        
        // Приблизительный референсный размер для расчета % отступа
        const refW = 1152;
        const paddingPct = (backendPaddingPx / refW) * 100;
        
        img.style.boxSizing = 'border-box';
        
        // Учитываем искажение пропорций Scale X / Scale Y
        const sx = (geom.scaleX || 100);
        const sy = (geom.scaleY || 100);
        let distortion = (sx > 0) ? (sy / sx) : 1;
        
        // Добавляем padding внутрь картинки
        img.style.paddingLeft   = `${paddingPct}%`;
        img.style.paddingRight  = `${paddingPct}%`;
        img.style.paddingTop    = `${paddingPct * distortion}%`;
        img.style.paddingBottom = `${paddingPct * distortion}%`;
    } else {
        img.style.filter = 'none';
        img.style.padding = '0';
    }
}
/**
 * 2. Обновленная функция для Превью (читает из DOM).
 * Она просто собирает объект params и вызывает математическую функцию выше.
 */
function svbComputeOverlayGeom(key) {
    const num = (suffix, def = 0) => {
        const el = document.querySelector(`input[name="${key}_${suffix}"]`);
        const v = parseFloat(el?.value);
        return Number.isFinite(v) ? v : def;
    };

    const params = {
        scale:   num('scale', 100),
        scale_x: num('scale_x', 100), 
        scale_y: num('scale_y', 100),
        skew:    num('skew', 0),
        skew_y:  num('skew_y', 0),
        angle:   num('angle', 0),
        radius:  num('radius', 0),
        x:       num('x', 0),
        y:       num('y', 0),
        glow:    num('glow', 0),
        pleft:   num('pleft', 0),
        pright:  num('pright', 0)
    };

    // ВАЖНО: Читаем реальные пропорции картинки из DOM
    const img = document.getElementById('img-' + key);
    let ratio = 1;
    if (img && img.naturalWidth > 0 && img.naturalHeight > 0) {
        ratio = img.naturalWidth / img.naturalHeight;
    }

    return svbCalculateGeomFromParams(params, ratio);
}


function svbCollectOverlayData() {
    const data = {};
    const keys = ['child1', 'child2', 'parent1', 'parent2'];

    keys.forEach((key) => {
        // Зберігаємо поточні інпути в пам'ять перед збором
        if (typeof svbSaveInputsToScene === 'function') {
             svbSaveInputsToScene(key, SVB_CURRENT_SCENE_INDEX[key]);
        }

        if (!SVB_SCENES_DATA[key]) return;

        // Визначаємо співвідношення сторін
        let currentRatio = 1;
        const img = document.getElementById('img-' + key);
        if (img && img.naturalWidth > 0 && img.naturalHeight > 0) {
            currentRatio = img.naturalWidth / img.naturalHeight;
        }

        data[key] = SVB_SCENES_DATA[key].map((sceneParams, idx) => {
            // Передаємо ratio в калькулятор
            const geom = svbCalculateGeomFromParams(sceneParams, currentRatio);
            
            return {
                cx_norm:  geom.cx_norm,
                cy_norm:  geom.cy_norm,
                scale:    geom.scale,
                scale_x:  (geom.scaleX !== undefined) ? geom.scaleX : geom.scale, // Зберігаємо scale_x
                scaleY:   geom.scaleY,
                scale_y:  geom.scaleY,
                skew:     geom.skew,
                skewY:    geom.skewY,
                angle:    geom.angle,
                radius:   geom.radius,
                
    
                pleft:    geom.pleft,
                pright:   geom.pright,
                // ===================================================

                glow:     (sceneParams.glow !== undefined) ? sceneParams.glow : 0,
                opacity:  (sceneParams.opacity !== undefined) ? sceneParams.opacity : 100,
                
                img_ratio: currentRatio,
                
                x_norm:   geom.x_norm,
                y_norm:   geom.y_norm,
                w_pred:   geom.w_pred,
                h_pred:   geom.h_pred,
                video:    geom.video,
                source_png: geom.source_png
            };
        });
    });

    const field = document.getElementById('overlay_json');
    if (field) {
        field.value = JSON.stringify(data);
    }
    return data;
}

const svbNorm = s => (s||'').toString().toLowerCase().trim().replace(/[\s_\-’']/g,'');

let SVB_SELECTED = {};
function buildSoundMap(){
  const pull = (cat) => {
    // ВИПРАВЛЕННЯ: Використовуємо getAllNames() замість getNameOptionsByGender()
    let items = (cat === 'name') ? getAllNames() : (SVB_AUDIO[cat] || []);
    
    // Шукаємо селект. Для імен це може бути name_audio, name_audio_2, etc.
    // Але ця функція збирає основний масив для кроку 3.
    // Тут логіка спрощена для загального списку.
    const sel = document.querySelector(`select[name="${cat}_audio"]`);
    
    if (!sel) return null;
    const file = sel.value;
    const it = items.find(i => i.file === file);
    return it ? { file: it.file, url: it.url, label: it.label } : null;
  };

  SVB_SELECTED = {
    name:    pull('name'),
    age:     pull('age'),
    facts:   pull('facts'),
    hobby:   pull('hobby'),
    praise:  pull('praise'),
    request: pull('request')
  };

  const box = document.getElementById('svb-result');
  if (box) {
    const rows = Object.entries(SVB_SELECTED)
      .filter(([k,v]) => !!v)
      .map(([k,v]) => `<div><b>${k}</b>: ${v.label} <small>(${v.file})</small></div>`)
      .join('');
    if (rows) { box.style.display='block'; box.innerHTML = `<div><b>Обрані озвучки:</b></div>${rows}`; }
  }
}

$('#svb-next-1').addEventListener('click', ()=> svbSetStep(2));
$('#svb-back-2').addEventListener('click', ()=> svbSetStep(1));
let svbJobToken = null, svbVideoURL = null, svbGenerating = false;
let svbPollInterval = null; 
$('#svb-next-2').addEventListener('click', ()=>{
  buildSoundMap();
  svbSetStep(3);
  $('#svb-spin').style.display='inline-block';
  $('#svb-status').textContent = 'Генеруємо відео… це може зайняти кілька хвилин';
  svbStartGenerate(); 
});
$('#svb-back-3').addEventListener('click', ()=> {
  svbSetStep(2);
  if (svbPollInterval) clearInterval(svbPollInterval);
});
$('#svb-finish').addEventListener('click', async ()=>{
  const email = $('#svb-email').value.trim();
  if(!email){ alert('Вкажіть email'); return; }
  if(!svbVideoURL){
    alert('Відео ще готується. Будь ласка, дочекайтесь повідомлення «Готово».');
    return;
  }
  const fd = new FormData();
  fd.append('action','svb_confirm');
  fd.append('_svb_nonce', <?php echo wp_json_encode($nonce); ?>);
  fd.append('email', email);
  fd.append('token', svbJobToken||'');
  fetch(SVB_AJAX.url, { method:'POST', body:fd })
    .then(r=>r.json()).then(data=>{
      if(data.success){
        const res = $('#svb-result');
        res.style.display='block';
        res.innerHTML = `<b>Готово!</b> <a href="${data.data.url}" download>Скачати відео</a>. Посилання дійсне 1 годину.`;
      } else {
        alert(data.data||'Помилка підтвердження');
      }
    });
});
function escapeHtml(s){ return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
function svbLock(on){
  const lock = $('#svb-lock');
  if (!lock) return;
  lock.style.display = on ? 'flex' : 'none';
  document.documentElement.style.overflow = on ? 'hidden' : '';
}
async function svbStartGenerate() {
    if (svbGenerating) return;
    svbGenerating = true;
    svbLock(true);
    $('#svb-lock-percent').textContent = '0';
    $('#svb-lock-text').textContent = 'Збирання даних...';
    $('#svb-status').textContent = 'Збирання даних...';

    // 1. Сохраняем сегменты времени
    svbSerializeSegmentsToField();

    const form = document.getElementById('svb-form');
    const fd = new FormData(form);
    
    // Передаем ID видео
    fd.set('selected_video_id', SVB_SELECTED_VIDEO_ID);
    
    // 2. Собираем данные геометрии (JSON)
    let overlayData = {};
    try {
        overlayData = svbCollectOverlayData();
        fd.append('overlay_json', JSON.stringify(overlayData));
    } catch (jsonErr) {
        console.error('overlay_json encode failed:', jsonErr);
    }

    // 3. === FIX: СБОР VISUAL DEBUG ДАННЫХ (С учетом скрытых элементов) ===
    const visualDebug = {};
    
    // Временно показываем Step 2, чтобы считать реальные размеры
    const step2 = document.querySelector('.svb-step[data-step="2"]');
    // Проверяем, скрыт ли блок сейчас
    const wasHidden = step2 && (getComputedStyle(step2).display === 'none');
    
    if (wasHidden && step2) {
        // Делаем видимым для браузера, но прозрачным для глаза
        step2.style.visibility = 'hidden'; 
        step2.style.display = 'block';
    }

    ['child1', 'child2', 'parent1', 'parent2'].forEach(key => {
        const img = document.getElementById('img-' + key);
        const vid = document.getElementById('svb-video-' + key); // Контейнер превью
        
        if (img && vid) {
            // Принудительно обновляем трансформацию перед замером
            if (typeof svbUpdatePreviewTransform === 'function') svbUpdatePreviewTransform(key);

            const rect = img.getBoundingClientRect();
            const vidRect = vid.getBoundingClientRect();
            const computed = window.getComputedStyle(img);
            
            visualDebug[key] = {
                client_rect: {
                    top: rect.top,
                    left: rect.left,
                    width: rect.width,
                    height: rect.height
                },
                video_rect: {
                    width: vidRect.width,
                    height: vidRect.height
                },
                css: {
                    transform: computed.transform,
                    width: computed.width,
                    height: computed.height,
                    // Добавляем отступы, чтобы понять позицию внутри родителя
                    offsetLeft: img.offsetLeft,
                    offsetTop: img.offsetTop
                },
                natural_ratio: (img.naturalWidth / (img.naturalHeight || 1)) || 1
            };
        }
    });

    // Возвращаем как было
    if (wasHidden && step2) {
        step2.style.display = 'none';
        step2.style.visibility = '';
    }

    fd.append('debug_front_visuals', JSON.stringify(visualDebug));
    // ==========================================

    fd.append('action', 'svb_generate');

    try {
        const response = await fetch(SVB_AJAX.url, { method:'POST', body:fd });
    
        if (!response.ok) {
            const text = await response.text(); 
            console.error('Server Error:', response.status, text);
            throw new Error(`Server responded with ${response.status}: ${text.substring(0, 200)}`);
        }

        const data = await response.json();
    
        if (data.success && data.data.token) {
            svbJobToken = data.data.token;
            $('#svb-status').textContent = 'Генерація почалася...';
            $('#svb-lock-text').textContent = 'Формуємо відео…';
            svbPollProgress(svbJobToken);
        } else {
            svbHandleError(data.data || {msg: 'Сервер повернув помилку (success: false).'});
        }
    } catch (err) {
        console.error(err);
        svbHandleError({
            msg: 'Критична помилка відправки даних.', 
            log: err.message + "\n\nПеревірте розмір фото (upload_max_filesize)!"
        });
    }
}
function svbPollProgress(token) {
  if (svbPollInterval) clearInterval(svbPollInterval);
  svbPollInterval = setInterval(async () => {
    try {
      const fd = new FormData();
      fd.append('action', 'svb_check_progress');
      fd.append('_svb_nonce', SVB_AJAX.nonce);
      fd.append('token', token);
      const response = await fetch(SVB_AJAX.url, { method: 'POST', body: fd });
      const data = await response.json();
      
      if (data.success) {
        
        // === DEBUG OUTPUT TO CONSOLE ===
        if (data.data.debug) {
            console.groupCollapsed(`🚀 SVB Progress: ${data.data.percent}%`);
            console.log("Log Exists:", data.data.debug.log_exists);
            console.log("Log Size:", data.data.debug.log_size);
            console.log("%cFFmpeg Log Tail:", "color: orange; font-weight: bold;");
            console.log(data.data.debug.tail);
            console.groupEnd();
        }
        // ===============================

        if (data.data.status === 'running') {
          const percent = data.data.percent || 0;
          $('#svb-lock-percent').textContent = percent + '%';
          $('#svb-status').textContent = `Іде обробка... ${percent}%`;
        } else if (data.data.status === 'done') {
          clearInterval(svbPollInterval);
          svbHandleSuccess(data.data.url);
        }
      } else {
        clearInterval(svbPollInterval);
        svbHandleError(data.data || {msg: 'Помилка під час перевірки статусу.'});
      }
    } catch (err) {
      clearInterval(svbPollInterval);
      svbHandleError({msg: 'Помилка мережі під час перевірки статусу.', log: err.message});
    }
  }, 3000); 
}
function svbHandleSuccess(url) {
  svbGenerating = false;
  svbLock(false);
  svbVideoURL = url; 
  $('#svb-lock-percent').textContent = '100%';
  $('#svb-spin').style.display = 'none';
  $('#svb-status').innerHTML = `✅ Відео зібрано. <a href="${url}" download>Скачати</a>`;
  const res = $('#svb-result');
  res.style.display = 'block';
  res.innerHTML = `<b>Готово!</b> <a href="${url}" download>Скачати відео</a>. Посилання дійсне 1 годину.`;
  $('#svb-finish').disabled = false;
}
function svbHandleError(data) {
  svbGenerating = false;
  svbLock(false);
  $('#svb-spin').style.display = 'none';
  $('#svb-status').textContent = 'Сталася помилка при генерації відео';
  const res = $('#svb-result');
  if (res) {
    let msg, cmd = '', log = '', hint = '';

    if (typeof data === 'string') {
      msg = data;                 // ← показываем строку, если это строка
    } else {
      msg  = (data && data.msg)  || 'Unknown error';
      cmd  = (data && data.cmd)  || '';
      log  = (data && data.log)  || '';
      hint = (data && data.hint) || '';
    }

    res.style.display = 'block';
    res.innerHTML = `<details open>
       <summary><b>Деталі помилки</b></summary>
       <div style="margin-top:8px"><b>Msg:</b> ${escapeHtml(msg)}</div>
       ${cmd ? `<div><b>Cmd:</b> <code style="white-space:pre-wrap">${escapeHtml(cmd)}</code></div>` : ''}
       ${hint? `<div><b>Hint:</b> ${escapeHtml(hint)}</div>` : ''}
       <pre style="white-space:pre-wrap;max-height:260px;overflow:auto;margin-top:8px">${escapeHtml(String(log)).slice(0,8000)}</pre>
    </details>`;
  }
}

const _svbNorm = s => (s||'').toString().toLowerCase().trim().replace(/[\s_\-’']/g,'');

function svbMarkTouched(key){
  ['x','y','scale','scale_x','scale_y','skew','skew_y','angle','radius','opacity','glow'].forEach(k=>{
    const el = document.querySelector(`input[name="${key}_${k}"]`);
    if (el && !el.__svb_bound) {
      el.addEventListener('input', ()=> el.dataset.touched = '1');
      el.__svb_bound = true;
    }
  });
}
// 1. Збираємо всі імена (хлопчики, дівчатка, root) в один список
function getAllNames() {
    let all = [];
    if (typeof SVB_AUDIO !== 'undefined' && SVB_AUDIO.name) {
        if (SVB_AUDIO.name.boy) all = all.concat(SVB_AUDIO.name.boy);
        if (SVB_AUDIO.name.girl) all = all.concat(SVB_AUDIO.name.girl);
        if (SVB_AUDIO.name.root) all = all.concat(SVB_AUDIO.name.root);
    }
    return all;
}

// Замените существующую функцию svbBindNameSuggestUniversal на эту:
function svbBindNameSuggestUniversal(inputId, resultBoxId, selectName, displayId, playBtnId) {
    const input = document.querySelector(`input[name="${inputId}"]`);
    const box = document.getElementById(resultBoxId);
    const sel = document.querySelector(`select[name="${selectName}"]`);
    const display = document.getElementById(displayId);
    const playBtn = document.getElementById(playBtnId);

    if (!input || !box || !sel) return;

    // Поиск
    const doSearch = (val) => {
        const list = getAllNames();
        const normQ = _svbNorm(val);
        const items = list.filter(i => !normQ || _svbNorm(i.label || i.file).includes(normQ)).slice(0, 10);
        
        box.innerHTML = items.map(i => 
            `<div class="svb-suggest-item" data-file="${i.file}" data-label="${i.label || i.file}">
                ${i.label || i.file} <small style="color:#999">(${i.file})</small>
             </div>`
        ).join('');
        
        if (items.length > 0 && normQ) {
            box.style.display = 'block';
            // Базовая стилизация выпадающего списка
            box.style.position = 'absolute';
            box.style.zIndex = '1000';
            box.style.background = '#fff';
            box.style.border = '1px solid #ddd';
            box.style.width = '100%';
            box.style.maxHeight = '200px';
            box.style.overflowY = 'auto';
        } else {
            box.style.display = 'none';
        }
    };

    input.addEventListener('input', e => {
        doSearch(e.target.value);
        if(playBtn) playBtn.style.display = 'none'; // Скрываем кнопку при наборе
    });
    input.addEventListener('focus', e => doSearch(e.target.value));

    // Клик по списку
    box.addEventListener('click', e => {
        const item = e.target.closest('.svb-suggest-item');
        if (!item) return;
        
        const label = item.dataset.label;
        const file = item.dataset.file;

        input.value = label;
        sel.innerHTML = `<option value="${file}" selected>${label}</option>`;
        sel.value = file;
        
        if (display) {
            display.textContent = `✅ Обрано: ${label}`;
            display.style.color = 'green';
        }

        // === ЛОГИКА КНОПКИ PLAY ===
        if (playBtn) {
            console.log('Попытка включить кнопку для:', file);
            const fullItem = getAllNames().find(i => i.file === file);
            
            if (fullItem && fullItem.url) {
                console.log('Аудио найдено:', fullItem.url);
                playBtn.dataset.audioUrl = fullItem.url;
                playBtn.style.display = 'flex'; // Показываем кнопку
            } else {
                console.warn('Аудио НЕ найдено для файла:', file);
                // Попробуем собрать URL вручную, если в JSON его нет (fallback)
                // Это поможет, если структура каталога простая
                // Но лучше полагаться на данные из PHP
            }
        }

        box.style.display = 'none';
    });

    document.addEventListener('click', e => {
        if (!e.target.closest('.svb-suggest') && e.target !== input) {
            box.style.display = 'none';
        }
    });
}


function svbBindRealtimeControls() {
    ['child1', 'child2', 'parent1', 'parent2'].forEach(key => {
        const vid = document.getElementById(`svb-video-${key}`);
        const playBtn = document.querySelector(`[data-vid-ctrl="play"][data-key="${key}"]`);
        const pauseBtn = document.querySelector(`[data-vid-ctrl="pause"][data-key="${key}"]`);
        const timeEl = document.getElementById(`svb-vid-time-${key}`);
        const muteBtn = document.querySelector(`[data-vid-ctrl="mute"][data-key="${key}"]`);
        const unmuteBtn = document.querySelector(`[data-vid-ctrl="unmute"][data-key="${key}"]`);
        const volumeSlider = document.querySelector(`[data-vid-ctrl="volume"][data-key="${key}"]`);
        const seekSlider = document.querySelector(`[data-vid-ctrl="seek"][data-key="${key}"]`);
                const timeInputMs = document.querySelector(`.svb-time-input[data-key="${key}"]`);
                const img = document.getElementById('img-' + key);
        function isOn(t){
            const windows = (typeof SVB_OVERLAY_WINDOWS === 'object' && SVB_OVERLAY_WINDOWS[key]) ? SVB_OVERLAY_WINDOWS[key] : [];
            for (let i = 0; i < windows.length; i++){
                const w = windows[i];
                if (t >= (w[0]||0) && t <= (w[1]||0)) return true;
            }
            return false;
        }


        function updateImgVisibility(timeSec) {
            if (!img) return;
            const baseAlpha = parseFloat(img.dataset.svbOpacity || '1') || 1;
            const visible   = isOn(timeSec);
            img.dataset.svbVisible = visible ? '1' : '0';
            const alpha = visible ? baseAlpha : 0;
            img.style.opacity = String(alpha);
        }

        if (img) {
            updateImgVisibility(0);
        }

        if (!vid || !playBtn || !pauseBtn || !timeEl || !muteBtn || !unmuteBtn || !volumeSlider || !seekSlider) {
            return;
        }


        playBtn.addEventListener('click', () => {
            if(svbCurrentSampleAudio) {
                svbCurrentSampleAudio.pause();
                svbCurrentSampleAudio = null;
            }
            vid.play();
        });
        pauseBtn.addEventListener('click', () => {
            vid.pause();
        });
        vid.addEventListener('play', () => {
            playBtn.style.display = 'none';
            pauseBtn.style.display = 'inline-flex';
        });
        vid.addEventListener('pause', () => {
            playBtn.style.display = 'inline-flex';
            pauseBtn.style.display = 'none';
        });

        let totalDuration = 0;
        vid.addEventListener('loadedmetadata', () => {
            totalDuration = vid.duration;
            seekSlider.max = totalDuration;

                        let start = 0;
            const currentWindows = (typeof SVB_OVERLAY_WINDOWS === 'object' && SVB_OVERLAY_WINDOWS[key]) ? SVB_OVERLAY_WINDOWS[key] : [];
            if (currentWindows && currentWindows.length) {
                start = currentWindows[0][0] || 0;
            }


            vid.currentTime = start;
            seekSlider.value = start;
            if (img) updateImgVisibility(start);

            timeEl.textContent = `${svbFormatTime(start)} / ${svbFormatTime(totalDuration)}`;

            if (timeInputMs) {
                timeInputMs.value = Math.round(start * 1000);
            }
        });




        vid.addEventListener('timeupdate', () => {
            const currentTime = vid.currentTime;
            if (!seekSlider.matches(':active')) {
                seekSlider.value = currentTime;
            }
            timeEl.textContent = `${svbFormatTime(currentTime)} / ${svbFormatTime(totalDuration || vid.duration || 0)}`;
            if (timeInputMs) {
                timeInputMs.value = Math.round(currentTime * 1000);
            }
            if (img) {
                updateImgVisibility(currentTime);
            }
        });



        seekSlider.addEventListener('input', (e) => {
            const t = parseFloat(e.target.value) || 0;
            vid.currentTime = t;
            if (timeInputMs) {
                timeInputMs.value = Math.round(t * 1000);
            }
            timeEl.textContent = `${svbFormatTime(t)} / ${svbFormatTime(totalDuration || vid.duration || 0)}`;
            if (img) {
                updateImgVisibility(t);
            }
        });



        const updateMuteButtons = (isMuted) => {
            muteBtn.style.display = isMuted ? 'none' : 'inline-flex';
            unmuteBtn.style.display = isMuted ? 'inline-flex' : 'none';

            if (isMuted) {
                volumeSlider.value = 0;
            } else {
                if (vid.volume < 0.05) {
                    vid.volume = 0.8;
                }
                volumeSlider.value = vid.volume;
            }
        };
        muteBtn.addEventListener('click', () => {
            vid.muted = true;
        });
        unmuteBtn.addEventListener('click', () => {
            vid.muted = false;
        });
        volumeSlider.addEventListener('input', (e) => {
            const vol = parseFloat(e.target.value);
            vid.volume = isNaN(vol) ? vid.volume : vol;
            vid.muted = (vol < 0.05);
        });

        vid.addEventListener('volumechange', () => {
             updateMuteButtons(vid.muted || vid.volume < 0.05);
        });
        vid.volume = parseFloat(volumeSlider.value || '0.8');
        vid.muted = vid.volume < 0.05;
        updateMuteButtons(vid.muted);
                if (timeInputMs) {
            timeInputMs.addEventListener('input', () => {
                const ms = parseFloat(timeInputMs.value);
                if (!Number.isFinite(ms)) return;

                const dur = totalDuration || vid.duration || 0;
                let sec = ms / 1000;
                if (sec < 0) sec = 0;
                if (dur > 0 && sec > dur) sec = dur;

                vid.currentTime = sec;
                seekSlider.value = sec;
                timeEl.textContent = `${svbFormatTime(sec)} / ${svbFormatTime(dur)}`;
                if (img) {
                    updateImgVisibility(sec);
                }
            });
        }

        const controls = {};
        const logBtn = document.querySelector(`[data-vid-ctrl="log"][data-key="${key}"]`);
if (logBtn) {
  logBtn.addEventListener('click', () => {
    const img = document.getElementById('img-' + key);
    svbDumpOverlayDebug(img, vid, key, svbJobToken);
    alert('Кадр зафиксирован в логе (svb_align.jsonl).');
  });
}

        $$(`.svb-key-control[name^="${key}_"]`).forEach(input => {
            const keyUp = input.dataset.keyUp;
            const keyDown = input.dataset.keyDown;
            if (keyUp) controls[keyUp] = { input, dir: 1 };
            if (keyDown) controls[keyDown] = { input, dir: -1 };
        });
        $$(`.svb-key-control[name^="${key}_"]`).forEach(slider => {
            slider.addEventListener('keydown', (e) => {
                const ctrl = controls[e.key];
                if (!ctrl) return;
                e.preventDefault();
                const input = ctrl.input;
                const dir = ctrl.dir;
                const step = parseFloat(input.step) || 1;
                const min = parseFloat(input.min) || -Infinity;
                const max = parseFloat(input.max) || Infinity;
                let val = parseFloat(input.value) || 0;
                val += dir * (e.shiftKey ? step * 10 : step);
                input.value = Math.max(min, Math.min(max, val)).toFixed(0);
                input.dispatchEvent(new Event('input', { bubbles: true }));
            });
        });
    });
}
function svbEnsureWrappers(){
  ['child1','child2','parent1','parent2'].forEach(key=>{
    const img = document.getElementById('img-'+key);
    const box = document.getElementById('svb-vid-preview-'+key);
    if(!img || !box) return;
    if (img.parentElement && img.parentElement.classList.contains('svb-ovbox')) return;

    const wrap = document.createElement('div');
    wrap.className = 'svb-ovbox';
    box.appendChild(wrap);
    wrap.appendChild(img); // переносим картинку внутрь bbox-обёртки
  });
}


function svbApplyTemplateTimings(videoId) {
    const timings = SVB_TEMPLATE_TIMINGS[videoId];
    if (!timings) return;
    
    // Обновить глобальный объект с новыми таймингами
    Object.keys(timings).forEach(key => {
        if (SVB_OVERLAY_WINDOWS && timings[key]) {
            SVB_OVERLAY_WINDOWS[key] = timings[key];
        }
    });
    
    // Пересчитать UI интервалов
    svbInitIntervalUi();
    svbBindIntervalUi();
}

// === СОХРАНЕНИЕ НАСТРОЕК (ADMIN) ===
// === ЛОГИКА СОХРАНЕНИЯ (ADMIN) ===
const saveBtn = document.getElementById('svb-save-settings-btn');
if (saveBtn) {
    saveBtn.addEventListener('click', () => {
        if (!confirm('Ви впевнені? Ці налаштування стануть дефолтними для всіх користувачів.')) return;

        // 1. Сохраняем текущее состояние инпутов в память
        ['child1','child2','parent1','parent2'].forEach(key => {
             if (typeof svbSaveInputsToScene === 'function') {
                 svbSaveInputsToScene(key, SVB_CURRENT_SCENE_INDEX[key]);
             }
        });

        // 2. Формируем JSON для сохранения
        const scenesConfig = {};
        
        ['child1','child2','parent1','parent2'].forEach(key => {
            scenesConfig[key] = [];
            const dataArr = SVB_SCENES_DATA[key] || [];
            
            // Важно: берем актуальные тайминги из SVB_OVERLAY_WINDOWS, 
            // так как пользователь мог их поменять в инпутах
            const timings = SVB_OVERLAY_WINDOWS[key] || [];
            
            dataArr.forEach((sceneItem, i) => {
                // Если есть тайминг в окнах, берем его, иначе старый
                const tPair = timings[i];
                const startStr = tPair ? svbSecondsToTS(tPair[0]) : sceneItem.start;
                const endStr   = tPair ? svbSecondsToTS(tPair[1]) : sceneItem.end;

                scenesConfig[key].push({
                    start: startStr,
                    end:   endStr,
                    label: sceneItem.label || `Сцена ${i+1}`,
                    // Сохраняем геометрию
                    x: sceneItem.x,
                    y: sceneItem.y,
                    scale: sceneItem.scale,
                    scale_x: sceneItem.scale_x, // <--- ДОБАВЛЕНО
                    scale_y: sceneItem.scale_y,
                    skew: sceneItem.skew,
                    skew_y: sceneItem.skew_y,
                    angle: sceneItem.angle,
                    radius: sceneItem.radius,
                    opacity: sceneItem.opacity,
                    glow: sceneItem.glow
                });
            });
        });

        // 3. Отправляем на сервер
        const fd = new FormData();
        fd.append('action', 'svb_save_config');
        fd.append('_svb_nonce', SVB_AJAX.nonce);
        fd.append('video_id', SVB_SELECTED_VIDEO_ID);
        fd.append('scenes', JSON.stringify(scenesConfig));

        const oldText = saveBtn.textContent;
        saveBtn.disabled = true;
        saveBtn.textContent = 'Збереження...';

        fetch(SVB_AJAX.url, { method:'POST', body:fd })
        .then(r => r.json())
        .then(res => {
            saveBtn.disabled = false;
            saveBtn.textContent = oldText;
            if(res.success) {
                alert('✅ ' + res.data);
            } else {
                alert('❌ Помилка: ' + (res.data || 'Unknown error'));
            }
        })
        .catch(err => {
            saveBtn.disabled = false;
            saveBtn.textContent = oldText;
            alert('Мережева помилка');
            console.error(err);
        });
    });
}
// Функції відкриття/закриття попапа запиту імені
function svbOpenNamePopup() {
    document.getElementById('svb-name-popup').classList.add('active');
}
function svbCloseNamePopup(e, force = false) {
    const modal = document.getElementById('svb-name-popup');
    // Закриваємо якщо клік по фону або хрестику
    if (force || e.target === modal) {
        modal.classList.remove('active');
    }
}
// === ЗАПУСК ===
document.addEventListener('DOMContentLoaded', () => {
    console.log('🚀 SVB Init started (Final Fix)');

    // 1. Сначала отрисовываем выбор видео
    if (typeof svbRenderUI === 'function') {
        svbRenderUI();
    }

    // 2. ВАЖНО: Сначала биндим переключатель детей, но НЕ вызываем обновление списков внутри него сразу
    //    Или просто привязываем события.
    const radios = document.querySelectorAll('input[name="child_count"]');
    radios.forEach(r => r.addEventListener('change', () => {
        // Обновляем глобальную переменную
        const checked = document.querySelector('input[name="child_count"]:checked');
        svbCurrentChildCount = checked ? parseInt(checked.value) : 1;
        
        // Управление видимостью полей
        const ageBlock = document.getElementById('svb-age-block');
        const field2 = document.querySelector('.field-child-2');
        const field3 = document.querySelector('.field-child-3');

        if (svbCurrentChildCount > 1) {
            if (ageBlock) ageBlock.style.display = 'none';
        } else {
            if (ageBlock) ageBlock.style.display = 'block';
        }
        if (field2) field2.style.display = (svbCurrentChildCount >= 2) ? 'block' : 'none';
        if (field3) field3.style.display = (svbCurrentChildCount >= 3) ? 'block' : 'none';

        // Перерисовываем списки аудио (фильтрация)
        svbPopulateSelects();
        
        // Перерисовываем выбор видео (доступные шаблоны)
        svbRenderUI();
    }));

    // 3. Инициализируем переменную счетчика детей ПЕРЕД заполнением
    const checked = document.querySelector('input[name="child_count"]:checked');
    svbCurrentChildCount = checked ? parseInt(checked.value) : 1;

    // 4. ТЕПЕРЬ заполняем списки (Аудио)
    svbPopulateSelects();

    // 5. Запускаем логику отображения полей (скрыть/показать поля для 2-3 детей)
    //    Имитируем событие change, но аккуратно
    const event = new Event('change');
    if(checked) checked.dispatchEvent(event); 

    // Биндим остальные элементы
    svbBindAudioPreview();
    svbBindPhotoInputs(); 
    svbEnsureWrappers();
    svbBindNumericControls();
    svbInitIntervalUi();
    svbBindIntervalUi();
    svbBindRealtimeControls();

    // Биндим поиск имен
    svbBindNameSuggestUniversal('name_text', 'svb-name-suggest', 'name_audio', 'svb-name-display-1', 'svb-name-play-1');
    svbBindNameSuggestUniversal('name_text_2', 'svb-name-suggest-2', 'name_audio_2', 'svb-name-display-2', 'svb-name-play-2');
    svbBindNameSuggestUniversal('name_text_3', 'svb-name-suggest-3', 'name_audio_3', 'svb-name-display-3', 'svb-name-play-3');

    // Логика попапа запроса имени
    const reqSubmit = document.getElementById('popup_req_submit');
    if (reqSubmit) {
        reqSubmit.addEventListener('click', () => {
            const nameVal = document.getElementById('popup_req_name').value.trim();
            const emailVal = document.getElementById('popup_req_email').value.trim();

            if (!nameVal || !emailVal) {
                alert('Будь ласка, заповніть обидва поля (Ім\'я та Email).');
                return;
            }

            const originalBtnText = reqSubmit.textContent;
            reqSubmit.disabled = true;
            reqSubmit.textContent = 'Відправка...';

            const fd = new FormData();
            fd.append('action', 'svb_request_name');
            fd.append('_svb_nonce', SVB_AJAX.nonce);
            fd.append('name_req', nameVal);
            fd.append('email_req', emailVal);

            fetch(SVB_AJAX.url, { method: 'POST', body: fd })
                .then(r => r.json())
                .then(res => {
                    if (res.success) {
                        alert(res.data);
                        document.getElementById('svb-name-popup').classList.remove('active');
                        document.getElementById('popup_req_name').value = '';
                        document.getElementById('popup_req_email').value = '';
                    } else {
                        alert('Помилка: ' + (res.data || 'Unknown error'));
                    }
                })
                .catch(err => { alert('Помилка з\'єднання'); })
                .finally(() => {
                    reqSubmit.disabled = false;
                    reqSubmit.textContent = originalBtnText;
                });
        });
    }

    // Принудительно выбираем видео и тайминги
    setTimeout(() => {
        if (typeof SVB_SELECTED_VIDEO_ID !== 'undefined' && SVB_SELECTED_VIDEO_ID) {
            svbSelectVideoTemplate(SVB_SELECTED_VIDEO_ID);
        } else {
            svbSelectVideoTemplate('video1');
        }
        // Обновляем превью картинок
        ['child1', 'child2', 'parent1', 'parent2'].forEach(key => {
            svbUpdatePreviewTransform(key);
            svbDebugPrint(key);
        });
    }, 200);
});
function svbDebugPrint(key) {
  const img = document.getElementById('img-' + key);
  if (!img) return;
  let dbg = document.getElementById('svb-dbg-' + key);
  if (!dbg) {
    dbg = document.createElement('div');
    dbg.id = 'svb-dbg-' + key;
    dbg.style.font = '12px/1.3 monospace';
    dbg.style.marginTop = '6px';
    img.closest('.svb-drop')?.appendChild(dbg);
  }
  const cs = getComputedStyle(img);
  dbg.textContent =
    `left:${cs.left} top:${cs.top} width:${cs.width} height:${cs.height} ` +
    `transform-origin:${cs.transformOrigin} transform:${cs.transform}`;
}

function svbDumpOverlayDebug(el, video, key, token){
  if(!el || !video) return;

  const targetEl = (el.parentElement && el.parentElement.classList.contains('svb-ovbox')) 
                   ? el.parentElement 
                   : el;

  const cs = getComputedStyle(targetEl);
  const r  = targetEl.getBoundingClientRect();
  const geom = svbComputeOverlayGeom(key);

  let angleDegCss = 0;
  const m = cs.transform.match(/matrix\(([^)]+)\)/);
  if (m) {
    const [a,b] = m[1].split(',').map(s=>parseFloat(s.trim()));
    angleDegCss = Math.atan2(b, a) * 180 / Math.PI;
  }

  const payload = {
    key,
    t: video.currentTime || 0,
    videoSize: { w: video.videoWidth, h: video.videoHeight },
    previewSize: { w: video.clientWidth, h: video.clientHeight },

    imgRectPx: {
      left: r.left + window.scrollX,
      top:  r.top  + window.scrollY,
      width: r.width,
      height: r.height
    },
    css: {
      transform: cs.transform,
      angleDegCss: Math.round(angleDegCss * 1000) / 1000
    },

    geom // всё, что посчитал svbComputeOverlayGeom
  };

  const fd = new FormData();
  fd.append('action','svb_dbg_push');
  fd.append('_svb_nonce', SVB_AJAX.nonce);
  fd.append('token', token || (window.svbJobToken||''));
  fd.append('payload', JSON.stringify(payload));
  fetch(SVB_AJAX.url, { method:'POST', body: fd }).then(()=>{});
}
// === МОБИЛЬНЫЙ СЛАЙДЕР (СКРИПТ-АДАПТАЦИЯ) ===
(function() {
    // 1. Создаем стили, которые перебивают inline-стили на мобилках
    const mobileStyle = document.createElement('style');
    mobileStyle.innerHTML = `
        @media (max-width: 768px) {
            #svb-video-selector {
                display: flex !important;       /* Перебиваем Grid */
                flex-wrap: nowrap !important;   /* Запрещаем перенос (лента) */
                overflow-x: auto !important;    /* Разрешаем скролл */
                scroll-snap-type: x mandatory;  /* Магнитное прилипание */
                grid-template-columns: none !important; /* Убираем колонки */
                gap: 16px !important;
                padding: 10px 4px 25px 4px !important; /* Отступ снизу */
                
                /* Скрываем скроллбар */
                scrollbar-width: none;
                -ms-overflow-style: none;
            }
            
            #svb-video-selector::-webkit-scrollbar {
                display: none;
            }

            .svb-video-option {
                flex: 0 0 100% !important;       /* Ширина карточки 85% экрана */
                width: 100% !important;
                max-width: 100% !important;
                scroll-snap-align: center;      /* Центровка при остановке */
                margin: 0 !important;
                transform: scale(0.96);         /* Чуть уменьшаем неактивные */
                transition: transform 0.3s ease, border-color 0.3s ease;
            }

            .svb-video-option.active {
                transform: scale(1);            /* Активная карточка крупнее */
                border-color: #FACC15 !important;
                box-shadow: 0 10px 25px rgba(250, 204, 21, 0.3) !important;
                outline: none !important;
            }
        }
    `;
    document.head.appendChild(mobileStyle);

    // 2. Логика авто-прокрутки к активному видео при загрузке
    document.addEventListener('DOMContentLoaded', () => {
        setTimeout(() => {
            const container = document.getElementById('svb-video-selector');
            const activeItem = container ? container.querySelector('.active') : null;
            
            // Если мы на мобильном и есть активный элемент
            if (window.innerWidth <= 768 && container && activeItem) {
                const scrollLeft = activeItem.offsetLeft - (container.clientWidth / 2) + (activeItem.clientWidth / 2);
                container.scrollTo({
                    left: scrollLeft,
                    behavior: 'smooth'
                });
            }
        }, 500); // Небольшая задержка, чтобы элементы успели отрисоваться
    });
})();

</script>


<?php
    return ob_get_clean();
}
