<?php

function svb_dbg_write($job_dir, $label, $text){
    if (!SVB_DEBUG || !$job_dir) return;
    $file = rtrim($job_dir, '/').'/svb_debug.log';
    $ts   = date('Y-m-d H:i:s');
    $line = "[$ts] $label\n";
    $line .= is_string($text) ? $text : var_export($text, true);
    $line .= "\n-----------------------------\n";
    @file_put_contents($file, $line, FILE_APPEND);
}

function svb_align_log_open($job_dir){
    if (!$job_dir) return '';
    $p = rtrim($job_dir, '/').'/svb_align.jsonl';
    if (!file_exists($p)) { @file_put_contents($p, ""); }
    return $p;
}

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

function svb_exec_find($bin) {
    @exec('which ' . escapeshellarg($bin), $out, $rc);
    if ($rc === 0 && !empty($out[0])) return trim($out[0]);
    return false;
}

function svb_imagick_supports_format($format)
{
    if (!class_exists('Imagick') || !extension_loaded('imagick')) {
        return false;
    }

    try {
        $supported = Imagick::queryFormats(strtoupper($format));
        return !empty($supported);
    } catch (Throwable $e) {
        return false;
    }
}

function svb_can_transcode_heic()
{
    return svb_imagick_supports_format('HEIC') || svb_imagick_supports_format('HEIF');
}

function svb_ff_has_filter($ffmpeg, $name){
    @exec($ffmpeg . ' -hide_banner -filters', $out, $rc);
    if ($rc !== 0 || empty($out)) return false;
    foreach ($out as $line) {
        if (strpos($line, $name) !== false) return true;
    }
    return false;
}

function svb_ffprobe_duration($file) {
    $ffprobe = svb_exec_find('ffprobe');
    if (!$ffprobe || !file_exists($file)) return 0;
    $cmd = $ffprobe . ' -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 ' . escapeshellarg($file);
    $out = []; $rc = 0;
    @exec($cmd, $out, $rc);
    if ($rc !== 0 || empty($out)) return 0;
    return (float)$out[0];
}

