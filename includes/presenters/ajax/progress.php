<?php
if (!defined('ABSPATH')) {
    exit;
}

function svb_check_progress() {
    if (!isset($_POST['_svb_nonce']) || !wp_verify_nonce($_POST['_svb_nonce'], 'svb_nonce')) {
        wp_send_json_error('bad nonce');
    }
    $token = isset($_POST['token']) ? sanitize_text_field($_POST['token']) : '';
    if (!$token) wp_send_json_error('no token');

    $data = get_transient('svb_job_data_'.$token);
    if (!$data || empty($data['logFile']) || empty($data['tplDur'])) {
        wp_send_json_error(['status' => 'error', 'msg' => 'Job data expired or invalid.']);
    }

    $logFile = $data['logFile'];
    $outputFile = $data['output'];
    $tplDur = (float)$data['tplDur'];
    $pidFile = $data['pidFile'];
    $pid = file_exists($pidFile) ? trim(@file_get_contents($pidFile)) : null;

    // 1. Проверяем, жив ли процесс
    $is_running = false;
    if ($pid && is_numeric($pid)) {
        // 'ps -p PID' вернет что-то, если процесс жив
        $check = @exec('ps -p '.escapeshellarg($pid));
        if ($check && strpos($check, (string)$pid) !== false) {
            $is_running = true;
        }
    }

    // 2. Если процесс жив, читаем лог и отдаем %
    if ($is_running) {
        if (!file_exists($logFile)) {
            wp_send_json_success(['status' => 'running', 'percent' => 0]); // Еще не начал писать
        }
        
        // Читаем последние ~20 строк лога, чтобы найти 'time='
        $log_content = @shell_exec('tail -n 20 ' . escapeshellarg($logFile));
        
        $percent = 0;
        
        // Ищем последнюю строку с 'time='
        // (frame=... time=00:01:30.12 ...)
        if (preg_match_all('/time=(\d{2}:\d{2}:\d{2}\.\d{2})/', $log_content, $matches)) {
            $last_time_str = end($matches[1]); // Берем последнее совпадение
            $current_sec = svb_ts_to_seconds($last_time_str);
            
            if ($tplDur > 0) {
                $percent = min(99, floor(($current_sec / $tplDur) * 100));
            }
        }
        wp_send_json_success(['status' => 'running', 'percent' => $percent]);
    }

    // 3. Процесс мертв. Проверяем результат.
    if (file_exists($outputFile) && filesize($outputFile) > 1000) {
        // Успех!
        svb_schedule_cleanup($data['job_dir']); // Теперь крон здесь
        set_transient('svb_job_'.$token, [ 'dir'=>$data['job_dir'], 'url'=>$data['url'] ], HOUR_IN_SECONDS); // Старый трансиент для email
        delete_transient('svb_job_data_'.$token); // Чистим data-трансиент
        @unlink($pidFile);
        @unlink($logFile);
        
        wp_send_json_success(['status' => 'done', 'url' => $data['url']]);
    } else {
        // Ошибка!
        $log_content = @file_get_contents($logFile);
        svb_dbg_write($data['job_dir'], 'final.error.log', $log_content);
        wp_send_json_error([
            'status' => 'error',
            'msg' => 'FFmpeg process failed or output file is invalid.',
            'log' => $log_content
        ]);
    }
}



/** === Подтверждение и выдача ссылки === */
