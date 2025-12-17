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

require_once SVB_PLUGIN_DIR . 'includes/Models/Order.php';
require_once SVB_PLUGIN_DIR . 'includes/Models/Config.php';
require_once SVB_PLUGIN_DIR . 'includes/Services/MediaPipeline.php';
require_once SVB_PLUGIN_DIR . 'includes/Presenters/ShortcodeController.php';
require_once SVB_PLUGIN_DIR . 'includes/Presenters/AjaxController.php';

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
