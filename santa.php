<?php
/**
 * Plugin Name: Santa Video Builder (All-in-One)
 * Description: 3-шаговый модуль генерации персонального видео (как elfisanta): выбор озвучек, загрузка фото, сборка через FFmpeg. Хранение результата 1 час.
 * Version: 3.1.3 (Real-Time Progress)
 * Author: You
 */

if (!defined('ABSPATH')) { exit; }

define('SVB_VER', '3.1.4');
define('SVB_PLUGIN_FILE', __FILE__);
define('SVB_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SVB_PLUGIN_URL', plugin_dir_url(__FILE__));
define('SVB_STATE_VERSION', 1);

if (!defined('SVB_DEBUG')) {
    define('SVB_DEBUG', true);
}
if (!defined('SVB_ORDERS_V2')) {
    define('SVB_ORDERS_V2', false);
}

function svb_debug_enabled() {
    if (defined('SVB_DEBUG') && SVB_DEBUG) {
        return true;
    }

    $option_flag = get_option('svb_debug_mode');
    return (bool) $option_flag;
}

function svb_mask_value($value, $len = 4) {
    if (!is_string($value) || $value === '') {
        return '';
    }

    if (strlen($value) <= ($len * 2)) {
        return $value;
    }

    return substr($value, 0, $len) . '...' . substr($value, -$len);
}

function svb_log($tag, $data = []) {
    if (!svb_debug_enabled()) {
        return;
    }

    $payload = is_array($data) ? $data : ['message' => (string) $data];
    $payload_safe = [];

    foreach ($payload as $key => $val) {
        if (stripos($key, 'token') !== false || stripos($key, 'session') !== false) {
            $payload_safe[$key] = svb_mask_value(is_scalar($val) ? (string) $val : wp_json_encode($val));
        } else {
            $payload_safe[$key] = $val;
        }
    }

    error_log('[SVB][' . $tag . '] ' . wp_json_encode($payload_safe));
}

