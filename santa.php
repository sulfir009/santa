<?php
/**
 * Plugin Name: Santa Video Builder (All-in-One)
 * Description: 3-шаговый модуль генерации персонального видео (как elfisanta): выбор озвучек, загрузка фото, сборка через FFmpeg. Хранение результата 1 час.
 * Version: 3.1.3 (Real-Time Progress)
 * Author: You
 */

if (!defined('ABSPATH')) { exit; }

define('SVB_VER', '3.1.3');
define('SVB_PLUGIN_FILE', __FILE__);
define('SVB_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SVB_PLUGIN_URL', plugin_dir_url(__FILE__));

if (!defined('SVB_DEBUG')) {
    define('SVB_DEBUG', true);
}

require_once SVB_PLUGIN_DIR . 'src/helpers/debug.php';
require_once SVB_PLUGIN_DIR . 'src/helpers/media.php';
require_once SVB_PLUGIN_DIR . 'src/models/audio.php';
require_once SVB_PLUGIN_DIR . 'src/models/jobs.php';
require_once SVB_PLUGIN_DIR . 'src/view/form.php';
require_once SVB_PLUGIN_DIR . 'src/presenter/ajax.php';

add_shortcode('santa_video_form', 'svb_render_form');
add_action('wp_ajax_svb_generate', 'svb_generate');
add_action('wp_ajax_nopriv_svb_generate', 'svb_generate');
add_action('wp_ajax_svb_confirm', 'svb_confirm');
add_action('wp_ajax_nopriv_svb_confirm', 'svb_confirm');
add_action('wp_ajax_svb_check_progress', 'svb_check_progress');
add_action('wp_ajax_nopriv_svb_check_progress', 'svb_check_progress');
add_action('wp_ajax_svb_dbg_push', 'svb_dbg_push');
add_action('wp_ajax_nopriv_svb_dbg_push', 'svb_dbg_push');

add_action('svb_cleanup_job', 'svb_cleanup_job_cb', 10, 1);
