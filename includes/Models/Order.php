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

function svb_detect_ssl() {
    if (is_ssl()) {
        return true;
    }

    $forwarded_proto = isset($_SERVER['HTTP_X_FORWARDED_PROTO']) ? strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) : '';
    return $forwarded_proto === 'https';
}

function svb_set_lax_cookie($name, $value, $expires, $http_only = true) {
    if (headers_sent()) {
        error_log('[SVB SESSION] Cannot set cookie, headers already sent');
        return false;
    }

    $options = [
        'expires' => (int) $expires,
        'path' => '/',
        'domain' => COOKIE_DOMAIN,
        'secure' => svb_detect_ssl(),
        'httponly' => (bool) $http_only,
        'samesite' => 'Lax',
    ];

    return setcookie($name, $value, $options);
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

function svb_orders_decode_payment($raw) {
    if (is_array($raw)) {
        return $raw;
    }

    $decoded = json_decode((string) $raw, true);
    return is_array($decoded) ? $decoded : svb_get_payment_defaults();
}

function svb_orders_normalize_payment(array $payment) {
    return array_merge(svb_get_payment_defaults(), $payment);
}

function svb_get_orders_v2_table() {
    global $wpdb;
    return $wpdb->prefix . 'svb_orders_v2';
}

function svb_orders_get_session_id() {
    $session_cookie = isset($_COOKIE['svb_session']) ? sanitize_text_field($_COOKIE['svb_session']) : '';
    $uid_cookie = isset($_COOKIE['svb_user_uid']) ? sanitize_text_field($_COOKIE['svb_user_uid']) : '';

    return $session_cookie ? $session_cookie : $uid_cookie;
}

function svb_orders_email_hash($email) {
    $clean = sanitize_email($email);
    if (!$clean || !is_email($clean)) {
        return '';
    }

    $normalized = strtolower(trim($clean));
    return hash('sha256', $normalized . AUTH_SALT);
}

function svb_orders_table_has_column($table, $column) {
    global $wpdb;
    $col = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$table} LIKE %s", $column));
    return (bool) $col;
}

function svb_orders_table_exists() {
    global $wpdb;
    $table = $wpdb->prefix . 'svb_orders';
    $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
    return $found === $table;
}

function svb_orders_v2_table_exists() {
    global $wpdb;
    $table = svb_get_orders_v2_table();
    $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
    return $found === $table;
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
        email_hash CHAR(64) DEFAULT '' NOT NULL,
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

function svb_maybe_ensure_orders_schema() {
    if (!current_user_can('manage_options')) {
        return;
    }

    if (!svb_orders_table_exists()) {
        svb_install_orders_table();
    } else {
        global $wpdb;
        $table = $wpdb->prefix . 'svb_orders';
        if (!svb_orders_table_has_column($table, 'public_token')) {
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN public_token VARCHAR(191) DEFAULT '' NOT NULL AFTER token_hash");
        }
        if (!svb_orders_table_has_column($table, 'email_hash')) {
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN email_hash CHAR(64) DEFAULT '' NOT NULL AFTER customer_email");
        }
    }

    if (svb_orders_v2_enabled() && !svb_orders_v2_table_exists()) {
        svb_install_orders_v2_table();
    } elseif (svb_orders_v2_enabled()) {
        global $wpdb;
        $table_v2 = svb_get_orders_v2_table();
        if (!svb_orders_table_has_column($table_v2, 'email_hash')) {
            $wpdb->query("ALTER TABLE {$table_v2} ADD COLUMN email_hash CHAR(64) DEFAULT '' NOT NULL AFTER customer_email");
        }
    }
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
        public_token VARCHAR(191) DEFAULT '' NOT NULL,
        created_at DATETIME NOT NULL,
        ip VARCHAR(64) DEFAULT '' NOT NULL,
        user_agent TEXT,
        session_id VARCHAR(128) DEFAULT '' NOT NULL,
        email_hash CHAR(64) DEFAULT '' NOT NULL,
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

function svb_get_order_by_id($order_id) {
    if (!$order_id || !svb_orders_table_exists()) {
        return null;
    }

    global $wpdb;
    $table = $wpdb->prefix . 'svb_orders';
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE order_id = %d LIMIT 1", absint($order_id)), ARRAY_A);
    if (!$row) {
        return null;
    }

    if (isset($row['payment']) && $row['payment']) {
        $decoded = json_decode($row['payment'], true);
        if (is_array($decoded)) {
            $row['payment'] = $decoded;
        }
    }

    if (!isset($row['public_token']) || !$row['public_token']) {
        $cookie_token = isset($_COOKIE['svb_public_token']) ? sanitize_text_field($_COOKIE['svb_public_token']) : '';
        if ($cookie_token && isset($row['token_hash']) && hash_equals($row['token_hash'], hash('sha256', $cookie_token))) {
            $row['public_token'] = $cookie_token;
            if (svb_orders_table_has_column($table, 'public_token')) {
                $wpdb->update($table, ['public_token' => $cookie_token], ['id' => $row['id']], ['%s'], ['%d']);
            }
        }
    }

    if (!isset($row['email_hash']) || !$row['email_hash']) {
        $email_hash = svb_orders_email_hash($row['customer_email'] ?? '');
        if ($email_hash) {
            $row['email_hash'] = $email_hash;
            if (svb_orders_table_has_column($table, 'email_hash')) {
                $wpdb->update($table, ['email_hash' => $email_hash], ['id' => $row['id']], ['%s'], ['%d']);
            }
        }
    }

    return $row;
}

function svb_get_order_by_id_and_token($order_id, $token) {
    $order = svb_get_order_by_id($order_id);
    if (!$order) {
        return null;
    }

    $token_hash = isset($order['token_hash']) ? $order['token_hash'] : '';
    if (!$token_hash || !$token || !hash_equals($token_hash, hash('sha256', $token))) {
        return null;
    }

    return $order;
}

function svb_get_order_by_token($token) {
    if (!$token || !svb_orders_table_exists()) {
        return null;
    }

    global $wpdb;
    $table = $wpdb->prefix . 'svb_orders';
    $hash = hash('sha256', $token);
    $row = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT order_id FROM {$table} WHERE token_hash = %s OR public_token = %s ORDER BY id DESC LIMIT 1",
            $hash,
            sanitize_text_field($token)
        ),
        ARRAY_A
    );

    if (!$row || empty($row['order_id'])) {
        return null;
    }

    $order = svb_get_order_by_id((int) $row['order_id']);
    if ($order) {
        $order['public_token'] = $token;
    }

    return $order;
}

