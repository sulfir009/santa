<?php
/**
 * Plugin Name: Santa Video Builder (All-in-One)
 * Description: 3-шаговый модуль генерации персонального видео (как elfisanta): выбор озвучек, загрузка фото, сборка через FFmpeg. Хранение результата 1 час.
 * Version: 3.1.3 (Real-Time Progress)
 * Author: You
 */

if (!defined('ABSPATH')) { exit; }

/** === Константы плагина === */
define('SVB_VER', '3.1.3'); // bump
define('SVB_PLUGIN_FILE', __FILE__);
define('SVB_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SVB_PLUGIN_URL', plugin_dir_url(__FILE__));

/** Включаем подробный локальный дебаг для каждого job */
if (!defined('SVB_DEBUG')) {
    define('SVB_DEBUG', true);
}

/** Запись диагностик в лог job-директории */
function svb_dbg_write($job_dir, $label, $text){
    if (!SVB_DEBUG || !$job_dir) return;
    $file = rtrim($job_dir, '/').'/svb_debug.log';
    $ts   = date('Y-m-d H:i:s');
    $line = "[$ts] $label\n";
    $line .= is_string($text) ? $text : var_export($text, true);
    $line .= "\n-----------------------------\n";
    @file_put_contents($file, $line, FILE_APPEND);
}

/** === Шорткод === */
add_shortcode('santa_video_form', 'svb_render_form');

/** === AJAX === */
add_action('wp_ajax_svb_generate', 'svb_generate');
add_action('wp_ajax_nopriv_svb_generate', 'svb_generate');
add_action('wp_ajax_svb_confirm', 'svb_confirm');
add_action('wp_ajax_nopriv_svb_confirm', 'svb_confirm');
// НОВЫЙ AJAX-ACTION ДЛЯ ПРОВЕРКИ ПРОГРЕССА
add_action('wp_ajax_svb_check_progress', 'svb_check_progress');
add_action('wp_ajax_nopriv_svb_check_progress', 'svb_check_progress');


/** === Крон для удаления === */
add_action('svb_cleanup_job', 'svb_cleanup_job_cb', 10, 1);

function svb_ts_to_seconds($ts) {
    $ts = trim((string)$ts);

    // 1) Явно поддержим ваш формат MM:SS:CC (центисекунды)
    if (preg_match('/^\d{1,2}:\d{2}:\d{2}$/', $ts)) {
        [$mm, $ss, $cc] = array_map('intval', explode(':', $ts));
        return $mm * 60 + $ss + ($cc / 100);
    }
    
    // 2) НОВЫЙ ФОРМАТ: HH:MM:SS.ss (из логов ffmpeg)
    if (preg_match('/^(\d{2}):(\d{2}):(\d{2})\.(\d{2})$/', $ts, $m)) {
        return ((int)$m[1] * 3600) + ((int)$m[2] * 60) + (int)$m[3] + ((int)$m[4] / 100);
    }

    // 3) Стандарт FFmpeg: [-][HH:]MM:SS[.m...]
    if (preg_match('/^-?(?:\d{1,2}:)?\d{1,2}:\d{2}(?:\.\d+)?$/', $ts)) {
        $neg = $ts[0] === '-';
        $ts  = ltrim($ts, '-');
        $p   = explode(':', $ts);
        if (count($p) === 3) {
            [$hh, $mm, $ss] = $p;
            $sec = (int)$hh * 3600 + (int)$mm * 60 + (float)$ss;
        } else {
            [$mm, $ss] = $p;
            $sec = (int)$mm * 60 + (float)$ss;
        }
        return $neg ? -$sec : $sec;
    }

    return 0.0;
}

function svb_exec_find($bin) {
    $path = @shell_exec('command -v '.escapeshellarg($bin).' 2>/dev/null');
    if (!$path) $path = @shell_exec('which '.escapeshellarg($bin).' 2>/dev/null');
    return $path ? trim($path) : '';
}

// Узнать, есть ли конкретный фильтр в текущей сборке ffmpeg
function svb_ff_has_filter($ffmpeg, $name){
    if (!$ffmpeg) return false;
    $cmd = $ffmpeg . ' -hide_banner -v 0 -filters 2>/dev/null';
    $out = @shell_exec($cmd);
    if (!$out) return false;
    // ищем строку вида " ...  V->V fifo ... " или " ...  A->A afifo ..."
    return (bool)preg_match('/\b'.preg_quote($name, '/').'\b/i', $out);
}

function svb_ffprobe_duration($file) {
    $ffprobe = svb_exec_find('ffprobe');
    if (!$ffprobe) return 480.0; // фолбек 8 минут
    $cmd = $ffprobe.' -v error -show_entries format=duration -of default=nw=1:nk=1 '.escapeshellarg($file).' 2>/dev/null';
    $out = @shell_exec($cmd);
    $sec = $out ? (float)$out : 0.0;
    if ($sec <= 0) return 480.0;
    return $sec;
}

// === Санитайз/транскод картинки в «ровный» PNG RGBA ===
function svb_transcode_image_to_png_rgba($ffmpeg, $src, $dst){
    // Гарантируем, что файл станет одиночным кадром PNG RGBA (sRGB) без «экзотики» профилей/битности
    $cmd = $ffmpeg . ' -y -v error -i ' . escapeshellarg($src)
         . ' -frames:v 1 -vf "format=rgba,setsar=1" -f image2 '
         . escapeshellarg($dst) . ' 2>&1';
    @exec($cmd, $o, $rc);
    return ($rc === 0) && file_exists($dst);
}

function svb_apply_manual_round_corners($file, $radiusCssPx, $scalePercent, $targetWidth, $job_dir = '') {
    if ($radiusCssPx <= 0) return true;
    if (!file_exists($file)) return false;

    $info = @getimagesize($file);
    if (!$info) return false;
    [$width, $height] = $info;
    if ($width <= 0 || $height <= 0) return false;

    $scalePercent = max(1, (int)$scalePercent);
    $scaledWidth = max(1, (int)round($targetWidth * ($scalePercent / 100.0)));
    $scaleFactor = $scaledWidth > 0 ? ($width / $scaledWidth) : 1.0;
    $radius = (int)round($radiusCssPx * $scaleFactor);
    $maxRadius = (int)floor((min($width, $height) - 1) / 2);
    if ($maxRadius < 1) $maxRadius = 1;
    $radius = max(1, min($radius, $maxRadius));
    if ($radius <= 0) return true;

    if (class_exists('Imagick')) {
        try {
            $img = new Imagick($file);
            $img->setImageFormat('png');
            $img->setImageAlphaChannel(Imagick::ALPHACHANNEL_SET);
            $img->roundCorners($radius, $radius);
            $img->writeImage($file);
            $img->clear();
            $img->destroy();
            return true;
        } catch (Throwable $e) {
            svb_dbg_write($job_dir, 'warn.imagick_round', $e->getMessage());
        }
    }

    if (!function_exists('imagecreatetruecolor') || !function_exists('imagepng')) {
        return false;
    }

    $img = @imagecreatefrompng($file);
    if (!$img) return false;

    imagealphablending($img, false);
    imagesavealpha($img, true);

    $mask = imagecreatetruecolor($width, $height);
    if (!$mask) {
        imagedestroy($img);
        return false;
    }

    if (function_exists('imageantialias')) imageantialias($mask, true);

    imagealphablending($mask, false);
    imagesavealpha($mask, true);

    $maskTransparent = imagecolorallocatealpha($mask, 0, 0, 0, 127);
    $maskOpaque = imagecolorallocatealpha($mask, 0, 0, 0, 0);

    imagefilledrectangle($mask, 0, 0, $width, $height, $maskTransparent);
    imagefilledrectangle($mask, $radius, 0, $width - $radius, $height, $maskOpaque);
    imagefilledrectangle($mask, 0, $radius, $width, $height - $radius, $maskOpaque);

    $diameter = $radius * 2;
    imagefilledellipse($mask, $radius, $radius, $diameter, $diameter, $maskOpaque);
    imagefilledellipse($mask, $width - $radius - 1, $radius, $diameter, $diameter, $maskOpaque);
    imagefilledellipse($mask, $radius, $height - $radius - 1, $diameter, $diameter, $maskOpaque);
    imagefilledellipse($mask, $width - $radius - 1, $height - $radius - 1, $diameter, $diameter, $maskOpaque);

    $transparentColor = imagecolorallocatealpha($img, 0, 0, 0, 127);
    $cache = [];

    for ($y = 0; $y < $height; $y++) {
        for ($x = 0; $x < $width; $x++) {
            $rgba = imagecolorat($mask, $x, $y);
            $alpha = ($rgba & 0x7F000000) >> 24;
            if ($alpha === 0) continue;
            if ($alpha >= 127) {
                imagesetpixel($img, $x, $y, $transparentColor);
                continue;
            }

            $srcRGBA = imagecolorsforindex($img, imagecolorat($img, $x, $y));
            $key = $srcRGBA['red'] . '_' . $srcRGBA['green'] . '_' . $srcRGBA['blue'] . '_' . $alpha;
            if (!isset($cache[$key])) {
                $cache[$key] = imagecolorallocatealpha($img, $srcRGBA['red'], $srcRGBA['green'], $srcRGBA['blue'], $alpha);
            }
            imagesetpixel($img, $x, $y, $cache[$key]);
        }
    }

    $ok = imagepng($img, $file);

    imagedestroy($img);
    imagedestroy($mask);

    return $ok;
}

