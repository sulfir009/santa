<?php
if (!defined('ABSPATH')) {
    exit;
}

function svb_exec_find($bin)
{
    $path = @shell_exec('command -v ' . escapeshellarg($bin) . ' 2>/dev/null');
    if (!$path) {
        $path = @shell_exec('which ' . escapeshellarg($bin) . ' 2>/dev/null');
    }

    return $path ? trim($path) : '';
}

function svb_ff_has_filter($ffmpeg, $name)
{
    if (!$ffmpeg) {
        return false;
    }

    $cmd = $ffmpeg . ' -hide_banner -v 0 -filters 2>/dev/null';
    $out = @shell_exec($cmd);
    if (!$out) {
        return false;
    }

    return (bool) preg_match('/\b' . preg_quote($name, '/') . '\b/i', $out);
}

function svb_ffprobe_duration($file)
{
    $ffprobe = svb_exec_find('ffprobe');
    if (!$ffprobe) {
        return 480.0;
    }

    $cmd = $ffprobe . ' -v error -show_entries format=duration -of default=nw=1:nk=1 ' . escapeshellarg($file) . ' 2>/dev/null';
    $out = @shell_exec($cmd);
    $sec = $out ? (float) $out : 0.0;
    if ($sec <= 0) {
        return 480.0;
    }

    return $sec;
}
