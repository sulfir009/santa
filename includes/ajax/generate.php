<?php
if (!defined('ABSPATH')) {
    exit;
}

function svb_generate() {

    if (!isset($_POST['_svb_nonce']) || !wp_verify_nonce($_POST['_svb_nonce'], 'svb_nonce')) {
        wp_send_json_error('bad nonce');
    }

    // 1. Читаем и санитизируем выбранный ID
    $selected_video_id = isset($_POST['selected_video_id'])
        ? sanitize_text_field( wp_unslash( $_POST['selected_video_id'] ) )
        : 'video1';

    // 2. Карта ID → путь к файлу
    $backend_video_templates = array(
        'video1' => SVB_PLUGIN_DIR . 'assets/template1.mp4',
        'video2' => SVB_PLUGIN_DIR . 'assets/template2.mp4',
        'video3' => SVB_PLUGIN_DIR . 'assets/template3.mp4',
        'video4' => SVB_PLUGIN_DIR . 'assets/template4.mp4',
    );

    if (
        empty($backend_video_templates[$selected_video_id]) ||
        !file_exists($backend_video_templates[$selected_video_id])
    ) {
        $selected_video_id = 'video1';
    }

    $template_video_path = $backend_video_templates[$selected_video_id];

    // 3. Создаём job-dir ДО логирования
    $uploads = wp_upload_dir();
    if (!empty($uploads['error'])) {
        wp_send_json_error('uploads not writable');
    }

    $job     = 'svb_' . wp_generate_password(8, false, false);
    $job_dir = trailingslashit($uploads['basedir']) . 'svb-jobs/' . $job;
    $job_url = trailingslashit($uploads['baseurl']) . 'svb-jobs/' . $job;

    if (!wp_mkdir_p($job_dir)) {
        wp_send_json_error('cannot create job dir');
    }

    // 4. Логируем выбор шаблона уже с валидным $job_dir
    svb_dbg_write($job_dir, 'generate.video_selection', array(
        'selected_id' => $selected_video_id,
        'video_path'  => $template_video_path,
        'exists'      => file_exists($template_video_path),
        'post'        => $_POST,
    ));

    // 5. Используем выбранное видео как основу, но с fallback на старую логику
    $template = $template_video_path;

    if (!file_exists($template)) {
        // fallback на старый template.mp4, если вдруг файла нет
        $template = SVB_PLUGIN_DIR . 'assets/template.mp4';

        if (!file_exists($template)) {
            $tpl2 = trailingslashit($uploads['basedir']) . 'santa-template.mp4';
            if (file_exists($tpl2)) {
                $template = $tpl2;
            }
        }
    }

    if (!file_exists($template)) {
        wp_send_json_error('template.mp4 not found (selected: '.$selected_video_id.')');
    }

    // 6. Дальше всё как было, но duration считаем уже от $template
    $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
    if (in_array('exec', $disabled, true)) {
        wp_send_json_error(array('msg' => 'exec() disabled by php.ini'));
    }

    $ffmpeg  = svb_exec_find('ffmpeg');  if (!$ffmpeg)  $ffmpeg  = '/opt/homebrew/bin/ffmpeg';
    $ffprobe = svb_exec_find('ffprobe'); if (!$ffprobe) $ffprobe = '/opt/homebrew/bin/ffprobe';

    $tplDur = svb_ffprobe_duration($template);

$HAS_FIFO        = svb_ff_has_filter($ffmpeg, 'fifo');
$HAS_AFIFO       = svb_ff_has_filter($ffmpeg, 'afifo');
$HAS_ZOOMPAN   = svb_ff_has_filter($ffmpeg, 'zoompan');
$HAS_BOXBLUR   = svb_ff_has_filter($ffmpeg, 'boxblur');
$HAS_FADE      = svb_ff_has_filter($ffmpeg, 'fade');
$HAS_ROUNDED     = svb_ff_has_filter($ffmpeg, 'roundedcorners');
$HAS_SHEAR     = svb_ff_has_filter($ffmpeg, 'shear'); 
$HAS_PERSPECTIVE = svb_ff_has_filter($ffmpeg, 'perspective');
$HAS_COLORCH   = svb_ff_has_filter($ffmpeg, 'colorchannelmixer');
$HAS_ALPHAEXTRACT = svb_ff_has_filter($ffmpeg, 'alphaextract');
$HAS_ALPHAMERGE   = svb_ff_has_filter($ffmpeg, 'alphamerge');
$HAS_BLEND        = svb_ff_has_filter($ffmpeg, 'blend');


    svb_dbg_write($job_dir, 'env.ffmpeg_version', @shell_exec($ffmpeg.' -hide_banner -version 2>&1'));
    // --- (Сохранение фото - без изменений) ---
    $photos = [];
    $photo_meta = [];
    $photo_keys = ['child1','child2','parent1','parent2','extra', 'extra2'];
    foreach ($photo_keys as $pk) {
        $field = 'photo_' . $pk;
        if (!empty($_FILES[$field]['name']) && $_FILES[$field]['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES[$field]['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['png','jpg','jpeg','webp'])) $ext = 'jpg';
            $base = $job_dir . '/' . $field;
            $tmp = $base . '_orig.' . $ext;
            if (!@move_uploaded_file($_FILES[$field]['tmp_name'], $tmp)) {
                wp_send_json_error('cannot save photo ' . $field);
            }
            $destPng = $base . '.png';
            if (svb_transcode_image_to_png_rgba($ffmpeg, $tmp, $destPng, 709, $job_dir)) {
                if ($tmp !== $destPng && file_exists($tmp)) {
                    @unlink($tmp);
                }
                $photos[$pk] = $destPng;
            } elseif (file_exists($destPng)) {
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

    foreach (['child1','child2','parent1','parent2'] as $required_pk) {
        if (empty($photos[$required_pk]) || !file_exists($photos[$required_pk])) {
            svb_dbg_write($job_dir, 'error.photo_missing.' . $required_pk, 'required photo missing after preprocessing');
            wp_send_json_error([
                'msg' => sprintf(__('Не вдалося обробити %s. Спробуйте ще раз завантажити фото.', 'svb'), $required_pk),
                'code' => 'photo_missing',
                'photo' => $required_pk,
            ]);
        }
    }
    
        // === НОВЫЙ КОНТРАКТ: геометрия приходит ТОЛЬКО из overlay_json ===
// === Геометрия из overlay_json (новый контракт) ===
$pos = [];
foreach ($photo_keys as $pk) {
    $pos[$pk] = [];
}

if (!empty($_POST['overlay_json'])) {
    $overlay_decoded = json_decode(stripslashes($_POST['overlay_json']), true);

    if (is_array($overlay_decoded)) {
        foreach ($photo_keys as $pk) {
            if (!isset($pos[$pk])) {
                $pos[$pk] = [];
            }

            $rec = $overlay_decoded[$pk] ?? null;
            if (!is_array($rec)) {
                continue;
            }

            // Управляющие параметры (в градусах/процентах)
            foreach ([
                's'      => 'scale',
                'sy'     => 'scaleY',
                'skew'   => 'skew',
                'skew_y' => 'skewY',
                'angle'  => 'angle',
                'radius' => 'radius',
            ] as $k => $src) {
                if (isset($rec[$src]) && is_numeric($rec[$src])) {
                    $val = (float)$rec[$src];
                    if ($src === 'radius') {
                        $pos[$pk][$k] = max(0, (int)round($val));
                    } else {
                        $pos[$pk][$k] = $val;
                    }
                }
            }

            // нормализованный центр bbox — главный источник правды
            if (isset($rec['cx_norm'])) {
                $pos[$pk]['cx_norm'] = max(0.0, min(1.0, (float)$rec['cx_norm']));
            }
            if (isset($rec['cy_norm'])) {
                $pos[$pk]['cy_norm'] = max(0.0, min(1.0, (float)$rec['cy_norm']));
            }

            // старые поля — оставляем для совместимости/логов
            if (isset($rec['x_norm'])) {
                $pos[$pk]['x_norm'] = max(0.0, min(1.0, (float)$rec['x_norm']));
            }
            if (isset($rec['y_norm'])) {
                $pos[$pk]['y_norm'] = max(0.0, min(1.0, (float)$rec['y_norm']));
            }
            if (isset($rec['w_pred'])) {
                $pos[$pk]['w_pred'] = (int)$rec['w_pred'];
            }
            if (isset($rec['h_pred'])) {
                $pos[$pk]['h_pred'] = (int)$rec['h_pred'];
            }

            // сырые x/y в пикселях нам больше не нужны
            unset($pos[$pk]['x'], $pos[$pk]['y']);
        }
    }
}

svb_dbg_write($job_dir, 'req.overlay', $pos);


    // === КОНЕЦ ИСПРАВЛЕНИЯ ===

    $original_w = 1920; // Исходная ширина, от которой считаем X
    $original_h = 1080; // Исходная высота, от которой считаем Y
    $target_w   = 854;  // Новая ширина (480p)
    $target_h   = 480;  // Новая высота (480p)

svb_align_log($job_dir, 'env.start', [
  'tpl'    => ['w'=>$original_w,'h'=>$original_h],
  'target' => ['w'=>$target_w,'h'=>$target_h],
  'tpl_duration_sec' => $tplDur,
  'ffmpeg_bin' => $ffmpeg,
  'ffmpeg_version' => trim(@shell_exec($ffmpeg.' -hide_banner -version 2>&1')),
]);
// Всю «форму» и мягкие края считаем заранее по PNG,
// чтобы не зависеть от фильтров ffmpeg
svb_dbg_write($job_dir, 'info.round_pre', 'apply round+feather directly to PNG for all photos');

foreach ($photo_keys as $pk) {
    if (empty($photos[$pk]) || !isset($pos[$pk])) {
        continue;
    }

    $r        = isset($pos[$pk]['radius']) ? (int)$pos[$pk]['radius'] : 0;
    $scalePct = isset($pos[$pk]['s'])      ? (int)$pos[$pk]['s']      : 100;
    $glowPct  = isset($pos[$pk]['glow'])   ? (float)$pos[$pk]['glow'] : 0.0;

    // Если ни радиуса, ни «світлих країв» — пропускаем
    if ($r <= 0 && $glowPct <= 0) {
        continue;
    }

    svb_apply_manual_round_corners(
        $photos[$pk],
        $r,          // радиус слайдера (CSS пиксели превью)
        $scalePct,   // общий scale для более точного пересчёта
        $target_w,   // ширина видео (854)
        $job_dir,
        $glowPct     // «Світлі краї» 0–100
    );

    $pos[$pk]['radius'] = 0;
    $pos[$pk]['glow']   = 0;
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
// === ТАЙМИНГИ ОЗВУЧЕК ДЛЯ КАЖДОГО ВИДЕО ===
$audio_timings = [
    'video1' => [
        'name'    => [ ['00:34:15','00:35:15'], ['01:42:18','01:43:18'], ['03:29:15','03:30:15'], ['05:50:19','05:51:19'] ],
        'age'     => [ ['03:37:16','03:38:16'] ],
        'facts'   => [ ['02:25:16','02:28:27'] ],
        'hobby'   => [ ['02:32:00','02:36:27'] ],
        'praise'  => [ ['05:54:10','05:57:15'] ],
        'request' => [ ['06:19:04','06:22:27'] ],
    ],
    'video2' => [
        'name'    => [ ['00:15:29','00:16:29'], ['00:49:17','00:50:17'], ['04:02:03','04:03:03'], ['06:52:23','06:53:23'] ],
        'age'     => [ ['01:28:22','01:29:22'] ],
        'facts'   => [ ['01:37:28','01:38:28'] ],
        'hobby'   => [ ['01:41:22','01:42:22'] ],
        'praise'  => [ ['04:05:27','04:06:27'] ],
        'request' => [ ['04:23:17','04:24:17'] ],
    ],
    'video3' => [
        'name'    => [ ['00:53:00','00:54:00'], ['01:44:28','01:45:28'], ['04:32:16','04:33:16'], ['04:57:04','04:58:04'], ['05:45:27','05:46:27'] ],
        'age'     => [ ['01:33:20','01:34:20'] ],
        'facts'   => [ ['01:51:13','01:52:13'] ],
        'hobby'   => [ ['01:55:01','01:56:01'] ],
        'praise'  => [ ['08:52:05','08:53:05'] ],
        'request' => [ ['05:05:23','05:06:23'] ],
    ],
    'video4' => [
        'name'    => [ ['00:55:04','00:56:04'], ['01:36:27','01:37:27'], ['04:47:29','04:48:29'], ['06:17:25','06:18:25'], ['08:25:26','08:26:26'] ],
        'age'     => [ ['06:21:02','06:22:02'] ],
        'facts'   => [ ['03:18:05','03:19:05'] ],
        'hobby'   => [ ['03:25:04','03:26:04'] ],
        'praise'  => [ ['07:26:00','07:27:00'] ],
        'request' => [ ['06:34:28','06:35:28'] ],
    ],
];

// ✅ Получаем тайминги озвучек для ВЫБРАННОГО видео
$timings_for_video = isset($audio_timings[$selected_video_id]) 
    ? $audio_timings[$selected_video_id] 
    : $audio_timings['video1'];

// ✅ Теперь используем привязанные тайминги
$A_NAME    = $timings_for_video['name'];
$A_AGE     = $timings_for_video['age'];
$A_FACTS   = $timings_for_video['facts'];
$A_HOBBY   = $timings_for_video['hobby'];
$A_PRAISE  = $timings_for_video['praise'];
$A_REQUEST = $timings_for_video['request'];


    // --- Интервалы фото: дефолт + то, что пришло с фронта ---
    $default_segments = [
        'child1'  => [ ['00:54:20','00:58:25'] ],
        'child2'  => [ ['02:17:14','02:21:25'] ],
        'parents' => [ ['06:35:03','06:43:13'] ],
        'extra'   => [ ['07:06:00','07:11:13'] ],
        'extra2'  => [ ['04:18:11','04:21:21'] ],
    ];

    $user_segments = [];
    if ( ! empty( $_POST['svb_segments'] ) ) {
        $raw     = wp_unslash( $_POST['svb_segments'] );
        $decoded = json_decode( $raw, true );
        if ( is_array( $decoded ) ) {
            $user_segments = $decoded;
        }
    }

    // фронт перекрывает дефолты
    $segments  = array_merge( $default_segments, $user_segments );

    $P_CHILD1  = $segments['child1'];
    $P_CHILD2  = $segments['child2'];
    $P_PARENTS = $segments['parents'];
    $P_EXTRA   = $segments['extra'];
    $P_EXTRA2  = $segments['extra2'];

    // пишем интервалы в JSON-лог, чтобы смотреть, что прилетело с фронта
    svb_align_log( $job_dir, 'segments.from_front', $segments );

    // === НОВОЕ: ПОЛУЧАЕМ ДЛИТЕЛЬНОСТЬ ЗДЕСЬ, ДО ЗАПУСКА ===
    $tplDur = svb_ffprobe_duration($template);


    
    // --- (Сборка входов - без изменений) ---
    $inputs = [];
    $inputs[] = '-i ' . escapeshellarg($template);
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
    
/* === FILTER COMPLEX === */

// База: видео шаблона в 480p, 30fps, rgba
$filter = [];
$filter[] = "[0:v]fps=30,setsar=1,scale={$target_w}:{$target_h},setpts=PTS-STARTPTS[vbase_tmp]";
$filter[] = "[vbase_tmp]format=rgba[vbase]";

$vlabel = "[vbase]";
$vcount = 0;
$SOURCE_SQUARE = 709; // квадратный PNG после препроцессинга

$addOverlay = function($key, $intervals) use (
    &$filter, &$vlabel, &$vcount,
    $imgIndexMap, $pos,
    $HAS_FIFO, $HAS_ROUNDED, $HAS_SHEAR,
    $target_w, $target_h, $job_dir, $SOURCE_SQUARE
) {
    if (!isset($imgIndexMap[$key])) {
        svb_dbg_write($job_dir, "overlay.{$key}.skip", 'no image index for this key');
        return;
    }

    $idx = $imgIndexMap[$key];
    $p   = $pos[$key] ?? [];

    // 1. Центр оверлея (от фронта)
    $cx_norm = isset($p['cx_norm']) ? (float)$p['cx_norm'] : 0.5;
    $cy_norm = isset($p['cy_norm']) ? (float)$p['cy_norm'] : 0.5;
    $cx_norm = max(0.0, min(1.0, $cx_norm));
    $cy_norm = max(0.0, min(1.0, $cy_norm));

    $cx = $cx_norm * $target_w;
    $cy = $cy_norm * $target_h;

    // 2. Масштаб / угол / радиус
    $scaleX = max(10, min(200, (int)round($p['s']  ?? 100))) / 100.0;
    $scaleY = max(10, min(200, (int)round($p['sy'] ?? 100))) / 100.0;

    $angle_deg = (float)($p['angle'] ?? 0.0);
    $radius    = isset($p['radius']) ? (int)$p['radius'] : 0;

    // 3. Skew в ГРАДУСАХ (из overlay_json)
    $skewX_deg = isset($p['skew'])   ? (float)$p['skew']   : 0.0;
    $skewY_deg = isset($p['skew_y']) ? (float)$p['skew_y'] : 0.0;

    // Базовый прямоугольник до skew/rotate
    $w_src = max(2, (int)round($target_w * $scaleX));
    $h_src = max(2, (int)round($w_src     * $scaleY));

    $need_shear = $HAS_SHEAR && (abs($skewX_deg) > 0.001 || abs($skewY_deg) > 0.001);

    $pad_x = $pad_y = 0;
    $w_padded = $w_src;
    $h_padded = $h_src;
    $shx = 0.0;
    $shy = 0.0;

    if ($need_shear) {
        $skewX_rad = $skewX_deg * M_PI / 180.0;
        $skewY_rad = $skewY_deg * M_PI / 180.0;

        // Максимальный сдвиг краёв из-за skew → под него делаем pad
        $maxShiftX = abs(tan($skewX_rad)) * $h_src; // по X из-за skewX
        $maxShiftY = abs(tan($skewY_rad)) * $w_src; // по Y из-за skewY
        $pad_margin = (int)ceil(max($maxShiftX, $maxShiftY));
        if ($pad_margin < 0) {
            $pad_margin = 0;
        }

        $pad_x = $pad_y = $pad_margin;

        $w_padded = $w_src + 2 * $pad_x;
        $h_padded = $h_src + 2 * $pad_y;

        if ($w_padded < 2) $w_padded = 2;
        if ($h_padded < 2) $h_padded = 2;

        // shear-факторы ffmpeg из углов, с ограничением -2..2
        $shx = -tan($skewX_rad);
        $shy =  tan($skewY_rad);

        $shx = max(-2.0, min(2.0, $shx));
        $shy = max(-2.0, min(2.0, $shy));
    }

    // угол в радианах для rotate
    $angle_rad = $angle_deg * M_PI / 180.0;
    $angle_str = rtrim(rtrim(sprintf('%.15F', $angle_rad), '0'), '.');

    $tmpOut   = "{$key}s{$vcount}_tmp";
    $finalOut = "{$key}s{$vcount}";

    // 4. Цепочка фильтров для этой фотки
    $chain  = "[{$idx}:v]setpts=PTS-STARTPTS,format=rgba";
    $chain .= ",scale=w={$w_src}:h={$h_src}";

    if ($need_shear) {
        // сначала расширяем, потом shear — чтобы не обрезало картинку
        $chain .= ",pad=w={$w_padded}:h={$h_padded}"
                . ":x={$pad_x}:y={$pad_y}:color=black@0";
        $chain .= ",shear=shx={$shx}:shy={$shy}:fillcolor=black@0";
    }

    // Поворот вокруг центра
    $chain .= ",rotate={$angle_str}:ow=rotw(iw):oh=roth(ih):c=none";

    if ($radius > 0 && $HAS_ROUNDED) {
        $chain .= ",roundedcorners=radius={$radius}:fillcolor=none";
    }

    $filter[] = $chain . "[{$tmpOut}]";

    // fifo + format=rgba перед overlay для устранения мигания
    $filter[] = "[{$tmpOut}]format=rgba" . ($HAS_FIFO ? ",fifo" : "") . "[{$finalOut}]";

    // 5. Центрируем overlay: x = cx - w/2, y = cy - h/2
    $xExpr = sprintf('%.6F - w/2', $cx);
    $yExpr = sprintf('%.6F - h/2', $cy);

    $exprParts = [];
    foreach ($intervals as $it) {
        $exprParts[] = "between(t," . svb_ts_to_seconds($it[0]) . "," . svb_ts_to_seconds($it[1]) . ")";
    }
    $enable = implode('+', $exprParts);

    $filter[] = "{$vlabel}[{$finalOut}]overlay="
              . "x={$xExpr}:y={$yExpr}:enable='{$enable}'[vtmp{$vcount}]";
    $filter[] = "[vtmp{$vcount}]format=rgba[v{$vcount}]";

    $vlabel = "[v{$vcount}]";
    $vcount++;

    // Лог для сверки
    svb_align_log($job_dir, "overlay.calc.{$key}", [
        'video_space' => ['W' => $target_w, 'H' => $target_h],
        'inputs'      => $p,
        'derived'     => [
            'cx_norm'   => $cx_norm,
            'cy_norm'   => $cy_norm,
            'cx'        => $cx,
            'cy'        => $cy,
            'w_src'     => $w_src,
            'h_src'     => $h_src,
            'w_padded'  => $w_padded,
            'h_padded'  => $h_padded,
            'skewX_deg' => $skewX_deg,
            'skewY_deg' => $skewY_deg,
            'shx'       => $shx,
            'shy'       => $shy,
            'angle_deg' => $angle_deg,
            'angle_rad' => $angle_rad,
        ],
    ]);
};





$addOverlay('child1',  $P_CHILD1);
$addOverlay('child2',  $P_CHILD2);
$addOverlay('parent1', $P_PARENTS);
$addOverlay('parent2', $P_PARENTS);
$addOverlay('extra2',  $P_EXTRA2); // новая сцена 04:18
$addOverlay('extra',   $P_EXTRA);  // финальная сцена

// один-единственный переход в 420p — прямо перед выходом
$filter[] = "{$vlabel}format=yuv420p[vfinal]";
$finalV = '[vfinal]';

    
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

set_transient('svb_job_data_' . $job, [
    'job_dir'  => $job_dir,
    'logFile'  => $logFile,
    'output'   => $output,
    'pidFile'  => $pidFile,
    'tplDur'   => $tplDur,
    'job_url'  => $job_url,
], HOUR_IN_SECONDS);

// Потім йде код запуску FFmpeg:
$cmd_bg = $cmd . ' > ' . escapeshellarg($logFile) . ' 2>&1 & echo $!'; 
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