function svb_scan_audio_catalog() {
    $out = ['name' => [ 'boy'=>[], 'girl'=>[], 'root'=>[] ]];

    $load_list = function($dir, $url) {
        $items = []; $index = [];
        if (file_exists($dir.'index.json')) {
            $raw = @file_get_contents($dir.'index.json');
            $j = json_decode($raw, true);
            if (is_array($j)) $index = $j; // [{file,label}]
        }
        $files = glob($dir . '*.{mp3,MP3,wav,WAV,m4a,M4A,ogg,OGG}', GLOB_BRACE) ?: [];
        foreach ($files as $f) {
            $file = basename($f);
            $label = pathinfo($file, PATHINFO_FILENAME);
            if ($index) {
                foreach ($index as $row) {
                    if (!empty($row['file']) && $row['file'] === $file && !empty($row['label'])) {
                        $label = $row['label']; break;
                    }
                }
            }
            $items[] = ['file'=>$file, 'url'=>$url.$file, 'label'=>$label];
        }
        return $items;
    };

    $out['name']['boy']  = is_dir(SVB_PLUGIN_DIR.'audio/name/boy/')  ? $load_list(SVB_PLUGIN_DIR.'audio/name/boy/',  SVB_PLUGIN_URL.'audio/name/boy/')  : [];
    $out['name']['girl'] = is_dir(SVB_PLUGIN_DIR.'audio/name/girl/') ? $load_list(SVB_PLUGIN_DIR.'audio/name/girl/', SVB_PLUGIN_URL.'audio/name/girl/') : [];
    $out['name']['root'] = is_dir(SVB_PLUGIN_DIR.'audio/name/')      ? $load_list(SVB_PLUGIN_DIR.'audio/name/',      SVB_PLUGIN_URL.'audio/name/')      : [];

    foreach (['age','facts','hobby','praise','request'] as $cat) {
        $dir = SVB_PLUGIN_DIR."audio/{$cat}/";
        $url = SVB_PLUGIN_URL."audio/{$cat}/";
        $out[$cat] = is_dir($dir) ? $load_list($dir,$url) : [];
    }

    $aliases = ['boy'=>[], 'girl'=>[], 'root'=>[]];
    $aliasFile = SVB_PLUGIN_DIR.'audio/name/aliases.json';
    if (file_exists($aliasFile)) {
        $j = json_decode(@file_get_contents($aliasFile), true);
        if (is_array($j)) {
            $aliases['boy']  = $j['boy']  ?? [];
            $aliases['girl'] = $j['girl'] ?? [];
            $aliases['root'] = $j['root'] ?? [];
        }
    }
    $out['_name_aliases'] = $aliases;

    $ageBucketsFile = SVB_PLUGIN_DIR.'audio/age/buckets.json';
    $out['_age_buckets'] = [];
    if (file_exists($ageBucketsFile)) {
        $j = json_decode(@file_get_contents($ageBucketsFile), true);
        if (is_array($j)) $out['_age_buckets'] = $j;
    }
    return $out;
}

function svb_rrmdir($dir) {
    if (!is_dir($dir)) return;
    $items = @scandir($dir);
    if (!$items) return;
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) svb_rrmdir($path); else @unlink($path);
    }
    @rmdir($dir);
}

function svb_schedule_cleanup($job_dir) {
    if (!wp_next_scheduled('svb_cleanup_job', [$job_dir])) {
        wp_schedule_single_event(time() + HOUR_IN_SECONDS, 'svb_cleanup_job', [$job_dir]);
    }
}

function svb_cleanup_job_cb($job_dir) { svb_rrmdir($job_dir); }


