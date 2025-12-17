<?php

function svb_get_orders_dir() {
    $upload = wp_upload_dir();
    $dir = trailingslashit($upload['basedir']) . 'svb-orders';
    if (!file_exists($dir)) {
        wp_mkdir_p($dir);
        // Створюємо файл захисту і лічильник, якщо немає
        @file_put_contents($dir . '/index.php', '<?php // Silence is golden');
        @file_put_contents($dir . '/.htaccess', "deny from all\n");
        @file_put_contents($dir . '/counter.txt', '0');
    }
    return $dir;
}

function svb_get_orders_upload_dir($order_id) {
    $base = trailingslashit(svb_get_orders_dir()) . 'orders/' . absint($order_id);
    $photos = $base . '/photos';
    $result = $base . '/result';

    wp_mkdir_p($photos);
    wp_mkdir_p($result);

    @file_put_contents($base . '/index.php', '<?php // Silence');
    @file_put_contents($base . '/.htaccess', "deny from all\n");

    return [
        'base' => $base,
        'photos' => $photos,
        'result' => $result,
    ];
}

function svb_orders_v2_enabled() {
    return defined('SVB_ORDERS_V2') && SVB_ORDERS_V2;
}

function svb_get_orders_v2_table() {
    global $wpdb;
    return $wpdb->prefix . 'svb_orders_v2';
}

function svb_install_orders_v2_table() {
    global $wpdb;
    $table = svb_get_orders_v2_table();
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE {$table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        order_id BIGINT UNSIGNED NOT NULL,
        public_token VARCHAR(191) NOT NULL,
        token_hash CHAR(64) NOT NULL,
        fingerprint_current CHAR(64) DEFAULT '' NOT NULL,
        fingerprint_paid CHAR(64) DEFAULT '' NOT NULL,
        selected_video_id VARCHAR(191) DEFAULT '' NOT NULL,
        customer_name VARCHAR(191) DEFAULT '' NOT NULL,
        customer_email VARCHAR(191) DEFAULT '' NOT NULL,
        session_id VARCHAR(191) DEFAULT '' NOT NULL,
        ip VARCHAR(64) DEFAULT '' NOT NULL,
        user_agent TEXT,
        payment_status VARCHAR(50) DEFAULT 'unpaid' NOT NULL,
        payment_invoice_id VARCHAR(191) DEFAULT '' NOT NULL,
        payment_reference VARCHAR(191) DEFAULT '' NOT NULL,
        payment_modified DATETIME NULL,
        payment_payload LONGTEXT,
        overlay_json LONGTEXT,
        segments LONGTEXT,
        voice LONGTEXT,
        photos LONGTEXT,
        result LONGTEXT,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY order_id (order_id),
        KEY token_hash (token_hash),
        KEY session_id (session_id)
    ) {$charset_collate};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
}

