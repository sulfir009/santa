<?php

if (!defined('ABSPATH')) { exit; }

function svb_ts_to_seconds($ts) {
    $ts = trim((string)$ts);
    if (preg_match('/^\d{1,2}:\d{2}:\d{2}$/', $ts)) {
        [$mm, $ss, $cc] = array_map('intval', explode(':', $ts));
        return $mm * 60 + $ss + ($cc / 100);
    }
    if (preg_match('/^(\d{2}):(\d{2}):(\d{2})\.(\d{2})$/', $ts, $m)) {
        return ((int)$m[1] * 3600) + ((int)$m[2] * 60) + (int)$m[3] + ((int)$m[4] / 100);
    }
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
function svb_ff_has_filter($ffmpeg, $name){
    if (!$ffmpeg) return false;
    $cmd = $ffmpeg . ' -hide_banner -v 0 -filters 2>/dev/null';
    $out = @shell_exec($cmd);
    if (!$out) return false;
    return (bool)preg_match('/\b'.preg_quote($name, '/').'\b/i', $out);
}

function svb_ffprobe_duration($file) {
    $ffprobe = svb_exec_find('ffprobe');
    if (!$ffprobe) return 480.0; 
    $cmd = $ffprobe.' -v error -show_entries format=duration -of default=nw=1:nk=1 '.escapeshellarg($file).' 2>/dev/null';
    $out = @shell_exec($cmd);
    $sec = $out ? (float)$out : 0.0;
    if ($sec <= 0) return 480.0;
    return $sec;
}
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
    function svb_apply_manual_round_corners($file, $radiusCssPx, $scalePercent, $targetWidth, $job_dir = '', $glowPercent = 0) {
        if ($radiusCssPx <= 0) return true;
        if (!file_exists($file)) return false;

        $info = @getimagesize($file);
        if (!$info) return false;
        [$width, $height] = $info;
        if ($width <= 0 || $height <= 0) return false;
        $scalePercent = max(1, (int)$scalePercent);
        $scaledWidth  = max(1, (int)round($targetWidth * ($scalePercent / 100.0)));
        $scaleFactor  = $scaledWidth > 0 ? ($width / $scaledWidth) : 1.0;

        $radius    = (int)round($radiusCssPx * $scaleFactor);
        $maxRadius = (int)floor((min($width, $height) - 1) / 2);
        if ($maxRadius < 1) $maxRadius = 1;
        $radius = max(1, min($radius, $maxRadius));
        if ($radius <= 0) return true;

        if (class_exists('Imagick')) {
            try {
                $img = new Imagick($file);
                if (method_exists($img, 'autoOrient')) {
                    $img->autoOrient();
                }
                $img->setImageFormat('png');
                $img->setImageAlphaChannel(Imagick::ALPHACHANNEL_SET);
                $img->roundCorners($radius, $radius);
                $glowPercent = max(0.0, min(100.0, (float)$glowPercent));
                if ($glowPercent > 0) {
                    $sigma = max(0.5, min(5.0, $glowPercent / 20.0));
                    try {
                        $img->blurImage($sigma, $sigma);
                    } catch (Throwable $e) {
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

        $maskTransparent = imagecolorallocatealpha($mask, 0, 0, 0, 127); 
        $maskOpaque      = imagecolorallocatealpha($mask, 0, 0, 0, 0);   
        imagefilledrectangle($mask, 0, 0, $width, $height, $maskTransparent);
        imagefilledrectangle($mask, $radius, 0, $width - $radius, $height, $maskOpaque);
        imagefilledrectangle($mask, 0, $radius, $width, $height - $radius, $maskOpaque);

        $diameter = $radius * 2;
        imagefilledellipse($mask, $radius, $radius, $diameter, $diameter, $maskOpaque);
        imagefilledellipse($mask, $width - $radius - 1, $radius, $diameter, $diameter, $maskOpaque);
        imagefilledellipse($mask, $radius, $height - $radius - 1, $diameter, $diameter, $maskOpaque);
        imagefilledellipse($mask, $width - $radius - 1, $height - $radius - 1, $diameter, $diameter, $maskOpaque);

        $glowPercent = max(0.0, min(100.0, (float)$glowPercent));
        if ($glowPercent > 0 && function_exists('imagefilter') && defined('IMG_FILTER_GAUSSIAN_BLUR')) {
            $passes = max(1, min(8, (int)ceil($glowPercent / 15)));
            for ($i = 0; $i < $passes; $i++) {
                @imagefilter($mask, IMG_FILTER_GAUSSIAN_BLUR);
            }
        }

        $transparentColor = imagecolorallocatealpha($img, 0, 0, 0, 127);
        $cache = [];

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $rgba  = imagecolorat($mask, $x, $y);
                $alpha = ($rgba & 0x7F000000) >> 24; 

                if ($alpha === 0) {
                    continue;
                }

                if ($alpha >= 127) {
                    imagesetpixel($img, $x, $y, $transparentColor);
                    continue;
                }

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
