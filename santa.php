

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
/** === Единый JSON-лог для сверки браузер/ffmpeg (по строке JSON на событие) === */
function svb_align_log_open($job_dir){
    if (!$job_dir) return '';
    $p = rtrim($job_dir, '/').'/svb_align.jsonl';
    if (!file_exists($p)) { @file_put_contents($p, ""); }
    return $p;
}
/** Запись JSON-строки (JSONL) */
function svb_align_log($job_dir, $event, $payload){
    if (!$job_dir) return;
    $file = svb_align_log_open($job_dir);
    if (!$file) return;
    $row = [
        'ts'    => date('c'),
        'event' => (string)$event,
        'data'  => $payload
    ];
    @file_put_contents($file, json_encode($row, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n", FILE_APPEND);
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
add_action('wp_ajax_svb_dbg_push', 'svb_dbg_push');
add_action('wp_ajax_nopriv_svb_dbg_push', 'svb_dbg_push');



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
function svb_transcode_image_to_png_rgba($ffmpeg, $src, $dst, $cropSize = 709, $job_dir = ''){
    $filters = 'format=rgba,setsar=1';
    if ($cropSize > 0) {
        $filters .= ',scale=' . $cropSize . ':' . $cropSize . ':force_original_aspect_ratio=increase';
        $filters .= ',crop=' . $cropSize . ':' . $cropSize;
    }

    $cmd = $ffmpeg . ' -y -v error -i ' . escapeshellarg($src)
         . ' -frames:v 1 -vf "' . $filters . '" -f image2 '
         . escapeshellarg($dst) . ' 2>&1';
    @exec($cmd, $o, $rc);
    if ($rc === 0 && file_exists($dst)) {
        return true;
    }

    svb_dbg_write($job_dir, 'warn.ffmpeg_transcode', [
        'src' => $src,
        'dst' => $dst,
        'rc'  => $rc,
        'out' => isset($o) ? implode("\n", $o) : '',
    ]);

    if (class_exists('Imagick')) {
        try {
            $img = new Imagick($src);
            $img->setImageFormat('png');
            $img->setImageAlphaChannel(Imagick::ALPHACHANNEL_SET);
            if ($cropSize > 0) {
                $img->setImageGravity(Imagick::GRAVITY_CENTER);
                $img->cropThumbnailImage($cropSize, $cropSize);
            }
            $img->writeImage($dst);
            $img->clear();
            $img->destroy();
            return file_exists($dst);
        } catch (Throwable $e) {
            svb_dbg_write($job_dir, 'warn.imagick_transcode', $e->getMessage());
        }
    }

    $data = @file_get_contents($src);
    if ($data === false) {
        return false;
    }
    $exifOrientation = null;
$extLower = strtolower(pathinfo($src, PATHINFO_EXTENSION));
if (in_array($extLower, ['jpg','jpeg'])) {
    if (function_exists('exif_read_data')) {
        $ex = @exif_read_data($src);
        if (!empty($ex['Orientation'])) {
            $exifOrientation = (int)$ex['Orientation'];
        }
    }
}
    $srcImg = @imagecreatefromstring($data);
    if (!$srcImg) {
        return false;
    }

    if ($exifOrientation) {
    if (function_exists('imagepalettetotruecolor')) {
        @imagepalettetotruecolor($srcImg);
    }
    imagealphablending($srcImg, true);
    imagesavealpha($srcImg, true);

    switch ($exifOrientation) {
        case 2: imageflip($srcImg, IMG_FLIP_HORIZONTAL); break;
        case 3: $srcImg = imagerotate($srcImg, 180, 0); break;
        case 4: imageflip($srcImg, IMG_FLIP_VERTICAL); break;
        case 5: imageflip($srcImg, IMG_FLIP_HORIZONTAL); $srcImg = imagerotate($srcImg, 270, 0); break;
        case 6: $srcImg = imagerotate($srcImg, -90, 0); break;  // 90° CW
        case 7: imageflip($srcImg, IMG_FLIP_HORIZONTAL); $srcImg = imagerotate($srcImg, -90, 0); break;
        case 8: $srcImg = imagerotate($srcImg, 90, 0); break;   // 270° CW
    }
}

    $srcW = imagesx($srcImg);
    $srcH = imagesy($srcImg);
    if ($srcW <= 0 || $srcH <= 0) {
        imagedestroy($srcImg);
        return false;
    }

    if ($cropSize > 0) {
        $scale = max($cropSize / $srcW, $cropSize / $srcH);
        $scaledW = (int)ceil($srcW * $scale);
        $scaledH = (int)ceil($srcH * $scale);
    } else {
        $scale = 1.0;
        $scaledW = $srcW;
        $scaledH = $srcH;
    }

    $scaled = imagecreatetruecolor($scaledW, $scaledH);
    if (!$scaled) {
        imagedestroy($srcImg);
        return false;
    }

    imagealphablending($scaled, false);
    imagesavealpha($scaled, true);
    $transparent = imagecolorallocatealpha($scaled, 0, 0, 0, 127);
    imagefilledrectangle($scaled, 0, 0, $scaledW, $scaledH, $transparent);

    imagecopyresampled($scaled, $srcImg, 0, 0, 0, 0, $scaledW, $scaledH, $srcW, $srcH);
    imagedestroy($srcImg);

    if ($cropSize > 0) {
        $crop = imagecreatetruecolor($cropSize, $cropSize);
        if (!$crop) {
            imagedestroy($scaled);
            return false;
        }
        imagealphablending($crop, false);
        imagesavealpha($crop, true);
        $transparentCrop = imagecolorallocatealpha($crop, 0, 0, 0, 127);
        imagefilledrectangle($crop, 0, 0, $cropSize, $cropSize, $transparentCrop);

        $offsetX = (int)max(0, floor(($scaledW - $cropSize) / 2));
        $offsetY = (int)max(0, floor(($scaledH - $cropSize) / 2));
        imagecopy($crop, $scaled, 0, 0, $offsetX, $offsetY, $cropSize, $cropSize);
        imagedestroy($scaled);
        $result = imagepng($crop, $dst);
        imagedestroy($crop);
        return (bool)$result;
    }

    $result = imagepng($scaled, $dst);
    imagedestroy($scaled);
    return (bool)$result;
}

if (!function_exists('svb_apply_manual_round_corners')) {
    /**
     * Скругление углов + мягкие края по альфе прямо в PNG.
     * $radiusCssPx  — радиус из слайдера (в "CSS-пикселях" превью)
     * $scalePercent — общий масштаб (тот же, что в превью)
     * $targetWidth  — ширина кадра видео (854)
     * $glowPercent  — наш слайдер "Світлі краї" (0–100), управляет feather краёв
     */
    function svb_apply_manual_round_corners($file, $radiusCssPx, $scalePercent, $targetWidth, $job_dir = '', $glowPercent = 0) {
        if ($radiusCssPx <= 0) return true;
        if (!file_exists($file)) return false;

        $info = @getimagesize($file);
        if (!$info) return false;
        [$width, $height] = $info;
        if ($width <= 0 || $height <= 0) return false;

        // пересчёт "CSS-радиуса" в реальные пиксели PNG с учётом масштаба
        $scalePercent = max(1, (int)$scalePercent);
        $scaledWidth  = max(1, (int)round($targetWidth * ($scalePercent / 100.0)));
        $scaleFactor  = $scaledWidth > 0 ? ($width / $scaledWidth) : 1.0;

        $radius    = (int)round($radiusCssPx * $scaleFactor);
        $maxRadius = (int)floor((min($width, $height) - 1) / 2);
        if ($maxRadius < 1) $maxRadius = 1;
        $radius = max(1, min($radius, $maxRadius));
        if ($radius <= 0) return true;

        // --- Вариант через Imagick ---
        if (class_exists('Imagick')) {
            try {
                $img = new Imagick($file);
                // безопасная автоориентация
                if (method_exists($img, 'autoOrient')) {
                    $img->autoOrient();
                }
                $img->setImageFormat('png');
                $img->setImageAlphaChannel(Imagick::ALPHACHANNEL_SET);
                $img->roundCorners($radius, $radius);

                // лёгкое мягкое перо по краям по желанию — через blur маски
                $glowPercent = max(0.0, min(100.0, (float)$glowPercent));
                if ($glowPercent > 0) {
                    // чем больше glow, тем сильнее blur (но очень умеренно)
                    $sigma = max(0.5, min(5.0, $glowPercent / 20.0));
                    try {
                        $img->blurImage($sigma, $sigma);
                    } catch (Throwable $e) {
                        // если blur не поддержан – просто игнорируем
                    }
                }

                $img->writeImage($file);
                $img->clear();
                $img->destroy();
                return true;
            } catch (Throwable $e) {
                svb_dbg_write($job_dir, 'warn.imagick_round', $e->getMessage());
            }
        }

        // --- Fallback через GD ---
        if (!function_exists('imagecreatetruecolor') || !function_exists('imagepng')) {
            return false;
        }

        $imgData = @file_get_contents($file);
        if ($imgData === false) return false;

        $img = @imagecreatefromstring($imgData);
        if (!$img) return false;

        if (function_exists('imagepalettetotruecolor')) {
            @imagepalettetotruecolor($img);
        }

        imagealphablending($img, false);
        imagesavealpha($img, true);

        $mask = imagecreatetruecolor($width, $height);
        if (!$mask) {
            imagedestroy($img);
            return false;
        }

        if (function_exists('imageantialias')) {
            imageantialias($mask, true);
        }

        imagealphablending($mask, false);
        imagesavealpha($mask, true);

        $maskTransparent = imagecolorallocatealpha($mask, 0, 0, 0, 127); // полностью прозрачное
        $maskOpaque      = imagecolorallocatealpha($mask, 0, 0, 0, 0);   // полностью непрозрачное

        // базовая "жёсткая" маска закруглённого прямоугольника
        imagefilledrectangle($mask, 0, 0, $width, $height, $maskTransparent);
        imagefilledrectangle($mask, $radius, 0, $width - $radius, $height, $maskOpaque);
        imagefilledrectangle($mask, 0, $radius, $width, $height - $radius, $maskOpaque);

        $diameter = $radius * 2;
        imagefilledellipse($mask, $radius, $radius, $diameter, $diameter, $maskOpaque);
        imagefilledellipse($mask, $width - $radius - 1, $radius, $diameter, $diameter, $maskOpaque);
        imagefilledellipse($mask, $radius, $height - $radius - 1, $diameter, $diameter, $maskOpaque);
        imagefilledellipse($mask, $width - $radius - 1, $height - $radius - 1, $diameter, $diameter, $maskOpaque);

        // --- НОВОЕ: мягкое перо по краям через размытие маски ---
        $glowPercent = max(0.0, min(100.0, (float)$glowPercent));
        if ($glowPercent > 0 && function_exists('imagefilter') && defined('IMG_FILTER_GAUSSIAN_BLUR')) {
            // 1–8 проходов gaussian blur в зависимости от слайдера
            $passes = max(1, min(8, (int)ceil($glowPercent / 15)));
            for ($i = 0; $i < $passes; $i++) {
                @imagefilter($mask, IMG_FILTER_GAUSSIAN_BLUR);
            }
        }

        $transparentColor = imagecolorallocatealpha($img, 0, 0, 0, 127);
        $cache = [];

        // применяем маску к исходной картинке
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $rgba  = imagecolorat($mask, $x, $y);
                $alpha = ($rgba & 0x7F000000) >> 24; // 0 (непрозрачный) .. 127 (полностью прозрачный)

                if ($alpha === 0) {
                    // полностью непрозрачный – пиксель оставляем как есть
                    continue;
                }

                if ($alpha >= 127) {
                    // полностью прозрачный – затираем
                    imagesetpixel($img, $x, $y, $transparentColor);
                    continue;
                }

                // промежуточная альфа – делаем частично прозрачным
                $srcRGBA = imagecolorsforindex($img, imagecolorat($img, $x, $y));
                $key     = $srcRGBA['red'].'_'.$srcRGBA['green'].'_'.$srcRGBA['blue'].'_'.$alpha;
                if (!isset($cache[$key])) {
                    $cache[$key] = imagecolorallocatealpha(
                        $img,
                        $srcRGBA['red'],
                        $srcRGBA['green'],
                        $srcRGBA['blue'],
                        $alpha
                    );
                }
                imagesetpixel($img, $x, $y, $cache[$key]);
            }
        }

        $ok = imagepng($img, $file);

        imagedestroy($img);
        imagedestroy($mask);

        return $ok;
    }
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

    $ffmpeg_path = svb_exec_find('ffmpeg');
    $preview_caps = [
        'perspective' => $ffmpeg_path ? svb_ff_has_filter($ffmpeg_path, 'perspective') : false,
    ];

    $P_CHILD1 = [ ['00:54:20','00:58:25'] ];
    // child2 – только сцена в середине (финальную убираем)
    $P_CHILD2 = [ ['02:17:14','02:21:25'] ];
    $P_PARENTS = [ ['06:35:03','06:43:13'] ];
    // extra – финальная сцена
    $P_EXTRA   = [ ['07:06:00','07:11:13'] ];
    // extra2 – новая сцена около 04:18
    $P_EXTRA2  = [ ['04:18:11','04:21:21'] ];

    // В хелпер конвертации в секунды используем уже определённую svb_ts_to_seconds()
    $to_sec = function($pairs){
        return array_map(function($a){
            return [ svb_ts_to_seconds($a[0]), svb_ts_to_seconds($a[1]) ];
        }, $pairs);
    };
    $OVER = [
        'child1'  => $to_sec($P_CHILD1),
        'child2'  => $to_sec($P_CHILD2),
        'parent1' => $to_sec($P_PARENTS),
        'parent2' => $to_sec($P_PARENTS),
        'extra'   => $to_sec($P_EXTRA),
        'extra2'  => $to_sec($P_EXTRA2),
    ];

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
.svb-controls .svb-val-input {
  border: 1px solid #E3E3E3;
  border-radius: 6px;
  padding: 2px 6px;
  font-size: 12px;
  width: 70px;
  text-align: right;
  box-sizing: border-box;
}

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
/* временно фиксируем 1:1 размер превью */
.svb-vid-preview { width: 854px; height: 480px; }

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
/* контейнер bbox, который мы позиционируем как в ffmpeg-рендере */
.svb-ovbox{
  position:absolute;
  left:0; top:0;
  width:0; height:0;
  z-index:2;
  will-change: left, top, width, height;
}

/* само изображение внутри ovbox — реальный контент до паддинга */
.svb-ovbox > img{
  position:absolute;
  left:0; top:0;
  width:auto; height:auto;
  transform-origin:50% 50%;
  will-change: transform, left, top, width, height;
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
          
          <!-- CHILD1 -->
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
            </div>

            <span class="svb-note">Інтервали: 00:54:20–00:58:25 та 04:18:11–04:21:21</span>
          </div>

          <!-- CHILD2 -->
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
            </div>

            <span class="svb-note">Інтервали: 02:17:14–02:21:25 та 07:04:23–07:11:13</span>
          </div>

          <!-- PARENT1 -->
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
            </div>

            <span class="svb-note">Інтервал: 06:35:03–06:43:13 (разом з фото матері)</span>
          </div>

          <!-- PARENT2 -->
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
            </div>

            <span class="svb-note">Інтервал: 06:35:03–06:43:13 (разом з фото батька)</span>
          </div>

          <!-- EXTRA2 (04:18 scene) -->
          <div class="svb-drop" data-photo="extra2">
            <div class="svb-field">
              <span class="svb-label">Додаткове фото (сцена 04:18)</span>
              <input class="svb-input" type="file" name="photo_extra2" accept="image/*">
            </div>

            <div class="svb-vid-preview" id="svb-vid-preview-extra2">
              <video id="svb-video-extra2"
                     src="<?php echo esc_url($template_url); ?>"
                     playsinline loop></video>
              <img id="img-extra2" alt="Фото тут" />
            </div>

            <div class="svb-vid-seek-bar-container">
              <input
                type="range"
                class="svb-range svb-seek-bar"
                data-vid-ctrl="seek"
                data-key="extra2"
                min="0"
                value="0"
                step="0.001"
              >
              <div style="margin-top: 4px; display: flex; align-items: center; gap: 6px; font-size: 12px;">
                <span>Час, мс:</span>
                <input
                  type="number"
                  class="svb-time-input"
                  data-key="extra2"
                  value="0"
                  min="0"
                  step="1"
                  style="width: 90px; padding: 2px 4px; font-size: 12px;"
                >
              </div>
            </div>

            <div class="svb-vid-controls">
              <button type="button" class="svb-btn ghost" data-vid-ctrl="play"  data-key="extra2">► Play</button>
              <button type="button" class="svb-btn ghost" data-vid-ctrl="pause" data-key="extra2" style="display:none;">❚❚ Pause</button>
              <div id="svb-vid-time-extra2" class="svb-btn ghost">00:00 / 00:00</div>

              <button type="button" class="svb-btn ghost" data-vid-ctrl="mute"   data-key="extra2">🔇 Mute</button>
              <button type="button" class="svb-btn ghost" data-vid-ctrl="unmute" data-key="extra2" style="display:none;">🔈 Unmute</button>
              <input type="range" class="svb-range" data-vid-ctrl="volume" data-key="extra2" min="0" max="1" step="0.05" value="0.8">
              <button type="button" class="svb-btn ghost" data-vid-ctrl="log" data-key="extra2">📌 Log frame</button>
            </div>

            <div class="svb-note" style="margin-top: 4px;">
              <b>Керування:</b> ті ж самі слайдери, що й для інших фото.
            </div>

            <div class="svb-controls" style="grid-template-columns: 1fr; gap: 12px;">
              <label>
                X
                <input
                  class="svb-val svb-val-input"
                  id="val-extra2-x"
                  type="number"
                  value="775"
                  min="-1000"
                  max="2500"
                  step="5"
                  data-range-name="extra2_x"
                />
                <input
                  class="svb-range svb-key-control"
                  type="range"
                  name="extra2_x"
                  value="775"
                  min="-1000"
                  max="2500"
                  step="5"
                  data-val-id="val-extra2-x"
                  data-key-up="ArrowRight"
                  data-key-down="ArrowLeft"
                />
              </label>

              <label>
                Y
                <input
                  class="svb-val svb-val-input"
                  id="val-extra2-y"
                  type="number"
                  value="405"
                  min="-1000"
                  max="2000"
                  step="5"
                  data-range-name="extra2_y"
                />
                <input
                  class="svb-range svb-key-control"
                  type="range"
                  name="extra2_y"
                  value="405"
                  min="-1000"
                  max="2000"
                  step="5"
                  data-val-id="val-extra2-y"
                  data-key-up="ArrowDown"
                  data-key-down="ArrowUp"
                />
              </label>

              <label>
                Scale
                <input
                  class="svb-val svb-val-input"
                  id="val-extra2-scale"
                  type="number"
                  value="31"
                  min="10"
                  max="200"
                  step="1"
                  data-range-name="extra2_scale"
                />
                %
                <input
                  class="svb-range svb-key-control"
                  type="range"
                  name="extra2_scale"
                  value="31"
                  min="10"
                  max="200"
                  step="1"
                  data-val-id="val-extra2-scale"
                  data-key-up="="
                  data-key-down="-"
                />
              </label>

              <label>
                Scale Y
                <input
                  class="svb-val svb-val-input"
                  id="val-extra2-scale-y"
                  type="number"
                  value="100"
                  min="10"
                  max="200"
                  step="1"
                  data-range-name="extra2_scale_y"
                />
                %
                <input
                  class="svb-range svb-key-control"
                  type="range"
                  name="extra2_scale_y"
                  value="100"
                  min="10"
                  max="200"
                  step="1"
                  data-val-id="val-extra2-scale-y"
                />
              </label>

              <label>
                Skew X
                <input
                  class="svb-val svb-val-input"
                  id="val-extra2-skew"
                  type="number"
                  value="0"
                  min="-45"
                  max="45"
                  step="1"
                  data-range-name="extra2_skew"
                />
                °
                <input
                  class="svb-range svb-key-control"
                  type="range"
                  name="extra2_skew"
                  value="0"
                  min="-45"
                  max="45"
                  step="1"
                  data-val-id="val-extra2-skew"
                />
              </label>

              <label>
                Skew Y
                <input
                  class="svb-val svb-val-input"
                  id="val-extra2-skew-y"
                  type="number"
                  value="0"
                  min="-45"
                  max="45"
                  step="1"
                  data-range-name="extra2_skew_y"
                />
                °
                <input
                  class="svb-range svb-key-control"
                  type="range"
                  name="extra2_skew_y"
                  value="0"
                  min="-45"
                  max="45"
                  step="1"
                  data-val-id="val-extra2-skew-y"
                />
              </label>

              <label>
                Angle
                <input
                  class="svb-val svb-val-input"
                  id="val-extra2-angle"
                  type="number"
                  value="0"
                  min="-45"
                  max="45"
                  step="1"
                  data-range-name="extra2_angle"
                />
                °
                <input
                  class="svb-range svb-key-control"
                  type="range"
                  name="extra2_angle"
                  value="0"
                  min="-45"
                  max="45"
                  step="1"
                  data-val-id="val-extra2-angle"
                  data-key-up="."
                  data-key-down=","
                />
              </label>

              <label>
                Radius
                <input
                  class="svb-val svb-val-input"
                  id="val-extra2-radius"
                  type="number"
                  value="0"
                  min="0"
                  max="200"
                  step="1"
                  data-range-name="extra2_radius"
                />
                px
                <input
                  class="svb-range svb-key-control"
                  type="range"
                  name="extra2_radius"
                  value="0"
                  min="0"
                  max="200"
                  step="1"
                  data-val-id="val-extra2-radius"
                  data-key-up="]"
                  data-key-down="["
                />
              </label>

              <label>
                Прозорість:
                <input
                  class="svb-val svb-val-input"
                  id="val-extra2-opacity"
                  type="number"
                  value="100"
                  min="30"
                  max="100"
                  step="1"
                  data-range-name="extra2_opacity"
                />
                %
                <input
                  class="svb-range svb-key-control"
                  type="range"
                  name="extra2_opacity"
                  min="30"
                  max="100"
                  step="1"
                  value="100"
                  data-val-id="val-extra2-opacity"
                />
              </label>

              <label>
                Світлі краї:
                <input
                  class="svb-val svb-val-input"
                  id="val-extra2-glow"
                  type="number"
                  value="0"
                  min="0"
                  max="100"
                  step="1"
                  data-range-name="extra2_glow"
                />
                %
                <input
                  class="svb-range svb-key-control"
                  type="range"
                  name="extra2_glow"
                  min="0"
                  max="100"
                  step="1"
                  value="0"
                  data-val-id="val-extra2-glow"
                />
              </label>
            </div>

            <span class="svb-note">Інтервал: 04:18:11–04:21:21 (додаткове фото в середині відео)</span>
          </div>

          <!-- EXTRA (final scene) -->
          <div class="svb-drop" data-photo="extra">
            <div class="svb-field">
              <span class="svb-label">Додаткове фото</span>
              <input class="svb-input" type="file" name="photo_extra" accept="image/*">
            </div>

            <div class="svb-vid-preview" id="svb-vid-preview-extra">
              <video id="svb-video-extra" src="<?php echo esc_url($template_url); ?>" playsinline loop></video>
              <img id="img-extra" alt="Фото тут" />
            </div>

            <div class="svb-vid-seek-bar-container">
              <input
                type="range"
                class="svb-range svb-seek-bar"
                data-vid-ctrl="seek"
                data-key="extra"
                min="0"
                value="0"
                step="0.001"
              >
              <div style="margin-top: 4px; display: flex; align-items: center; gap: 6px; font-size: 12px;">
                <span>Час, мс:</span>
                <input
                  type="number"
                  class="svb-time-input"
                  data-key="extra"
                  value="0"
                  min="0"
                  step="1"
                  style="width: 90px; padding: 2px 4px; font-size: 12px;"
                >
              </div>
            </div>

            <div class="svb-vid-controls">
              <button type="button" class="svb-btn ghost" data-vid-ctrl="play" data-key="extra">► Play</button>
              <button type="button" class="svb-btn ghost" data-vid-ctrl="pause" data-key="extra" style="display:none;">❚❚ Pause</button>
              <div id="svb-vid-time-extra" class="svb-btn ghost">00:00 / 00:00</div>

              <button type="button" class="svb-btn ghost" data-vid-ctrl="mute" data-key="extra">🔇 Mute</button>
              <button type="button" class="svb-btn ghost" data-vid-ctrl="unmute" data-key="extra" style="display:none;">🔈 Unmute</button>
              <input type="range" class="svb-range" data-vid-ctrl="volume" data-key="extra" min="0" max="1" step="0.05" value="0.8">
              <button type="button" class="svb-btn ghost" data-vid-ctrl="log" data-key="extra">📌 Log frame</button>
            </div>

            <div class="svb-note" style="margin-top: 4px;">
              <b>Керування:</b> Фокус на слайдерах. <b>Стрілки</b> (X/Y), <b>+ / -</b> (Scale), <b>[ / ]</b> (Radius), <b>, / .</b> (Angle).
            </div>

            <div class="svb-controls" style="grid-template-columns: 1fr; gap: 12px;">
              <label>
                X
                <input
                  class="svb-val svb-val-input"
                  id="val-extra-x"
                  type="number"
                  value="775"
                  min="-1000"
                  max="2500"
                  step="5"
                  data-range-name="extra_x"
                />
                <input
                  class="svb-range svb-key-control"
                  type="range"
                  name="extra_x"
                  value="775"
                  min="-1000"
                  max="2500"
                  step="5"
                  data-val-id="val-extra-x"
                  data-key-up="ArrowRight"
                  data-key-down="ArrowLeft"
                />
              </label>

              <label>
                Y
                <input
                  class="svb-val svb-val-input"
                  id="val-extra-y"
                  type="number"
                  value="405"
                  min="-1000"
                  max="2000"
                  step="5"
                  data-range-name="extra_y"
                />
                <input
                  class="svb-range svb-key-control"
                  type="range"
                  name="extra_y"
                  value="405"
                  min="-1000"
                  max="2000"
                  step="5"
                  data-val-id="val-extra-y"
                  data-key-up="ArrowDown"
                  data-key-down="ArrowUp"
                />
              </label>

              <label>
                Scale
                <input
                  class="svb-val svb-val-input"
                  id="val-extra-scale"
                  type="number"
                  value="31"
                  min="10"
                  max="200"
                  step="1"
                  data-range-name="extra_scale"
                />
                %
                <input
                  class="svb-range svb-key-control"
                  type="range"
                  name="extra_scale"
                  value="31"
                  min="10"
                  max="200"
                  step="1"
                  data-val-id="val-extra-scale"
                  data-key-up="="
                  data-key-down="-"
                />
              </label>

              <label>
                Scale Y
                <input
                  class="svb-val svb-val-input"
                  id="val-extra-scale-y"
                  type="number"
                  value="100"
                  min="10"
                  max="200"
                  step="1"
                  data-range-name="extra_scale_y"
                />
                %
                <input
                  class="svb-range svb-key-control"
                  type="range"
                  name="extra_scale_y"
                  value="100"
                  min="10"
                  max="200"
                  step="1"
                  data-val-id="val-extra-scale-y"
                />
              </label>

              <label>
                Skew X
                <input
                  class="svb-val svb-val-input"
                  id="val-extra-skew"
                  type="number"
                  value="0"
                  min="-45"
                  max="45"
                  step="1"
                  data-range-name="extra_skew"
                />
                °
                <input
                  class="svb-range svb-key-control"
                  type="range"
                  name="extra_skew"
                  value="0"
                  min="-45"
                  max="45"
                  step="1"
                  data-val-id="val-extra-skew"
                />
              </label>

              <label>
                Skew Y
                <input
                  class="svb-val svb-val-input"
                  id="val-extra-skew-y"
                  type="number"
                  value="0"
                  min="-45"
                  max="45"
                  step="1"
                  data-range-name="extra_skew_y"
                />
                °
                <input
                  class="svb-range svb-key-control"
                  type="range"
                  name="extra_skew_y"
                  value="0"
                  min="-45"
                  max="45"
                  step="1"
                  data-val-id="val-extra-skew-y"
                />
              </label>

              <label>
                Angle
                <input
                  class="svb-val svb-val-input"
                  id="val-extra-angle"
                  type="number"
                  value="0"
                  min="-45"
                  max="45"
                  step="1"
                  data-range-name="extra_angle"
                />
                °
                <input
                  class="svb-range svb-key-control"
                  type="range"
                  name="extra_angle"
                  value="0"
                  min="-45"
                  max="45"
                  step="1"
                  data-val-id="val-extra-angle"
                  data-key-up="."
                  data-key-down=","
                />
              </label>

              <label>
                Radius
                <input
                  class="svb-val svb-val-input"
                  id="val-extra-radius"
                  type="number"
                  value="0"
                  min="0"
                  max="200"
                  step="1"
                  data-range-name="extra_radius"
                />
                px
                <input
                  class="svb-range svb-key-control"
                  type="range"
                  name="extra_radius"
                  value="0"
                  min="0"
                  max="200"
                  step="1"
                  data-val-id="val-extra-radius"
                  data-key-up="]"
                  data-key-down="["
                />
              </label>

              <label>
                Прозорість:
                <input
                  class="svb-val svb-val-input"
                  id="val-extra-opacity"
                  type="number"
                  value="100"
                  min="30"
                  max="100"
                  step="1"
                  data-range-name="extra_opacity"
                />
                %
                <input
                  class="svb-range svb-key-control"
                  type="range"
                  name="extra_opacity"
                  min="30"
                  max="100"
                  step="1"
                  value="100"
                  data-val-id="val-extra-opacity"
                />
              </label>

              <label>
                Світлі краї:
                <input
                  class="svb-val svb-val-input"
                  id="val-extra-glow"
                  type="number"
                  value="0"
                  min="0"
                  max="100"
                  step="1"
                  data-range-name="extra_glow"
                />
                %
                <input
                  class="svb-range svb-key-control"
                  type="range"
                  name="extra_glow"
                  min="0"
                  max="100"
                  step="1"
                  value="0"
                  data-val-id="val-extra-glow"
                />
              </label>
            </div>

            <span class="svb-note">Інтервал: 07:06:00–07:11:13 (додаткове фото у фінальній сцені)</span>
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
    <span id="svb-lock-percent">0%</span>
  </div>
  <div class="svb-screenlock__txt" id="svb-lock-text">Формуємо відео… будь ласка, не закривайте сторінку</div>
</div>


<script>
const SVB_AUDIO = <?php echo wp_json_encode($audio_catalog, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); ?>;
const SVB_AJAX  = {
    url: <?php echo wp_json_encode($ajax_url); ?>,
    nonce: <?php echo wp_json_encode($nonce); ?>,
    video_template: <?php echo wp_json_encode($template_url); ?>
};
const SVB_PROCESSED_PHOTO_SIZE = 709; // ширина/высота PNG после препроцессинга на сервере
const SVB_PREVIEW_CAPS = <?php echo wp_json_encode($preview_caps, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); ?>;
const SVB_OVERLAY_WINDOWS = <?php echo wp_json_encode($OVER, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); ?>;

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
  ['child1', 'child2', 'parent1', 'parent2', 'extra', 'extra2'].forEach(key => {
    svbMarkTouched(key);
    const input = document.querySelector(`input[name="photo_${key}"]`);
    if(!input) return;

    // === ЗАМЕНА ОБРАБОТЧИКА CHANGE НА ВАРИАНТ С EXIF + 709x709 ===
    input.addEventListener('change', async (e) => {
      const f = e.target.files && e.target.files[0];
      if (!f) return;

      const imgEl = document.getElementById('img-' + key);
      if (!imgEl) return;

      // 1) Применяем EXIF-ориентацию (как на сервере)
      let bmp;
      try {
        bmp = await createImageBitmap(f, { imageOrientation: 'from-image' });
      } catch {
        // фолбэк (если браузер не поддерживает from-image)
        bmp = await createImageBitmap(f);
      }

      // 2) Центр-кроп до квадрата 709×709 (как svb_transcode_image_to_png_rgba(..., 709))
      const SIZE = 709;
      const canvas = document.createElement('canvas');
      canvas.width = SIZE;
      canvas.height = SIZE;
      const ctx = canvas.getContext('2d');

      const srcW = bmp.width, srcH = bmp.height;
      const scale = Math.max(SIZE / srcW, SIZE / srcH);
      const drawW = Math.ceil(srcW * scale);
      const drawH = Math.ceil(srcH * scale);
      const dx = Math.floor((SIZE - drawW) / 2);
      const dy = Math.floor((SIZE - drawH) / 2);

      ctx.clearRect(0, 0, SIZE, SIZE);
      ctx.drawImage(bmp, dx, dy, drawW, drawH);

      // 3) Отдаём PNG в <img>, затем считаем трансформации
      canvas.toBlob((blob) => {
        if (!blob) return;
        const url = URL.createObjectURL(blob);
        if (imgEl.src) URL.revokeObjectURL(imgEl.src);
        imgEl.onload = () => { svbUpdatePreviewTransform(key); svbDebugPrint(key); };
        imgEl.src = url;
      }, 'image/png');
    });
  });

  // === Ниже — как и было: привязки к X/Y/Scale/... ===
  ['child1', 'child2', 'parent1', 'parent2', 'extra', 'extra2'].forEach(key => {
    ['x','y','scale','scale_y','skew','skew_y','angle','radius','opacity','glow'].forEach(k=>{
      const ctrl = document.querySelector(`input[name="${key}_${k}"]`);
      if(ctrl){
        ctrl.addEventListener('input', (e)=> {
                    const valId = e.target.dataset.valId;
          if (valId) {
              const valEl = document.getElementById(valId);
              if (valEl) {
                  if (valEl.tagName === 'INPUT') {
                      valEl.value = e.target.value;
                  } else {
                      valEl.textContent = e.target.value;
                  }
              }
          }
          svbUpdatePreviewTransform(key);
          svbDebugPrint(key);

        });
      }
    });
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
            // триггерим обычный обработчик слайдера
            range.dispatchEvent(new Event('input', { bubbles: true }));
        });
    });
}


