<?php
if (!defined('ABSPATH')) { exit; }

function svb_render_form() {
    // === 1. ЛОГІКА ЗАМОВЛЕННЯ (ORDER SYSTEM) ===
    // Ініціалізуємо або отримуємо існуюче замовлення для цього користувача
    $order_data = svb_init_user_order();
    $order_id = $order_data['order_id'];
    $payment_state = isset($order_data['payment']) ? $order_data['payment'] : svb_get_payment_defaults();
    if (!empty($payment_state)) {
        $payment_state['status'] = svb_payment_normalize_status($payment_state['status'] ?? 'unpaid');
    }

    svb_log('payment_debug_page_boot', [
        'source' => 'render_form',
        'order_id' => $order_id,
        'payment_status' => $payment_state['status'] ?? '',
        'public_token' => !empty($order_data['public_token']) ? svb_mask_value($order_data['public_token']) : '',
        'session_id' => !empty($order_data['session_id']) ? svb_mask_value($order_data['session_id']) : '',
    ]);

    $welcome_msg = "Ваше замовлення №<strong>{$order_id}</strong>.";
    $video_ready_html = '';

    // Перевірка наявності попереднього відео
    if (!empty($order_data['video_generated']) && !empty($order_data['video_url'])) {
        // Перевіряємо, чи файл все ще існує на диску
        $phys_path = str_replace(site_url('/'), ABSPATH, $order_data['video_url']);
        // Або використовуємо збережений video_path, якщо він коректний
        if (!empty($order_data['video_path']) && file_exists($order_data['video_path'])) {
            // Відео існує фізично -> Даємо кнопку скачати
            $video_ready_html = '
            <div style="margin-top:10px; padding:10px; background:#d4edda; color:#155724; border-radius:8px; border:1px solid #c3e6cb;">
                <strong>🎥 Ваше відео готове!</strong><br>
                <div style="margin-top:5px; display:flex; gap:10px; align-items:center;">
                    <a href="'.$order_data['video_url'].'" download class="svb-btn primary" style="padding:6px 12px; font-size:12px; text-decoration:none; color:white;">⬇ Завантажити</a>
                    <span style="font-size:11px; color:#666;">або створіть нове нижче</span>
                </div>
            </div>';
        } elseif (!empty($order_data['video_generated'])) {
            // Файл видалено
             $video_ready_html = '
            <div style="margin-top:10px; padding:10px; background:#fff3cd; color:#856404; border-radius:8px; border:1px solid #ffeeba;">
                <strong>⚠️ Термін дії відео закінчився.</strong><br>
                Будь ласка, створіть нове відео. Ваші дані (Ім\'я/E-mail) збережені.
            </div>';
        }
    }
    // ============================================

    $defs = svb_get_definitions();

    $video_templates = [];
    $template_timings = [];

    foreach ($defs as $vid => $cfg) {
        $video_templates[$vid] = [
            'label'  => $cfg['label'],
            'file'   => basename($cfg['file']),
            'url'    => $cfg['url'],
            'image'  => isset($cfg['image']) ? $cfg['image'] : '',
            'scenes' => $cfg['scenes'],
            'for_children' => isset($cfg['for_children']) ? $cfg['for_children'] : [1] // Default to 1 if not set
        ];
        $template_timings[$vid] = $cfg['scenes'];
    }

    $selected_video_id = isset($_POST['selected_video_id']) ?
        sanitize_text_field($_POST['selected_video_id']) : 'video1';

    if (!isset($video_templates[$selected_video_id])) {
        $selected_video_id = 'video1';
    }

    $template_url = $video_templates[$selected_video_id]['url'];

    $default_segments = $template_timings[$selected_video_id];

    $audio_catalog = svb_scan_audio_catalog();
    $ajax_url = admin_url('admin-ajax.php');
    $nonce = wp_create_nonce('svb_nonce');

    $current_path = isset($_SERVER['REQUEST_URI']) ? wp_unslash($_SERVER['REQUEST_URI']) : '/';
    $payment_return_url = add_query_arg('svb_payment_return', '1', home_url($current_path));
    $payment_prices = svb_get_price_map_uah();
    $payment_enabled = svb_monobank_get_token() && (max($payment_prices ?: [0]) > 0);

    $ffmpeg_path = svb_exec_find('ffmpeg');
    $preview_caps = [
        'perspective' => $ffmpeg_path ? svb_ff_has_filter($ffmpeg_path, 'perspective') : false,
    ];
    $is_admin = is_user_logged_in() && current_user_can('manage_options');


    $user_segments = [];

    if ( ! empty( $_POST['svb_segments'] ) ) {
        $raw = wp_unslash( $_POST['svb_segments'] );
        $decoded = json_decode( $raw, true );
        if ( is_array( $decoded ) ) {
            $user_segments = $decoded;
        }
    }

    $segments = array_merge( $default_segments, $user_segments );

    $P_CHILD1  = $segments['child1'] ?? [];
    $P_CHILD2  = $segments['child2'] ?? [];
    // FIX: Берем parent1, так как ключа parents в конфиге нет
    $P_PARENTS = $segments['parents'] ?? ($segments['parent1'] ?? []);

    $to_sec = function($pairs){
        return array_map(function($a){
            // Проверяем, в каком формате пришли данные (start/end или 0/1)
            $s = isset($a['start']) ? $a['start'] : (isset($a[0]) ? $a[0] : 0);
            $e = isset($a['end'])   ? $a['end']   : (isset($a[1]) ? $a[1] : 0);
            return [ svb_ts_to_seconds($s), svb_ts_to_seconds($e) ];
        }, $pairs);
    };
    $OVER = [
        'child1'  => $to_sec($P_CHILD1),
        'child2'  => $to_sec($P_CHILD2),
        'parent1' => $to_sec($P_PARENTS),
        'parent2' => $to_sec($P_PARENTS),
    ];

    $localize = [
        'audio'               => $audio_catalog,
        'video_templates'     => $video_templates,
        'template_timings'    => $template_timings,
        'selected_video_id'   => $selected_video_id,
        'ajax_url'            => $ajax_url,
        'nonce'               => $nonce,
        'template_url'        => $template_url,
        'preview_caps'        => $preview_caps,
        'overlay_windows'     => $OVER,
        'processed_photo_size'=> 709,
        'payment'             => [
            'enabled'     => (bool) $payment_enabled,
            'status'      => $payment_state['status'] ?? 'unpaid',
            'invoice_id'  => $payment_state['invoice_id'] ?? '',
            'child_count' => (int) ($payment_state['child_count'] ?? 1),
            'prices'      => $payment_prices,
            'return_url'  => esc_url($payment_return_url),
            'is_admin'    => $is_admin,
        ],
        // FIX: allow JS-side admin diagnostics without exposing to visitors
        'is_admin'            => $is_admin,
        'debug'               => [
            'enabled'              => svb_debug_enabled(),
            'order_id'             => $order_id,
            'payment_status'       => $payment_state['status'] ?? 'unpaid',
            'public_token_masked'  => !empty($order_data['public_token']) ? substr($order_data['public_token'], 0, 4) . '...' : '',
            'state_version'        => defined('SVB_STATE_VERSION') ? SVB_STATE_VERSION : null,
        ],
    ];

    $price_map_uah = $payment_prices;

    svb_enqueue_shortcode_assets( $is_admin, $localize );

    $template_path = SVB_PLUGIN_DIR . 'templates/shortcode-view.php';

    ob_start();
    if ( file_exists( $template_path ) ) {
        include $template_path;
    }
    return ob_get_clean();
}

function svb_enqueue_shortcode_assets( $is_admin, array $localize ) {
    wp_enqueue_style(
        'svb-cropper',
        'https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.css',
        [],
        '1.5.13'
    );

    wp_enqueue_style(
        'svb-shortcode',
        SVB_PLUGIN_URL . 'assets/css/shortcode.css',
        [],
        SVB_VER
    );

    if ( ! $is_admin ) {
        $inline_css = '.svb-admin-only,[id^="svb-dbg-"]{display:none !important;}';
        wp_add_inline_style( 'svb-shortcode', $inline_css );
    }

    wp_enqueue_script(
        'svb-heic2any',
        SVB_PLUGIN_URL . 'assets/js/heic2any.min.js',
        [],
        '0.0.4',
        true
    );

    wp_enqueue_script(
        'svb-cropper',
        'https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.js',
        [],
        '1.5.13',
        true
    );

    wp_enqueue_script(
        'svb-shortcode',
        SVB_PLUGIN_URL . 'assets/js/shortcode.js',
        [ 'svb-heic2any' ],
        SVB_VER,
        true
    );

    wp_localize_script( 'svb-shortcode', 'SVB_DATA', $localize );
}