function svb_get_next_order_id() {
    global $wpdb;
    $table = $wpdb->prefix . 'svb_orders';

    $last = (int) $wpdb->get_var("SELECT MAX(order_id) FROM {$table}");
    if ($last > 0) {
        return $last + 1;
    }

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

function svb_install_orders_table() {
    global $wpdb;
    $table = $wpdb->prefix . 'svb_orders';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE {$table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        order_id BIGINT UNSIGNED NOT NULL,
        token_hash CHAR(64) NOT NULL,
        created_at DATETIME NOT NULL,
        ip VARCHAR(64) DEFAULT '' NOT NULL,
        user_agent TEXT,
        session_id VARCHAR(128) DEFAULT '' NOT NULL,
        child_count TINYINT NOT NULL DEFAULT 1,
        selected_video_id VARCHAR(191) DEFAULT '' NOT NULL,
        customer_name VARCHAR(191) DEFAULT '' NOT NULL,
        customer_email VARCHAR(191) DEFAULT '' NOT NULL,
        overlay_json LONGTEXT,
        segments LONGTEXT,
        voice LONGTEXT,
        photos LONGTEXT,
        fingerprint_current CHAR(64) DEFAULT '' NOT NULL,
        payment LONGTEXT,
        result LONGTEXT,
        PRIMARY KEY  (id),
        UNIQUE KEY order_id (order_id)
    ) {$charset_collate};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
}

function svb_init_user_order() {
    $cookie_name = 'svb_user_uid';
    $session_cookie = 'svb_session';
    $dir = svb_get_orders_dir();

    if (empty($_COOKIE[$session_cookie])) {
        $session_val = wp_generate_password(24, false, false);
        if (!headers_sent()) {
            setcookie($session_cookie, $session_val, time() + MONTH_IN_SECONDS, '/', COOKIE_DOMAIN, false, true);
        }
        $_COOKIE[$session_cookie] = $session_val;
    }

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
            'customer_email' => '', // Новое поле: Email заказчика (Крок 1)
            'payment' => [
                'status' => 'unpaid',
                'invoice_id' => '',
                'reference' => '',
                'amount' => 0,
                'child_count' => 1,
                'updated_at' => date('Y-m-d H:i:s'),
            ],
        ];
        file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    } else {
        $data = json_decode(file_get_contents($file), true);
        if (!is_array($data)) {
             @unlink($file);
             return svb_init_user_order();
        }
        if (!isset($data['payment']) || !is_array($data['payment'])) {
            $data['payment'] = [
                'status' => 'unpaid',
                'invoice_id' => '',
                'reference' => '',
                'amount' => 0,
                'child_count' => 1,
                'updated_at' => date('Y-m-d H:i:s'),
            ];
            file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }
    }

    $order_row = svb_get_order_row_by_uid($uid, $data);
    if ($order_row) {
        $data['order_id'] = (int) $order_row['order_id'];
        $data['public_token'] = $order_row['public_token'];
        $data['token_hash'] = $order_row['token_hash'];
    }

    return $data;
}

function svb_get_payment_defaults() {
    return [
        'status' => 'unpaid',
        'invoice_id' => '',
        'reference' => '',
        'amount' => 0,
        'child_count' => 1,
        'updated_at' => date('Y-m-d H:i:s'),
    ];
}

function svb_generate_public_token() {
    $token = bin2hex(random_bytes(32));
    return [
        'token' => $token,
        'hash' => hash('sha256', $token),
    ];
}

function svb_orders_v2_defaults(array $args = []) {
    $now = current_time('mysql');
    $token_data = svb_generate_public_token();
    $order_id = isset($args['order_id']) ? absint($args['order_id']) : svb_get_next_order_id();

    return [
        'order_id' => $order_id,
        'public_token' => $args['public_token'] ?? $token_data['token'],
        'token_hash' => $args['token_hash'] ?? $token_data['hash'],
        'fingerprint_current' => $args['fingerprint_current'] ?? '',
        'fingerprint_paid' => $args['fingerprint_paid'] ?? '',
        'selected_video_id' => $args['selected_video_id'] ?? '',
        'customer_name' => $args['customer_name'] ?? '',
        'customer_email' => $args['customer_email'] ?? '',
        'session_id' => $args['session_id'] ?? '',
        'ip' => $args['ip'] ?? '',
        'user_agent' => $args['user_agent'] ?? '',
        'payment_status' => $args['payment_status'] ?? 'unpaid',
        'payment_invoice_id' => $args['payment_invoice_id'] ?? '',
        'payment_reference' => $args['payment_reference'] ?? '',
        'payment_modified' => $args['payment_modified'] ?? null,
        'payment_payload' => isset($args['payment_payload']) ? wp_json_encode($args['payment_payload']) : '',
        'overlay_json' => isset($args['overlay_json']) ? wp_json_encode($args['overlay_json']) : '',
        'segments' => isset($args['segments']) ? wp_json_encode($args['segments']) : '',
        'voice' => isset($args['voice']) ? wp_json_encode($args['voice']) : '',
        'photos' => isset($args['photos']) ? wp_json_encode($args['photos']) : '',
        'result' => isset($args['result']) ? wp_json_encode($args['result']) : '',
        'created_at' => $args['created_at'] ?? $now,
        'updated_at' => $args['updated_at'] ?? $now,
    ];
}

