<?php
if (!defined('ABSPATH')) {
    exit;
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

