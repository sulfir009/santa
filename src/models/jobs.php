<?php

if (!defined('ABSPATH')) { exit; }

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

