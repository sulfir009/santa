<?php
function svb_request_name() {
    if (!check_ajax_referer('svb_nonce', '_svb_nonce', false)) {
        wp_send_json_error('Bad nonce');
    }

    $name_req = sanitize_text_field($_POST['name_req']);
    $email_req = sanitize_email($_POST['email_req']);

    if (!$name_req || !$email_req) {
        wp_send_json_error('Заповніть усі поля');
    }

    $admin_email = get_option('admin_email');
    $subject = 'Запит на нове ім\'я (Santa Video)';
    $message = "Користувач просить додати ім'я:\n\nІм'я/Наголос: $name_req\nEmail клієнта: $email_req";
    $headers = ['Content-Type: text/plain; charset=UTF-8'];

    wp_mail($admin_email, $subject, $message, $headers);

    wp_send_json_success('Запит відправлено! Ми сповістимо вас.');
}
function svb_save_config() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Permission denied');
    }
    if (!check_ajax_referer('svb_nonce', '_svb_nonce', false)) {
        wp_send_json_error('Bad nonce');
    }

    $videoId = sanitize_text_field($_POST['video_id']);
    $scenesRaw = wp_unslash($_POST['scenes']);
    $scenesData = json_decode($scenesRaw, true);

    if (!$videoId || !is_array($scenesData)) {
        wp_send_json_error('Invalid data');
    }

    for ($i = 1; $i <= 3; $i++) {
        $price_key = 'price_child_' . $i;
        if (isset($_POST[$price_key])) {
            $val = (int) sanitize_text_field(wp_unslash($_POST[$price_key]));
            if ($val < 0) {
                $val = 0;
            }
            if ($val > 100000) {
                $val = 100000;
            }
            update_option('svb_price_child_' . $i, $val);
        }
    }

    // 1. Получаем ПОЛНУЮ конфигурацию (Дефолтные настройки + то, что уже было в файле)
    // Функция svb_get_definitions() уже делает слияние за нас.
    $allVideos = svb_get_definitions();

    // 2. Обновляем в этом полном массиве данные ТОЛЬКО для текущего видео
    if (isset($allVideos[$videoId])) {
        $allVideos[$videoId]['scenes'] = $scenesData;
    } else {
        // Если вдруг видео с таким ID нет в дефолтах (маловероятно), создаем его
        $allVideos[$videoId] = [
            'label'  => $videoId,
            'scenes' => $scenesData
        ];
    }

    // 3. Подготавливаем чистый массив для сохранения в JSON.
    // Нам не нужно сохранять пути к файлам и URL (они хардкодом в PHP), сохраняем только 'scenes'.
    $configToSave = [];
    foreach ($allVideos as $vidKey => $vidData) {
        if (isset($vidData['scenes'])) {
            $configToSave[$vidKey] = [
                'scenes' => $vidData['scenes']
            ];
        }
    }

    // 4. Сохраняем полный список сцен всех видео
    $configFile = SVB_PLUGIN_DIR . 'svb_config.json';
    
    if (file_put_contents($configFile, json_encode($configToSave, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))) {
        wp_send_json_success('Налаштування збережено для ВСІХ шаблонів (у svb_config.json)');
    } else {
        wp_send_json_error('Помилка запису файлу. Перевірте права на папку плагіна (потрібні 755 або 775).');
    }
}

