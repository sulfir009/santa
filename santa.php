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

if (!defined('SVB_DEBUG')) {
    define('SVB_DEBUG', true);
}
if (!defined('SVB_ORDERS_V2')) {
    define('SVB_ORDERS_V2', false);
}

require_once SVB_PLUGIN_DIR . 'includes/Models/Order.php';
require_once SVB_PLUGIN_DIR . 'includes/Models/Config.php';
require_once SVB_PLUGIN_DIR . 'includes/Services/MediaPipeline.php';
require_once SVB_PLUGIN_DIR . 'includes/Services/MonobankGateway.php';
require_once SVB_PLUGIN_DIR . 'includes/Presenters/ShortcodeController.php';
require_once SVB_PLUGIN_DIR . 'includes/Presenters/AjaxController.php';

register_activation_hook(__FILE__, 'svb_install_orders_table');
register_activation_hook(__FILE__, 'svb_install_orders_v2_table');
register_activation_hook(__FILE__, function() {
    if (!wp_next_scheduled('svb_cleanup_order_results')) {
        wp_schedule_event(time() + MINUTE_IN_SECONDS, 'hourly', 'svb_cleanup_order_results');
    }
});

add_action('svb_cleanup_order_results', 'svb_cleanup_order_results_cb');

add_filter('redirect_canonical', function($redirect_url, $requested_url) {
    if (isset($_GET['svb_download'])) {
        return false;
    }

    return $redirect_url;
}, 10, 2);

add_action('init', function() {
    if (is_admin()) {
        svb_maybe_ensure_orders_schema();
    }
}, 0);

function svb_stream_order_video($order_id, $token) {
    $order_id = absint($order_id);
    $token = sanitize_text_field($token);

    $set_reason_header = function($reason_key) {
        header('X-SVB-Download: hit');
        if (defined('SVB_DEBUG') && SVB_DEBUG) {
            header('X-SVB-Download-Reason: ' . $reason_key);
        }
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

    if (empty($row['token_hash']) || !hash_equals($row['token_hash'], hash('sha256', $token))) {
        $send_error('bad_token', 403);
    }

    $result = is_array(json_decode($row['result'] ?? '', true)) ? json_decode($row['result'], true) : [];
    $video_path = $result['video_path'] ?? '';
    $generated_at = isset($result['generated_at']) ? strtotime($result['generated_at']) : 0;

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
    if (empty($_GET['svb_order']) || empty($_GET['token'])) {
        return;
    }

    svb_stream_order_video(absint($_GET['svb_order']), wp_unslash($_GET['token']));
}

function svb_handle_download_endpoint() {
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
add_action('init', 'svb_init_cookie_logic', 1); // Приоритет 1 (раньше всех)

add_action('svb_cleanup_job', 'svb_cleanup_job_cb', 10, 1);
add_action('wp_ajax_svb_save_config', 'svb_save_config');

add_shortcode('santa_video_form', 'svb_render_form');
add_action('wp_ajax_svb_generate', 'svb_generate');
add_action('wp_ajax_nopriv_svb_generate', 'svb_generate');
add_action('wp_ajax_svb_confirm', 'svb_confirm');
add_action('wp_ajax_nopriv_svb_confirm', 'svb_confirm');
add_action('wp_ajax_svb_check_progress', 'svb_check_progress');
add_action('wp_ajax_nopriv_svb_check_progress', 'svb_check_progress');
add_action('wp_ajax_svb_dbg_push', 'svb_dbg_push');
add_action('wp_ajax_nopriv_svb_dbg_push', 'svb_dbg_push');
add_action('wp_ajax_svb_request_name', 'svb_request_name');
add_action('wp_ajax_nopriv_svb_request_name', 'svb_request_name');
add_action('wp_ajax_svb_order_recover', 'svb_order_recover');
add_action('wp_ajax_nopriv_svb_order_recover', 'svb_order_recover');
add_action('wp_ajax_svb_payment_gate', 'svb_payment_gate');
add_action('wp_ajax_nopriv_svb_payment_gate', 'svb_payment_gate');
add_action('wp_ajax_svb_pay_debug_state', 'svb_pay_debug_state');
add_action('wp_ajax_nopriv_svb_pay_debug_state', 'svb_pay_debug_state');
add_action('wp_ajax_svb_monobank_create_invoice', 'svb_monobank_create_invoice');
add_action('wp_ajax_nopriv_svb_monobank_create_invoice', 'svb_monobank_create_invoice');
add_action('wp_ajax_svb_monobank_check_status', 'svb_monobank_check_status');
add_action('wp_ajax_nopriv_svb_monobank_check_status', 'svb_monobank_check_status');
add_action('init', 'svb_handle_monobank_return', 2);
add_action('init', 'svb_handle_public_order', 3);
add_action('template_redirect', 'svb_handle_download_endpoint', 0);
