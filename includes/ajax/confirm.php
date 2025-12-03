<?php
if (!defined('ABSPATH')) {
    exit;
}

function svb_confirm(){
    if (!isset($_POST['_svb_nonce']) || !wp_verify_nonce($_POST['_svb_nonce'], 'svb_nonce')) {
        wp_send_json_error('bad nonce');
    }
    $email = isset($_POST['email']) ? sanitize_email($_POST['email']) : '';
    $token = isset($_POST['token']) ? sanitize_text_field($_POST['token']) : '';
    if (!$token) wp_send_json_error('no token');
    
    // ВНИМАНИЕ: теперь ищем старый трансиент, который создается в svb_check_progress
    $data = get_transient('svb_job_'.$token); 
    
    if (!$data || empty($data['url'])) {
        // Проверяем, может, он еще в обработке?
        $data_progress = get_transient('svb_job_data_'.$token);
        if ($data_progress) {
             wp_send_json_error('Video is still processing.');
        }
        wp_send_json_error('Video not found or expired.');
    }

    if ($email) {
        $subject = 'Ваше персональне відео від Санти';
        $message = 'Дякуємо! Ваше відео готове: ' . $data['url'] . "\nПосилання дійсне протягом 1 години.";
        @wp_mail($email, $subject, $message);
    }
    // ✅ Получаем выбранное видео
$selected_video_id = isset($input['selected_video_id']) 
    ? sanitize_text_field($input['selected_video_id']) 
    : 'video1';

if (!isset($video_templates[$selected_video_id])) {
    $selected_video_id = 'video1';
}

// ✅ Используем тайминги для выбранного видео
svb_dbg_write($job_dir, 'confirm.selected_video', [
    'video_id' => $selected_video_id,
    'segments' => $segments
]);


    wp_send_json_success([ 'url'=>$data['url'] ]);
}