const SVB_MODEL_W = 854, SVB_MODEL_H = 480;
const PROCESSED_SQUARE = (typeof SVB_PROCESSED_PHOTO_SIZE==='number' && SVB_PROCESSED_PHOTO_SIZE>0) ? SVB_PROCESSED_PHOTO_SIZE : 709;

// «чётное вверх»
const toEvenUp = v => {
  const n = Math.ceil(v);
  // если получилось нечётное — поднимаем к следующему чётному,
  // а не опускаем вниз
  return (n & 1) ? (n + 1) : n;
};

const clamp01  = v => Math.max(0, Math.min(1, v));

/**
 * ЕДИНСТВЕННОЕ место, где считаем геометрию.
 * Всё, что видишь в превью = то, что уйдёт в overlay_json.
 */
function svbComputeOverlayGeom(key) {
  const num = (suffix, def = 0) => {
    const el = document.querySelector(`input[name="${key}_${suffix}"]`);
    const v  = parseFloat(el?.value);
    return Number.isFinite(v) ? v : def;
  };

  const scaleXpct = num('scale', 100);
  const scaleYpct = num('scale_y', 100);
  // слайдеры skew в ГРАДУСАХ
  const skewXdeg  = num('skew',   0);
  const skewYdeg  = num('skew_y', 0);
  const angleDeg  = num('angle',  0);
  const radiusPx  = num('radius', 0);
  const xRaw      = num('x',      0);
  const yRaw      = num('y',      0);

  // НОВОЕ: прозрачность и «світлі краї»
  const opacityPct = num('opacity', 100); // 0–100
  const glowPct    = num('glow',    0);   // 0–100

  const sX = Math.max(10, Math.min(200, scaleXpct)) / 100;
  const sY = Math.max(10, Math.min(200, scaleYpct)) / 100;

  const w_content = Math.max(2, Math.round(SVB_MODEL_W * sX));
  const h_content = Math.max(
    2,
    Math.round(PROCESSED_SQUARE * (w_content / PROCESSED_SQUARE) * sY)
  );

  const rad      = Math.PI / 180;
  const angRad   = angleDeg * rad;
  const skewXrad = skewXdeg * rad;
  const skewYrad = skewYdeg * rad;

  const tx  = Math.tan(skewXrad);
  const ty  = Math.tan(skewYrad);
  const cos = Math.cos(angRad);
  const sin = Math.sin(angRad);

  const mSx = [1,  tx, 0, 1];
  const mSy = [1,  0, ty, 1];
  const mR  = [cos, -sin, sin, cos];

  const mul2 = (m1, m2) => ([
    m1[0]*m2[0] + m1[1]*m2[2],
    m1[0]*m2[1] + m1[1]*m2[3],
    m1[2]*m2[0] + m1[3]*m2[2],
    m1[2]*m2[1] + m1[3]*m2[3],
  ]);

  const mShear = mul2(mSy, mSx);
  const mTotal = mul2(mR,  mShear);

  const hw = w_content / 2;
  const hh = h_content / 2;

  const corners = [
    [-hw, -hh],
    [ hw, -hh],
    [ hw,  hh],
    [-hw,  hh],
  ];

  let minX = +Infinity, maxX = -Infinity;
  let minY = +Infinity, maxY = -Infinity;

  for (const [x, y] of corners) {
    const x2 = mTotal[0] * x + mTotal[1] * y;
    const y2 = mTotal[2] * x + mTotal[3] * y;
    if (x2 < minX) minX = x2;
    if (x2 > maxX) maxX = x2;
    if (y2 < minY) minY = y2;
    if (y2 > maxY) maxY = y2;
  }

  const w_bbox = Math.max(2, toEvenUp(maxX - minX));
  const h_bbox = Math.max(2, toEvenUp(maxY - minY));

  const xBase = (xRaw / 1920) * SVB_MODEL_W;
  const yBase = (yRaw / 1080) * SVB_MODEL_H;

  const offX  = (w_bbox - w_content) / 2;
  const offY  = (h_bbox - h_content) / 2;

  const left0 = xBase - offX;
  const top0  = yBase - offY;

  const x_norm = clamp01(left0 / Math.max(1, SVB_MODEL_W  - w_bbox));
  const y_norm = clamp01(top0  / Math.max(1, SVB_MODEL_H - h_bbox));

  const finalX = Math.floor(x_norm * Math.max(0, SVB_MODEL_W  - w_bbox));
  const finalY = Math.floor(y_norm * Math.max(0, SVB_MODEL_H - h_bbox));

  return {
    x_norm, y_norm,
    w_pred:   w_bbox,
    h_pred:   h_bbox,
    final_x:  finalX,
    final_y:  finalY,
    w_content,
    h_content,

    scale:  scaleXpct,
    scaleY: scaleYpct,

    skew:     skewXdeg,
    skewY:    skewYdeg,
    skewXdeg: skewXdeg,
    skewYdeg: skewYdeg,

    angle:  angleDeg,
    radius: radiusPx,

    // НОВОЕ
    opacity: opacityPct, // 0–100
    glow:    glowPct,    // 0–100

    video:      { w: SVB_MODEL_W, h: SVB_MODEL_H },
    source_png: { square: PROCESSED_SQUARE },
    x_raw: xRaw,
    y_raw: yRaw
  };
}


