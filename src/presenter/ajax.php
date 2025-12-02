<?php

if (!defined('ABSPATH')) { exit; }

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
    
        // === НОВЫЙ КОНТРАКТ: геометрия приходит ТОЛЬКО из overlay_json ===
    $pos = [];
    foreach ($photo_keys as $pk) {
        $pos[$pk] = [];
    }

    // === Разбор overlay_json, присланного фронтом ===
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

                // управляющие параметры
                $map = [
                    's'       => 'scale',   // масштаб X (%)
                    'sy'      => 'scaleY',  // масштаб Y (%)
                    'skew'    => 'skew',    // наклон X (градусы)
                    'skew_y'  => 'skewY',   // наклон Y (градусы)
                    'angle'   => 'angle',   // поворот (градусы)
                    'radius'  => 'radius',  // радиус скругления (px)
                    'opacity' => 'opacity', // 0–100
                    'glow'    => 'glow',    // 0–100
                ];

                foreach ($map as $dstKey => $srcKey) {
                    if (!isset($rec[$srcKey]) || !is_numeric($rec[$srcKey])) {
                        continue;
                    }
                    $val = (float)$rec[$srcKey];

                    if ($srcKey === 'radius') {
                        $pos[$pk][$dstKey] = max(0, (int)round($val));
                    } elseif ($srcKey === 'opacity' || $srcKey === 'glow') {
                        $pos[$pk][$dstKey] = max(0.0, min(100.0, $val));
                    } else {
                        $pos[$pk][$dstKey] = $val;
                    }
                }

                // нормализованный центр bbox: 0..1 по 854×480
                if (isset($rec['cx_norm'])) {
                    $pos[$pk]['cx_norm'] = max(0.0, min(1.0, (float)$rec['cx_norm']));
                }
                if (isset($rec['cy_norm'])) {
                    $pos[$pk]['cy_norm'] = max(0.0, min(1.0, (float)$rec['cy_norm']));
                }

                // чисто для лога
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

    // Радиус/світлі краї теперь зашиты в PNG,
    // дальше в ffmpeg про них знать не нужно
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
    // --- (Тайминги озвучки - без изменений) ---
    $A_NAME   = [ ['00:34:15','00:35:15'], ['01:42:18','01:43:18'], ['03:29:15','03:30:15'], ['05:50:19','05:51:19'] ];
    $A_AGE    = [ ['03:37:16','03:38:16'] ];
    $A_FACTS  = [ ['02:25:16','02:28:27'] ];
    $A_HOBBY  = [ ['02:32:00','02:36:27'] ];
    $A_PRAISE = [ ['05:54:10','05:57:15'] ];
    $A_REQUEST= [ ['06:19:04','06:22:27'] ];

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

    // 1) Центр в нормированных координатах (общих для браузера и FFmpeg)
    $cx_norm = isset($p['cx_norm']) ? (float)$p['cx_norm'] : 0.5;
    $cy_norm = isset($p['cy_norm']) ? (float)$p['cy_norm'] : 0.5;
    $cx_norm = max(0.0, min(1.0, $cx_norm));
    $cy_norm = max(0.0, min(1.0, $cy_norm));

    $cx = $cx_norm * $target_w;
    $cy = $cy_norm * $target_h;

    // 2) Масштаб, поворот и наклоны (то, что крутят слайдеры)
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

    // 3) Размер «контента» (как на фронте)
    $w_src = max(2, (int)round($target_w * $scaleX));
    $h_src = max(2, (int)round($w_src     * $scaleY));

    // 4) Если есть shear — добавляем паддинги, чтобы не резало углы
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

        // X как в CSS, Y с инвертированным знаком, чтобы не «отражать» картинку
        $shx = tan($skewX_rad);
        $shy = -tan($skewY_rad);

        $shx = max(-2.0, min(2.0, $shx));
        $shy = max(-2.0, min(2.0, $shy));
    }

    // 5) Цепочка фильтров для картинки
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

    // 6) Прозрачность (opacity)
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

    // 7) Overlay по центру (w и h — фактический размер после всех трансформаций)
    $xExpr = sprintf('%.6F - w/2', $cx);
    $yExpr = sprintf('%.6F - h/2', $cy);

    // enable по интервалам
    $exprParts = [];
    foreach ($intervals as $it) {
        $exprParts[] = "between(t," . svb_ts_to_seconds($it[0]) . "," . svb_ts_to_seconds($it[1]) . ")";
    }
    $enable = implode('+', $exprParts);

    $filter[] = "{$vlabel}[{$finalOut}]overlay=x={$xExpr}:y={$yExpr}:enable='{$enable}'[vtmp{$vcount}]";
    $filter[] = "[vtmp{$vcount}]format=rgba[v{$vcount}]";

    $vlabel = "[v{$vcount}]";
    $vcount++;

    svb_align_log($job_dir, "overlay.calc.{$key}", [
        'video_space' => ['W' => $target_w, 'H' => $target_h],
        'inputs'      => $p,
        'intervals'   => $intervals, // <— сюда прилетают интервалы в секундах
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
            'opacity'   => $opacity_val,
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