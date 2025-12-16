<?php

function svb_get_orders_dir() {
    $upload = wp_upload_dir();
    $dir = trailingslashit($upload['basedir']) . 'svb-orders';
    if (!file_exists($dir)) {
        wp_mkdir_p($dir);
        // Створюємо файл захисту і лічильник, якщо немає
        @file_put_contents($dir . '/index.php', '<?php // Silence is golden');
        @file_put_contents($dir . '/.htaccess', 'deny from all');
        @file_put_contents($dir . '/counter.txt', '0');
    }
    return $dir;
}

function svb_get_next_order_id() {
    $dir = svb_get_orders_dir();
    $counterFile = $dir . '/counter.txt';

    $fp = fopen($counterFile, 'c+');
    if (flock($fp, LOCK_EX)) {
        $id = (int)fread($fp, filesize($counterFile) ?: 1);
        $id++;
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, (string)$id);
        flock($fp, LOCK_UN);
    } else {
        $id = time(); // Fallback
    }
    fclose($fp);
    return $id;
}

function svb_init_user_order() {
    $cookie_name = 'svb_user_uid';
    $dir = svb_get_orders_dir();

    // 1. Сначала ищем существующий ID в куках
    $uid = isset($_COOKIE[$cookie_name]) ? sanitize_text_field($_COOKIE[$cookie_name]) : '';
    $need_set_cookie = false;

    // 2. Если куки нет - пробуем найти активный ордер по IP (защита от дублей при F5)
    if (!$uid) {
        $user_ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        // Ищем файл, созданный менее 1 часа назад с таким же IP
        $files = glob($dir . '/order_*.json');
        if ($files) {
            // Сортируем по дате модификации (свежие в начале)
            usort($files, function($a, $b) { return filemtime($b) - filemtime($a); });

            foreach ($files as $fpath) {
                // Смотрим только файлы за последний час
                if (time() - filemtime($fpath) > 3600) break;

                $tmp = json_decode(file_get_contents($fpath), true);
                if (isset($tmp['ip']) && $tmp['ip'] === $user_ip) {
                    $uid = $tmp['uid']; // Нашли старый UID по IP
                    break;
                }
            }
        }
    }

    // 3. Если все равно нет UID - генерируем новый
    if (!$uid) {
        $uid = uniqid('u');
        $need_set_cookie = true;
    } else {
        // Если UID нашли (по IP), но куки не было - надо её поставить
        if (!isset($_COOKIE[$cookie_name])) {
            $need_set_cookie = true;
        }
    }

    // 4. Устанавливаем куки
    if ($need_set_cookie && !headers_sent()) {
        setcookie($cookie_name, $uid, time() + (86400 * 30), '/', COOKIE_DOMAIN);
        $_COOKIE[$cookie_name] = $uid;
    }

    // 5. Работаем с файлом
    $file = $dir . '/order_' . $uid . '.json';

    if (!file_exists($file)) {
        $order_id = svb_get_next_order_id();
        $data = [
            'uid' => $uid,
            'order_id' => $order_id,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
            'created_at' => date('Y-m-d H:i:s'),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'video_generated' => false,
            'video_path' => '',
            'video_url' => '',
            'video_time' => 0,
            'email' => '', // Email получателя видео (Крок 3)
            'customer_name' => '', // Новое поле: Имя заказчика (Крок 1)
            'customer_email' => '' // Новое поле: Email заказчика (Крок 1)
        ];
        file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    } else {
        $data = json_decode(file_get_contents($file), true);
        if (!is_array($data)) {
             @unlink($file);
             return svb_init_user_order();
        }
    }

    return $data;
}

function svb_update_user_order($uid, $updates = []) {
    $dir = svb_get_orders_dir();
    $file = $dir . '/order_' . $uid . '.json';
    if (file_exists($file)) {
        $content = file_get_contents($file);
        $data = json_decode($content, true);
        if (is_array($data)) {
            $data = array_merge($data, $updates);
            file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }
    }
}

function svb_init_cookie_logic() {
    // Не запускаем в админке, но запускаем в AJAX и на фронте
    if (!is_admin() || defined('DOING_AJAX')) {
        svb_init_user_order();
    }
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