/** Обновление DOM-превью: теперь только читает geom из svbComputeOverlayGeom() */
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
  const kx = (rect.width  || SVB_MODEL_W) / SVB_MODEL_W;
  const ky = (rect.height || SVB_MODEL_H) / SVB_MODEL_H;

  // bbox (ovbox)
  wrap.style.left   = Math.floor(geom.final_x * kx) + 'px';
  wrap.style.top    = Math.floor(geom.final_y * ky) + 'px';
  wrap.style.width  = Math.floor(geom.w_pred * kx) + 'px';
  wrap.style.height = Math.floor(geom.h_pred * ky) + 'px';

  // контент внутри bbox
  const innerLeft = Math.floor(((geom.w_pred - geom.w_content) / 2) * kx);
  const innerTop  = Math.floor(((geom.h_pred - geom.h_content) / 2) * ky);

  img.style.left   = innerLeft + 'px';
  img.style.top    = innerTop  + 'px';
  img.style.width  = Math.floor(geom.w_content * kx) + 'px';
  img.style.height = Math.floor(geom.h_content * ky) + 'px';

  // Skew + rotate
  const t = [];
  if (geom.angle)    t.push(`rotate(${geom.angle}deg)`);
  if (geom.skewYdeg) t.push(`skewY(${geom.skewYdeg}deg)`);
  if (geom.skewXdeg) t.push(`skewX(${geom.skewXdeg}deg)`);
  img.style.transformOrigin = '50% 50%';
  img.style.transform = t.length ? t.join(' ') : 'none';

  img.style.borderRadius = geom.radius > 0
    ? Math.floor(geom.radius * kx) + 'px'
    : '0px';

  // НОВОЕ: базовая прозрачность (0–100% → 0–1)
  let alpha = 1;
  if (typeof geom.opacity === 'number') {
    alpha = Math.max(0, Math.min(100, geom.opacity)) / 100;
  }
  img.dataset.svbOpacity = String(alpha);
  // если сейчас помечено как видимое — обновим opacity,
  // иначе пусть останется 0 (его контролит плеер по таймкодам)
  const visibleFlag = img.dataset.svbVisible === '0' ? 0 : 1;
  img.style.opacity = String(visibleFlag ? alpha : 0);