/** === UI (шорткод) === */
function svb_render_form() {
    // === НОВОЕ: Получаем URL шаблона видео ===
    $template_url = SVB_PLUGIN_URL . 'assets/template.mp4';
    // Проверим, есть ли он в uploads
    if (!file_exists(SVB_PLUGIN_DIR . 'assets/template.mp4')) {
        $uploads = wp_upload_dir();
        if (file_exists(trailingslashit($uploads['basedir']) . 'santa-template.mp4')) {
            $template_url = trailingslashit($uploads['baseurl']) . 'santa-template.mp4';
        }
    }
    // === КОНЕЦ НОВОГО ===

    $audio_catalog = svb_scan_audio_catalog();
    $ajax_url = admin_url('admin-ajax.php');
    $nonce = wp_create_nonce('svb_nonce');

    ob_start(); ?>
<style>
/* ... (ВСЕ СТИЛИ ДО .svb-screenlock__spinner) ... */
.svb-wrap { max-width: 980px; margin: 40px auto; padding: 0 16px; }
.svb-card { background:#fff; border-radius:16px; box-shadow:0 10px 30px rgba(0,0,0,.08); padding:28px; }
.svb-header { display:flex; align-items:center; gap:16px; margin-bottom:16px; }
.svb-stepper { display:flex; gap:8px; align-items:center; }
.svb-dot { width:28px; height:28px; border-radius:50%; display:inline-flex; justify-content:center; align-items:center; font-weight:700; }
.svb-dot.active { background:#D62828; color:#fff; }
.svb-dot.muted { background:#f0f0f0; color:#999; }
.svb-title { font-size:28px; font-weight:800; margin:0; }
.svb-actions { display:flex; gap:12px; margin-top:20px; }
.svb-btn { appearance:none; border:none; border-radius:10px; padding:12px 18px; font-weight:700; cursor:pointer; transition:.2s ease; }
.svb-btn.primary { background:#D62828; color:#fff; }
.svb-btn.primary:hover { background:#B81F1F; }
.svb-btn.ghost { background:#f3f3f3; padding: 6px 10px; font-size: 13px; }
.svb-grid { display:grid; gap:16px; }
@media (min-width: 760px){ .svb-grid.cols-2 { grid-template-columns: 1fr 1fr; } }
@media (min-width: 1024px){ .svb-grid.cols-3 { grid-template-columns: repeat(3, 1fr); } }
.svb-field { display:flex; flex-direction:column; gap:8px; }
.svb-label { font-weight:700; font-size:14px; }
.svb-input, .svb-select, .svb-range { border:1px solid #E3E3E3; border-radius:12px; padding:10px 12px; font-size:15px; }
.svb-range { padding: 0; height: 22px; }
.svb-controls label { display: flex; align-items: center; gap: 8px; font-size: 13px; }
.svb-controls .svb-val { font-weight: 700; min-width: 30px; text-align: right; }
.svb-note { color:#666; font-size:12px; }
.svb-audio-row { display:flex; align-items:center; gap:8px; }
.svb-play { border:none; border-radius:50%; width:36px; height:36px; display:flex; align-items:center; justify-content:center; background:#EEE; cursor:pointer; }
.svb-step { display:none; }
.svb-step.active { display:block; }
.svb-photo-grid { display:grid; gap:18px; }
@media (min-width: 760px){ .svb-photo-grid { grid-template-columns: repeat(2, 1fr); } }
.svb-drop { border:2px dashed #E6E6E6; border-radius:16px; padding:14px; display:flex; flex-direction:column; gap:10px; }
.svb-drop .svb-preview { /* (УДАЛЕНО) Этот стиль больше не нужен */ }
.svb-controls { display:grid; gap:8px; grid-template-columns: 1fr 1fr 1fr; }
.svb-result { background:#F8F8F8; border-radius:12px; padding:16px; margin-top:16px; }
.svb-spinner { width:22px; height:22px; border:3px solid #ddd; border-top-color:#D62828; border-radius:50%; animation:spin 1s linear infinite; display:inline-block; vertical-align:middle; margin-right:8px; }
@keyframes spin{ to { transform:rotate(360deg); } }
.svb-suggest { position: relative; }
#svb-name-suggest{
  position: absolute; top: 100%; left: 0; right: 0;
  background: #fff; border: 1px solid #E3E3E3; border-radius: 10px;
  box-shadow: 0 8px 24px rgba(0,0,0,.08);
  z-index: 9999; max-height: 240px; overflow: auto; display: none;
}
.svb-suggest-item{ padding: 10px 12px; cursor: pointer; }
.svb-suggest-item:hover, .svb-suggest-item.active{ background: #F6F6F6; }
.svb-screenlock{
  position: fixed; inset: 0; z-index: 999999;
  background: rgba(255,255,255,.92);
  display: none; align-items: center; justify-content: center; flex-direction: column;
  backdrop-filter: blur(2px);
}

.svb-preview img.svb-draggable { /* (УДАЛЕНО) Этот стиль больше не нужен */ }

/* === НОВЫЕ СТИЛИ ДЛЯ ВИДЕО-ПРЕВЬЮ === */
.svb-vid-preview {
  position: relative;
  aspect-ratio: 1.777; /* 16 / 9 (854 / 480) */
  background: #000;
  border-radius: 12px;
  overflow: hidden;
  width: 100%;
}
.svb-vid-preview video {
  position: absolute;
  top: 0; left: 0;
  width: 100%;
  height: 100%;
  object-fit: contain;
  z-index: 1;
}

.svb-vid-preview img {
  position: absolute;
  top: 0; /* ИСПОЛЬЗУЕМ TOP/LEFT ДЛЯ ПОЗИЦИОНИРОВАНИЯ */
  left: 0; /* ИСПОЛЬЗУЕМ TOP/LEFT ДЛЯ ПОЗИЦИОНИРОВАНИЯ */
  transform-origin: center center;
  z-index: 2; /* Картинка поверх видео */
  will-change: transform, top, left, width, height;
  
  /* === ИСПРАВЛЕНИЕ: Плейсхолдер больше не 100px === */
  /* Он будет 0px, пока JS не задаст ему % ширины */
  width: 0; 
  height: auto;
}
/* Стили плейсхолдера (когда src="" пустой) */
.svb-vid-preview img:not([src]) {
    display: flex;
    align-items: center;
    justify-content: center;
    /* Размеры задаются через JS (style.width) */
    min-height: 50px; /* Минимальная высота, чтобы его было видно */
    background: rgba(255, 255, 255, 0.4);
    backdrop-filter: blur(2px);
    border: 1px dashed #fff;
    color: white;
    font-weight: bold;
    font-size: 14px;
    text-shadow: 0 1px 2px rgba(0,0,0,0.5);
    box-sizing: border-box;
}
.svb-vid-preview img:not([src])::after {
    content: attr(alt); /* Показываем текст из alt="" */
}
/* Скрываем плейсхолдер, когда есть картинка */
.svb-vid-preview img[src] {
    background: transparent;
    backdrop-filter: none;
    color: transparent;
    border: none;
    min-height: 0; /* Сбрасываем мин. высоту */
}
.svb-vid-preview img[src]::after {
    content: '';
}
/* === КОНЕЦ ИСПРАВЛЕНИЯ === */


.svb-vid-controls {
  display: flex;
  gap: 8px;
  align-items: center;
  flex-wrap: wrap;
}
.svb-vid-controls input[type="range"] {
    margin-left: 4px;
    padding: 0;
    width: 80px;
}
.svb-vid-seek-bar-container {
    padding: 8px 0;
}
.svb-seek-bar {
    width: 100%;
    padding: 0 !important;
    margin: 0 !important;
}
#svb-vid-time-child1 {
    font-size: 12px;
    min-width: 70px; /* Место для "00:00 / 08:00" */
}
/* === КОНЕЦ НОВЫХ СТИЛЕЙ === */

.svb-screenlock__spinner{
  width:48px; height:48px; border:5px solid #e1e1e1; border-top-color:#D62828; border-radius:50%; animation: spin 1s linear infinite;
  position: relative;
  display: flex;
  align-items: center;
  justify-content: center;
}
#svb-lock-percent {
  position: absolute;
  font-size: 14px;
  font-weight: 700;
  color: #333;
  animation: none; 
  transform: none;
}
.svb-screenlock__txt{ margin-top:14px; font-weight:800; font-size:18px; color:#333; text-align:center; }

</style>

<div class="svb-wrap">
  <div class="svb-card">
    <div class="svb-header">
      <div class="svb-stepper">
        <span class="svb-dot active" id="svb-dot-1">1</span>
        <span class="svb-dot muted" id="svb-dot-2">2</span>
        <span class="svb-dot muted" id="svb-dot-3">3</span>
      </div>
      <h2 class="svb-title" id="svb-title">Крок 1 — Дані дитини</h2>
    </div>

    <form id="svb-form" enctype="multipart/form-data">
      <input type="hidden" name="_svb_nonce" value="<?php echo esc_attr($nonce); ?>" />

      <section class="svb-step active" data-step="1">
        <div class="svb-grid cols-2">
          <div class="svb-field svb-suggest">
            <label class="svb-label">Імʼя дитини</label>
            <input class="svb-input" type="text" name="name_text" placeholder="Напр.: Андрій" required />
            <div id="svb-name-suggest"></div>
            <span class="svb-note">Імʼя в тексті. Для озвучки обери нижче варіант з каталогу.</span>
          </div>
          
          <div class="svb-field">
            <label class="svb-label">Стать</label>
            <select class="svb-select" name="gender" required>
              <option value="boy">Хлопчик</option>
              <option value="girl">Дівчинка</option>
            </select>
          </div>

          <div class="svb-field">
            <label class="svb-label">Вік</label>
            <input class="svb-input" type="number" min="1" max="17" name="age_value" placeholder="Напр.: 7" required />
            <div class="svb-audio-row">
              <select class="svb-select" name="age_audio" data-cat="age"></select>
              <button class="svb-play" type="button" data-play="age" title="Прослухати">▶</button>
            </div>
          </div>

          <div class="svb-field">
            <label class="svb-label">Озвучка Імені</label>
            <div class="svb-audio-row">
              <select class="svb-select" name="name_audio" data-cat="name"></select>
              <button class="svb-play" type="button" data-play="name" title="Прослухати">▶</button>
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
        </div>

        <div class="svb-actions">
          <button class="svb-btn primary" type="button" id="svb-next-1">Далі</button>
        </div>
      </section>

      <section class="svb-step" data-step="2">
  <div class="svb-photo-grid">
    
    <div class="svb-drop" data-photo="child1">
        <div class="svb-field"><span class="svb-label">Фото дитини 1</span><input class="svb-input" type="file" name="photo_child1" accept="image/*" required></div>
        
        <div class="svb-vid-preview" id="svb-vid-preview-child1">
            <video id="svb-video-child1" src="<?php echo esc_url($template_url); ?>" playsinline loop></video>
            <img id="img-child1" alt="Фото тут" />
        </div>
        
        <div class="svb-vid-seek-bar-container">
            <input type="range" class="svb-range svb-seek-bar" data-vid-ctrl="seek" data-key="child1" min="0" value="0" step="0.1">
        </div>
        <div class="svb-vid-controls">
            <button type="button" class="svb-btn ghost" data-vid-ctrl="play" data-key="child1">► Play</button>
            <button type="button" class="svb-btn ghost" data-vid-ctrl="pause" data-key="child1" style="display:none;">❚❚ Pause</button>
            <div id="svb-vid-time-child1" class="svb-btn ghost">00:00 / 00:00</div> 
            
            <button type="button" class="svb-btn ghost" data-vid-ctrl="mute" data-key="child1">🔇 Mute</button>
            <button type="button" class="svb-btn ghost" data-vid-ctrl="unmute" data-key="child1" style="display:none;">🔈 Unmute</button>
            <input type="range" class="svb-range" data-vid-ctrl="volume" data-key="child1" min="0" max="1" step="0.05" value="0.8">
        </div>

        <div class="svb-note" style="margin-top: 4px;">
           <b>Керування:</b> Фокус на слайдерах. <b>Стрілки</b> (X/Y), <b>+ / -</b> (Scale), <b>[ / ]</b> (Radius), <b>, / .</b> (Angle).
        </div>

        <div class="svb-controls" style="grid-template-columns: 1fr; gap: 12px;">
            <label>X<span class="svb-val" id="val-child1-x">650</span>
                <input class="svb-range svb-key-control" type="range" name="child1_x" value="650" min="-1000" max="2500" step="5" data-val-id="val-child1-x" data-key-up="ArrowRight" data-key-down="ArrowLeft"/>
            </label>
            <label>Y<span class="svb-val" id="val-child1-y">210</span>
                <input class="svb-range svb-key-control" type="range" name="child1_y" value="210" min="-1000" max="2000" step="5" data-val-id="val-child1-y" data-key-up="ArrowDown" data-key-down="ArrowUp"/>
            </label>
            <label>Scale<span class="svb-val" id="val-child1-scale">35</span>%
                <input class="svb-range svb-key-control" type="range" name="child1_scale" value="35" min="10" max="200" step="1" data-val-id="val-child1-scale" data-key-up="=" data-key-down="-"/>
            </label>
            <label>Angle<span class="svb-val" id="val-child1-angle">4</span>°
                <input class="svb-range svb-key-control" type="range" name="child1_angle" value="4" min="-45" max="45" step="1" data-val-id="val-child1-angle" data-key-up="." data-key-down=","/>
            </label>
            <label>Radius<span class="svb-val" id="val-child1-radius">30</span>px
                <input class="svb-range svb-key-control" type="range" name="child1_radius" value="30" min="0" max="200" step="1" data-val-id="val-child1-radius" data-key-up="]" data-key-down="["/>
            </label>
        </div>
        <span class="svb-note">Інтервали: 00:54:20–00:58:25 та 04:18:11–04:21:21</span>
    </div>
    <div class="svb-drop" data-photo="child2">
      <div class="svb-field"><span class="svb-label">Фото дитини 2</span><input class="svb-input" type="file" name="photo_child2" accept="image/*" required></div>

      <div class="svb-vid-preview" id="svb-vid-preview-child2">
        <video id="svb-video-child2" src="<?php echo esc_url($template_url); ?>" playsinline loop></video>
        <img id="img-child2" alt="Фото тут" />
      </div>

      <div class="svb-vid-seek-bar-container">
        <input type="range" class="svb-range svb-seek-bar" data-vid-ctrl="seek" data-key="child2" min="0" value="0" step="0.1">
      </div>
      <div class="svb-vid-controls">
        <button type="button" class="svb-btn ghost" data-vid-ctrl="play" data-key="child2">► Play</button>
        <button type="button" class="svb-btn ghost" data-vid-ctrl="pause" data-key="child2" style="display:none;">❚❚ Pause</button>
        <div id="svb-vid-time-child2" class="svb-btn ghost">00:00 / 00:00</div>

        <button type="button" class="svb-btn ghost" data-vid-ctrl="mute" data-key="child2">🔇 Mute</button>
        <button type="button" class="svb-btn ghost" data-vid-ctrl="unmute" data-key="child2" style="display:none;">🔈 Unmute</button>
        <input type="range" class="svb-range" data-vid-ctrl="volume" data-key="child2" min="0" max="1" step="0.05" value="0.8">
      </div>

      <div class="svb-note" style="margin-top: 4px;">
        <b>Керування:</b> Фокус на слайдерах. <b>Стрілки</b> (X/Y), <b>+ / -</b> (Scale), <b>[ / ]</b> (Radius), <b>, / .</b> (Angle).
      </div>

      <div class="svb-controls" style="grid-template-columns: 1fr; gap: 12px;">
        <label>X<span class="svb-val" id="val-child2-x">1156</span>
          <input class="svb-range svb-key-control" type="range" name="child2_x" value="1156" min="-1000" max="2500" step="5" data-val-id="val-child2-x" data-key-up="ArrowRight" data-key-down="ArrowLeft"/>
        </label>
        <label>Y<span class="svb-val" id="val-child2-y">250</span>
          <input class="svb-range svb-key-control" type="range" name="child2_y" value="250" min="-1000" max="2000" step="5" data-val-id="val-child2-y" data-key-up="ArrowDown" data-key-down="ArrowUp"/>
        </label>
        <label>Scale<span class="svb-val" id="val-child2-scale">33</span>%
          <input class="svb-range svb-key-control" type="range" name="child2_scale" value="33" min="10" max="200" step="1" data-val-id="val-child2-scale" data-key-up="=" data-key-down="-"/>
        </label>
        <label>Angle<span class="svb-val" id="val-child2-angle">10</span>°
          <input class="svb-range svb-key-control" type="range" name="child2_angle" value="10" min="-45" max="45" step="1" data-val-id="val-child2-angle" data-key-up="." data-key-down=","/>
        </label>
        <label>Radius<span class="svb-val" id="val-child2-radius">0</span>px
          <input class="svb-range svb-key-control" type="range" name="child2_radius" value="0" min="0" max="200" step="1" data-val-id="val-child2-radius" data-key-up="]" data-key-down="["/>
        </label>
      </div>
      <span class="svb-note">Інтервали: 02:17:14–02:21:25 та 07:04:23–07:11:13</span>
    </div>
    <div class="svb-drop" data-photo="parent1">
      <div class="svb-field"><span class="svb-label">Фото батька</span><input class="svb-input" type="file" name="photo_parent1" accept="image/*" required></div>

      <div class="svb-vid-preview" id="svb-vid-preview-parent1">
        <video id="svb-video-parent1" src="<?php echo esc_url($template_url); ?>" playsinline loop></video>
        <img id="img-parent1" alt="Фото тут" />
      </div>

      <div class="svb-vid-seek-bar-container">
        <input type="range" class="svb-range svb-seek-bar" data-vid-ctrl="seek" data-key="parent1" min="0" value="0" step="0.1">
      </div>
      <div class="svb-vid-controls">
        <button type="button" class="svb-btn ghost" data-vid-ctrl="play" data-key="parent1">► Play</button>
        <button type="button" class="svb-btn ghost" data-vid-ctrl="pause" data-key="parent1" style="display:none;">❚❚ Pause</button>
        <div id="svb-vid-time-parent1" class="svb-btn ghost">00:00 / 00:00</div>

        <button type="button" class="svb-btn ghost" data-vid-ctrl="mute" data-key="parent1">🔇 Mute</button>
        <button type="button" class="svb-btn ghost" data-vid-ctrl="unmute" data-key="parent1" style="display:none;">🔈 Unmute</button>
        <input type="range" class="svb-range" data-vid-ctrl="volume" data-key="parent1" min="0" max="1" step="0.05" value="0.8">
      </div>

      <div class="svb-note" style="margin-top: 4px;">
        <b>Керування:</b> Фокус на слайдерах. <b>Стрілки</b> (X/Y), <b>+ / -</b> (Scale), <b>[ / ]</b> (Radius), <b>, / .</b> (Angle).
      </div>

      <div class="svb-controls" style="grid-template-columns: 1fr; gap: 12px;">
        <label>X<span class="svb-val" id="val-parent1-x">166</span>
          <input class="svb-range svb-key-control" type="range" name="parent1_x" value="166" min="-1000" max="2500" step="5" data-val-id="val-parent1-x" data-key-up="ArrowRight" data-key-down="ArrowLeft"/>
        </label>
        <label>Y<span class="svb-val" id="val-parent1-y">0</span>
          <input class="svb-range svb-key-control" type="range" name="parent1_y" value="0" min="-1000" max="2000" step="5" data-val-id="val-parent1-y" data-key-up="ArrowDown" data-key-down="ArrowUp"/>
        </label>
        <label>Scale<span class="svb-val" id="val-parent1-scale">75</span>%
          <input class="svb-range svb-key-control" type="range" name="parent1_scale" value="75" min="10" max="200" step="1" data-val-id="val-parent1-scale" data-key-up="=" data-key-down="-"/>
        </label>
        <label>Angle<span class="svb-val" id="val-parent1-angle">0</span>°
          <input class="svb-range svb-key-control" type="range" name="parent1_angle" value="0" min="-45" max="45" step="1" data-val-id="val-parent1-angle" data-key-up="." data-key-down=","/>
        </label>
        <label>Radius<span class="svb-val" id="val-parent1-radius">0</span>px
          <input class="svb-range svb-key-control" type="range" name="parent1_radius" value="0" min="0" max="200" step="1" data-val-id="val-parent1-radius" data-key-up="]" data-key-down="["/>
        </label>
      </div>
      <span class="svb-note">Інтервал: 06:35:03–06:43:13 (разом з фото матері)</span>
    </div>
    <div class="svb-drop" data-photo="parent2">
      <div class="svb-field"><span class="svb-label">Фото матері</span><input class="svb-input" type="file" name="photo_parent2" accept="image/*" required></div>

      <div class="svb-vid-preview" id="svb-vid-preview-parent2">
        <video id="svb-video-parent2" src="<?php echo esc_url($template_url); ?>" playsinline loop></video>
        <img id="img-parent2" alt="Фото тут" />
      </div>

      <div class="svb-vid-seek-bar-container">
        <input type="range" class="svb-range svb-seek-bar" data-vid-ctrl="seek" data-key="parent2" min="0" value="0" step="0.1">
      </div>
      <div class="svb-vid-controls">
        <button type="button" class="svb-btn ghost" data-vid-ctrl="play" data-key="parent2">► Play</button>
        <button type="button" class="svb-btn ghost" data-vid-ctrl="pause" data-key="parent2" style="display:none;">❚❚ Pause</button>
        <div id="svb-vid-time-parent2" class="svb-btn ghost">00:00 / 00:00</div>

        <button type="button" class="svb-btn ghost" data-vid-ctrl="mute" data-key="parent2">🔇 Mute</button>
        <button type="button" class="svb-btn ghost" data-vid-ctrl="unmute" data-key="parent2" style="display:none;">🔈 Unmute</button>
        <input type="range" class="svb-range" data-vid-ctrl="volume" data-key="parent2" min="0" max="1" step="0.05" value="0.8">
      </div>

      <div class="svb-note" style="margin-top: 4px;">
        <b>Керування:</b> Фокус на слайдерах. <b>Стрілки</b> (X/Y), <b>+ / -</b> (Scale), <b>[ / ]</b> (Radius), <b>, / .</b> (Angle).
      </div>

      <div class="svb-controls" style="grid-template-columns: 1fr; gap: 12px;">
        <label>X<span class="svb-val" id="val-parent2-x">166</span>
          <input class="svb-range svb-key-control" type="range" name="parent2_x" value="166" min="-1000" max="2500" step="5" data-val-id="val-parent2-x" data-key-up="ArrowRight" data-key-down="ArrowLeft"/>
        </label>
        <label>Y<span class="svb-val" id="val-parent2-y">0</span>
          <input class="svb-range svb-key-control" type="range" name="parent2_y" value="0" min="-1000" max="2000" step="5" data-val-id="val-parent2-y" data-key-up="ArrowDown" data-key-down="ArrowUp"/>
        </label>
        <label>Scale<span class="svb-val" id="val-parent2-scale">75</span>%
          <input class="svb-range svb-key-control" type="range" name="parent2_scale" value="75" min="10" max="200" step="1" data-val-id="val-parent2-scale" data-key-up="=" data-key-down="-"/>
        </label>
        <label>Angle<span class="svb-val" id="val-parent2-angle">0</span>°
          <input class="svb-range svb-key-control" type="range" name="parent2_angle" value="0" min="-45" max="45" step="1" data-val-id="val-parent2-angle" data-key-up="." data-key-down=","/>
        </label>
        <label>Radius<span class="svb-val" id="val-parent2-radius">0</span>px
          <input class="svb-range svb-key-control" type="range" name="parent2_radius" value="0" min="0" max="200" step="1" data-val-id="val-parent2-radius" data-key-up="]" data-key-down="["/>
        </label>
      </div>
      <span class="svb-note">Інтервал: 06:35:03–06:43:13 (разом з фото батька)</span>
    </div>
  </div>

  <div class="svb-actions">
    <button class="svb-btn ghost" type="button" id="svb-back-2">Назад</button>
    <button class="svb-btn primary" type="button" id="svb-next-2">Далі</button>
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
    <span id="svb-lock-percent">0%</span> </div>
  <div class="svb-screenlock__txt" id="svb-lock-text">Формуємо відео… будь ласка, не закривайте сторінку</div>
</div>

<script>
const SVB_AUDIO = <?php echo wp_json_encode($audio_catalog, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); ?>;
const SVB_AJAX  = {
    url: <?php echo wp_json_encode($ajax_url); ?>,
    nonce: <?php echo wp_json_encode($nonce); ?>,
    video_template: <?php echo wp_json_encode($template_url); ?>
};

const $  = (sel,root=document) => root.querySelector(sel);
const $$ = (sel,root=document) => Array.from(root.querySelectorAll(sel));

let svbCurrentSampleAudio = null;

function svbFormatTime(seconds) {
    const min = Math.floor(seconds / 60);
    const sec = Math.floor(seconds % 60);
    return `${min.toString().padStart(2, '0')}:${sec.toString().padStart(2, '0')}`;
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
function svbPopulateSelects(){
  $$('select[data-cat]').forEach(sel=>{
    const cat = sel.getAttribute('data-cat');
    if (cat === 'name') return;
    const items = SVB_AUDIO[cat] || [];
    sel.innerHTML = items.length
      ? items.map(i=>`<option value="${i.file}">${i.label||i.file}</option>`).join('')
      : '<option value="">— немає файлів —</option>';
  });
  updateNameOptions();
  const gsel = document.querySelector('select[name="gender"]');
  if (gsel && !gsel.__svb_bound) {
    gsel.addEventListener('change', ()=> { updateNameOptions(); autoBindNameAudio(); });
    gsel.__svb_bound = true;
  }
  autoBindAgeAudio();
}
function getNameOptionsByGender(){
  const g = (document.querySelector('select[name="gender"]').value || 'boy');
  if (SVB_AUDIO.name && SVB_AUDIO.name[g] && SVB_AUDIO.name[g].length) return SVB_AUDIO.name[g];
  if (SVB_AUDIO.name && SVB_AUDIO.name.root) return SVB_AUDIO.name.root;
  return [];
}
function updateNameOptions(){
  const sel = document.querySelector('select[name="name_audio"]');
  if (!sel) return;
  const items = getNameOptionsByGender();
  sel.innerHTML = items.length
    ? items.map(i=>`<option value="${i.file}">${i.label||i.file}</option>`).join('')
    : '<option value="">— немає файлів —</option>';
}

function svbBindAudioPreview(){
  $$('.svb-play').forEach(btn=>{
    btn.addEventListener('click', ()=>{
      const cat = btn.getAttribute('data-play');
      const sel = document.querySelector(`select[name="${cat}_audio"]`);
      if(!sel) return;
      const file = sel.value;
      let items = (cat === 'name') ? getNameOptionsByGender() : (SVB_AUDIO[cat]||[]);
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

function svbBindPhotoInputs(){
 ['child1', 'child2', 'parent1', 'parent2'].forEach(key => {
   svbMarkTouched(key);
   const input = document.querySelector(`input[name="photo_${key}"]`);
   if(!input) return;

   input.addEventListener('change', e=>{
     const f = e.target.files && e.target.files[0];
     if(!f) return;

     const url = URL.createObjectURL(f);
     const img = document.getElementById('img-' + key);

     if (img && img.src) {
        URL.revokeObjectURL(img.src);
     }

     if (!img) return;

     img.onload = ()=>{
       svbUpdatePreviewTransform(key);
     };
     img.src = url;
   });
 });

 ['child1', 'child2', 'parent1', 'parent2'].forEach(key => {
  ['x','y','scale','angle','radius'].forEach(k=>{
    const ctrl = document.querySelector(`input[name="${key}_${k}"]`);
    if(ctrl){
      ctrl.addEventListener('input', (e)=> {
        const valId = e.target.dataset.valId;
         if(valId) {
           const valEl = document.getElementById(valId);
           if(valEl) valEl.textContent = e.target.value;
         }
         svbUpdatePreviewTransform(key);
       });
     }
   });
 });
}

function svbUpdatePreviewTransform(key){
 const img = document.getElementById('img-' + key);
 const previewBox = document.getElementById('svb-vid-preview-' + key);

 if(!img || !previewBox) return;

 const x_raw = parseFloat(document.querySelector(`input[name="${key}_x"]`)?.value||0);
 const y_raw = parseFloat(document.querySelector(`input[name="${key}_y"]`)?.value||0);
 const s_raw = parseFloat(document.querySelector(`input[name="${key}_scale"]`)?.value||100);
 const a = parseFloat(document.querySelector(`input[name="${key}_angle"]`)?.value||0);
 const radius = parseFloat(document.querySelector(`input[name="${key}_radius"]`)?.value||0);

 const original_w = 1920;
 const original_h = 1080;
  const target_w = 854;
  const target_h = 480;

  const previewWidth = previewBox.clientWidth || previewBox.offsetWidth || target_w;
  const previewHeight = previewBox.clientHeight || previewBox.offsetHeight || (previewWidth * (target_h / target_w));

  const scaleX = previewWidth / target_w;
  const scaleY = previewHeight / target_h;

  const baseX = (x_raw / original_w) * target_w;
  const baseY = (y_raw / original_h) * target_h;

  img.style.left = `${baseX * scaleX}px`;
  img.style.top = `${baseY * scaleY}px`;

  const safeScale = Math.max(10, s_raw);
  const widthVideo = target_w * (safeScale / 100);
  const naturalW = img.naturalWidth || target_w;
  const naturalH = img.naturalHeight || target_h;
  const aspect = (naturalW > 0 && naturalH > 0) ? (naturalH / naturalW) : (target_h / target_w);
  const heightVideo = widthVideo * aspect;

  img.style.width = `${widthVideo * scaleX}px`;
  img.style.height = `${heightVideo * scaleY}px`;
  img.style.transformOrigin = 'center center';
  img.style.transform = `rotate(${a}deg)`;

  if (!isNaN(radius) && radius > 0) {
    const radiusPx = radius * scaleX;
    img.style.borderRadius = `${radiusPx}px`;
    img.style.overflow = 'hidden';
  } else {
    img.style.borderRadius = '0px';
    img.style.overflow = '';
  }
}

function svbCollectOverlayData() {
  const keys = ['child1', 'child2', 'parent1', 'parent2'];
  const payload = {};
  keys.forEach(key => {
    const pick = (suffix, fallback = 0) => {
      const el = document.querySelector(`input[name="${key}_${suffix}"]`);
      if (!el) return fallback;
      const num = parseFloat(el.value);
      return Number.isFinite(num) ? num : fallback;
    };
    payload[key] = {
      x: pick('x'),
      y: pick('y'),
      scale: pick('scale', 100),
      angle: pick('angle'),
      radius: pick('radius')
    };
  });
  return payload;
}


// ... (Функции autoBindNameAudio, autoBindAgeAudio, buildSoundMap - БЕЗ ИЗМЕНЕНИЙ) ...
const svbNorm = s => (s||'').toString().toLowerCase().trim().replace(/[\s_\-’']/g,'');
function autoBindNameAudio(){
  const nameInput = document.querySelector('input[name="name_text"]');
  const gsel = document.querySelector('select[name="gender"]');
  const sel = document.querySelector('select[name="name_audio"]');
  if (!nameInput || !gsel || !sel) return;
  const gender = gsel.value || 'boy';
  const nameKey = svbNorm(nameInput.value || '');
  const AL = (SVB_AUDIO._name_aliases && SVB_AUDIO._name_aliases[gender]) ? SVB_AUDIO._name_aliases[gender] : {};
  const ALroot = (SVB_AUDIO._name_aliases && SVB_AUDIO._name_aliases.root) ? SVB_AUDIO._name_aliases.root : {};
  let matchFile = AL[nameKey] || ALroot[nameKey] || null;
  const opts = getNameOptionsByGender();
  if (!matchFile && opts.length) {
    let found = opts.find(o => svbNorm(o.label) === nameKey)
            ||   opts.find(o => svbNorm(o.file.replace(/\.[^.]+$/,'')) === nameKey)
            ||   opts.find(o => svbNorm(o.label).startsWith(nameKey))
            ||   opts.find(o => svbNorm(o.label).includes(nameKey));
    if (found) matchFile = found.file;
  }
  if (matchFile) {
    const has = Array.from(sel.options).some(op => op.value === matchFile);
    if (!has) updateNameOptions();
    if (Array.from(sel.options).some(op => op.value === matchFile)) sel.value = matchFile;
  }
  buildSoundMap();
}
function autoBindAgeAudio() {
  const ageInput = document.querySelector('input[name="age_value"]');
  const sel = document.querySelector('select[name="age_audio"]');
  if (!ageInput || !sel) return;
  const age = parseInt(ageInput.value, 10);
  if (isNaN(age)) return;
  const opts = SVB_AUDIO.age || [];
  let match = null;
  const baseName = f => f.toLowerCase().split('.').slice(0,-1).join('.');
  match = opts.find(o => baseName(o.file) === String(age));
  if (!match) {
    match = opts.find(o => {
      const b = baseName(o.file);
      if (b.startsWith('age')) {
        const n = parseInt(b.slice(3), 10);
        return n === age;
      }
      return false;
    });
  }
  if (!match && Array.isArray(SVB_AUDIO._age_buckets)) {
    for (const bkt of SVB_AUDIO._age_buckets) {
      const parts = String(bkt.range||'').split('-');
      if (parts.length === 2) {
        const a = parseInt(parts[0].trim(), 10);
        const z = parseInt(parts[1].trim(), 10);
        if (!isNaN(a) && !isNaN(z) && age>=a && age<=z) { match = opts.find(o => o.file === bkt.file); if (match) break; }
      }
    }
  }
  if (match) sel.value = match.file;
  buildSoundMap();
}
let SVB_SELECTED = {};
function buildSoundMap(){
  const pull = (cat) => {
    let items = (cat === 'name') ? getNameOptionsByGender() : (SVB_AUDIO[cat] || []);
    const sel = document.querySelector(`select[name="${cat}_audio"]`);
    if (!sel) return null;
    const file = sel.value;
    const it = items.find(i => i.file === file);
    return it ? { file: it.file, url: it.url, label: it.label || it.file } : null;
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

// ... (Обработчики кнопок, svbStartGenerate, svbPollProgress, svbHandleSuccess, svbHandleError - БЕЗ ИЗМЕНЕНИЙ) ...
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
async function svbStartGenerate(){
  if(svbGenerating) return;
  svbGenerating = true;
  svbLock(true);
  $('#svb-lock-percent').textContent = '0%';
  $('#svb-lock-text').textContent = 'Запускаємо процес...';
  $('#svb-status').textContent = 'Запускаємо процес...';
  const form = document.getElementById('svb-form');
  const fd = new FormData(form);
  try {
    fd.append('overlay_json', JSON.stringify(svbCollectOverlayData()));
  } catch (jsonErr) {
    console.error('overlay_json encode failed', jsonErr);
  }
  fd.append('action', 'svb_generate');
  try {
    const response = await fetch(SVB_AJAX.url, { method:'POST', body:fd });
    const data = await response.json();
    if (data.success && data.data.token) {
      svbJobToken = data.data.token;
      $('#svb-status').textContent = 'Генерація почалася...';
      $('#svb-lock-text').textContent = 'Формуємо відео…';
      svbPollProgress(svbJobToken);
    } else {
      svbHandleError(data.data || {msg: 'Не вдалося запустити завдання на сервері.'});
    }
  } catch (err) {
    svbHandleError({msg: 'Помилка мережі або конфігурації сервера.', log: err.message});
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
    const msg = (data && data.msg) || 'Unknown error';
    const cmd = (data && data.cmd) || '';
    const log = (data && data.log) || '';
    const hint= (data && data.hint) || '';
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

// ... (Функции подсказок для имени - БЕЗ ИЗМЕНЕНИЙ) ...
const _svbNorm = s => (s||'').toString().toLowerCase().trim().replace(/[\s_\-’']/g,'');
function svbNameCandidates(){
  const g = (document.querySelector('select[name="gender"]')?.value || 'boy');
  if (SVB_AUDIO.name && SVB_AUDIO.name[g] && SVB_AUDIO.name[g].length) return SVB_AUDIO.name[g];
  if (SVB_AUDIO.name && SVB_AUDIO.name.root) return SVB_AUDIO.name.root;
  return [];
}
function svbBuildNameSuggest(q=''){
  const box = document.getElementById('svb-name-suggest');
  if(!box) return;
  const list = svbNameCandidates();
  const normQ = _svbNorm(q);
  const items = list.filter(i => !normQ || _svbNorm(i.label||i.file).includes(normQ)).slice(0, 8);
  box.innerHTML = items.map(i =>
    `<div class="svb-suggest-item" data-file="${i.file}" data-label="${i.label||i.file}">${i.label||i.file}</div>`
  ).join('');
  box.style.display = items.length ? 'block' : 'none';
}
function svbBindNameSuggest(){
  const input = document.querySelector('input[name="name_text"]');
  const box   = document.getElementById('svb-name-suggest');
  const sel   = document.querySelector('select[name="name_audio"]');
  if(!input || !box || !sel) return;
  input.addEventListener('input',  e => svbBuildNameSuggest(e.target.value));
  input.addEventListener('focus',  e => svbBuildNameSuggest(e.target.value));
  box.addEventListener('click', e=>{
    const item = e.target.closest('.svb-suggest-item');
    if(!item) return;
    input.value = item.dataset.label;
    updateNameOptions();
    sel.value = item.dataset.file;
    box.style.display = 'none';
    buildSoundMap && buildSoundMap();
  });
  document.addEventListener('click', e=>{
    if (!e.target.closest('.svb-suggest') && !e.target.closest('#svb-name-suggest')) {
      box.style.display = 'none';
    }
  });
  document.querySelector('select[name="gender"]').addEventListener('change', ()=>{
    svbBuildNameSuggest(input.value);
  });
}

// ... (svbMarkTouched - БЕЗ ИЗМЕНЕНИЙ) ...
function svbMarkTouched(key){
 ['x','y','scale','angle','radius'].forEach(k=>{
 const el = document.querySelector(`input[name="${key}_${k}"]`);
 if (el && !el.__svb_bound) {
 el.addEventListener('input', ()=> el.dataset.touched = '1');
 el.__svb_bound = true;
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
            timeEl.textContent = `${svbFormatTime(0)} / ${svbFormatTime(totalDuration)}`;
        });

        vid.addEventListener('timeupdate', () => {
            const currentTime = vid.currentTime;
            if (!seekSlider.matches(':active')) {
                seekSlider.value = currentTime;
            }
            timeEl.textContent = `${svbFormatTime(currentTime)} / ${svbFormatTime(totalDuration)}`;
        });

        seekSlider.addEventListener('input', (e) => {
            vid.currentTime = parseFloat(e.target.value) || 0;
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

        const controls = {};
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


// === ЗАПУСК ===
svbPopulateSelects();
svbBindAudioPreview();
svbBindPhotoInputs(); 
svbBindNameSuggest();
svbBuildNameSuggest(document.querySelector('input[name="name_text"]')?.value || '');
document.querySelector('select[name="gender"]').addEventListener('change', autoBindNameAudio);
document.querySelector('input[name="name_text"]').addEventListener('input', autoBindNameAudio);
document.querySelector('input[name="age_value"]').addEventListener('input', autoBindAgeAudio);

// Обновляем позицию при загрузке
['child1', 'child2', 'parent1', 'parent2'].forEach(key => {
  if (document.getElementById('img-' + key)) {
    svbUpdatePreviewTransform(key);
  }
});

// Активируем управление плеером и клавиатурой
svbBindRealtimeControls();

</script>
<?php
    return ob_get_clean();
}

/** === Генерация видео (AJAX) === */
function svb_generate() {
    // --- (Вся подготовка до $tplDur остается такой же) ---
    if (!isset($_POST['_svb_nonce']) || !wp_verify_nonce($_POST['_svb_nonce'], 'svb_nonce')) {
        wp_send_json_error('bad nonce');
    }
    $uploads = wp_upload_dir();
    if (!empty($uploads['error'])) wp_send_json_error('uploads not writable');
    $job = 'svb_' . wp_generate_password(8,false,false);
    $job_dir = trailingslashit($uploads['basedir']) . 'svb-jobs/' . $job;
    $job_url = trailingslashit($uploads['baseurl']) . 'svb-jobs/' . $job;
    if (!wp_mkdir_p($job_dir)) wp_send_json_error('cannot create job dir');
    $template = SVB_PLUGIN_DIR . 'assets/template.mp4';
    if (!file_exists($template)) {
        $tpl2 = trailingslashit($uploads['basedir']) . 'santa-template.mp4';
        if (file_exists($tpl2)) $template = $tpl2;
    }
    if (!file_exists($template)) wp_send_json_error('template.mp4 not found');
    $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
    if (in_array('exec', $disabled, true)) {
        wp_send_json_error(['msg'=>'exec() disabled by php.ini']);
    }
    $ffmpeg  = svb_exec_find('ffmpeg'); if (!$ffmpeg) $ffmpeg = '/opt/homebrew/bin/ffmpeg';
    $ffprobe = svb_exec_find('ffprobe'); if (!$ffprobe) $ffprobe = '/opt/homebrew/bin/ffprobe';
    $HAS_FIFO     = svb_ff_has_filter($ffmpeg, 'fifo');
    $HAS_AFIFO    = svb_ff_has_filter($ffmpeg, 'afifo');
    $HAS_ROUNDED  = svb_ff_has_filter($ffmpeg, 'roundedcorners');
    svb_dbg_write($job_dir, 'env.ffmpeg_version', @shell_exec($ffmpeg.' -hide_banner -version 2>&1'));
    // --- (Сохранение фото - без изменений) ---
    $photos = [];
    $photo_meta = [];
    $photo_keys = ['child1','child2','parent1','parent2'];
    foreach ($photo_keys as $pk) {
        $field = 'photo_' . $pk;
        if (!empty($_FILES[$field]['name']) && $_FILES[$field]['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES[$field]['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['png','jpg','jpeg','webp'])) $ext = 'jpg';
            $tmp = $job_dir . '/' . $field . '.' . $ext;
            if (!@move_uploaded_file($_FILES[$field]['tmp_name'], $tmp)) {
                wp_send_json_error('cannot save photo ' . $field);
            }
            $destPng = $job_dir . '/' . $field . '.png';
            if (svb_transcode_image_to_png_rgba($ffmpeg, $tmp, $destPng)) {
                @unlink($tmp);
                $photos[$pk] = $destPng;
            } else {
                $photos[$pk] = $tmp;
            }

            if (!empty($photos[$pk])) {
                $meta = @getimagesize($photos[$pk]);
                if ($meta && isset($meta[0], $meta[1])) {
                    $photo_meta[$pk] = ['w' => (int)$meta[0], 'h' => (int)$meta[1]];
                }
            }
        }
    }
    
    // === ИСПРАВЛЕНИЕ: Добавляем 'angle' и 'radius' в массив $pos ===
    $pos = [];
    foreach ($photo_keys as $pk) {
        $pos[$pk] = [
            'x' => isset($_POST[$pk . '_x']) ? intval($_POST[$pk . '_x']) : 0,
            'y' => isset($_POST[$pk . '_y']) ? intval($_POST[$pk . '_y']) : 0,
            's' => isset($_POST[$pk . '_scale']) ? max(10, intval($_POST[$pk . '_scale'])) : 100,
            'angle' => isset($_POST[$pk . '_angle']) ? floatval($_POST[$pk . '_angle']) : 0.0,
            'radius' => isset($_POST[$pk . '_radius']) ? intval($_POST[$pk . '_radius']) : 0,
        ];
    }

    if (!empty($_POST['overlay_json'])) {
        $overlay_raw = wp_unslash($_POST['overlay_json']);
        $overlay_decoded = json_decode($overlay_raw, true);
        if (is_array($overlay_decoded)) {
            foreach ($photo_keys as $pk) {
                if (empty($overlay_decoded[$pk]) || !is_array($overlay_decoded[$pk])) continue;
                $record = $overlay_decoded[$pk];
                if (isset($record['x']) && is_numeric($record['x'])) {
                    $pos[$pk]['x'] = (int)round($record['x']);
                }
                if (isset($record['y']) && is_numeric($record['y'])) {
                    $pos[$pk]['y'] = (int)round($record['y']);
                }
                if (isset($record['scale']) && is_numeric($record['scale'])) {
                    $pos[$pk]['s'] = max(10, (int)round($record['scale']));
                }
                if (isset($record['angle']) && is_numeric($record['angle'])) {
                    $pos[$pk]['angle'] = (float)$record['angle'];
                }
                if (isset($record['radius']) && is_numeric($record['radius'])) {
                    $pos[$pk]['radius'] = max(0, (int)round($record['radius']));
                }
            }
        }
    }
    svb_dbg_write($job_dir, 'req.overlay', $pos);
    // === КОНЕЦ ИСПРАВЛЕНИЯ ===

    $original_w = 1920; // Исходная ширина, от которой считаем X
    $original_h = 1080; // Исходная высота, от которой считаем Y
    $target_w   = 854;  // Новая ширина (480p)
    $target_h   = 480;  // Новая высота (480p)

    if (!$HAS_ROUNDED) {
        svb_dbg_write($job_dir, 'info.round_fallback', 'roundedcorners filter missing, applying manual mask');
        foreach ($photo_keys as $pk) {
            if (empty($photos[$pk])) continue;
            $radius = $pos[$pk]['radius'] ?? 0;
            if ($radius <= 0) continue;
            $scalePercent = min(200, max(10, $pos[$pk]['s'] ?? 100));
            if (!svb_apply_manual_round_corners($photos[$pk], $radius, $scalePercent, $target_w, $job_dir)) {
                svb_dbg_write($job_dir, 'warn.round_fallback', "Manual corner radius failed for {$pk}");
            }
        }
    }

    $scale_factor_x = $target_w / $original_w;
    $scale_factor_y = $target_h / $original_h;

    $audio_cats = ['name','age','facts','hobby','praise','request'];
    $audio_sel  = [];
    foreach($audio_cats as $cat){
        $key = $cat . '_audio';
        $fn = isset($_POST[$key]) ? sanitize_file_name($_POST[$key]) : '';
        if ($fn) {
            if ($cat === 'name') {
                $gender = isset($_POST['gender']) ? sanitize_text_field($_POST['gender']) : 'boy';
                $sub = in_array($gender, ['boy','girl'], true) ? $gender : 'boy';
                $path = SVB_PLUGIN_DIR . 'audio/name/' . $sub . '/' . $fn;
                if (!file_exists($path)) $path = SVB_PLUGIN_DIR . 'audio/name/' . $fn;
            } else {
                $path = SVB_PLUGIN_DIR . 'audio/' . $cat . '/' . $fn;
            }
            if (file_exists($path)) $audio_sel[$cat] = $path;
        }
    }
    // --- (Тайминги - без изменений) ---
    $A_NAME   = [ ['00:34:15','00:35:15'], ['01:42:18','01:43:18'], ['03:29:15','03:30:15'], ['05:50:19','05:51:19'] ];
    $A_AGE    = [ ['03:37:16','03:38:16'] ];
    $A_FACTS  = [ ['02:25:16','02:28:27'] ];
    $A_HOBBY  = [ ['02:32:00','02:36:27'] ];
    $A_PRAISE = [ ['05:54:10','05:57:15'] ];
    $A_REQUEST= [ ['06:19:04','06:22:27'] ];
    $P_CHILD1 = [ ['00:54:20','00:58:25'], ['04:18:11','04:21:21'] ];
    $P_CHILD2 = [ ['02:17:14','02:21:25'], ['07:04:23','07:11:13'] ];
    $P_PARENTS= [ ['06:35:03','06:43:13'] ];
    
    // === НОВОЕ: ПОЛУЧАЕМ ДЛИТЕЛЬНОСТЬ ЗДЕСЬ, ДО ЗАПУСКА ===
    $tplDur = svb_ffprobe_duration($template);
    
    // --- (Сборка входов - без изменений) ---
    $inputs = [];
    $inputs[] = '-threads 1 -i ' . escapeshellarg($template);
    $imgIndexMap = [];
    foreach ($photos as $k => $png) {
        $inputs[] = '-loop 1 -framerate 1 -i ' . escapeshellarg($png);
        $imgIndexMap[$k] = count($inputs) - 1;
    }
    $audIndexMap = [];
    foreach ($audio_sel as $cat => $path) {
        $inputs[] = '-i ' . escapeshellarg($path);
        $audIndexMap[$cat] = count($inputs) - 1;
    }

    /* === FILTER COMPLEX === */
    
    $filter = [];
    $filter[] = "[0:v]fps=30,format=yuv420p,setsar=1,setpts=PTS-STARTPTS[vbase]";

    $vlabel = "[vbase]";
    $vcount = 0;
    $even = static function($n){ $n = (int)$n; return ($n & 1) ? $n - 1 : $n; };

    $addOverlay = function($key, $intervals) use (&$filter, &$vlabel, &$vcount, $imgIndexMap, $pos, $HAS_FIFO, $HAS_ROUNDED, $even, $scale_factor_x, $scale_factor_y, $target_w, $target_h, $photo_meta, $job_dir){
        if (!isset($imgIndexMap[$key])) return;
        $idx = $imgIndexMap[$key];
        // === ИСПРАВЛЕНИЕ: Добавляем defaults для angle и radius ===
        $p = $pos[$key] ?? ['x'=>0,'y'=>0,'s'=>100, 'angle'=>0, 'radius'=>0];

        // Позиция (X/Y) - в пикселях на целевом видео (854x480)
        $x_base = (float)($p['x'] * $scale_factor_x);
        $y_base = (float)($p['y'] * $scale_factor_y);

        // Логика Масштаба (Scale)
        $scale_percent = max(10, min(200, (int)($p['s'] ?? 100)));
        $sx_perc = $scale_percent / 100.0; // e.g., 0.35
        $scW = max(2, $even((int)round($target_w * $sx_perc)));

        $meta = $photo_meta[$key] ?? null;
        $src_w = ($meta['w'] ?? $target_w) ?: $target_w;
        $src_h = ($meta['h'] ?? $target_h) ?: $target_h;
        if ($src_w <= 0) $src_w = $target_w;
        if ($src_h <= 0) $src_h = $target_h;

        $scale_ratio = $scW / $src_w;
        $scH_val = $src_h * $scale_ratio;
        $scH = max(2, $even((int)round($scH_val)));

        // === ИСПРАВЛЕНИЕ: Получаем angle и radius из $p ===
        $angle_degrees = max(-180.0, min(180.0, (float)($p['angle'] ?? 0.0)));
        $radius = max(0, (int)($p['radius'] ?? 0));

        // === ИСПРАВЛЕНИЕ (Угол): теперь используем тот же знак, что и CSS-превью ===
        $angle_radians = $angle_degrees * (M_PI / 180);

        $chain = "[{$idx}:v]setpts=PTS-STARTPTS,format=rgba";

        $chain .= ",scale=w={$scW}:h={$scH}";

        // Сохраняем реальный размер после scale для коррекции offset'ов
        $scaled_w = $scW;
        $scaled_h = $scH;

        $rot_diag = sqrt(($scaled_w * $scaled_w) + ($scaled_h * $scaled_h));
        $pad_mod = 16;
        $pad_side = (int)ceil($rot_diag / $pad_mod) * $pad_mod;
        if ($pad_side < $pad_mod) {
            $pad_side = $pad_mod;
        }

        $offset_x = ($pad_side - $scaled_w) / 2.0;
        $offset_y = ($pad_side - $scaled_h) / 2.0;

        $x = $even((int)round($x_base - $offset_x));
        $y = $even((int)round($y_base - $offset_y));

        svb_dbg_write($job_dir, 'calc.overlay.' . $key, [
            'input' => $p,
            'scaled_w' => $scaled_w,
            'scaled_h' => $scaled_h,
            'pad_side' => $pad_side,
            'offset_x' => $offset_x,
            'offset_y' => $offset_y,
            'x_base' => $x_base,
            'y_base' => $y_base,
            'final_x' => $x,
            'final_y' => $y,
        ]);

        // === ИСПРАВЛЕНИЕ (Радиус): Добавляем фильтр roundedcorners при наличии ===
        // (Примечание: этот фильтр может отсутствовать в старых версиях FFmpeg)
        if ($HAS_ROUNDED && $radius > 0) {
            // Мы масштабировали ширину до $scW, который равен $target_w * $sx_perc
            // CSS применяет 'px' радиус до scale.
            // FFmpeg применяет 'px' радиус после scale.
            // Нам нужно масштабировать радиус.
            
            // $css_scale = $p['s'] / 100.0 (e.g. 0.35)
            // $ffmpeg_scale = $scW / [original_image_width] (неизвестно)
            
            // Простой подход: CSS применяет `30px` к оригинальному изображению.
            // Наш JS scale - это % от ширины видео.
            // `img.style.borderRadius = '30px'`
            // Это 30px на ОРИГИНАЛЬНОЙ картинке.
            
            // Давайте пересчитаем:
            // CSS: `style.borderRadius = '30px'` - это 30px на превью.
            // $radius = 30
            // Нам нужно 30px на 480p видео.
            
            // $scW - это ширина картинки в пикселях (e.g. 854 * 0.35 = 298px)
            // $radius = 30
            // Попробуем просто применить $radius
            $chain .= ",roundedcorners=radius={$radius}";
        }
        // === КОНЕЦ ИСПРАВЛЕНИЯ ===

        if ($angle_radians != 0) { 
            $chain .= ",rotate={$angle_radians}:ow='hypot(iw,ih)':oh='hypot(iw,ih)':c=none"; 
        }
        
        $mod = 16; $modW = "ceil(iw/{$mod})*{$mod}"; $modH = "ceil(ih/{$mod})*{$mod}";
        $chain .= ",pad=w={$modW}:h={$modH}:x=(ow-iw)/2:y=(oh-ih)/2:color=black@0";

        $chain .= ",format=yuva420p"; 
        
        if ($HAS_FIFO) $chain .= ",fifo"; 

        $chain .= "[{$key}s{$vcount}]"; 
        
        $filter[] = $chain;

        // enable по таймингам
        $expr = [];
        foreach ($intervals as $it){ $expr[] = "between(t,".svb_ts_to_seconds($it[0]).",".svb_ts_to_seconds($it[1]).")"; }
        $enable = implode('+', $expr);

        // сам overlay
        $filter[] = "{$vlabel}[{$key}s{$vcount}]overlay=x={$x}:y={$y}:enable='{$enable}'[v{$vcount}]";
        $vlabel = "[v{$vcount}]";
        $vcount++;
    };

    $addOverlay('child1', $P_CHILD1); $addOverlay('child2', $P_CHILD2);
    $addOverlay('parent1', $P_PARENTS); $addOverlay('parent2', $P_PARENTS);
    $finalV = $vlabel;
    
    // --- Аудио (Без изменений) ---
    $audio_format_chain = ",aformat=sample_fmts=fltp:sample_rates=22050:channel_layouts=mono,aresample=async=1:first_pts=0";

    $makeAudioBlocks = function($cat, $intervals) use (&$filter, &$amix_inputs, $audIndexMap, $HAS_AFIFO, $tplDur, $audio_format_chain){
        if (!isset($audIndexMap[$cat]) || empty($intervals)) return;
        $idx = $audIndexMap[$cat];
                
        if (count($intervals) === 1) {
            [$stS, $enS] = $intervals[0]; $st = svb_ts_to_seconds($stS); $en = svb_ts_to_seconds($enS);
            $dur = max(0.1, $en - $st); $ms = (int)round($st * 1000); $label = "[{$cat}a1]";
            $chain  = "[{$idx}:a]atrim=0:{$dur},asetpts=PTS-STARTPTS";
            $chain .= $audio_format_chain; 
            $chain .= ",adelay={$ms}:all=1,atrim=0:{$tplDur}";
            if ($HAS_AFIFO) $chain .= ",afifo"; $chain .= "{$label}";
            $filter[] = $chain; $amix_inputs[] = $label; return;
        }
        $outs = []; for ($i=1; $i<=count($intervals); $i++) $outs[] = "[{$cat}s{$i}]";
        $filter[] = "[{$idx}:a]asplit=" . count($intervals) . implode('', $outs);
        for ($i=1; $i<=count($intervals); $i++){
            [$stS, $enS] = $intervals[$i-1]; $st = svb_ts_to_seconds($stS); $en = svb_ts_to_seconds($enS);
            $dur = max(0.1, $en - $st); $ms = (int)round($st * 1000); $label = "[{$cat}a{$i}]";
            $chain  = "{$outs[$i-1]}atrim=0:{$dur},asetpts=PTS-STARTPTS";
            $chain .= $audio_format_chain; 
            $chain .= ",adelay={$ms}:all=1";
            if ($HAS_AFIFO) $chain .= ",afifo"; $chain .= "{$label}";
            $filter[] = $chain; $amix_inputs[] = $label;
        }
    };
    
    $filter[] = '[0:a]aformat=sample_fmts=fltp:sample_rates=22050:channel_layouts=mono,aresample=async=1:first_pts=0[abase]';
    $amix_inputs = ['[abase]']; 

    $makeAudioBlocks('name',   $A_NAME); $makeAudioBlocks('age',    $A_AGE); $makeAudioBlocks('facts',  $A_FACTS);
    $makeAudioBlocks('hobby',  $A_HOBBY); $makeAudioBlocks('praise', $A_PRAISE); $makeAudioBlocks('request',$A_REQUEST);

    if (count($amix_inputs) <= 1) {
        $filter[] = '[abase]asplit[aout]'; 
    } else {
        $chain  = implode('', $amix_inputs) . 'amix=inputs=' . count($amix_inputs) . ':duration=longest:dropout_transition=0';
        if ($HAS_AFIFO) $chain .= ',afifo'; 
        $chain .= '[aout]';
        $filter[] = $chain;
    }

    $filter_complex = implode(';', $filter);
    $output = $job_dir . '/video.mp4';
    
    // --- Команда (Без изменений) ---
    $env_report = 'FFREPORT=file='.escapeshellarg($job_dir.'/ffreport.log').':level=32';
    $cmd = $env_report.' '.$ffmpeg
    . ' -nostdin -y -hide_banner'
    . ' -loglevel level+info' 
    . ' -probesize 50M -analyzeduration 50M'
    . ' -filter_complex_threads 8' 
    . ' -fflags +genpts -avoid_negative_ts make_zero'
    . ' ' . implode(' ', $inputs)
    . ' -filter_complex ' . escapeshellarg($filter_complex)
    . ' -map ' . escapeshellarg($finalV)
    . ' -map ' . escapeshellarg('[aout]')
    . ' -map_metadata -1 -map_chapters -1'
    . ' -c:v libx264 -preset ultrafast -crf 30 -pix_fmt yuv420p -threads 0'
    . ' -c:a aac -b:a 64k -ar 22050 -ac 1'
    . ' -max_muxing_queue_size 8192'
    . ' -muxdelay 0 -muxpreload 0'
    . ' -movflags +faststart'
    . ' -t ' . escapeshellarg((string)$tplDur) 
    . ' ' . escapeshellarg($output);
    
    
    // --- Запуск в фоне (Без изменений) ---
    $logFile = $job_dir . '/ffmpeg.log';
    $pidFile = $job_dir . '/ffmpeg.pid';

    set_transient('svb_job_data_'.$job, [
        'tplDur' => $tplDur,
        'logFile' => $logFile,
        'output' => $output,
        'job_dir' => $job_dir,
        'url' => $job_url . '/video.mp4',
        'pidFile' => $pidFile
    ], HOUR_IN_SECONDS * 2); 

    $cmd_bg = $cmd . ' > ' . escapeshellarg($logFile) . ' 2>&1 & echo $!;';
    svb_dbg_write($job_dir, 'final.cmd_bg', $cmd_bg);
    
    $pid = @exec($cmd_bg, $pid_out, $rc_pid);
    
    if ($pid && is_numeric($pid)) {
        @file_put_contents($pidFile, $pid);
        svb_dbg_write($job_dir, 'final.pid', $pid);
    } else {
        svb_dbg_write($job_dir, 'final.pid.error', $pid_out);
        wp_send_json_error(['msg'=>'Could not start background process.', 'log' => implode("\n", (array)$pid_out)]);
    }

    wp_send_json_success([ 'token' => $job ]);
}


/**
 * === НОВАЯ ФУНКЦИЯ: ПРОВЕРКА ПРОГРЕССА (AJAX) ===
 */
function svb_check_progress() {
    if (!isset($_POST['_svb_nonce']) || !wp_verify_nonce($_POST['_svb_nonce'], 'svb_nonce')) {
        wp_send_json_error('bad nonce');
    }
    $token = isset($_POST['token']) ? sanitize_text_field($_POST['token']) : '';
    if (!$token) wp_send_json_error('no token');

    $data = get_transient('svb_job_data_'.$token);
    if (!$data || empty($data['logFile']) || empty($data['tplDur'])) {
        wp_send_json_error(['status' => 'error', 'msg' => 'Job data expired or invalid.']);
    }

    $logFile = $data['logFile'];
    $outputFile = $data['output'];
    $tplDur = (float)$data['tplDur'];
    $pidFile = $data['pidFile'];
    $pid = file_exists($pidFile) ? trim(@file_get_contents($pidFile)) : null;

    // 1. Проверяем, жив ли процесс
    $is_running = false;
    if ($pid && is_numeric($pid)) {
        // 'ps -p PID' вернет что-то, если процесс жив
        $check = @exec('ps -p '.escapeshellarg($pid));
        if ($check && strpos($check, (string)$pid) !== false) {
            $is_running = true;
        }
    }

    // 2. Если процесс жив, читаем лог и отдаем %
    if ($is_running) {
        if (!file_exists($logFile)) {
            wp_send_json_success(['status' => 'running', 'percent' => 0]); // Еще не начал писать
        }
        
        // Читаем последние ~20 строк лога, чтобы найти 'time='
        $log_content = @shell_exec('tail -n 20 ' . escapeshellarg($logFile));
        
        $percent = 0;
        
        // Ищем последнюю строку с 'time='
        // (frame=... time=00:01:30.12 ...)
        if (preg_match_all('/time=(\d{2}:\d{2}:\d{2}\.\d{2})/', $log_content, $matches)) {
            $last_time_str = end($matches[1]); // Берем последнее совпадение
            $current_sec = svb_ts_to_seconds($last_time_str);
            
            if ($tplDur > 0) {
                $percent = min(99, floor(($current_sec / $tplDur) * 100));
            }
        }
        wp_send_json_success(['status' => 'running', 'percent' => $percent]);
    }

    // 3. Процесс мертв. Проверяем результат.
    if (file_exists($outputFile) && filesize($outputFile) > 1000) {
        // Успех!
        svb_schedule_cleanup($data['job_dir']); // Теперь крон здесь
        set_transient('svb_job_'.$token, [ 'dir'=>$data['job_dir'], 'url'=>$data['url'] ], HOUR_IN_SECONDS); // Старый трансиент для email
        delete_transient('svb_job_data_'.$token); // Чистим data-трансиент
        @unlink($pidFile);
        @unlink($logFile);
        
        wp_send_json_success(['status' => 'done', 'url' => $data['url']]);
    } else {
        // Ошибка!
        $log_content = @file_get_contents($logFile);
        svb_dbg_write($data['job_dir'], 'final.error.log', $log_content);
        wp_send_json_error([
            'status' => 'error',
            'msg' => 'FFmpeg process failed or output file is invalid.',
            'log' => $log_content
        ]);
    }
}


/** === Подтверждение и выдача ссылки === */
function svb_confirm(){
    if (!isset($_POST['_svb_nonce']) || !wp_verify_nonce($_POST['_svb_nonce'], 'svb_nonce')) {
        wp_send_json_error('bad nonce');
    }
    $email = isset($_POST['email']) ? sanitize_email($_POST['email']) : '';
    $token = isset($_POST['token']) ? sanitize_text_field($_POST['token']) : '';
    if (!$token) wp_send_json_error('no token');
    
    // ВНИМАНИЕ: теперь ищем старый трансиент, который создается в svb_check_progress
    $data = get_transient('svb_job_'.$token); 
    
    if (!$data || empty($data['url'])) {
        // Проверяем, может, он еще в обработке?
        $data_progress = get_transient('svb_job_data_'.$token);
        if ($data_progress) {
             wp_send_json_error('Video is still processing.');
        }
        wp_send_json_error('Video not found or expired.');
    }

    if ($email) {
        $subject = 'Ваше персональне відео від Санти';
        $message = 'Дякуємо! Ваше відео готове: ' . $data['url'] . "\nПосилання дійсне протягом 1 години.";
        @wp_mail($email, $subject, $message);
    }

    wp_send_json_success([ 'url'=>$data['url'] ]);
}