function svb_resolve_order_public_token(array $order_row) {
    $token = isset($order_row['public_token']) ? sanitize_text_field($order_row['public_token']) : '';
    if ($token) {
        return $token;
    }

    $cookie_token = isset($_COOKIE['svb_public_token']) ? sanitize_text_field($_COOKIE['svb_public_token']) : '';
    if ($cookie_token && isset($order_row['token_hash']) && hash_equals($order_row['token_hash'], hash('sha256', $cookie_token))) {
        return $cookie_token;
    }

    return '';
}

function svb_find_latest_paid_order_by_email_hash($email_hash) {
    if (!$email_hash || !svb_orders_table_exists()) {
        return null;
    }

    global $wpdb;
    $table = $wpdb->prefix . 'svb_orders';
    $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE email_hash = %s ORDER BY id DESC LIMIT 5", $email_hash), ARRAY_A);
    if (!$rows) {
        return null;
    }

    foreach ($rows as $row) {
        $payment = svb_orders_decode_payment($row['payment'] ?? []);
        $status = $payment['status'] ?? '';
        if (in_array($status, ['paid', 'success'], true)) {
            $row['payment'] = $payment;
            return $row;
        }
    }

    return null;
}

function svb_order_create_or_load_for_session($uid, $fallback_data = []) {
    if (!$uid) {
        return new WP_Error('svb_order_missing_uid', 'Missing session UID');
    }

    if (!svb_orders_table_exists()) {
        return new WP_Error('svb_orders_table_missing', 'Orders table missing');
    }

    $row = svb_get_order_row_by_uid($uid, $fallback_data);
    if (!$row) {
        global $wpdb;
        $db_error = $wpdb->last_error;
        return new WP_Error('svb_order_not_created', 'Order storage error', ['db_error' => $db_error]);
    }

    $verified = svb_get_order_by_id($row['order_id']);
    if (!$verified) {
        global $wpdb;
        $db_error = $wpdb->last_error;
        return new WP_Error('svb_order_not_readable', 'Order storage error', ['db_error' => $db_error]);
    }

    if (!empty($row['public_token'])) {
        $verified['public_token'] = $row['public_token'];
        $verified['token_hash'] = $row['token_hash'];
    }

    $session_id = svb_orders_get_session_id();
    svb_update_order_contact(
        $verified['order_id'],
        $fallback_data['customer_name'] ?? '',
        $fallback_data['customer_email'] ?? '',
        $session_id
    );

    return $verified;
}