if (typeof geom.glow === 'number' && geom.glow > 0) {
    const g = Math.max(0, Math.min(100, geom.glow));
    const maxSide = Math.max(rect.width, rect.height) || 1;
    const blurPx  = Math.max(2, Math.round((g / 100) * (maxSide * 0.07)));
    const inner   = Math.round(blurPx * 0.7);
    const outerB  = Math.round(blurPx * 0.5);
    const outerS  = Math.round(blurPx * 0.15);
    // Внутреннее «перо» + лёгкий внешний свет
    img.style.boxShadow =
      `inset 0 0 ${blurPx}px ${inner}px rgba(255,255,255,0.9),` +
      `0 0 ${outerB}px ${outerS}px rgba(255,255,255,0.6)`;
} else {
    img.style.boxShadow = 'none';
}

}


/**
 * overlay_json — фронт единожды считает геометрию и отдаёт на бэкенд
 * НОРМАЛИЗОВАННЫЙ ЦЕНТР bbox в координатах итогового видео 854×480.
 * FFmpeg больше НИЧЕГО сам не пересчитывает «по-своему».
 */
function svbCollectOverlayData() {
  const data = {};
  const keys = ['child1', 'child2', 'parent1', 'parent2', 'extra', 'extra2'];

  keys.forEach((key) => {
    const img = document.getElementById('img-' + key);
    if (!img) return;

    const geom = svbComputeOverlayGeom(key);
    if (!geom) return;

    // центр предсказанного bbox в модели 854×480
    const cx_model = geom.final_x + geom.w_pred / 2;
    const cy_model = geom.final_y + geom.h_pred / 2;

    data[key] = {
      // единственный источник истины для ffmpeg:
      cx_norm: cx_model / SVB_MODEL_W,
      cy_norm: cy_model / SVB_MODEL_H,

      // для отладки можно сохранить размер bbox:
      w_pred:  geom.w_pred,
      h_pred:  geom.h_pred,

      // управляющие параметры трансформации
      scale:   geom.scale,
      scaleY:  geom.scaleY,
      skew:    geom.skew,
      skewY:   geom.skewY,
      angle:   geom.angle,
      radius:  geom.radius,
      opacity: geom.opacity,
      glow:    geom.glow
    };
  });

  const field = document.getElementById('overlay_json');
  if (field) {
    field.value = JSON.stringify(data);
  }
  return data;
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
  ['x','y','scale','scale_y','skew','skew_y','angle','radius','opacity','glow'].forEach(k=>{
    const el = document.querySelector(`input[name="${key}_${k}"]`);
    if (el && !el.__svb_bound) {
      el.addEventListener('input', ()=> el.dataset.touched = '1');
      el.__svb_bound = true;
    }
  });
}