function svb_build_download_url($order_id, $token) {
    $order_id = absint($order_id);
    $token = sanitize_text_field($token);

    if (!$order_id || !$token) {
        return '';
    }

    return add_query_arg(
        [
            'svb_download' => 1,
            'order_id' => $order_id,
            'token' => $token,
        ],
        home_url('/')
    );
}
function svb_generate() {
    @ini_set('memory_limit', '512M'); 
    @ini_set('max_execution_time', 300);
    @ini_set('post_max_size', '64M');
    @ini_set('upload_max_filesize', '64M');

    // Функция поиска аудио
    $find_audio_path = function($filename) {
        if (!$filename) return false;
        $dirs = [
            SVB_PLUGIN_DIR . 'audio/name/boy/',
            SVB_PLUGIN_DIR . 'audio/name/girl/',
            SVB_PLUGIN_DIR . 'audio/name/',
            SVB_PLUGIN_DIR . 'audio/age/',
        ];
        foreach ($dirs as $dir) {
            if (file_exists($dir . $filename)) return $dir . $filename;
        }
        return false;
    };

    if (!isset($_POST['_svb_nonce']) || !wp_verify_nonce($_POST['_svb_nonce'], 'svb_nonce')) {
        wp_send_json_error('bad nonce');
    }
    $order_data = svb_init_user_order();
    $uid = $order_data['uid'] ?? '';

    $order_row = svb_order_create_or_load_for_session($uid, $order_data);
    if (is_wp_error($order_row)) {
        $db_error = $order_row->get_error_data()['db_error'] ?? '';
        if ($db_error) {
            svb_pay_log('invoice.order_error', ['db_error' => $db_error], $order_data);
        }
        wp_send_json_error('Order storage error');
    }

    $order_data['order_id'] = (int) ($order_row['order_id'] ?? ($order_data['order_id'] ?? 0));
    if (!empty($order_row['public_token'])) {
        $order_data['public_token'] = $order_row['public_token'];
        $order_data['token_hash'] = $order_row['token_hash'] ?? '';
    }

    $requested_child_count = isset($_POST['child_count']) ? (int) $_POST['child_count'] : 1;
    if ($requested_child_count < 1) $requested_child_count = 1;
    if ($requested_child_count > 3) $requested_child_count = 3;

    $payment_required = svb_monobank_get_token() && (svb_monobank_amount_for_children($requested_child_count) > 0);

    if ($payment_required && !current_user_can('manage_options')) {
        $payment_state = svb_orders_normalize_payment(svb_orders_decode_payment($order_row['payment'] ?? []));
        $paid_children = isset($payment_state['child_count']) ? (int) $payment_state['child_count'] : 0;
        $paid_fingerprint = isset($payment_state['paid_fingerprint']) ? $payment_state['paid_fingerprint'] : '';
        $current_fingerprint = isset($order_row['fingerprint_current']) ? $order_row['fingerprint_current'] : '';

        if (($payment_state['status'] ?? 'unpaid') !== 'paid') {
            wp_send_json_error('Оплата неуспішна або відсутня. Генерація відео не буде виконана.');
        }

        if ($paid_children && $requested_child_count > $paid_children) {
            wp_send_json_error('Оплата не відповідає вибраній кількості дітей.');
        }

        if ($current_fingerprint && $paid_fingerprint && !hash_equals($current_fingerprint, $paid_fingerprint)) {
            wp_send_json_error('Оплата неуспішна або параметри змінені. Генерація відео не буде виконана.');
        }
    }
    // === ЗБЕРЕЖЕННЯ ДАНИХ ЗАМОВНИКА (КРОК 1) ===
    if ($uid) {
        $cust_name = isset($_POST['customer_name']) ? sanitize_text_field(wp_unslash($_POST['customer_name'])) : '';
        $cust_email = isset($_POST['customer_email_step1']) ? sanitize_email(wp_unslash($_POST['customer_email_step1'])) : '';

        // Оновлюємо файл замовлення
        svb_update_user_order($uid, [
            'customer_name' => $cust_name,
            'customer_email' => $cust_email
        ]);
    }
    // ===========================================
    
    $defs = svb_get_definitions();
    $selected_video_id = isset($_POST['selected_video_id']) ? sanitize_text_field(wp_unslash($_POST['selected_video_id'])) : 'video1';
    if (!isset($defs[$selected_video_id])) $selected_video_id = 'video1';

    $current_def = $defs[$selected_video_id];
    $template_video_path = $current_def['file'];

    $uploads = wp_upload_dir();
    if (!empty($uploads['error'])) wp_send_json_error('uploads not writable');

    $job       = 'svb_' . wp_generate_password(8, false, false);
    $job_dir = trailingslashit($uploads['basedir']) . 'svb-jobs/' . $job;
    $job_url = trailingslashit($uploads['baseurl']) . 'svb-jobs/' . $job;

    if (!wp_mkdir_p($job_dir)) wp_send_json_error('cannot create job dir');

    // === LOGGING START ===
    svb_dbg_write($job_dir, '1. INITIAL_REQUEST', array(
        'selected_id' => $selected_video_id,
        'video_path'  => $template_video_path,
    ));

    $front_visuals = isset($_POST['debug_front_visuals']) ? json_decode(stripslashes($_POST['debug_front_visuals']), true) : 'NONE';
    svb_dbg_write($job_dir, '2. FRONT_VISUALS_DEBUG', $front_visuals);

    $raw_overlay_json = isset($_POST['overlay_json']) ? stripslashes($_POST['overlay_json']) : '';
    svb_dbg_write($job_dir, '3. RAW_OVERLAY_JSON', json_decode($raw_overlay_json, true));
    // === LOGGING END ===

    $template = file_exists($template_video_path) ? $template_video_path : SVB_PLUGIN_DIR . 'assets/template.mp4';
    if (!file_exists($template)) $template = SVB_PLUGIN_DIR . 'assets/template1.mp4';
    if (!file_exists($template)) wp_send_json_error('template.mp4 not found');

    $ffmpeg  = svb_exec_find('ffmpeg');  if (!$ffmpeg)  $ffmpeg  = '/opt/homebrew/bin/ffmpeg';
    $ffprobe = svb_exec_find('ffprobe'); if (!$ffprobe) $ffprobe = '/opt/homebrew/bin/ffprobe';
    $tplDur = svb_ffprobe_duration($template);

    $HAS_FIFO         = svb_ff_has_filter($ffmpeg, 'fifo');
    $HAS_AFIFO        = svb_ff_has_filter($ffmpeg, 'afifo');
    $HAS_ROUNDED      = svb_ff_has_filter($ffmpeg, 'roundedcorners');
    $HAS_SHEAR        = svb_ff_has_filter($ffmpeg, 'shear'); 
    $HAS_PERSPECTIVE = svb_ff_has_filter($ffmpeg, 'perspective');

    // === СОХРАНЕНИЕ ФОТО (WEBP) ===
    $photos = [];
    $photo_keys = ['child1','child2','parent1','parent2'];
    $allowedExt  = ['png','jpg','jpeg','webp','heic','heif'];
    $allowedMime = ['image/png','image/jpeg','image/webp','image/heic','image/heif','image/heic-sequence','image/heif-sequence'];

    foreach ($photo_keys as $pk) {
        $field = 'photo_' . $pk;
        if (!empty($_FILES[$field]['name']) && $_FILES[$field]['error'] === UPLOAD_ERR_OK) {
            $file     = $_FILES[$field];
            $checked  = wp_check_filetype_and_ext($file['tmp_name'], $file['name']);
            $ext      = strtolower($checked['ext'] ?? '');
            $mimeType = $checked['type'] ?? '';

            if (!$ext || !$mimeType || !in_array($ext, $allowedExt, true) || !in_array($mimeType, $allowedMime, true)) {
                wp_send_json_error('unsupported image type');
            }

            $base = $job_dir . '/' . $field;
            $tmp  = $base . '_orig.' . $ext;

            if (!@move_uploaded_file($_FILES[$field]['tmp_name'], $tmp)) {
                wp_send_json_error('cannot save photo ' . $field);
            }

            // Use PNG for FFmpeg overlays to avoid WebP incompatibilities
            $destFilePng = $base . '_rgba.png';
            $isHeic   = in_array($ext, ['heic','heif'], true);

            $transcoded = svb_transcode_image_to_rgba($ffmpeg, $tmp, $destFilePng, 0, $job_dir);

            if ($transcoded && file_exists($destFilePng)) {
                if (file_exists($tmp)) { @unlink($tmp); }
                $photos[$pk] = $destFilePng;
            } else {
                if ($isHeic) {
                    if (file_exists($destFilePng)) { @unlink($destFilePng); }
                    if (file_exists($tmp)) { @unlink($tmp); }
                    wp_send_json_error('HEIC/HEIF is not supported on this server (cannot convert to PNG).');
                }
                $photos[$pk] = $tmp; // fallback for non-HEIC
            }
        }
    }

    // === ПАРАМЕТРЫ ОВЕРЛЕЯ ===
    $pos = [];
    foreach ($photo_keys as $pk) $pos[$pk] = [];

    if (!empty($raw_overlay_json)) {
        $overlay_decoded = json_decode($raw_overlay_json, true);
        if (is_array($overlay_decoded)) {
            foreach ($photo_keys as $pk) {
                $scenesList = $overlay_decoded[$pk] ?? [];
                if (!is_array($scenesList)) continue;

                foreach ($scenesList as $sceneIdx => $rec) {
                    if (!is_array($rec)) continue;
                    if (!isset($pos[$pk][$sceneIdx])) $pos[$pk][$sceneIdx] = [];

                    $paramMap = [
                        'scale'=>'scale','scale_x'=>'scale_x', 'scaleY'=>'scaleY', 'skew'=>'skew', 'skewY'=>'skewY', 
                        'angle'=>'angle', 'radius'=>'radius', 'pleft'=>'pleft', 'pright'=>'pright',
                        'img_ratio'=>'img_ratio', 'opacity'=>'opacity', 'glow'=>'glow'
                    ];

                    foreach ($paramMap as $destKey => $srcKey) {
                        $val = null;
                        if (isset($rec[$srcKey])) $val = $rec[$srcKey];
                        elseif (isset($rec[strtolower($srcKey)])) $val = $rec[strtolower($srcKey)];
                        
                        if ($val !== null && is_numeric($val)) {
                            $pos[$pk][$sceneIdx][$destKey] = (float)$val;
                        }
                    }
                    foreach(['cx_norm','cy_norm'] as $f) {
                        if(isset($rec[$f])) $pos[$pk][$sceneIdx][$f] = $rec[$f];
                    }
                }
            }
        }
    }

    $original_w = 1920; 
    $original_h = 1080; 
    $target_w   = 854;  
    $target_h   = 480;  

    // === ОБРАБОТКА ФОТО (ГЕОМЕТРИЯ + СВЕЧЕНИЕ) ===
    foreach ($photo_keys as $pk) {
        if (empty($photos[$pk]) || !isset($pos[$pk])) continue;
        
// --- FIX: Шукаємо параметри у ВСІХ сценах персонажа (беремо максимальні) ---
        $scenes_list = isset($pos[$pk]) && is_array($pos[$pk]) ? $pos[$pk] : [];
        
        $r = 0;
        $glowPct = 0.0;
        $scalePct = 100; // Дефолт

        // 1. Проходимо по всіх сценах, щоб знайти увімкнене свічення або радіус
        foreach ($scenes_list as $sc) {
            if (isset($sc['radius']) && (int)$sc['radius'] > $r) {
                $r = (int)$sc['radius'];
            }
            if (isset($sc['glow']) && (float)$sc['glow'] > $glowPct) {
                $glowPct = (float)$sc['glow'];
            }
        }

        // 2. Scale (Zoom) беремо пріоритетно з першої сцени, бо це база для кропу,
        // але якщо сцени [0] немає, то шукаємо хоч десь.
        if (isset($scenes_list[0]['scale'])) {
            $scalePct = (int)$scenes_list[0]['scale'];
        } elseif (!empty($scenes_list)) {
            // Фолбек: беремо з першої наявної
            $first_k = array_key_first($scenes_list);
            if (isset($scenes_list[$first_k]['scale'])) {
                $scalePct = (int)$scenes_list[$first_k]['scale'];
            }
        }
        // --------------------------------------------------------------------------
        
        svb_dbg_write($job_dir, "debug.pre_process_$pk", [
            'radius' => $r, 
            'glow_raw' => $firstScene['glow'] ?? 'NOT_SET', 
            'glow_final' => $glowPct
        ]);

        if ($r > 0 || $glowPct > 0) {
            svb_apply_manual_round_corners($photos[$pk], $r, $scalePct, $target_w, $job_dir, $glowPct);
        }
    }

    // --- Аудио ---
    $audio_sel = [];
    if (!empty($_POST['name_audio'])) {
        $path = $find_audio_path(sanitize_file_name($_POST['name_audio']));
        if ($path) $audio_sel['name'] = $path;
    }
    if (!empty($_POST['name_audio_2'])) {
        $path = $find_audio_path(sanitize_file_name($_POST['name_audio_2']));
        if ($path) $audio_sel['name2'] = $path;
    }
    if (!empty($_POST['name_audio_3'])) {
        $path = $find_audio_path(sanitize_file_name($_POST['name_audio_3']));
        if ($path) $audio_sel['name3'] = $path;
    }

    $child_count = 1;
    if (isset($audio_sel['name2'])) $child_count = 2;
    if (isset($audio_sel['name3'])) $child_count = 3;

    $cats = ['facts', 'hobby', 'praise', 'request'];
    if ($child_count === 1) array_unshift($cats, 'age');

    foreach ($cats as $cat) {
        $key = $cat . '_audio';
        $fn = isset($_POST[$key]) ? basename(wp_unslash($_POST[$key])) : '';
        if ($fn) {
            $path = SVB_PLUGIN_DIR . 'audio/' . $cat . '/' . $fn;
            if (file_exists($path)) $audio_sel[$cat] = $path;
        }
    }

    // --- Тайминги ---
    $audT = $current_def['audio_timings'];
    $A_NAME    = $audT['name'];
    $A_AGE     = $audT['age'] ?? [];
    $A_FACTS   = $audT['facts'] ?? [];
    $A_HOBBY   = $audT['hobby'] ?? [];
    $A_PRAISE  = $audT['praise'] ?? [];
    $A_REQUEST = $audT['request'] ?? [];

    $fmtTime = function($sec) {
        $m = floor($sec / 60);
        $s = $sec % 60;
        $ms = ($sec - floor($sec)) * 1000;
        return sprintf("%02d:%02d.%03d", $m, $s, $ms);
    };

   $A_NAME_2 = []; 
    $A_NAME_3 = [];
    if ($child_count >= 2) {
        foreach ($A_NAME as $pair) {
             // 2-га дитина: зміщення +1 секунда
             $s = svb_ts_to_seconds($pair[0]) + 1.0; 
             $e = svb_ts_to_seconds($pair[1]) + 1.0;
             $A_NAME_2[] = [$fmtTime($s), $fmtTime($e)];
        }
    }
    if ($child_count >= 3) {
        foreach ($A_NAME as $pair) {
             // 3-тя дитина: зміщення +2 секунди (1+1)
             $s = svb_ts_to_seconds($pair[0]) + 2.0; 
             $e = svb_ts_to_seconds($pair[1]) + 2.0;
             $A_NAME_3[] = [$fmtTime($s), $fmtTime($e)];
        }
    }

    // Сцены
    $default_segments = $current_def['scenes'];
    $user_segments = [];
    if ( ! empty( $_POST['svb_segments'] ) ) {
        $raw = wp_unslash( $_POST['svb_segments'] ); 
        $decoded = json_decode( $raw, true );
        if ( is_array( $decoded ) ) $user_segments = $decoded;
    }
    $segments = array_merge( $default_segments, $user_segments );

    $P_CHILD1  = $segments['child1'] ?? [];
    $P_CHILD2  = $segments['child2'] ?? [];
    $P_PARENTS = $segments['parents'] ?? ($segments['parent1'] ?? []);

    $order_id = (int) ($order_data['order_id'] ?? 0);
    $permanent_photos = [];
    if ($order_id) {
        $upload_dirs = svb_get_orders_upload_dir($order_id);
        foreach ($photos as $key => $path) {
            $ext = pathinfo($path, PATHINFO_EXTENSION);
            $dest = trailingslashit($upload_dirs['photos']) . sanitize_file_name($key . '.' . $ext);
            @copy($path, $dest);
            $permanent_photos[$key] = $dest;
        }

        $fingerprint_params = [
            'child_count' => $child_count,
            'selected_video_id' => $selected_video_id,
            'voice' => array_keys($audio_sel),
            'segments' => $segments,
            'overlay_json' => json_decode($raw_overlay_json ?: '{}', true),
        ];

        $fingerprint_current = svb_compute_fingerprint($fingerprint_params, array_values($permanent_photos ?: $photos));

        global $wpdb;
        $table = $wpdb->prefix . 'svb_orders';
        $wpdb->update(
            $table,
            [
                'child_count' => $child_count,
                'selected_video_id' => $selected_video_id,
                'overlay_json' => wp_json_encode($fingerprint_params['overlay_json']),
                'segments' => wp_json_encode($segments),
                'voice' => wp_json_encode($audio_sel),
                'photos' => wp_json_encode($permanent_photos ?: $photos),
                'fingerprint_current' => $fingerprint_current,
            ],
            ['order_id' => $order_id],
            ['%d','%s','%s','%s','%s','%s','%s'],
            ['%d']
        );
    }

    // --- Сборка командной строки FFmpeg ---
    $inputs = [];
    $inputs[] = '-i ' . escapeshellarg($template);
    
    $imgIndexMap = [];
    foreach ($photos as $k => $png) {
        $inputs[] = '-i ' . escapeshellarg($png);
        $imgIndexMap[$k] = count($inputs) - 1;
    }
    
    $audIndexMap = [];
    foreach ($audio_sel as $cat => $path) {
        $inputs[] = '-i ' . escapeshellarg($path);
        $audIndexMap[$cat] = count($inputs) - 1;
    }

    /* === FILTER COMPLEX === */
    $filter = [];
    $filter[] = "[0:v]fps=24,setsar=1,scale={$target_w}:{$target_h},setpts=PTS-STARTPTS[vbase_tmp]";
    $filter[] = "[vbase_tmp]format=rgba[vbase]";

    $vlabel = "[vbase]";
    $vcount = 0;

    $addOverlay = function($key, $scenesConfig) use (
        &$filter, &$vlabel, &$vcount,
        $imgIndexMap, $pos,
        $HAS_FIFO, $HAS_ROUNDED, $HAS_SHEAR, $HAS_PERSPECTIVE,
        $target_w, $target_h, $job_dir
    ) {
        if (!isset($imgIndexMap[$key])) return;
        $idx = $imgIndexMap[$key];
        $geomScenes = isset($pos[$key]) && is_array($pos[$key]) ? $pos[$key] : [];

        foreach ($scenesConfig as $i => $sceneMeta) {
            $p = isset($geomScenes[$i]) ? $geomScenes[$i] : ($geomScenes[0] ?? []);
            
$rawStart = $sceneMeta['start'] ?? ($sceneMeta[0] ?? 0);
            $rawEnd   = $sceneMeta['end']   ?? ($sceneMeta[1] ?? 0);
            $startSec = svb_ts_to_seconds($rawStart);
            $endSec   = svb_ts_to_seconds($rawEnd);
            
            $adjStart = max(0, $startSec - 0.045);
            // Для кінця додаємо трохи, щоб не зникло раніше часу
            $adjEnd   = $endSec + 0.01; 

            $s_fmt = number_format($adjStart, 4, '.', ''); // 4 знаки для точності
            $e_fmt = number_format($adjEnd, 4, '.', '');
            // === FIX LAG: END ===
            
            $enableExpr = "between(t,{$s_fmt},{$e_fmt})";

            $cx_norm = isset($p['cx_norm']) ? (float)$p['cx_norm'] : 0.5;
            $cy_norm = isset($p['cy_norm']) ? (float)$p['cy_norm'] : 0.5;
            $cx = $cx_norm * $target_w;
            $cy = $cy_norm * $target_h;

// Отримуємо параметри (як у JS)
            $baseScale = isset($p['scale'])   ? (int)$p['scale']   : 100;
            $strX      = isset($p['scale_x']) ? (int)$p['scale_x'] : 100;
            $strY      = isset($p['scaleY'])  ? (int)$p['scaleY']  : 100;

            $S  = max(0.1, $baseScale / 100.0);
            $SX = max(0.1, $strX / 100.0);
            $SY = max(0.1, $strY / 100.0);

            $angle_deg = (float)($p['angle'] ?? 0.0);
            $skewX_deg = isset($p['skew'])    ? (float)$p['skew']        : 0.0;
            $skewY_deg = isset($p['skewY'])   ? (float)$p['skewY']       : 0.0;
            $ratio = (isset($p['img_ratio']) && $p['img_ratio'] > 0) ? (float)$p['img_ratio'] : 1.0;

// === FIX START: Компенсація Glow ===
            // Розраховуємо, скільки пікселів "з'їло" світіння при обробці фото
            $glowVal = isset($p['glow']) ? (float)$p['glow'] : 0.0;
            $scaleMultiplier = 1.0;

            if ($glowVal > 0) {
                $sigma = ($glowVal / 100.0) * 30.0;
                $paddingPx = ceil(($sigma * 3) + 2);
                
                // Припускаємо середній розмір обробленого фото (1152px - стандарт для Imagick у вашому коді)
                // Це не ідеально точно, але візуально компенсує втрату розміру
                $refW = 1152; 
                $contentW = $refW - ($paddingPx * 2);
                if ($contentW > 0) {
                    $scaleMultiplier = $refW / $contentW;
                }
            }

            // Базові розміри з урахуванням компенсації
            $w_src = max(2, (int)round($target_w * $S * $SX * $scaleMultiplier));
            
            $baseW_for_H = $target_w * $S;
            $h_src = max(2, (int)round(($baseW_for_H / $ratio) * $SY * $scaleMultiplier));
            // === FIX END ===
            $pleft  = isset($p['pleft'])  ? (int)$p['pleft']  : 0;
            $pright = isset($p['pright']) ? (int)$p['pright'] : 0;
            
            $chain  = "[{$idx}:v]format=rgba";
            $chain .= ",scale=w={$w_src}:h={$h_src}";

            if ($HAS_PERSPECTIVE && ($pleft != 0 || $pright != 0)) {
                // Додаємо відступ, рівний максимальному розтягненню
                $padding = max(0, $pleft, $pright);

                if ($padding > 0) {
                    $w_padded = $w_src;
                    $h_padded = $h_src + ($padding * 2); // Паддінг зверху і знизу
                    
                    // Центруємо оригінал по вертикалі
                    $chain .= ",pad=w={$w_padded}:h={$h_padded}:x=0:y={$padding}:color=black@0";
                    
                    // === НОВА ЛОГІКА КООРДИНАТ (FIXED) ===
                    // Координати для perspective беруться від 0 до H_padded
                    // x0,y0 (TL)  x1,y1 (TR)
                    // x2,y2 (BL)  x3,y3 (BR)

                    // Top Left:  0, Pad - StrL
                    $y0 = $padding - $pleft;
                    // Top Right: W, Pad - StrR
                    $y1 = $padding - $pright;
                    // Bot Left:  0, Pad + H + StrL
                    $y2 = $padding + $h_src + $pleft;
                    // Bot Right: W, Pad + H + StrR
                    $y3 = $padding + $h_src + $pright;

                    $chain .= ",perspective=x0=0:y0={$y0}:x1={$w_src}:y1={$y1}:x2=0:y2={$y2}:x3={$w_src}:y3={$y3}:interpolation=linear";
                    
                    // Оновлюємо висоту для наступних фільтрів
                    $h_src = $h_padded;
                }
            }

            // --- 2. ЛОГІКА SHEAR (SKEW) ---
            $need_shear = $HAS_SHEAR && (abs($skewX_deg) > 0.001 || abs($skewY_deg) > 0.001);
            $pad_x = 0; $pad_y = 0; 
            $w_padded = $w_src; $h_padded = $h_src;
            $shx = 0.0; $shy = 0.0;

            if ($need_shear) {
                $skewX_rad = ($skewX_deg * -1) * M_PI / 180.0;
                $skewY_rad = ($skewY_deg * -1) * M_PI / 180.0;
                
                $skewX_rad_abs = abs($skewX_deg) * M_PI / 180.0;
                $skewY_rad_abs = abs($skewY_deg) * M_PI / 180.0;
                
                // Рахуємо відступи, щоб при нахилі картинка не обрізалась
                $maxShiftX = abs(tan($skewX_rad_abs)) * $h_src;
                $maxShiftY = abs(tan($skewY_rad_abs)) * $w_src;
                $pad_margin = (int)ceil(max($maxShiftX, $maxShiftY));
                
                // Робимо паддінг симетричним + невеликий запас
                $w_padded = $w_src + 2 * $pad_margin;
                $h_padded = $h_src + 2 * $pad_margin;
                $pad_x = $pad_y = $pad_margin;
                
                $val_shx = tan($skewX_rad);
                $val_shy = tan($skewY_rad);

                $shx = number_format($val_shx, 6, '.', '');
                $shy = number_format($val_shy, 6, '.', '');

                $chain .= ",pad=w={$w_padded}:h={$h_padded}:x={$pad_x}:y={$pad_y}:color=black@0";
                $chain .= ",shear=shx={$shx}:shy={$shy}:fillcolor=black@0";

                // === FIX SYNC 100%: Компенсация сдвига от Shear ===
                // FFmpeg 'shear' працює з ПОВНИМ розміром кадру (після pad), а не тільки з контентом.
                // Тому зсув центру залежить від $h_padded та $w_padded.
                
                // 1. Считаем реальный сдвиг в пикселях на основе PADDED размеров
                $shift_px_x = $val_shx * $h_padded;
                $shift_px_y = $val_shy * $w_padded;

                // 2. Корректируем центр оверлея в обратную сторону
                $cx -= ($shift_px_x / 2.0);
                $cy -= ($shift_px_y / 2.0);
            }

            // --- 3. ROTATE & OPACITY ---
            $angle_rad = $angle_deg * M_PI / 180.0;
            $angle_str = number_format($angle_rad, 15, '.', '');
            $angle_str = rtrim(rtrim($angle_str, '0'), '.');

            // Debug transform parameters to trace sign/values mismatch
            svb_dbg_write($job_dir, "debug.transform_{$key}_sc{$i}", [
                'input_deg' => [
                    'skewX_deg' => $skewX_deg,
                    'skewY_deg' => $skewY_deg,
                    'angle_deg' => $angle_deg,
                ],
                'computed' => [
                    'need_shear'     => $need_shear,
                    'skewX_rad'      => isset($skewX_rad) ? $skewX_rad : 0.0,
                    'skewY_rad'      => isset($skewY_rad) ? $skewY_rad : 0.0,
                    'shx'            => $shx,
                    'shy'            => $shy,
                    'rotate_radians' => $angle_rad,
                    'angle_str'      => $angle_str,
                ],
                'filter_fragments' => [
                    'shear'  => $need_shear ? "shear=shx={$shx}:shy={$shy}:fillcolor=black@0" : 'disabled',
                    'rotate' => "rotate={$angle_str}:ow=rotw(iw):oh=roth(ih):c=none",
                ],
            ]);

            $chain .= ",rotate={$angle_str}:ow=rotw(iw):oh=roth(ih):c=none";

            $opacityPct = isset($p['opacity']) ? (float)$p['opacity'] : 100.0;
            $opacityVal = max(0, min(1, $opacityPct / 100.0));
            if ($opacityVal < 1.0) {
                $op_fmt = number_format($opacityVal, 3, '.', '');
                $chain .= ",colorchannelmixer=aa={$op_fmt}";
            }

            $tmpOut   = "{$key}_sc{$i}_tmp";
            $finalOut = "{$key}_sc{$i}_fin";

            $filter[] = $chain . "[{$tmpOut}]";
            $filter[] = "[{$tmpOut}]format=rgba[{$finalOut}]";

// === FIX START: Точне центрування ===
            // $cx і $cy приходять вже нормалізовані з JS
            $cx_fmt = number_format($cx, 4, '.', '');
            $cy_fmt = number_format($cy, 4, '.', '');

            // w і h - це змінні FFmpeg, що означають поточну ширину/висоту шару (overlay)
            // Оскільки ми використовуємо rotate/pad, розмір шару змінюється.
            // Формула (Center - w/2) гарантує, що центр шару співпаде з нашим розрахованим центром.
            $xExpr = "{$cx_fmt} - (w / 2)";
            $yExpr = "{$cy_fmt} - (h / 2)";
            // === FIX END ===

            $filter[] = "{$vlabel}[{$finalOut}]overlay=x={$xExpr}:y={$yExpr}:enable='{$enableExpr}'[vtmp{$vcount}]";
            $filter[] = "[vtmp{$vcount}]format=rgba[v{$vcount}]";
            $vlabel = "[v{$vcount}]";
            $vcount++;
        }
    };

    $addOverlay('child1',  $P_CHILD1);
    $addOverlay('child2',  $P_CHILD2);
    $addOverlay('parent1', $P_PARENTS);
    $addOverlay('parent2', $P_PARENTS);

    $filter[] = "{$vlabel}format=yuv420p[vfinal]";
    $finalV = '[vfinal]';

    // --- Аудио ---
    $audio_format_chain = ",aformat=sample_fmts=fltp:sample_rates=22050:channel_layouts=mono,aresample=async=1:first_pts=0";
    $amix_inputs = ['[abase]'];
    $filter[] = '[0:a]aformat=sample_fmts=fltp:sample_rates=22050:channel_layouts=mono,aresample=async=1:first_pts=0,volume=0.15[abase]';

    // Функция сборки аудио
    $makeAudioBlocks = function($cat, $intervals) use (&$filter, &$amix_inputs, $audIndexMap, $HAS_AFIFO, $tplDur, $audio_format_chain){
        if (!isset($audIndexMap[$cat]) || empty($intervals)) return;
        $idx = $audIndexMap[$cat];
        $isName = strpos($cat, 'name') === 0;
        $name_format_chain = ',aformat=sample_fmts=fltp:sample_rates=22050:channel_layouts=mono,aresample=async=0:first_pts=0';

        if (count($intervals) === 1) {
            [$stS, $enS] = $intervals[0];
            $st = svb_ts_to_seconds($stS);
            $len = max(0, svb_ts_to_seconds($enS) - $st);
            $segDur = $isName ? min($len, 1.007) : $tplDur;
            $ms = (int)round($st * 1000);
            $label = "[{$cat}a1]";

            $chain  = "[{$idx}:a]";
            if ($isName) $chain .= "atrim=0:{$segDur},";
            $chain .= "asetpts=PTS-STARTPTS";
            $chain .= $isName ? $name_format_chain : $audio_format_chain;
            $chain .= ",volume=0.4,adelay={$ms}:all=1";
            if (!$isName) $chain .= ",atrim=0:{$tplDur}";

            if ($HAS_AFIFO) $chain .= ",afifo";
            $chain .= "{$label}";

            $filter[] = $chain;
            $amix_inputs[] = $label;
            return;
        }

        $outs = [];
        for ($i=1; $i<=count($intervals); $i++) $outs[] = "[{$cat}s{$i}]";

        $filter[] = "[{$idx}:a]asplit=" . count($intervals) . implode('', $outs);

        for ($i=1; $i<=count($intervals); $i++){
            [$stS, $enS] = $intervals[$i-1];
            $st = svb_ts_to_seconds($stS);
            $len = max(0, svb_ts_to_seconds($enS) - $st);
            $segDur = $isName ? min($len, 1.007) : $tplDur;
            $ms = (int)round($st * 1000);
            $label = "[{$cat}a{$i}]";

            $chain  = "{$outs[$i-1]}";
            if ($isName) $chain .= "atrim=0:{$segDur},";
            $chain .= "asetpts=PTS-STARTPTS";
            $chain .= $isName ? $name_format_chain : $audio_format_chain;
            $chain .= ",volume=0.4,adelay={$ms}:all=1";

            if ($HAS_AFIFO) $chain .= ",afifo";
            $chain .= "{$label}";

            $filter[] = $chain;
            $amix_inputs[] = $label;
        }
    };

$makeAudioBlocks('name',   $A_NAME);
    
    // Додаємо блоки тільки якщо файли дійсно існують і відрізняються
    if (!empty($audio_sel['name2'])) {
        $makeAudioBlocks('name2', $A_NAME_2);
    }
    if (!empty($audio_sel['name3'])) {
        $makeAudioBlocks('name3', $A_NAME_3);
    }
   $makeAudioBlocks('age',    $A_AGE); 
    $makeAudioBlocks('facts',  $A_FACTS);
    $makeAudioBlocks('hobby',  $A_HOBBY); 
    $makeAudioBlocks('praise', $A_PRAISE); 
    $makeAudioBlocks('request',$A_REQUEST);

    if (count($amix_inputs) <= 1) {
        $filter[] = '[abase]asplit[aout]'; 
    } else {
        $chain  = implode('', $amix_inputs) . 'amix=inputs=' . count($amix_inputs) . ':duration=longest:dropout_transition=0:normalize=0';
        $chain .= ',alimiter=level_in=1:level_out=1:limit=1:attack=5:release=100';
        if ($HAS_AFIFO) $chain .= ',afifo'; 
        $chain .= '[aout]';
        $filter[] = $chain;
    }

    $filter_complex = implode(';', $filter);
    $output = $job_dir . '/video.mp4';
    
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
    . ' -r 24 -c:v libx264 -preset ultrafast -crf 30 -pix_fmt yuv420p -threads 0'
    . ' -c:a aac -b:a 64k -ar 22050 -ac 1'
    . ' -max_muxing_queue_size 8192'
    . ' -muxdelay 0 -muxpreload 0'
    . ' -movflags +faststart'
    . ' -t ' . escapeshellarg((string)$tplDur) 
    . ' ' . escapeshellarg($output);
    
    $logFile = $job_dir . '/ffmpeg.log';
    $pidFile = $job_dir . '/ffmpeg.pid';
    $rcFile  = $job_dir . '/ffmpeg.rc';
    if (file_exists($rcFile)) { @unlink($rcFile); }

    if (file_put_contents($logFile, "Init...\n") === false) {
        wp_send_json_error(['msg' => 'Помилка: Неможливо створити файл логу. Перевірте права на папку: ' . $job_dir]);
    }

    set_transient('svb_job_data_' . $job, [
        'job_dir'  => $job_dir,
        'logFile'  => $logFile,
        'output'   => $output,
        'pidFile'  => $pidFile,
        'tplDur'   => $tplDur,
        'job_url'  => $job_url,
        'rcFile'   => $rcFile,
        'cmd'      => $cmd,
    ], HOUR_IN_SECONDS);

    $cmd_bg = '(' . $cmd . ' > ' . escapeshellarg($logFile) . ' 2>&1; echo $? > ' . escapeshellarg($rcFile) . ') > /dev/null 2>&1 & echo $!';
    
    svb_dbg_write($job_dir, 'final.cmd_bg', $cmd_bg);
    
    $pid = null;
    $pid_out = [];
    $rc = -1;
    
    $last_line = exec($cmd_bg, $pid_out, $rc);
    $pid = is_string($last_line) ? trim($last_line) : null;

    $debug_log  = "=== DIAGNOSTIC INFO ===\n";
    $debug_log .= "Exit Code (RC): " . var_export($rc, true) . "\n";
    $debug_log .= "PID Value: " . var_export($pid, true) . "\n";
    $debug_log .= "Output Lines: " . print_r($pid_out, true) . "\n";
    $debug_log .= "Command: " . $cmd_bg . "\n";
    $debug_log .= "Job Dir: " . $job_dir . "\n";
    $debug_log .= "Log File Writable: " . (is_writable($job_dir) ? 'YES' : 'NO') . "\n";
    
    if (file_exists($logFile)) {
        $ffmpeg_log_content = file_get_contents($logFile);
        $debug_log .= "\n=== FFMPEG LOG CONTENT (first 500 chars) ===\n";
        $debug_log .= substr($ffmpeg_log_content, 0, 500) . "\n";
    }

    if ($pid && is_numeric($pid) && $pid > 0) {
        @file_put_contents($pidFile, $pid);
        svb_dbg_write($job_dir, 'final.pid', $pid);
        wp_send_json_success([ 'token' => $job ]);
    } else {
        svb_dbg_write($job_dir, 'final.pid.error', $debug_log);
        wp_send_json_error([
            'msg' => 'Не вдалося запустити завдання на сервері (exec failed).', 
            'log' => $debug_log
        ]);
    }
}