function svb_create_new_order_for_session($uid, array $base_data = []) {
    if (!$uid) {
        return new WP_Error('svb_order_missing_uid', 'Missing session UID');
    }

    if (!svb_orders_table_exists()) {
        return new WP_Error('svb_orders_table_missing', 'Orders table missing');
    }

    global $wpdb;
    $table = $wpdb->prefix . 'svb_orders';
    $order_id = svb_get_next_order_id();
    $token_data = svb_generate_public_token();
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $session = svb_orders_get_session_id();
    $created_at = current_time('mysql');

    $customer_name = isset($base_data['customer_name']) ? sanitize_text_field($base_data['customer_name']) : '';
    $customer_email = isset($base_data['customer_email']) ? sanitize_email($base_data['customer_email']) : '';
    $email_hash = $customer_email ? svb_orders_email_hash($customer_email) : '';

    $payment = svb_orders_normalize_payment(isset($base_data['payment']) && is_array($base_data['payment']) ? $base_data['payment'] : []);

    $inserted = $wpdb->insert(
        $table,
        [
            'order_id' => $order_id,
            'token_hash' => $token_data['hash'],
            'public_token' => $token_data['token'],
            'created_at' => $created_at,
            'ip' => $ip,
            'user_agent' => $ua,
            'session_id' => $session ? $session : $uid,
            'customer_name' => $customer_name,
            'customer_email' => $customer_email,
            'email_hash' => $email_hash,
            'payment' => wp_json_encode($payment),
        ],
        ['%d','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s']
    );

    if (!$inserted) {
        if (defined('SVB_DEBUG') && SVB_DEBUG) {
            error_log('[SVB] failed to insert new order row: ' . $wpdb->last_error);
        }
        return new WP_Error('svb_order_not_created', 'Order storage error', ['db_error' => $wpdb->last_error]);
    }

    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE order_id = %d LIMIT 1", $order_id), ARRAY_A);
    if (!$row) {
        return new WP_Error('svb_order_not_readable', 'Order storage error', ['db_error' => $wpdb->last_error]);
    }

    svb_set_lax_cookie('svb_public_token', $token_data['token'], time() + MONTH_IN_SECONDS, true);
    $_COOKIE['svb_public_token'] = $token_data['token'];

    $row['public_token'] = $token_data['token'];
    $row['token_hash'] = $token_data['hash'];

    return $row;
}