function svbBindRealtimeControls() {
    ['child1', 'child2', 'parent1', 'parent2', 'extra', 'extra2'].forEach(key => {
        const vid = document.getElementById(`svb-video-${key}`);
        const playBtn = document.querySelector(`[data-vid-ctrl="play"][data-key="${key}"]`);
        const pauseBtn = document.querySelector(`[data-vid-ctrl="pause"][data-key="${key}"]`);
        const timeEl = document.getElementById(`svb-vid-time-${key}`);
        const muteBtn = document.querySelector(`[data-vid-ctrl="mute"][data-key="${key}"]`);
        const unmuteBtn = document.querySelector(`[data-vid-ctrl="unmute"][data-key="${key}"]`);
        const volumeSlider = document.querySelector(`[data-vid-ctrl="volume"][data-key="${key}"]`);
        const seekSlider = document.querySelector(`[data-vid-ctrl="seek"][data-key="${key}"]`);
                const timeInputMs = document.querySelector(`.svb-time-input[data-key="${key}"]`);

                // === ВКЛЮЧАЕМ ВИДИМОСТЬ КАРТИНКИ ПО ТАЙМИНГАМ ===
                // === ВКЛЮЧАЕМ ВИДИМОСТЬ КАРТИНКИ ПО ТАЙМИНГАМ ===
        const img = document.getElementById('img-' + key);
        const windows = (typeof SVB_OVERLAY_WINDOWS === 'object' && SVB_OVERLAY_WINDOWS[key]) ? SVB_OVERLAY_WINDOWS[key] : [];
        function isOn(t){
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
            // Начальное состояние до старта воспроизведения
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
            if (windows && windows.length) {
                start = windows[0][0] || 0;
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
  ['child1','child2','parent1','parent2','extra', 'extra2'].forEach(key=>{
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


// === ЗАПУСК ===
svbPopulateSelects();
svbBindAudioPreview();
svbBindPhotoInputs(); 
svbEnsureWrappers();
svbBindNameSuggest();
svbBindNumericControls();
svbBuildNameSuggest(document.querySelector('input[name="name_text"]')?.value || '');
document.querySelector('select[name="gender"]').addEventListener('change', autoBindNameAudio);
document.querySelector('input[name="name_text"]').addEventListener('input', autoBindNameAudio);
document.querySelector('input[name="age_value"]').addEventListener('input', autoBindAgeAudio);

// Обновляем позицию при загрузке
['child1', 'child2', 'parent1', 'parent2', 'extra', 'extra2'].forEach(key => {
  if (document.getElementById('img-' + key)) {
    svbUpdatePreviewTransform(key);
    svbDebugPrint(key);
  }
});

// Активируем управление плеером и клавиатурой
svbBindRealtimeControls();
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

  const cs = getComputedStyle(el);
  const r  = el.getBoundingClientRect();
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
    
    // === ИСПРАВЛЕНИЕ: Добавляем 'angle' и 'radius' в массив $pos ===
    // === НОВЫЙ КОНТРАКТ: геометрия приходит ТОЛЬКО из overlay_json ===
    $pos = [];
    foreach ($photo_keys as $pk) {
        // сюда позже запишем всё, что пришло с фронта
        $pos[$pk] = [];
    }


// === Разбор overlay_json: фронт прислал ГОТОВУЮ геометрию ===
// Центр (cx_norm/cy_norm) — одна система координат для браузера и FFmpeg.
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

            // управляющие параметры (всё, что крутит картинку)
            foreach ([
                's'       => 'scale',
                'sy'      => 'scaleY',
                'skew'    => 'skew',
                'skew_y'  => 'skewY',
                'angle'   => 'angle',
                'radius'  => 'radius',
                'opacity' => 'opacity',
                'glow'    => 'glow',
            ] as $k => $src) {
                if (!isset($rec[$src]) || !is_numeric($rec[$src])) {
                    continue;
                }
                $val = (float)$rec[$src];

                if ($src === 'radius') {
                    $pos[$pk][$k] = max(0, (int)round($val));          // px
                } elseif ($src === 'opacity' || $src === 'glow') {
                    $pos[$pk][$k] = max(0.0, min(100.0, $val));        // 0–100
                } else {
                    $pos[$pk][$k] = $val;                              // как есть
                }
            }

            // НОРМАЛИЗОВАННЫЙ центр bbox: 0..1 относительно 854×480
            if (isset($rec['cx_norm'])) {
                $pos[$pk]['cx_norm'] = max(0.0, min(1.0, (float)$rec['cx_norm']));
            }
            if (isset($rec['cy_norm'])) {
                $pos[$pk]['cy_norm'] = max(0.0, min(1.0, (float)$rec['cy_norm']));
            }

            // чисто для логов (ничего не используем для overlay)
            if (isset($rec['w_pred'])) {
                $pos[$pk]['w_pred'] = (int)$rec['w_pred'];
            }
            if (isset($rec['h_pred'])) {
                $pos[$pk]['h_pred'] = (int)$rec['h_pred'];
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

svb_align_log($job_dir, 'env.start', [
  'tpl'    => ['w'=>$original_w,'h'=>$original_h],
  'target' => ['w'=>$target_w,'h'=>$target_h],
  'tpl_duration_sec' => $tplDur,
  'ffmpeg_bin' => $ffmpeg,
  'ffmpeg_version' => trim(@shell_exec($ffmpeg.' -hide_banner -version 2>&1')),
]);

// Всю "форму" и мягкие края считаем заранее по PNG, чтобы не зависеть от фильтров ffmpeg
svb_dbg_write($job_dir, 'info.round_pre', 'apply round+feather directly to PNG for all photos');

foreach ($photo_keys as $pk) {
    if (empty($photos[$pk]) || !isset($pos[$pk])) {
        continue;
    }

    $r        = isset($pos[$pk]['radius']) ? (int)$pos[$pk]['radius'] : 0;
    $scalePct = isset($pos[$pk]['s'])      ? (int)$pos[$pk]['s']      : 100;
    $glowPct  = isset($pos[$pk]['glow'])   ? (float)$pos[$pk]['glow'] : 0.0;

    if ($r <= 0 && $glowPct <= 0) {
        // ничего не делаем – ни радиуса, ни мягких краёв
        continue;
    }

    svb_apply_manual_round_corners(
        $photos[$pk],
        $r,
        $scalePct,
        $target_w,
        $job_dir,
        $glowPct
    );

    // радиус/світлі краї уже "зашиты" в PNG – ffmpeg про них больше знать не должен
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
    // --- (Тайминги - без изменений) ---
    $A_NAME   = [ ['00:34:15','00:35:15'], ['01:42:18','01:43:18'], ['03:29:15','03:30:15'], ['05:50:19','05:51:19'] ];
    $A_AGE    = [ ['03:37:16','03:38:16'] ];
    $A_FACTS  = [ ['02:25:16','02:28:27'] ];
    $A_HOBBY  = [ ['02:32:00','02:36:27'] ];
    $A_PRAISE = [ ['05:54:10','05:57:15'] ];
    $A_REQUEST= [ ['06:19:04','06:22:27'] ];
    $P_CHILD1 = [ ['00:54:20','00:58:25'] ];
    $P_CHILD2 = [ ['02:17:14','02:21:25'] ];
    $P_PARENTS= [ ['06:35:03','06:43:13'] ];
    $P_EXTRA  = [ ['07:06:00','07:11:13'] ];
    $P_EXTRA2 = [ ['04:18:11','04:21:21'] ];

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
    
    $filter = [];
// сначала всё без format
$filter[] = "[0:v]fps=30,setsar=1,scale={$target_w}:{$target_h},setpts=PTS-STARTPTS[vbase_tmp]";
// потом отдельной строкой применяем format и помечаем выход
$filter[] = "[vbase_tmp]format=rgba[vbase]";


    $vlabel = "[vbase]";
    $vcount = 0;
$SOURCE_SQUARE = 709; // размер квадратного PNG после препроцессинга

$addOverlay = function($key, $intervals) use (
    &$filter, &$vlabel, &$vcount,
    $imgIndexMap, $pos,
    $HAS_FIFO, $HAS_SHEAR, $HAS_COLORCH,
    $target_w, $target_h, $job_dir
) {
    if (!isset($imgIndexMap[$key])) {
        svb_dbg_write($job_dir, "overlay.{$key}.skip", 'no image index for this key');
        return;
    }

    $idx = $imgIndexMap[$key];
    $p   = $pos[$key] ?? [];

    // 1) Центр в нормированных координатах (общее между браузером и FFmpeg)
    $cx_norm = isset($p['cx_norm']) ? (float)$p['cx_norm'] : 0.5;
    $cy_norm = isset($p['cy_norm']) ? (float)$p['cy_norm'] : 0.5;
    $cx_norm = max(0.0, min(1.0, $cx_norm));
    $cy_norm = max(0.0, min(1.0, $cy_norm));

    $cx = $cx_norm * $target_w;
    $cy = $cy_norm * $target_h;

    // 2) Масштаб и поворот/скошенность (то же, что крутится слайдерами)
    $scaleX = max(10, min(200, (int)round($p['s']  ?? 100))) / 100.0;
    $scaleY = max(10, min(200, (int)round($p['sy'] ?? 100))) / 100.0;

    $angle_deg = (float)($p['angle'] ?? 0.0);
    $angle_rad = $angle_deg * (M_PI / 180.0);
    $angle_str = rtrim(rtrim(sprintf('%.15F', $angle_rad), '0'), '.');

    $skewX_deg = isset($p['skew'])   ? (float)$p['skew']   : 0.0;
    $skewY_deg = isset($p['skew_y']) ? (float)$p['skew_y'] : 0.0;

    $opacity_val  = isset($p['opacity']) ? (float)$p['opacity'] : 100.0;
    $opacity_val  = max(0.0, min(100.0, $opacity_val));
    $opacity_norm = $opacity_val / 100.0;

    // 3) Базовый размер «контента» (как в svbComputeOverlayGeom на фронте)
    $w_src = max(2, (int)round($target_w * $scaleX));
    $h_src = max(2, (int)round($w_src     * $scaleY));

    // 4) Если есть shear — заранее добавляем паддинги, чтобы не резало углы
    $need_shear = $HAS_SHEAR && (abs($skewX_deg) > 0.001 || abs($skewY_deg) > 0.001);

    $pad_x = 0;
    $pad_y = 0;
    $w_padded = $w_src;
    $h_padded = $h_src;
    $shx = 0.0;
    $shy = 0.0;

    if ($need_shear) {
        $skewX_rad = $skewX_deg * (M_PI / 180.0);
        $skewY_rad = $skewY_deg * (M_PI / 180.0);

        $maxShiftX = abs(tan($skewX_rad)) * $h_src;
        $maxShiftY = abs(tan($skewY_rad)) * $w_src;

        $pad_margin = (int)ceil(max($maxShiftX, $maxShiftY));
        if ($pad_margin < 0) {
            $pad_margin = 0;
        }

        $pad_x = $pad_y = $pad_margin;
        $w_padded = $w_src + 2 * $pad_x;
        $h_padded = $h_src + 2 * $pad_y;
        if ($w_padded < 2) $w_padded = 2;
        if ($h_padded < 2) $h_padded = 2;

        // X — как в CSS, Y — с инвертированным знаком, чтобы картинка не отражалась
        $shx = tan($skewX_rad);
        $shy = -tan($skewY_rad);

        $shx = max(-2.0, min(2.0, $shx));
        $shy = max(-2.0, min(2.0, $shy));
    }

    // 5) Строим цепочку фильтров для этого изображения
    $baseLabel = "{$key}s{$vcount}_b";
    $chain = "[{$idx}:v]scale={$w_src}:{$h_src}:force_original_aspect_ratio=disable,setsar=1";

    if ($need_shear) {
        $chain .= ",pad={$w_padded}:{$h_padded}:{$pad_x}:{$pad_y}:color=black@0";
        $chain .= ",shear=shx={$shx}:shy={$shy}:fillcolor=none:interp=bilinear";
    }

    if (abs($angle_deg) > 0.0001) {
        $chain .= ",rotate=angle={$angle_str}:ow='rotw(iw)':oh='roth(ih)':c=none:bilinear=1";
    }

    $chain .= ",format=rgba[{$baseLabel}]";
    $filter[] = $chain;

    $currLabel = $baseLabel;

    // 6) Глобальная прозрачность (Opacity), если не 100%
    if ($HAS_COLORCH && abs($opacity_norm - 1.0) > 0.0001) {
        $opLabel = "{$key}s{$vcount}_op";
        $line = "[{$currLabel}]colorchannelmixer=aa={$opacity_norm}";
        if ($HAS_FIFO) {
            $line .= ",fifo";
        }
        $line .= "[{$opLabel}]";
        $filter[] = $line;
        $currLabel = $opLabel;
    } elseif ($HAS_FIFO) {
        $ffLabel = "{$key}s{$vcount}_ff";
        $line = "[{$currLabel}]fifo[{$ffLabel}]";
        $filter[] = $line;
        $currLabel = $ffLabel;
    }

    $finalOut = $currLabel;

    // 7) Overlay: позиционирование ТОЛЬКО по центру
    // w и h — реальные размеры результата после всех трансформаций
    $xExpr = sprintf('%.6F - w/2', $cx);
    $yExpr = sprintf('%.6F - h/2', $cy);

    $exprParts = [];
    foreach ($intervals as $it) {
        $exprParts[] = "between(t," . svb_ts_to_seconds($it[0]) . "," . svb_ts_to_seconds($it[1]) . ")";
    }
    $enable = implode('+', $exprParts);

    $filter[] = "{$vlabel}[{$finalOut}]overlay=x={$xExpr}:y={$yExpr}:enable='{$enable}'[vtmp{$vcount}]";
    $filter[] = "[vtmp{$vcount}]format=rgba[v{$vcount}]";

    $vlabel = "[v{$vcount}]";
    $vcount++;

    // лог для сверки с браузером
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
            'w_pad'     => $w_padded,
            'h_pad'     => $h_padded,
            'skewX_deg' => $skewX_deg,
            'skewY_deg' => $skewY_deg,
            'shx'       => $shx,
            'shy'       => $shy,
            'angle_deg' => $angle_deg,
            'angle_rad' => $angle_rad,
            'opacity'   => $opacity_norm,
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
function svb_dbg_push(){
    if (!isset($_POST['_svb_nonce']) || !wp_verify_nonce($_POST['_svb_nonce'], 'svb_nonce')) {
        wp_send_json_error('bad nonce');
    }
    $token = isset($_POST['token']) ? sanitize_text_field($_POST['token']) : '';
    if (!$token) wp_send_json_error('no token');

    // Пытаемся найти job_dir: либо ещё идёт (svb_job_data_), либо уже закончено (svb_job_)
    $data = get_transient('svb_job_data_'.$token);
    if (!$data || empty($data['job_dir'])) {
        $data = get_transient('svb_job_'.$token);
    }
    if (!$data || empty($data['dir']) && empty($data['job_dir'])) {
        wp_send_json_error('job not found');
    }
    $job_dir = !empty($data['job_dir']) ? $data['job_dir'] : $data['dir'];

    $payload_raw = isset($_POST['payload']) ? wp_unslash($_POST['payload']) : '';
    $payload = json_decode($payload_raw, true);
    if (!is_array($payload)) $payload = ['raw'=>$payload_raw];

    svb_align_log($job_dir, 'browser.dump', $payload);

    wp_send_json_success(['ok'=>1]);
}