if (!function_exists('svb_apply_manual_round_corners')) {
    function svb_apply_manual_round_corners($file, $radiusCssPx, $scalePercent, $targetWidth, $job_dir = '', $glowPercent = 0) {
        // 1. Перевірка файлу
        if (!file_exists($file)) return false;

        // 2. Отримуємо розміри
        $info = @getimagesize($file);
        if (!$info) return false;
        [$width, $height] = $info;

        // 3. Розрахунок параметрів
        $scalePercent = max(1, (int)$scalePercent);
        $scaledWidth  = max(1, (int)round($targetWidth * ($scalePercent / 100.0)));
        $scaleFactor  = $scaledWidth > 0 ? ($width / $scaledWidth) : 1.0;

        $radius = (int)round($radiusCssPx * $scaleFactor * 0.35);
        $maxRadius    = (int)floor((min($width, $height) - 1) / 2);
        $radius       = max(0, min($radius, $maxRadius));
        $glowPercent = max(0.0, min(100.0, (float)$glowPercent));

        // Логування старту
        if (function_exists('svb_dbg_write')) {
            svb_dbg_write($job_dir, 'img_process_start', "File: $file, Glow: $glowPercent, Radius: $radius, OrigW: $width");
        }

        // =========================================================
        // СПРОБА 1: IMAGICK (Підтримує світіння/Glow)
        // =========================================================
        if (class_exists('Imagick')) {
            try {
                $img = new Imagick();
                $img->readImage($file);
                
                // Примусово переходимо в PNG
                $img->setImageFormat('png');
                $img->setImageAlphaChannel(Imagick::ALPHACHANNEL_SET);

                $origW = $img->getImageWidth();
                $origH = $img->getImageHeight();

            
                if ($glowPercent > 0) {
                
                    $sigma = ($glowPercent / 100.0) * 30.0;
                    
                    // Відступ має бути 3 * sigma (правило Гауса) + 2px запас
                    $padding = (int)ceil(($sigma * 3) + 2);
                    
                    // Перевірка безпеки: Padding не може з'їсти всю картинку (макс 40%)
                    $maxSafePadding = (int)(min($origW, $origH) * 0.4);
                    if ($padding > $maxSafePadding) {
                        $padding = $maxSafePadding;
                        // Якщо зображення дуже мале і паддінг завеликий, зменшуємо сігму
                        $sigma = max(0.1, ($padding - 2) / 3);
                    }

                    // Нові розміри контенту (фото)
                    $contentW = $origW - ($padding * 2);
                    $contentH = $origH - ($padding * 2);
                    
                    if ($contentW > 0 && $contentH > 0) {
                        // Зменшуємо фото, щоб звільнити місце для світіння
                        $img->resizeImage($contentW, $contentH, Imagick::FILTER_LANCZOS, 1);
                    }

                    // Створюємо прозоре полотно оригінального розміру
                    $canvas = new Imagick();
                    $canvas->newImage($origW, $origH, new ImagickPixel('transparent'), 'png');
                    
                    // Центруємо зменшене фото на полотні
                    $x = ($origW - $img->getImageWidth()) / 2;
                    $y = ($origH - $img->getImageHeight()) / 2;
                    $canvas->compositeImage($img, Imagick::COMPOSITE_DEFAULT, $x, $y);
                    
                    $img->clear(); $img->destroy();
                    $img = $canvas;
                    
                    // Оновлюємо розміри для маски
                    $width = $img->getImageWidth();
                    $height = $img->getImageHeight();
                }

                // --- КРОК Б: Закруглення кутів ---
                if ($radius > 0) {
                    $mask = new Imagick();
                    $mask->newImage($width, $height, new ImagickPixel('transparent'), 'png');
                    
                    $draw = new ImagickDraw();
                    $draw->setFillColor('#FFFFFF'); 
                    
                    if ($glowPercent > 0 && isset($padding)) {
                        // Малюємо маску тільки для області контенту (всередині padding)
                        // Коригуємо радіус, бо картинка зменшилась
                        $ratioW = $width / ($width + ($padding*2)); // прибл.
                        $adjRadius = $radius; // Можна залишити оригінальний або трохи зменшити
                        
                        $draw->roundRectangle($padding, $padding, $width - $padding, $height - $padding, $adjRadius, $adjRadius);
                    } else {
                        $draw->roundRectangle(0, 0, $width, $height, $radius, $radius);
                    }
                    
                    $mask->drawImage($draw);
                    // Обрізаємо
                    $img->compositeImage($mask, Imagick::COMPOSITE_DSTIN, 0, 0);
                    
                    $mask->clear(); $mask->destroy(); 
                    $draw->clear(); $draw->destroy();
                }

                // --- КРОК В: Генерація світіння (Glow) ---
                if ($glowPercent > 0 && isset($sigma) && $sigma > 0) {
                    // Клонуємо вирізане фото
                    $glow = clone $img;
                    
                    // Робимо його повністю білим (або жовтуватим, якщо треба)
                    // EVALUATE_SET замінює пікселі на заданий колір, зберігаючи форму альфа-каналу
                    $maxQuantum = defined('Imagick::QUANTUM_RANGE') ? Imagick::QUANTUM_RANGE : 65535;
                    $glow->evaluateImage(Imagick::EVALUATE_SET, $maxQuantum, Imagick::CHANNEL_RED);
                    $glow->evaluateImage(Imagick::EVALUATE_SET, $maxQuantum, Imagick::CHANNEL_GREEN);
                    $glow->evaluateImage(Imagick::EVALUATE_SET, $maxQuantum, Imagick::CHANNEL_BLUE);
                    
                    // Розмиваємо білий силует
                    // Sigma * 3 - це радіус візуального згасання.
                    // Ми вже виділили під нього місце в $padding.
                    $glow->blurImage($sigma, $sigma);
                    
                    // Накладаємо оригінальне фото ПОВЕРХ розмитого світіння
                    $glow->compositeImage($img, Imagick::COMPOSITE_OVER, 0, 0);
                    
                    $img->clear(); $img->destroy();
                    $img = $glow;
                }

                $img->setImageFormat('png'); 
                $img->writeImage($file); 
                $img->clear(); $img->destroy();

                if (function_exists('svb_dbg_write')) {
                    svb_dbg_write($job_dir, 'img_process_success', "Processed with Imagick (Glow: $glowPercent, Padding: " . ($padding ?? 0) . ")");
                }
                return true;

            } catch (Throwable $e) {
                if (function_exists('svb_dbg_write')) {
                    svb_dbg_write($job_dir, 'warn.imagick_crashed', $e->getMessage());
                }
            }
        }

        // =========================================================
        // ВАРІАНТ 2: GD (Запасний)
        // =========================================================
        // ... (Тут лишається старий код для GD без змін, бо GD не вміє гарний Glow) ...
        if (!function_exists('imagecreatetruecolor')) return false;
        $fileContent = @file_get_contents($file);
        if (!$fileContent) return false;
        $srcImg = @imagecreatefromstring($fileContent);
        if (!$srcImg) return false;
        imagealphablending($srcImg, false);
        imagesavealpha($srcImg, true);
        if ($radius > 0) {
            $mask = imagecreatetruecolor($width, $height);
            imagealphablending($mask, false);
            imagesavealpha($mask, true);
            $maskTransparent = imagecolorallocatealpha($mask, 0, 0, 0, 127); 
            $maskOpaque = imagecolorallocatealpha($mask, 0, 0, 0, 0);
            imagefilledrectangle($mask, 0, 0, $width, $height, $maskTransparent);
            $drawX = 0; $drawY = 0; $drawW = $width; $drawH = $height;
            imagefilledrectangle($mask, $drawX+$radius, $drawY, $drawW-$radius, $drawH, $maskOpaque);
            imagefilledrectangle($mask, $drawX, $drawY+$radius, $drawW, $drawH-$radius, $maskOpaque);
            imagefilledellipse($mask, $radius*2, $radius*2, $radius*2, $radius*2, $maskOpaque);
            imagefilledellipse($mask, $drawW-$radius*2-1, $radius*2, $radius*2, $radius*2, $maskOpaque);
            imagefilledellipse($mask, $radius*2, $drawH-$radius*2-1, $radius*2, $radius*2, $maskOpaque);
            imagefilledellipse($mask, $drawW-$radius*2-1, $drawH-$radius*2-1, $radius*2, $radius*2, $maskOpaque);
            for ($y = 0; $y < $height; $y++) {
                for ($x = 0; $x < $width; $x++) {
                    $alphaM = (imagecolorat($mask, $x, $y) >> 24) & 0x7F;
                    if ($alphaM == 127) {
                        imagesetpixel($srcImg, $x, $y, $maskTransparent);
                    }
                }
            }
            imagedestroy($mask);
        }
        imagepng($srcImg, $file);
        imagedestroy($srcImg);
        return true;
    }
}