function svb_transcode_image_to_rgba($ffmpeg, $src, $dst, $cropSize = 0, $job_dir = ''){
    $isWebp = (strtolower(pathinfo($dst, PATHINFO_EXTENSION)) === 'webp');

    // --- 1. Попытка через FFmpeg ---
    $filters = 'format=rgba';
    // Ресайз
    if ($cropSize > 0) {
        // Кроп в квадрат по центру
        $filters .= ',scale=' . $cropSize . ':' . $cropSize . ':force_original_aspect_ratio=increase';
        $filters .= ',crop=' . $cropSize . ':' . $cropSize;
    } else {
        // Сохраняем пропорции, но ограничиваем размер (оптимизация)
        $filters .= ',scale=\'min(1280,iw)\':\'min(1280,ih)\':force_original_aspect_ratio=decrease';
    }

    $cmd = $ffmpeg . ' -y -v error -i ' . escapeshellarg($src)
         . ' -frames:v 1 -vf "' . $filters . '" ';

    // Выбор кодека в зависимости от формата
    if ($isWebp) {
        // libwebp, качество 90, уровень сжатия 4 (баланс скорости/размера)
        $cmd .= ' -c:v libwebp -lossless 0 -compression_level 4 -q:v 90 ';
    } else {
        $cmd .= ' -f image2 ';
    }

    $cmd .= escapeshellarg($dst) . ' 2>&1';

    @exec($cmd, $o, $rc);

    // Если FFmpeg справился, выходим
    if ($rc === 0 && file_exists($dst)) return true;

    // Логируем ошибку FFmpeg, если он не справился, и переходим к PHP библиотекам
    svb_dbg_write($job_dir, 'warn.ffmpeg_transcode', [ 'src' => $src, 'dst' => $dst, 'out' => isset($o) ? implode("\n", $o) : '' ]);

    // --- 2. Попытка через Imagick (Предпочтительно для качества) ---
    if (class_exists('Imagick')) {
        try {
            $img = new Imagick($src);

            // Устанавливаем формат
            $img->setImageFormat($isWebp ? 'webp' : 'png');

            // Обеспечиваем наличие альфа-канала
            $img->setImageAlphaChannel(Imagick::ALPHACHANNEL_SET);

            // Исправляем ориентацию (если есть EXIF данные о повороте)
            try {
                $img->autoOrientImage();
            } catch (Throwable $e) {
                // fallback для старых версий Imagick
                $orientation = $img->getImageOrientation();
                switch ($orientation) {
                    case Imagick::ORIENTATION_BOTTOMRIGHT:
                        $img->rotateimage("#000", 180);
                        break;
                    case Imagick::ORIENTATION_RIGHTTOP:
                        $img->rotateimage("#000", 90);
                        break;
                    case Imagick::ORIENTATION_LEFTBOTTOM:
                        $img->rotateimage("#000", -90);
                        break;
                }
                $img->setImageOrientation(Imagick::ORIENTATION_TOPLEFT);
            }

            // Кроп или ресайз
            if ($cropSize > 0) {
                $img->setImageGravity(Imagick::GRAVITY_CENTER);
                $img->cropThumbnailImage($cropSize, $cropSize);
            } else {
                // Ограничиваем макс размер 1280px по широкой стороне
                $w = $img->getImageWidth();
                $h = $img->getImageHeight();
                if ($w > 1280 || $h > 1280) {
                    $img->scaleImage($w > $h ? 1280 : 0, $w > $h ? 0 : 1280);
                }
            }

            $img->writeImage($dst);
            $img->clear();
            $img->destroy();
            return file_exists($dst);
        } catch (Throwable $e) {
            svb_dbg_write($job_dir, 'warn.imagick_transcode', $e->getMessage());
        }
    }

    // --- 3. Попытка через GD (Fallback) ---
    $data = @file_get_contents($src);
    if ($data === false) return false;

    $srcImg = @imagecreatefromstring($data);
    if (!$srcImg) return false;

    // Обработка EXIF поворота для GD
    $exif = function_exists('exif_read_data') ? @exif_read_data($src) : null;
    if (!empty($exif['Orientation'])) {
        switch ($exif['Orientation']) {
            case 3: $srcImg = imagerotate($srcImg, 180, 0); break;
            case 6: $srcImg = imagerotate($srcImg, -90, 0); break;
            case 8: $srcImg = imagerotate($srcImg, 90, 0); break;
        }
    }

    $srcW = imagesx($srcImg);
    $srcH = imagesy($srcImg);

    // Расчет новых размеров
    if ($cropSize > 0) {
        $scale = max($cropSize / $srcW, $cropSize / $srcH);
        $scaledW = (int)ceil($srcW * $scale);
        $scaledH = (int)ceil($srcH * $scale);
    } else {
        // Лимит 1280px
        if ($srcW > 1280 || $srcH > 1280) {
            $scale = 1280 / max($srcW, $srcH);
            $scaledW = (int)ceil($srcW * $scale);
            $scaledH = (int)ceil($srcH * $scale);
        } else {
            $scaledW = $srcW;
            $scaledH = $srcH;
        }
    }

    $scaled = imagecreatetruecolor($scaledW, $scaledH);
    if (!$scaled) {
        imagedestroy($srcImg);
        return false;
    }

    // Сохранение прозрачности в GD
    imagealphablending($scaled, false);
    imagesavealpha($scaled, true);
    $transparent = imagecolorallocatealpha($scaled, 0, 0, 0, 127);
    imagefilledrectangle($scaled, 0, 0, $scaledW, $scaledH, $transparent);

    imagecopyresampled($scaled, $srcImg, 0, 0, 0, 0, $scaledW, $scaledH, $srcW, $srcH);
    imagedestroy($srcImg);

    // Финальный кроп (если нужен квадрат)
    if ($cropSize > 0) {
        $crop = imagecreatetruecolor($cropSize, $cropSize);
        imagealphablending($crop, false);
        imagesavealpha($crop, true);
        $transparentCrop = imagecolorallocatealpha($crop, 0, 0, 0, 127);
        imagefilledrectangle($crop, 0, 0, $cropSize, $cropSize, $transparentCrop);

        $offsetX = (int)floor(($scaledW - $cropSize) / 2);
        $offsetY = (int)floor(($scaledH - $cropSize) / 2);

        imagecopy($crop, $scaled, 0, 0, $offsetX, $offsetY, $cropSize, $cropSize);
        imagedestroy($scaled);
        $scaled = $crop; // Подменяем переменную для сохранения
    }

    // Сохранение файла
    if ($isWebp) {
        $result = imagewebp($scaled, $dst, 90); // WebP качество 90
    } else {
        $result = imagepng($scaled, $dst);
    }

    imagedestroy($scaled);
    return (bool)$result;
}

function svb_calculate_photo_position($scene, $strength_left, $strength_right) {
    // Базовые параметры сцены
    $base_x = isset($scene['photo_x']) ? (int)$scene['photo_x'] : 0;
    $base_y = isset($scene['photo_y']) ? (int)$scene['photo_y'] : 0;
    $base_width = isset($scene['photo_width']) ? (int)$scene['photo_width'] : 300;
    $base_height = isset($scene['photo_height']) ? (int)$scene['photo_height'] : 300;

    // Применяем strength (смещение в пикселях)
    $final_x = $base_x + $strength_left;
    $final_y = $base_y + $strength_right; // или наоборот, в зависимости от логики

    return [
        'x' => $final_x,
        'y' => $final_y,
        'width' => $base_width,
        'height' => $base_height,
        'ffmpeg_overlay' => "overlay={$final_x}:{$final_y}"
    ];
}