function svb_init_user_order() {
    $cookie_name = 'svb_user_uid';
    $session_cookie = 'svb_session';
    $dir = svb_get_orders_dir();

    if (empty($_COOKIE[$session_cookie])) {
        try {
            $session_val = bin2hex(random_bytes(16));
        } catch (Exception $e) {
            $fallback = wp_generate_password(16, false, false);
            $session_val = $fallback ? bin2hex(substr($fallback, 0, 8)) : uniqid('svb', true);
        }

        svb_set_lax_cookie($session_cookie, $session_val, time() + MONTH_IN_SECONDS, true);
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
    if ($need_set_cookie) {
        svb_set_lax_cookie($cookie_name, $uid, time() + (86400 * 30), false);
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
            'session_id' => $session_val,
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
        'invoice_page_url' => '',
        'invoice_fingerprint' => '',
        'modifiedDate' => 0,
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
    $customer_email = isset($args['customer_email']) ? sanitize_email($args['customer_email']) : '';
    $email_hash = $args['email_hash'] ?? '';
    if (!$email_hash && $customer_email) {
        $email_hash = svb_orders_email_hash($customer_email);
    }

    return [
        'order_id' => $order_id,
        'public_token' => $args['public_token'] ?? $token_data['token'],
        'token_hash' => $args['token_hash'] ?? $token_data['hash'],
        'fingerprint_current' => $args['fingerprint_current'] ?? '',
        'fingerprint_paid' => $args['fingerprint_paid'] ?? '',
        'selected_video_id' => $args['selected_video_id'] ?? '',
        'customer_name' => $args['customer_name'] ?? '',
        'customer_email' => $customer_email,
        'email_hash' => $email_hash,
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
        'email_hash' => $data['email_hash'],
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

    $formats = ['%d','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s'];

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

    if (!svb_orders_table_exists()) {
        if (defined('SVB_DEBUG') && SVB_DEBUG) {
            error_log('[SVB] orders table missing during session lookup');
        }
        return null;
    }
    global $wpdb;
    $table = $wpdb->prefix . 'svb_orders';
    $session_id = svb_orders_get_session_id();
    $session_lookup = $session_id ? $session_id : $uid;
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE session_id = %s ORDER BY id DESC LIMIT 1", $session_lookup), ARRAY_A);
    if (!$row && $session_lookup !== $uid) {
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE session_id = %s ORDER BY id DESC LIMIT 1", $uid), ARRAY_A);
    }
    if ($row) {
        $cookie_token = isset($_COOKIE['svb_public_token']) ? sanitize_text_field($_COOKIE['svb_public_token']) : '';
        if ($cookie_token && hash_equals($row['token_hash'], hash('sha256', $cookie_token))) {
            $row['public_token'] = $cookie_token;
            if (svb_orders_table_has_column($table, 'public_token') && empty($row['public_token'])) {
                $wpdb->update($table, ['public_token' => $cookie_token], ['id' => $row['id']], ['%s'], ['%d']);
            }
        } else {
            $row['public_token'] = $row['public_token'] ?? '';
        }

        $fallback_email_hash = svb_orders_email_hash($fallback_data['customer_email'] ?? '');
        if (empty($row['email_hash']) && $fallback_email_hash && svb_orders_table_has_column($table, 'email_hash')) {
            $wpdb->update($table, ['email_hash' => $fallback_email_hash], ['id' => $row['id']], ['%s'], ['%d']);
            $row['email_hash'] = $fallback_email_hash;
        }

        return $row;
    }

    $order_id = svb_get_next_order_id();
    $token_data = svb_generate_public_token();
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $customer_name = isset($fallback_data['customer_name']) ? sanitize_text_field($fallback_data['customer_name']) : '';
    $customer_email = isset($fallback_data['customer_email']) ? sanitize_email($fallback_data['customer_email']) : '';
    $email_hash = $customer_email ? svb_orders_email_hash($customer_email) : '';

    $payment = svb_get_payment_defaults();
    $created_at = current_time('mysql');
    $inserted = $wpdb->insert(
        $table,
        [
            'order_id' => $order_id,
            'token_hash' => $token_data['hash'],
            'public_token' => $token_data['token'],
            'created_at' => $created_at,
            'ip' => $ip,
            'user_agent' => $ua,
            'session_id' => $session_lookup,
            'customer_name' => $customer_name,
            'customer_email' => $customer_email,
            'email_hash' => $email_hash,
            'payment' => wp_json_encode($payment),
        ],
        ['%d','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s']
    );

    if (!$inserted) {
        if (defined('SVB_DEBUG') && SVB_DEBUG) {
            error_log('[SVB] failed to insert order row: ' . $wpdb->last_error);
        }
        return null;
    }

    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE order_id = %d LIMIT 1", $order_id), ARRAY_A);
    if (!$row) {
        if (defined('SVB_DEBUG') && SVB_DEBUG) {
            error_log('[SVB] order row not readable after insert: ' . $wpdb->last_error);
        }
        return null;
    }

    svb_set_lax_cookie('svb_public_token', $token_data['token'], time() + MONTH_IN_SECONDS, true);
    $_COOKIE['svb_public_token'] = $token_data['token'];

    $row['public_token'] = $token_data['token'];
    $row['token_hash'] = $token_data['hash'];

    return $row;
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
        $session_id = svb_orders_get_session_id();
        $fields = [];

        if (array_key_exists('customer_email', $updates)) {
            $customer_email = sanitize_email($updates['customer_email']);
            $fields['customer_email'] = $customer_email;
            $email_hash = $customer_email ? svb_orders_email_hash($customer_email) : '';
            if ($email_hash) {
                $fields['email_hash'] = $email_hash;
            }
        }

        if (array_key_exists('customer_name', $updates)) {
            $fields['customer_name'] = sanitize_text_field($updates['customer_name']);
        }

        if ($session_id) {
            $fields['session_id'] = $session_id;
        }

        if (!empty($fields)) {
            $formats = [];
            foreach ($fields as $v) { $formats[] = '%s'; }
            $wpdb->update(
                $table,
                $fields,
                ['order_id' => $order_id],
                $formats,
                ['%d']
            );
        }
    }
}

function svb_update_order_contact($order_id, $customer_name = '', $customer_email = '', $session_id = '') {
    if (!$order_id || !svb_orders_table_exists()) {
        return;
    }

    global $wpdb;
    $table = $wpdb->prefix . 'svb_orders';

    $fields = [];
    if ($customer_name !== '') {
        $fields['customer_name'] = sanitize_text_field($customer_name);
    }
    if ($customer_email !== '') {
        $clean_email = sanitize_email($customer_email);
        $fields['customer_email'] = $clean_email;
        $hash = svb_orders_email_hash($clean_email);
        if ($hash) {
            $fields['email_hash'] = $hash;
        }
    }

    if ($session_id !== '') {
        $fields['session_id'] = sanitize_text_field($session_id);
    }

    if (empty($fields)) {
        return;
    }

    $formats = [];
    foreach ($fields as $value) {
        $formats[] = '%s';
    }

    $wpdb->update($table, $fields, ['order_id' => (int) $order_id], $formats, ['%d']);
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
    $existing = $wpdb->get_row($wpdb->prepare("SELECT id,payment,fingerprint_current,order_id FROM {$table} WHERE session_id = %s ORDER BY id DESC LIMIT 1", $uid), ARRAY_A);
    if ($existing) {
        $stored_payment = svb_orders_decode_payment($existing['payment']);
        $new_payment = array_merge($defaults, $stored_payment, $updates, [
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        if (isset($updates['status']) && $updates['status'] === 'paid') {
            if (!isset($new_payment['paid_fingerprint']) && !empty($existing['fingerprint_current'])) {
                $new_payment['paid_fingerprint'] = $existing['fingerprint_current'];
            }
        }

        $wpdb->update($table, ['payment' => wp_json_encode($new_payment)], ['id' => $existing['id']], ['%s'], ['%d']);
    }
}

function svb_update_order_payment_by_session($uid, array $updates) {
    global $wpdb;
    $table = $wpdb->prefix . 'svb_orders';
    $row = $wpdb->get_row($wpdb->prepare("SELECT id,order_id,payment,fingerprint_current FROM {$table} WHERE session_id = %s ORDER BY id DESC LIMIT 1", $uid), ARRAY_A);
    if (!$row) {
        return;
    }

    $defaults = svb_get_payment_defaults();
    $existing = svb_orders_decode_payment($row['payment']);
    $new_payment = array_merge($defaults, $existing, $updates, [
        'updated_at' => date('Y-m-d H:i:s'),
    ]);

    if (isset($updates['status']) && $updates['status'] === 'paid' && empty($new_payment['paid_fingerprint']) && !empty($row['fingerprint_current'])) {
        $new_payment['paid_fingerprint'] = $row['fingerprint_current'];
    }

    $wpdb->update($table, ['payment' => wp_json_encode($new_payment)], ['id' => $row['id']], ['%s'], ['%d']);
}

function svb_update_order_payment_by_order_id($order_id, array $updates) {
    if (!$order_id || !svb_orders_table_exists()) {
        return null;
    }

    global $wpdb;
    $table = $wpdb->prefix . 'svb_orders';
    $row = $wpdb->get_row($wpdb->prepare("SELECT id,order_id,payment,fingerprint_current FROM {$table} WHERE order_id = %d LIMIT 1", (int) $order_id), ARRAY_A);
    if (!$row) {
        return null;
    }

    $defaults = svb_get_payment_defaults();
    $existing = svb_orders_decode_payment($row['payment']);
    $new_payment = array_merge($defaults, $existing, $updates, [
        'updated_at' => date('Y-m-d H:i:s'),
    ]);

    if (isset($updates['status']) && in_array($updates['status'], ['paid', 'success'], true) && empty($new_payment['paid_fingerprint']) && !empty($row['fingerprint_current'])) {
        $new_payment['paid_fingerprint'] = $row['fingerprint_current'];
    }

    $wpdb->update($table, ['payment' => wp_json_encode($new_payment)], ['id' => $row['id']], ['%s'], ['%d']);

    return $new_payment;
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

function svb_compute_fingerprint_from_hashes(array $params, array $photo_hashes) {
    $normalized = wp_json_encode($params);
    $hashes = array_map('strval', $photo_hashes);
    sort($hashes);
    $payload = $normalized . '|' . implode('|', $hashes);
    return hash('sha256', $payload);
}

function svb_update_order_fingerprint($order_id, $fingerprint_current, array $extra_fields = []) {
    if (!$order_id || !svb_orders_table_exists()) {
        return;
    }

    global $wpdb;
    $table = $wpdb->prefix . 'svb_orders';
    $fields = array_merge([
        'fingerprint_current' => $fingerprint_current,
    ], $extra_fields);

    $formats = [];
    foreach ($fields as $key => $val) {
        $formats[] = is_int($val) ? '%d' : '%s';
    }

    $wpdb->update($table, $fields, ['order_id' => (int) $order_id], $formats, ['%d']);
}
