<?php
if (!defined('ABSPATH')) {
    exit;
}

function svb_dbg_push(){
    if (!isset($_POST['_svb_nonce']) || !wp_verify_nonce($_POST['_svb_nonce'], 'svb_nonce')) {
        wp_send_json_error('bad nonce');
    }
    $token = isset($_POST['token']) ? sanitize_text_field($_POST['token']) : '';
    if (!$token) wp_send_json_error('no token');

    // Пытаемся найти job_dir: либо ещё идёт (svb_job_data_), либо уже закончено (svb_job_)
    $data = get_transient('svb_job_data_'.$token);
    if (!$data || empty($data['job_dir'])) {
        $data = get_transient('svb_job_'.$token);
    }
    if (!$data || empty($data['dir']) && empty($data['job_dir'])) {
        wp_send_json_error('job not found');
    }
    $job_dir = !empty($data['job_dir']) ? $data['job_dir'] : $data['dir'];

    $payload_raw = isset($_POST['payload']) ? wp_unslash($_POST['payload']) : '';
    $payload = json_decode($payload_raw, true);
    if (!is_array($payload)) $payload = ['raw'=>$payload_raw];

    svb_align_log($job_dir, 'browser.dump', $payload);

    wp_send_json_success(['ok'=>1]);
}