function svb_orders_v2_create(array $args = []) {
    if (!svb_orders_v2_enabled()) {
        return new WP_Error('svb_orders_v2_disabled', 'Orders v2 are disabled');
    }

    global $wpdb;
    $table = svb_get_orders_v2_table();

    $data = svb_orders_v2_defaults($args);

    $insert_data = [
        'order_id' => $data['order_id'],
        'public_token' => $data['public_token'],
        'token_hash' => $data['token_hash'],
        'fingerprint_current' => $data['fingerprint_current'],
        'fingerprint_paid' => $data['fingerprint_paid'],
        'selected_video_id' => $data['selected_video_id'],
        'customer_name' => $data['customer_name'],
        'customer_email' => $data['customer_email'],
        'session_id' => $data['session_id'],
        'ip' => $data['ip'],
        'user_agent' => $data['user_agent'],
        'payment_status' => $data['payment_status'],
        'payment_invoice_id' => $data['payment_invoice_id'],
        'payment_reference' => $data['payment_reference'],
        'payment_modified' => $data['payment_modified'],
        'payment_payload' => $data['payment_payload'],
        'overlay_json' => $data['overlay_json'],
        'segments' => $data['segments'],
        'voice' => $data['voice'],
        'photos' => $data['photos'],
        'result' => $data['result'],
        'created_at' => $data['created_at'],
        'updated_at' => $data['updated_at'],
    ];

    $formats = ['%d','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s'];

    try {
        $wpdb->insert($table, $insert_data, $formats);
    } catch (Exception $e) {
        return new WP_Error('svb_orders_v2_insert_error', 'Failed to create order', ['message' => $e->getMessage()]);
    }

    if (!$wpdb->insert_id) {
        return new WP_Error('svb_orders_v2_insert_failed', 'Failed to insert order');
    }

    return array_merge($data, [
        'id' => (int) $wpdb->insert_id,
    ]);
}

function svb_orders_v2_get_by_order_id($order_id) {
    if (!svb_orders_v2_enabled()) {
        return null;
    }

    global $wpdb;
    $table = svb_get_orders_v2_table();
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE order_id = %d LIMIT 1", absint($order_id)), ARRAY_A);
    if (!$row) {
        return null;
    }

    foreach (['payment_payload','overlay_json','segments','voice','photos','result'] as $json_field) {
        if (isset($row[$json_field]) && $row[$json_field] !== '') {
            $decoded = json_decode($row[$json_field], true);
            if (is_array($decoded)) {
                $row[$json_field] = $decoded;
            }
        }
    }

    return $row;
}

function svb_get_order_row_by_uid($uid, $fallback_data = []) {
    if (!$uid) {
        return null;
    }
    global $wpdb;
    $table = $wpdb->prefix . 'svb_orders';
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE session_id = %s ORDER BY id DESC LIMIT 1", $uid), ARRAY_A);
    if ($row) {
        $cookie_token = isset($_COOKIE['svb_public_token']) ? sanitize_text_field($_COOKIE['svb_public_token']) : '';
        if ($cookie_token && hash_equals($row['token_hash'], hash('sha256', $cookie_token))) {
            $row['public_token'] = $cookie_token;
        } else {
            $row['public_token'] = '';
        }
        return $row;
    }

    $order_id = svb_get_next_order_id();
    $token_data = svb_generate_public_token();
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $session = isset($_COOKIE['svb_session']) ? sanitize_text_field($_COOKIE['svb_session']) : '';

    $payment = svb_get_payment_defaults();
    $created_at = current_time('mysql');
    $wpdb->insert(
        $table,
        [
            'order_id' => $order_id,
            'token_hash' => $token_data['hash'],
            'created_at' => $created_at,
            'ip' => $ip,
            'user_agent' => $ua,
            'session_id' => $uid,
            'payment' => wp_json_encode($payment),
        ],
        ['%d','%s','%s','%s','%s','%s','%s']
    );

    if (!headers_sent()) {
        setcookie('svb_public_token', $token_data['token'], time() + MONTH_IN_SECONDS, '/', COOKIE_DOMAIN, false, true);
    }
    $_COOKIE['svb_public_token'] = $token_data['token'];

    return [
        'order_id' => $order_id,
        'public_token' => $token_data['token'],
        'token_hash' => $token_data['hash'],
        'created_at' => $created_at,
        'ip' => $ip,
        'user_agent' => $ua,
        'session_id' => $uid,
        'payment' => wp_json_encode($payment),
    ];
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

    if (!empty($updates['order_id'])) {
        global $wpdb;
        $table = $wpdb->prefix . 'svb_orders';
        $order_id = (int) $updates['order_id'];
        $wpdb->update($table, ['customer_email' => $updates['customer_email'] ?? '', 'customer_name' => $updates['customer_name'] ?? ''], ['order_id' => $order_id]);
    }
}

