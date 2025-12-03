<?php
if (!defined('ABSPATH')) {
    exit;
}

function svb_dbg_write($job_dir, $label, $text)
{
    if (!SVB_DEBUG || !$job_dir) {
        return;
    }

    $file = rtrim($job_dir, '/') . '/svb_debug.log';
    $ts   = date('Y-m-d H:i:s');
    $line = "[$ts] $label\n";
    $line .= is_string($text) ? $text : var_export($text, true);
    $line .= "\n-----------------------------\n";
    @file_put_contents($file, $line, FILE_APPEND);
}

function svb_align_log_open($job_dir)
{
    if (!$job_dir) {
        return '';
    }

    $path = rtrim($job_dir, '/') . '/svb_align.jsonl';
    if (!file_exists($path)) {
        @file_put_contents($path, '');
    }

    return $path;
}

function svb_align_log($job_dir, $event, $payload)
{
    if (!$job_dir) {
        return;
    }

    $file = svb_align_log_open($job_dir);
    if (!$file) {
        return;
    }

    $row = [
        'ts'    => date('c'),
        'event' => (string) $event,
        'data'  => $payload,
    ];

    @file_put_contents(
        $file,
        json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n",
        FILE_APPEND
    );
}