function svb_register_error_observer() {
    if (!svb_debug_enabled()) {
        return;
    }

    set_error_handler(function ($severity, $message, $file, $line) {
        if (!(error_reporting() & $severity)) {
            return false;
        }

        svb_log('php_error', [
            'severity' => $severity,
            'message' => $message,
            'file' => $file,
            'line' => $line,
        ]);

        return false;
    });

    register_shutdown_function(function () {
        $last = error_get_last();
        if ($last && in_array($last['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            svb_log('shutdown_fatal', $last);
        }
    });
}

function svb_register_missing_dependency_notice($missing_files) {
    if (empty($missing_files) || !is_array($missing_files)) {
        return;
    }

    add_action('admin_notices', function() use ($missing_files) {
        foreach ($missing_files as $missing) {
            echo '<div class="notice notice-error"><p>SVB: missing file ' . esc_html($missing) . '</p></div>';
        }
    });
}

$svb_dependencies = [
    'includes/Models/Order.php',
    'includes/Models/Config.php',
    'includes/Services/MediaPipeline.php',
    'includes/Services/MonobankGateway.php',
    'includes/Presenters/ShortcodeController.php',
    'includes/Presenters/AjaxController.php',
];

$svb_missing_files = [];

foreach ($svb_dependencies as $svb_dependency) {
    $svb_full_path = SVB_PLUGIN_DIR . $svb_dependency;
    if (!file_exists($svb_full_path)) {
        $svb_missing_files[] = $svb_dependency;
    }
}

if (!empty($svb_missing_files)) {
    if (defined('SVB_DEBUG') && SVB_DEBUG) {
        error_log('SVB: missing dependencies - ' . implode(', ', $svb_missing_files));
    }

    svb_register_missing_dependency_notice($svb_missing_files);

    return;
}

require_once SVB_PLUGIN_DIR . 'includes/Models/Order.php';
require_once SVB_PLUGIN_DIR . 'includes/Models/Config.php';
require_once SVB_PLUGIN_DIR . 'includes/Services/MediaPipeline.php';
require_once SVB_PLUGIN_DIR . 'includes/Services/MonobankGateway.php';
require_once SVB_PLUGIN_DIR . 'includes/Presenters/ShortcodeController.php';
require_once SVB_PLUGIN_DIR . 'includes/Presenters/AjaxController.php';

svb_register_error_observer();

function svb_log_throwable(Throwable $e, $context) {
    svb_log('throwable', [
        'context' => $context,
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);

    if (svb_debug_enabled()) {
        error_log($e->getTraceAsString());
    }
}

function svb_wrap_hook($callback, $context, $on_error = null) {
    return function() use ($callback, $context, $on_error) {
        try {
            return call_user_func($callback);
        } catch (Throwable $e) {
            svb_log_throwable($e, $context);
            if ($on_error) {
                return call_user_func($on_error, $e);
            }
        }

        return null;
    };
}

function svb_wrap_ajax($callback, $context) {
    return svb_wrap_hook($callback, $context, function(Throwable $e) use ($context) {
        $payload = [
            'error' => 'internal_error',
            'context' => $context,
            'message' => $e->getMessage(),
        ];

        if (svb_debug_enabled()) {
            $payload['debug'] = [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ];
        }

        wp_send_json_error($payload);
    });
}

register_activation_hook(__FILE__, 'svb_install_orders_table');
register_activation_hook(__FILE__, 'svb_install_orders_v2_table');
register_activation_hook(__FILE__, function() {
    if (!wp_next_scheduled('svb_cleanup_order_results')) {
        wp_schedule_event(time() + MINUTE_IN_SECONDS, 'hourly', 'svb_cleanup_order_results');
    }
});

add_action('svb_cleanup_order_results', 'svb_cleanup_order_results_cb');

add_filter('redirect_canonical', function($redirect_url, $requested_url) {
    if (isset($_GET['svb_download']) || isset($_GET['svb_payment_return'])) {
        return false;
    }

    return $redirect_url;
}, 10, 2);

add_action('init', svb_wrap_hook(function() {
    if (is_admin()) {
        svb_maybe_ensure_orders_schema();
    }
}, 'init:ensure_orders_schema'), 0);

function svb_stream_order_video($order_id, $token) {
    $order_id = absint($order_id);
    $token = sanitize_text_field($token);

    $set_reason_header = function($reason_key) {
        header('X-SVB-Download: hit');
        header('X-SVB-Download-Reason: ' . $reason_key);
    };

    $send_error = function($reason_key, $status) use ($set_reason_header) {
        $set_reason_header($reason_key);
        status_header($status);
        header('Content-Type: text/plain; charset=UTF-8');
        nocache_headers();
        echo 'SVB download error: ' . $reason_key;
        exit;
    };

    if (!$order_id || !$token) {
        $send_error('missing_params', 400);
    }

    global $wpdb;
    $table = $wpdb->prefix . 'svb_orders';
    if (!svb_orders_table_exists()) {
        if (defined('SVB_DEBUG') && SVB_DEBUG) {
            error_log('[SVB DOWNLOAD] orders table missing while streaming order_id=' . $order_id);
        }
        $send_error('no_order', 404);
    }
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE order_id = %d", $order_id), ARRAY_A);
    if (!$row) {
        if (defined('SVB_DEBUG') && SVB_DEBUG) {
            error_log('[SVB DOWNLOAD] no_order for order_id=' . $order_id . ' storage=table');
        }
        $send_error('no_order', 404);
    }

    $payment = svb_orders_normalize_payment(svb_orders_decode_payment($row['payment'] ?? []));
    $payment_status = $payment['status'] ?? '';
    $paid_fingerprint = $payment['paid_fingerprint'] ?? '';
    $fingerprint_current = $row['fingerprint_current'] ?? '';

    if (empty($row['token_hash']) || !svb_safe_hash_equals($row['token_hash'], hash('sha256', $token))) {
        $send_error('bad_token', 403);
    }

    $result = is_array(json_decode($row['result'] ?? '', true)) ? json_decode($row['result'], true) : [];
    $video_path = $result['video_path'] ?? '';
    $generated_at = isset($result['generated_at']) ? strtotime($result['generated_at']) : 0;

    if (defined('SVB_DEBUG') && SVB_DEBUG) {
        error_log(sprintf(
            '[SVB DOWNLOAD] order_id=%d status=%s paid_fp=%s current_fp=%s video_path=%s exists=%s',
            $order_id,
            $payment_status,
            $paid_fingerprint ? substr($paid_fingerprint, 0, 8) : '',
            $fingerprint_current ? substr($fingerprint_current, 0, 8) : '',
            $video_path,
            $video_path && file_exists($video_path) ? 'true' : 'false'
        ));
    }

    if (!$video_path || !file_exists($video_path)) {
        $send_error('no_file', 404);
    }

    if (!is_readable($video_path)) {
        $send_error('not_readable', 403);
    }

    if ($generated_at && (time() - $generated_at) > HOUR_IN_SECONDS) {
        $send_error('expired', 410);
    }

    $fp = fopen($video_path, 'rb');
    if (!$fp) {
        $send_error('not_readable', 500);
    }

    while (ob_get_level()) {
        ob_end_clean();
    }

    $set_reason_header('ok');

    status_header(200);
    nocache_headers();

    $ext = strtolower(pathinfo($video_path, PATHINFO_EXTENSION));
    $content_type = 'video/mp4';
    if ($ext === 'webm') {
        $content_type = 'video/webm';
    }

    $filename = 'santa-video.' . ($ext ? $ext : 'mp4');

    header('Content-Type: ' . $content_type);
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($video_path));
    header('Accept-Ranges: bytes');

    fpassthru($fp);
    fclose($fp);
    exit;
}

function svb_handle_public_order() {
    if (isset($_GET['svb_payment_return'])) {
        return;
    }

    if (empty($_GET['svb_download']) || empty($_GET['svb_order']) || empty($_GET['token'])) {
        return;
    }

    svb_stream_order_video(absint($_GET['svb_order']), wp_unslash($_GET['token']));
}

function svb_handle_download_endpoint() {
    if (isset($_GET['svb_payment_return'])) {
        return;
    }

    if (!isset($_GET['svb_download'])) {
        return;
    }

    if (!defined('DONOTCACHEPAGE')) {
        define('DONOTCACHEPAGE', true);
    }

    nocache_headers();

    header('X-SVB-Download: hit');
    if (defined('SVB_DEBUG') && SVB_DEBUG) {
        header('X-SVB-Download-Reason: received');
    }

    $order_id = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;
    $token = isset($_GET['token']) ? sanitize_text_field(wp_unslash($_GET['token'])) : '';

    if (!$order_id || !$token) {
        if (defined('SVB_DEBUG') && SVB_DEBUG) {
            header('X-SVB-Download-Reason: missing_params');
        }
        status_header(400);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'SVB download error: missing_params';
        exit;
    }

    svb_stream_order_video($order_id, $token);
}

function svb_handle_payment_return_redirect() {
    if (empty($_GET['svb_payment_return'])) {
        return;
    }

    $order_id = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;
    $token = isset($_GET['token']) ? sanitize_text_field(wp_unslash($_GET['token'])) : '';

    if (!$token && isset($_GET['svb_order'])) {
        $token = sanitize_text_field(wp_unslash($_GET['svb_order']));
    }

    $is_token_valid = $token && preg_match('/^[a-f0-9]{8,}$/i', $token);
    $order_row = null;

    if ($order_id && $is_token_valid) {
        $order_row = svb_get_order_by_id_and_token($order_id, $token);
    }

    if (!$order_row && $is_token_valid) {
        $order_row = svb_get_order_by_token($token);
    }

    if (!$order_row) {
        svb_log('pay_return_missing', [
            'order_id' => $order_id,
            'token' => $token ? svb_mask_value($token) : '',
        ]);

        svb_clear_user_state('payment_return_not_found');

        svb_clear_user_state('payment_return_not_found');

        $fallback_target = home_url('/');
        if ($is_token_valid) {
            $fallback_target = add_query_arg([
                'svb_step'  => 2,
                'svb_token' => $token,
            ], $fallback_target);
        }

        wp_safe_redirect($fallback_target);
        exit;
    }

    $public_token = svb_resolve_order_public_token($order_row);
    if ($public_token) {
        svb_set_lax_cookie('svb_public_token', $public_token, time() + MONTH_IN_SECONDS, true);
        $_COOKIE['svb_public_token'] = $public_token;
    }

    if (!empty($order_row['session_id'])) {
        svb_set_lax_cookie('svb_session', $order_row['session_id'], time() + MONTH_IN_SECONDS, true);
        $_COOKIE['svb_session'] = $order_row['session_id'];
    }

    svb_log('pay_return_resume', [
        'order_id' => $order_row['order_id'] ?? 0,
        'public_token' => $public_token ? svb_mask_value($public_token) : '',
        'session' => !empty($order_row['session_id']) ? svb_mask_value($order_row['session_id']) : '',
    ]);

    $resume_url = add_query_arg(
        [
            'svb_token' => $public_token,
            'svb_step' => 3,
        ],
        home_url('/')
    );

    wp_safe_redirect($resume_url);
    exit;
}

// Allow HEIC/HEIF uploads when supported by WordPress core.
add_filter('upload_mimes', function ($mimes) {
    $mimes['heic'] = 'image/heic';
    $mimes['heif'] = 'image/heif';
    return $mimes;
});

// Help WordPress detect HEIC/HEIF mime types during upload validation.
add_filter('wp_check_filetype_and_ext', function ($data, $file, $filename, $mimes, $real_mime = false) {
    if (!empty($data['ext']) && !empty($data['type'])) {
        return $data; // Core already resolved type.
    }

    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    if (!in_array($ext, ['heic', 'heif'], true)) {
        return $data;
    }

    $heicMimes = ['image/heic', 'image/heif', 'image/heic-sequence', 'image/heif-sequence'];
    if (!$real_mime || !in_array($real_mime, $heicMimes, true)) {
        return $data; // Do not guess mime types outside the allowed HEIC/HEIF list.
    }

    return [
        'ext'             => $ext,
        'type'            => $real_mime,
        'proper_filename' => $data['proper_filename'] ?? false,
    ];
}, 10, 5);

// Хук init: запускаем как можно раньше, чтобы успеть поставить куки до вывода HTML
add_action('init', svb_wrap_hook('svb_init_cookie_logic', 'init:init_cookie_logic'), 1); // Приоритет 1 (раньше всех)

add_action('svb_cleanup_job', 'svb_cleanup_job_cb', 10, 1);
function svb_register_ajax_handler($action, $callback, $allow_nopriv = false) {
    add_action('wp_ajax_' . $action, svb_wrap_ajax($callback, 'ajax:' . $action));
    if ($allow_nopriv) {
        add_action('wp_ajax_nopriv_' . $action, svb_wrap_ajax($callback, 'ajax:' . $action));
    }
}

add_action('wp_ajax_svb_save_config', svb_wrap_ajax('svb_save_config', 'ajax:svb_save_config'));

add_shortcode('santa_video_form', 'svb_render_form');
svb_register_ajax_handler('svb_generate', 'svb_generate', true);
svb_register_ajax_handler('svb_confirm', 'svb_confirm', true);
svb_register_ajax_handler('svb_check_progress', 'svb_check_progress', true);
svb_register_ajax_handler('svb_dbg_push', 'svb_dbg_push', true);
svb_register_ajax_handler('svb_request_name', 'svb_request_name', true);
svb_register_ajax_handler('svb_find_video', 'svb_find_video', true);
svb_register_ajax_handler('svb_order_recover', 'svb_order_recover', true);
svb_register_ajax_handler('svb_order_resume_info', 'svb_order_resume_info', true);
svb_register_ajax_handler('svb_payment_gate', 'svb_payment_gate', true);
svb_register_ajax_handler('svb_pay_debug_state', 'svb_pay_debug_state', true);
svb_register_ajax_handler('svb_debug_session', 'svb_debug_session', true);
svb_register_ajax_handler('svb_monobank_sync_status', 'svb_monobank_sync_status', true);
svb_register_ajax_handler('svb_monobank_invalidate_invoice', 'svb_monobank_invalidate_invoice', true);
svb_register_ajax_handler('svb_monobank_create_invoice', 'svb_monobank_create_invoice', true);
svb_register_ajax_handler('svb_monobank_check_status', 'svb_monobank_check_status', true);
add_action('init', svb_wrap_hook('svb_handle_monobank_return', 'init:monobank_return'), 2);
add_action('init', svb_wrap_hook('svb_handle_public_order', 'init:public_order'), 3);
add_action('template_redirect', svb_wrap_hook('svb_handle_download_endpoint', 'template:download'), 0);
add_action('template_redirect', svb_wrap_hook('svb_handle_payment_return_redirect', 'template:payment_return'), -1);
