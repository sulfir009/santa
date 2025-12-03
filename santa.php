<?php
/**
 * Plugin Name: Santa Video Builder (All-in-One)
 * Description: 3-шаговый модуль генерации персонального видео (как elfisanta): выбор озвучек, загрузка фото, сборка через FFmpeg. Хранение результата 1 час.
 * Version: 3.1.3 (Real-Time Progress)
 * Author: You
 */

if (!defined('ABSPATH')) {
    exit;
}

define('SVB_VER', '3.1.3');
define('SVB_PLUGIN_FILE', __FILE__);
define('SVB_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SVB_PLUGIN_URL', plugin_dir_url(__FILE__));

if (!defined('SVB_DEBUG')) {
    define('SVB_DEBUG', true);
}

require_once SVB_PLUGIN_DIR . 'includes/helpers/debug.php';
require_once SVB_PLUGIN_DIR . 'includes/helpers/time.php';
require_once SVB_PLUGIN_DIR . 'includes/helpers/system.php';
require_once SVB_PLUGIN_DIR . 'includes/helpers/media.php';
require_once SVB_PLUGIN_DIR . 'includes/helpers/filesystem.php';
require_once SVB_PLUGIN_DIR . 'includes/services/audio-library.php';
require_once SVB_PLUGIN_DIR . 'includes/services/cleanup.php';
require_once SVB_PLUGIN_DIR . 'includes/frontend/form.php';
require_once SVB_PLUGIN_DIR . 'includes/ajax/generate.php';
require_once SVB_PLUGIN_DIR . 'includes/ajax/progress.php';
require_once SVB_PLUGIN_DIR . 'includes/ajax/confirm.php';
require_once SVB_PLUGIN_DIR . 'includes/ajax/debug.php';
require_once SVB_PLUGIN_DIR . 'includes/hooks.php';
