<?php
if (!defined('ABSPATH')) {
    exit;
}

function svb_ts_to_seconds($ts)
{
    $ts = trim((string) $ts);

    if (preg_match('/^\d{1,2}:\d{2}:\d{2}$/', $ts)) {
        [$mm, $ss, $cc] = array_map('intval', explode(':', $ts));
        return $mm * 60 + $ss + ($cc / 100);
    }

    if (preg_match('/^(\d{2}):(\d{2}):(\d{2})\.(\d{2})$/', $ts, $m)) {
        return ((int) $m[1] * 3600) + ((int) $m[2] * 60) + (int) $m[3] + ((int) $m[4] / 100);
    }

    if (preg_match('/^-?(?:\d{1,2}:)?\d{1,2}:\d{2}(?:\.\d+)?$/', $ts)) {
        $neg = $ts[0] === '-';
        $ts  = ltrim($ts, '-');
        $parts   = explode(':', $ts);
        if (count($parts) === 3) {
            [$hh, $mm, $ss] = $parts;
            $sec            = (int) $hh * 3600 + (int) $mm * 60 + (float) $ss;
        } else {
            [$mm, $ss] = $parts;
            $sec       = (int) $mm * 60 + (float) $ss;
        }

        return $neg ? -$sec : $sec;
    }

    return 0.0;
}