function svb_check_progress() {
    // Перевірка безпеки
    if (!isset($_POST['_svb_nonce']) || !wp_verify_nonce($_POST['_svb_nonce'], 'svb_nonce')) {
        wp_send_json_error('bad nonce');
    }
    $token = isset($_POST['token']) ? sanitize_text_field($_POST['token']) : '';
    if (!$token) wp_send_json_error('no token');

    // Отримуємо дані про завдання
    $data = get_transient('svb_job_data_'.$token);
    
    if (!$data) {
        wp_send_json_error(['status' => 'error', 'msg' => 'Сесія закінчилася. Спробуйте ще раз.']);
    }

    $logFile    = $data['logFile'];
    $outputFile = $data['output'];
    $tplDur     = (float)$data['tplDur'];
    
    // Якщо тривалість не визначилась (0), ставимо 8 хвилин (480с) як заглушку, щоб прогрес йшов
    if ($tplDur <= 0) $tplDur = 480.0;

    clearstatcache(true, $logFile);
    clearstatcache(true, $outputFile);

    $percent = 0;
    $is_finished = false;
    $log_content = '';
    $debug_tail = '';

    if (file_exists($logFile)) {
        $size = filesize($logFile);
        $readSize = 10240; 
        $offset = max(0, $size - $readSize);
        $log_content = file_get_contents($logFile, false, null, $offset);
        
        // Для дебагу в консоль
        $debug_tail = substr($log_content, -300);

        if ($log_content) {
            // Шукаємо час у форматі time=00:03:23.49
            if (preg_match_all('/time=\s*([\d:.]+)/', $log_content, $matches)) {
                $last_time_str = end($matches[1]);
                $current_sec = svb_ts_to_seconds($last_time_str);
                
                // Додаємо в дебаг, щоб бачити, як парситься час
                $debug_tail .= "\n[PHP Parsing] String: $last_time_str -> Seconds: $current_sec -> Duration: $tplDur";

                if ($tplDur > 0) {
                    $percent = min(99, floor(($current_sec / $tplDur) * 100));
                }
            }

            // Шукаємо фініш
            if (strpos($log_content, 'muxing overhead') !== false || strpos($log_content, 'global headers:') !== false) {
                $is_finished = true;
                $percent = 100;
            }
        }
    }

    // Якщо відео готове
    $rcFile = isset($data['rcFile']) ? $data['rcFile'] : '';
    $cmdRun = isset($data['cmd']) ? $data['cmd'] : '';

    if ($rcFile && file_exists($rcFile)) {
        $rcVal = (int) trim((string) @file_get_contents($rcFile));
        if ($rcVal !== 0 && (!file_exists($outputFile) || filesize($outputFile) === 0)) {
            $logTail = '';
            if (file_exists($logFile)) {
                $size = filesize($logFile);
                $readSize = 8192;
                $offset = max(0, $size - $readSize);
                $logTail = file_get_contents($logFile, false, null, $offset) ?: '';
            }
            svb_dbg_write($data['job_dir'], 'warn.ffmpeg_render', [
                'cmd' => $cmdRun,
                'rc' => $rcVal,
                'stderr' => $logTail,
            ]);

            delete_transient('svb_job_data_'.$token);
            delete_transient('svb_job_'.$token);

            wp_send_json_error(['status' => 'error', 'msg' => 'Помилка під час рендерингу відео. Спробуйте ще раз.']);
        }
    }

    if ($is_finished && file_exists($outputFile) && filesize($outputFile) > 10000) {
        svb_schedule_cleanup($data['job_dir']);
        @unlink($data['pidFile']);
        @unlink($logFile);
        if ($rcFile) { @unlink($rcFile); }
        
        $videoUrl = trailingslashit($data['job_url']) . 'video.mp4';
        $permanent_path = $outputFile;

        if (isset($_COOKIE['svb_user_uid'])) {
            $uid = sanitize_text_field($_COOKIE['svb_user_uid']);
            $order_data = svb_init_user_order();
            $order_row = svb_order_create_or_load_for_session($uid, $order_data);

            $order_id = (int) ($order_row && !is_wp_error($order_row) ? ($order_row['order_id'] ?? 0) : ($order_data['order_id'] ?? 0));
            $upload_dirs = $order_id ? svb_get_orders_upload_dir($order_id) : null;
            $permanent_path = $upload_dirs ? trailingslashit($upload_dirs['result']) . 'video.mp4' : $outputFile;
            if ($upload_dirs) {
                @copy($outputFile, $permanent_path);
                $videoUrl = str_replace(trailingslashit(wp_upload_dir()['basedir']), trailingslashit(wp_upload_dir()['baseurl']), $permanent_path);
            }
            svb_update_user_order($uid, [
                'order_id' => $order_id,
                'video_generated' => true,
                'video_path' => $permanent_path,
                'video_url' => $videoUrl,
                'video_time' => time()
            ]);

            if ($order_id && !is_wp_error($order_row)) {
                global $wpdb;
                $table = $wpdb->prefix . 'svb_orders';
                $result = [
                    'video_path' => $permanent_path,
                    'generated_at' => current_time('mysql'),
                ];
                $wpdb->update($table, ['result' => wp_json_encode($result)], ['order_id' => $order_id], ['%s'], ['%d']);

                $verified_order = svb_get_order_by_id($order_id);
                $order_exists = (bool) $verified_order;

                if ($verified_order && !empty($verified_order['public_token'])) {
                    $downloadUrl = svb_build_download_url($order_id, $verified_order['public_token']);
                    if ($downloadUrl) {
                        $videoUrl = $downloadUrl;
                    }
                }

                if (!empty($data['job_dir'])) {
                    $masked_download_url = $videoUrl;
                    if ($videoUrl && strpos($videoUrl, 'token=') !== false) {
                        $masked_download_url = preg_replace('/(token=)([^&#]+)/', '$1***', $videoUrl);
                    }
                    svb_dbg_write($data['job_dir'], 'download.url', [
                        'order_id' => $order_id,
                        'order_exists' => $order_exists,
                        'storage' => 'table',
                        'download_url' => $masked_download_url,
                        'abs_path' => $permanent_path,
                    ]);
                }
            } elseif (!empty($data['job_dir'])) {
                svb_dbg_write($data['job_dir'], 'download.url', [
                    'order_id' => $order_id,
                    'order_exists' => false,
                    'storage' => 'table',
                    'download_url' => $videoUrl,
                    'abs_path' => $permanent_path,
                    'reason' => is_wp_error($order_row) ? $order_row->get_error_message() : 'order_missing',
                ]);
            }
        }

        set_transient('svb_job_'.$token, [ 'dir'=>$data['job_dir'], 'url'=>$videoUrl ], HOUR_IN_SECONDS);
        delete_transient('svb_job_data_'.$token);
        
        wp_send_json_success(['status' => 'done', 'url' => $videoUrl]);

    } else {
        // Якщо файл ще створюється, але прогрес 0% - покажемо хоча б 1%
        if ($percent === 0 && file_exists($data['pidFile'])) {
            $percent = 1;
        }

        wp_send_json_success([
            'status'  => 'running', 
            'percent' => $percent,
            'debug'   => ['tail' => $debug_tail] // Передаємо інфо для консолі
        ]);
    }
}
function svb_confirm(){
    if (!isset($_POST['_svb_nonce']) || !wp_verify_nonce($_POST['_svb_nonce'], 'svb_nonce')) {
        wp_send_json_error('bad nonce');
    }
    $email = isset($_POST['email']) ? sanitize_email($_POST['email']) : '';
    $token = isset($_POST['token']) ? sanitize_text_field($_POST['token']) : '';
    if (!$token) wp_send_json_error('no token');
    
    $data = get_transient('svb_job_'.$token); 
    
    if (!$data || empty($data['url'])) {
        $data_progress = get_transient('svb_job_data_'.$token);
        if ($data_progress) {
             wp_send_json_error('Video is still processing.');
        }
        wp_send_json_error('Video not found or expired.');
    }

    if ($email) {
        // === ОНОВЛЕННЯ EMAIL В ЗАМОВЛЕННІ ===
        if (isset($_COOKIE['svb_user_uid'])) {
            $uid = sanitize_text_field($_COOKIE['svb_user_uid']);
            svb_update_user_order($uid, ['email' => $email]);
        }
        // ====================================

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

    $data = get_transient('svb_job_data_'.$token);
    if (!$data || empty($data['job_dir'])) {
        $data = get_transient('svb_job_'.$token);
    }
    if (!$data || (empty($data['dir']) && empty($data['job_dir']))) {
        wp_send_json_error('job not found');
    }
    $job_dir = !empty($data['job_dir']) ? $data['job_dir'] : $data['dir'];

    $payload_raw = isset($_POST['payload']) ? wp_unslash($_POST['payload']) : '';
      $payload = json_decode($payload_raw, true);
      if (!is_array($payload)) $payload = ['raw'=>$payload_raw];

      svb_align_log($job_dir, 'browser.dump', $payload);

      wp_send_json_success(['ok'=>1]);
}

function svb_pay_should_log() {
    return (defined('SVB_DEBUG') && SVB_DEBUG);
}

function svb_pay_trim($value, $limit = 800) {
    $str = is_string($value) ? $value : wp_json_encode($value);
    if (strlen($str) > $limit) {
        return substr($str, 0, $limit) . '...';
    }
    return $str;
}

function svb_pay_log($message, $context = [], $order_data = []) {
    if (!svb_pay_should_log()) return;
    $job_dir = (is_array($order_data) && !empty($order_data['job_dir'])) ? $order_data['job_dir'] : '';
    if ($job_dir && function_exists('svb_dbg_write')) {
        svb_dbg_write($job_dir, 'pay.debug', [
            'message' => $message,
            'context' => $context,
        ]);
        return;
    }

    error_log('[SVB PAY] ' . $message . (!empty($context) ? ' ' . wp_json_encode($context) : ''));
}

function svb_payment_gate() {
    if (!check_ajax_referer('svb_nonce', '_svb_nonce', false)) {
        wp_send_json_error('Bad nonce');
    }

    $order_data = svb_init_user_order();
    $uid = $order_data['uid'] ?? '';
    if (!$uid) {
        wp_send_json_error('Session missing');
    }

    $order_id_req = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
    $token_req = isset($_POST['token']) ? sanitize_text_field(wp_unslash($_POST['token'])) : '';

    $child_count = isset($_POST['child_count']) ? (int) $_POST['child_count'] : 1;
    if ($child_count < 1) $child_count = 1;
    if ($child_count > 3) $child_count = 3;

    $selected_video_id = isset($_POST['selected_video_id']) ? sanitize_text_field(wp_unslash($_POST['selected_video_id'])) : '';
    $overlay_json_raw = isset($_POST['overlay_json']) ? wp_unslash($_POST['overlay_json']) : '';
    $overlay_json = json_decode($overlay_json_raw, true);
    if (!is_array($overlay_json)) {
        $overlay_json = [];
    }

    $segments_raw = isset($_POST['segments']) ? wp_unslash($_POST['segments']) : '';
    $segments = json_decode($segments_raw, true);
    if (!is_array($segments)) {
        $segments = [];
    }

    $voice_raw = isset($_POST['voice_payload']) ? wp_unslash($_POST['voice_payload']) : '';
    $voice = json_decode($voice_raw, true);
    if (!is_array($voice)) {
        $voice = [];
    }

    $photo_hashes_raw = isset($_POST['photo_hashes']) ? wp_unslash($_POST['photo_hashes']) : '[]';
    $photo_hashes = json_decode($photo_hashes_raw, true);
    if (!is_array($photo_hashes)) {
        $photo_hashes = [];
    }

    $fingerprint_params = [
        'child_count' => $child_count,
        'selected_video_id' => $selected_video_id,
        'voice' => $voice,
        'segments' => $segments,
        'overlay_json' => $overlay_json,
    ];
    $fingerprint_current = svb_compute_fingerprint_from_hashes($fingerprint_params, $photo_hashes);

    $order_row = null;
    $storage = 'table';

    if ($order_id_req && $token_req) {
        $order_row = svb_get_order_by_id_and_token($order_id_req, $token_req);
    }

    if (!$order_row) {
        $order_row = svb_get_order_row_by_uid($uid, $order_data);
    }

    if (is_wp_error($order_row)) {
        $db_error = $order_row->get_error_data()['db_error'] ?? '';
        if ($db_error) {
            svb_pay_log('payment_gate.order_error', ['db_error' => $db_error], $order_data);
        }
        wp_send_json_error('Order storage error');
    }

    if (!$order_row) {
        svb_pay_log('payment_gate.no_order_row', ['uid' => $uid]);
        wp_send_json_error('Order not found');
    }

    $payment = svb_orders_normalize_payment(svb_orders_decode_payment($order_row['payment'] ?? []));
    $paid_fingerprint = isset($payment['paid_fingerprint']) ? $payment['paid_fingerprint'] : '';
    $is_status_paid = in_array($payment['status'], ['paid', 'success'], true);
    $is_paid_for_current = ($is_status_paid && $paid_fingerprint && hash_equals($paid_fingerprint, $fingerprint_current));
    $reason = 'not_paid';

    if ($is_paid_for_current) {
        $reason = 'paid_match';
    } elseif ($is_status_paid && (!$paid_fingerprint || !hash_equals($paid_fingerprint, $fingerprint_current))) {
        $reason = 'fingerprint_changed';
        $new_row = svb_create_new_order_for_session($uid, ['payment' => svb_get_payment_defaults()]);
        if (is_wp_error($new_row)) {
            $db_error = $new_row->get_error_data()['db_error'] ?? '';
            if ($db_error) {
                svb_pay_log('payment_gate.new_order_error', ['db_error' => $db_error], $order_data);
            }
            wp_send_json_error('Order storage error');
        }
        $order_row = $new_row;
        $payment = svb_orders_normalize_payment(svb_get_payment_defaults());
        $is_paid_for_current = false;
    }

    svb_update_order_fingerprint(
        $order_row['order_id'],
        $fingerprint_current,
        [
            'child_count' => $child_count,
            'selected_video_id' => $selected_video_id,
            'overlay_json' => wp_json_encode($overlay_json),
            'segments' => wp_json_encode($segments),
            'voice' => wp_json_encode($voice),
            'photos' => wp_json_encode(['hashes' => $photo_hashes]),
        ]
    );

    svb_update_user_order($uid, [
        'order_id' => (int) $order_row['order_id'],
        'public_token' => $order_row['public_token'] ?? '',
        'token_hash' => $order_row['token_hash'] ?? '',
        'fingerprint_current' => $fingerprint_current,
    ]);

    $response = [
        'order_id' => (int) $order_row['order_id'],
        'public_token' => $order_row['public_token'] ?? '',
        'fingerprint_current' => $fingerprint_current,
        'paid_fingerprint' => $paid_fingerprint,
        'payment_status' => $payment['status'] ?? 'unpaid',
        'is_paid_for_current' => $is_paid_for_current,
        'reason' => $reason,
        'storage' => $storage,
    ];

    if (svb_pay_should_log()) {
        svb_pay_log('payment_gate.result', [
            'order_id' => $response['order_id'],
            'payment_status' => $response['payment_status'],
            'paid_fingerprint' => $response['paid_fingerprint'],
            'fingerprint_current' => $fingerprint_current,
            'reason' => $reason,
        ]);
    }

    wp_send_json_success($response);
}

function svb_monobank_create_invoice() {
    if (!check_ajax_referer('svb_nonce', '_svb_nonce', false)) {
        wp_send_json_error('Bad nonce');
    }

    $is_admin = current_user_can('manage_options');
    $child_count = isset($_POST['child_count']) ? (int) $_POST['child_count'] : 0;
    if ($child_count < 1 || $child_count > 3) {
        wp_send_json_error('Invalid child_count');
    }

    $order_data = svb_init_user_order();
    $uid = $order_data['uid'] ?? '';
    $order_id_req = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
    $token_req = isset($_POST['token']) ? sanitize_text_field(wp_unslash($_POST['token'])) : '';

    if ($order_id_req && $token_req) {
        $order_row = svb_get_order_by_id_and_token($order_id_req, $token_req);
        if (!$order_row) {
            svb_pay_log('invoice.order_not_found', ['order_id' => $order_id_req]);
            wp_send_json_error('Order not found');
        }
    } else {
        $order_row = svb_order_create_or_load_for_session($uid, $order_data);
        if (is_wp_error($order_row)) {
            $db_error = $order_row->get_error_data()['db_error'] ?? '';
            if ($db_error) {
                svb_pay_log('invoice.order_error', ['db_error' => $db_error], $order_data);
            }
            wp_send_json_error('Order storage error');
        }
    }

    $order_data['order_id'] = (int) ($order_row['order_id'] ?? ($order_data['order_id'] ?? 0));
    if (!empty($order_row['public_token'])) {
        $order_data['public_token'] = $order_row['public_token'];
        $order_data['token_hash'] = $order_row['token_hash'] ?? '';
    }

    $order_row = svb_order_create_or_load_for_session($uid, $order_data);
    if (is_wp_error($order_row)) {
        $db_error = $order_row->get_error_data()['db_error'] ?? '';
        if ($db_error) {
            svb_pay_log('invoice.order_error', ['db_error' => $db_error], $order_data);
        }
        wp_send_json_error('Order storage error');
    }

    $order_data['order_id'] = (int) ($order_row['order_id'] ?? ($order_data['order_id'] ?? 0));
    if (!empty($order_row['public_token'])) {
        $order_data['public_token'] = $order_row['public_token'];
        $order_data['token_hash'] = $order_row['token_hash'] ?? '';
    }

    svb_pay_log('invoice.start', [
        'child_count' => $child_count,
        'is_admin' => $is_admin,
        'payment_disabled_request' => ($is_admin && isset($_POST['payment_disabled']) && $_POST['payment_disabled'] === '1'),
    ], $order_data);

    if ($is_admin && isset($_POST['payment_disabled']) && $_POST['payment_disabled'] === '1') {
        svb_pay_log('invoice.bypass_admin', ['uid' => $uid], $order_data);
        wp_send_json_success(['bypass' => true]);
    }

    if (!svb_monobank_get_token()) {
        svb_pay_log('invoice.no_token');
        wp_send_json_error('Payment is not configured.');
    }

    $price_map = svb_get_price_map_uah();
    $uah = (int) ($price_map[$child_count] ?? 0);
    if ($uah <= 0) {
        svb_pay_log('invoice.invalid_amount', [
            'child_count' => $child_count,
            'price_map' => $price_map,
        ], $order_data);
        wp_send_json_error('Invalid amount for selected children');
    }

    $amount = (int) ($uah * 100);

    $return_raw = isset($_POST['return_url']) ? esc_url_raw(wp_unslash($_POST['return_url'])) : '';
    $return_path = $return_raw ? wp_parse_url($return_raw, PHP_URL_PATH) : '/';
    $return_url = add_query_arg('svb_payment_return', '1', home_url($return_path ?: '/'));

    $reference = 'SVB-' . ($order_data['order_id'] ?? 'order') . '-' . wp_generate_password(6, false, false);
    $comment = sprintf('Santa Video, kids=%d, order=%s', $child_count, $order_data['order_id'] ?? 'unknown');

    svb_pay_log('invoice.request', [
        'child_count' => $child_count,
        'amount_kop' => $amount,
        'ccy' => 980,
        'reference' => $reference,
        'endpoint' => 'https://api.monobank.ua/api/merchant/invoice/create',
        'return_path' => $return_path,
    ], $order_data);

    $invoice = svb_monobank_create_invoice_request($amount, $return_url, $reference, $comment);
    if (is_wp_error($invoice)) {
        $err_data = $invoice->get_error_data();
        $status = is_array($err_data) ? ($err_data['status'] ?? '') : '';
        $body_snippet = (is_array($err_data) && isset($err_data['body'])) ? svb_pay_trim($err_data['body']) : '';

        svb_pay_log('invoice.request_failed', [
            'http_code' => $status,
            'body_snippet' => $body_snippet,
            'message' => $invoice->get_error_message(),
        ], $order_data);

        $message_parts = [];
        if ($status) $message_parts[] = 'http=' . $status;
        if ($body_snippet) $message_parts[] = 'body=' . $body_snippet;
        $public_message = $message_parts ? ('mono api ' . implode(' ', $message_parts)) : $invoice->get_error_message();
        wp_send_json_error($public_message);
    }

    $invoice_id = isset($invoice['invoiceId']) ? sanitize_text_field($invoice['invoiceId']) : '';
    $http_status = isset($invoice['_http_status']) ? (int) $invoice['_http_status'] : 0;
    $raw_body = isset($invoice['_raw_body']) ? $invoice['_raw_body'] : '';
    $body_snippet = $raw_body ? svb_pay_trim($raw_body) : '';

    svb_pay_log('invoice.created', [
        'http_code' => $http_status ?: 200,
        'body_snippet' => $body_snippet,
        'has_page_url' => !empty($invoice['pageUrl']),
        'invoice_id' => $invoice_id,
    ], $order_data);

    unset($invoice['_http_status'], $invoice['_raw_body']);

    svb_update_user_payment_state($uid, [
        'status' => 'pending',
        'invoice_id' => $invoice_id,
        'reference' => $reference,
        'amount' => $amount,
        'child_count' => $child_count,
    ]);

    wp_send_json_success([
        'pageUrl' => $invoice['pageUrl'] ?? '',
        'invoiceId' => $invoice_id,
        'amount' => $amount,
    ]);
}

function svb_monobank_check_status() {
    if (!check_ajax_referer('svb_nonce', '_svb_nonce', false)) {
        wp_send_json_error('Bad nonce');
    }

    $invoice_id = isset($_POST['invoice_id']) ? sanitize_text_field(wp_unslash($_POST['invoice_id'])) : '';
    $order_data = svb_init_user_order();
    $uid = $order_data['uid'] ?? '';
    $payment_state = svb_get_user_payment_state($uid);

    if (!$invoice_id && !empty($payment_state['invoice_id'])) {
        $invoice_id = $payment_state['invoice_id'];
    }

    if (!$invoice_id) {
        wp_send_json_error('Invoice not found');
    }

    $status = svb_monobank_get_invoice_status($invoice_id);
    if (is_wp_error($status)) {
        wp_send_json_error($status->get_error_message());
    }

    $remote_status = $status['status'] ?? '';
    $is_reference_valid = true;
    if (!empty($payment_state['reference']) && isset($status['paymentDetails']['merchantPaymInfo']['reference'])) {
        $is_reference_valid = ($payment_state['reference'] === $status['paymentDetails']['merchantPaymInfo']['reference']);
    }

    if (!$is_reference_valid) {
        wp_send_json_error('Invoice does not match this session');
    }

    $normalized_status = 'pending';
    if ($remote_status === 'success') {
        $normalized_status = 'paid';
    } elseif (in_array($remote_status, ['failure', 'expired', 'canceled', 'reversed'], true)) {
        $normalized_status = 'failed';
    }

    svb_update_user_payment_state($uid, [
        'status' => $normalized_status,
        'invoice_id' => $invoice_id,
    ]);

    wp_send_json_success([
        'status' => $normalized_status,
        'invoiceId' => $invoice_id,
    ]);
}