function svb_get_user_payment_state($uid = '') {
    $uid = $uid ?: (isset($_COOKIE['svb_user_uid']) ? sanitize_text_field($_COOKIE['svb_user_uid']) : '');
    if (!$uid) {
        return svb_get_payment_defaults();
    }

    global $wpdb;
    $table = $wpdb->prefix . 'svb_orders';
    $row = $wpdb->get_row($wpdb->prepare("SELECT payment FROM {$table} WHERE session_id = %s ORDER BY id DESC LIMIT 1", $uid), ARRAY_A);
    if ($row && !empty($row['payment'])) {
        $decoded = json_decode($row['payment'], true);
        if (is_array($decoded)) {
            return array_merge(svb_get_payment_defaults(), $decoded);
        }
    }

    $dir = svb_get_orders_dir();
    $file = $dir . '/order_' . $uid . '.json';
    if (!file_exists($file)) {
        return svb_get_payment_defaults();
    }

    $data = json_decode(file_get_contents($file), true);
    if (!is_array($data)) {
        return svb_get_payment_defaults();
    }

    $defaults = svb_get_payment_defaults();
    $payment = isset($data['payment']) && is_array($data['payment']) ? $data['payment'] : [];

    return array_merge($defaults, $payment);
}

function svb_update_user_payment_state($uid, array $updates) {
    $dir = svb_get_orders_dir();
    $file = $dir . '/order_' . $uid . '.json';
    if (!file_exists($file)) {
        return;
    }

    $content = file_get_contents($file);
    $data = json_decode($content, true);
    if (!is_array($data)) {
        return;
    }

    $defaults = svb_get_payment_defaults();
    $current = isset($data['payment']) && is_array($data['payment']) ? $data['payment'] : [];
    $data['payment'] = array_merge($defaults, $current, $updates, [
        'updated_at' => date('Y-m-d H:i:s'),
    ]);

    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    global $wpdb;
    $table = $wpdb->prefix . 'svb_orders';
    $existing = $wpdb->get_row($wpdb->prepare("SELECT id,payment FROM {$table} WHERE session_id = %s ORDER BY id DESC LIMIT 1", $uid), ARRAY_A);
    if ($existing) {
        $new_payment = array_merge($defaults, is_array(json_decode($existing['payment'], true)) ? json_decode($existing['payment'], true) : [], $updates, [
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $wpdb->update($table, ['payment' => wp_json_encode($new_payment)], ['id' => $existing['id']], ['%s'], ['%d']);
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

function svb_cleanup_order_results_cb() {
    global $wpdb;
    $table = $wpdb->prefix . 'svb_orders';
    $rows = $wpdb->get_results("SELECT id,result FROM {$table} WHERE result IS NOT NULL", ARRAY_A);
    if (!$rows) {
        return;
    }
    $now = time();
    foreach ($rows as $row) {
        $result = json_decode($row['result'], true);
        if (!is_array($result) || empty($result['video_path']) || empty($result['generated_at'])) {
            continue;
        }
        $generated_ts = strtotime($result['generated_at']);
        if ($generated_ts && ($now - $generated_ts) > HOUR_IN_SECONDS) {
            if (file_exists($result['video_path'])) {
                @unlink($result['video_path']);
            }
            $result['video_path'] = '';
            $wpdb->update($table, ['result' => wp_json_encode($result)], ['id' => $row['id']], ['%s'], ['%d']);
        }
    }
}

function svb_compute_fingerprint(array $params, array $photo_paths) {
    $normalized = wp_json_encode($params);
    $photo_hashes = [];
    foreach ($photo_paths as $path) {
        if ($path && file_exists($path)) {
            $photo_hashes[] = hash_file('sha256', $path);
        }
    }
    sort($photo_hashes);
    $payload = $normalized . '|' . implode('|', $photo_hashes);
    return hash('sha256', $payload);
}
