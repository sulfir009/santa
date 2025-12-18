<?php

if (!defined('ABSPATH')) { exit; }

function svb_get_price_map_uah() {
    $defaults = [1 => 249, 2 => 249, 3 => 249];
    $map = [];

    for ($i = 1; $i <= 3; $i++) {
        $opt_key = 'svb_price_child_' . $i;
        $const_key = 'SVB_MONOBANK_PRICE_' . $i;
        $val_raw = defined($const_key) ? constant($const_key) : get_option($opt_key, null);
        if ($val_raw === null || $val_raw === '') {
            $map[$i] = $defaults[$i];
            continue;
        }

        $val = (int) $val_raw;
        if ($val < 0) {
            $val = 0;
        }
        if ($val > 100000) {
            $val = 100000;
        }
        $map[$i] = $val;
    }

    return $map;
}

function svb_monobank_get_token() {
    if (defined('SVB_MONOBANK_TOKEN') && SVB_MONOBANK_TOKEN) {
        return SVB_MONOBANK_TOKEN;
    }

    $opt = get_option('svb_monobank_token');
    if ($opt) {
        return sanitize_text_field($opt);
    }

    return '';
}

function svb_monobank_price_to_kop($val) {
    if ($val === null || $val === '') {
        return 0;
    }
    $normalized = str_replace(',', '.', (string) $val);
    if (!is_numeric($normalized)) {
        return 0;
    }
    $float = (float) $normalized;
    if ($float < 0) {
        return 0;
    }
    return (int) round($float * 100);
}

function svb_monobank_price_map() {
    $uah_map = svb_get_price_map_uah();
    $map = [];

    for ($i = 1; $i <= 3; $i++) {
        $map[$i] = svb_monobank_price_to_kop($uah_map[$i] ?? 0);
    }

    return $map;
}

function svb_monobank_amount_for_children($child_count) {
    $count = (int) $child_count;
    if ($count < 1 || $count > 3) {
        return 0;
    }
    $map = svb_monobank_price_map();
    return isset($map[$count]) ? (int) $map[$count] : 0;
}

function svb_monobank_create_invoice_request($amount_kop, $redirect_url, $reference, $comment, $webhook_url = '') {
    $token = svb_monobank_get_token();
    if (!$token) {
        return new WP_Error('svb_monobank_no_token', 'Monobank token is not configured');
    }

    $body = [
        'amount' => (int) $amount_kop,
        'ccy' => 980,
        'redirectUrl' => esc_url_raw($redirect_url),
        'merchantPaymInfo' => [
            'reference' => $reference,
            'destination' => $comment,
        ],
    ];

    if ($webhook_url) {
        $body['webHookUrl'] = esc_url_raw($webhook_url);
    }

    $response = wp_remote_post(
        'https://api.monobank.ua/api/merchant/invoice/create',
        [
            'headers' => [
                'X-Token' => $token,
                'Content-Type' => 'application/json',
            ],
            'body'    => wp_json_encode($body),
            'timeout' => 20,
        ]
    );

    if (is_wp_error($response)) {
        return $response;
    }

    $code = wp_remote_retrieve_response_code($response);
    $body_raw = wp_remote_retrieve_body($response);
    $decoded = json_decode($body_raw, true);

    if ($code < 200 || $code >= 300 || !is_array($decoded)) {
        return new WP_Error('svb_monobank_bad_response', 'Invalid response from Monobank', [
            'status' => $code,
            'body'   => $body_raw,
        ]);
    }

    $decoded['_http_status'] = (int) $code;
    $decoded['_raw_body'] = $body_raw;

    return $decoded;
}

function svb_monobank_get_public_key() {
    return get_option('svb_monobank_pubkey');
}

function svb_monobank_verify_signature($body, $signature_b64) {
    $pubkey = svb_monobank_get_public_key();
    if (!$pubkey || !$signature_b64) {
        return null;
    }

    $signature = base64_decode($signature_b64);
    if ($signature === false) {
        return false;
    }

    $res = openssl_verify($body, $signature, $pubkey, OPENSSL_ALGO_SHA256);
    if ($res === 1) return true;
    if ($res === 0) return false;
    return null;
}

function svb_handle_monobank_webhook() {
    if (!isset($_GET['svb_monobank_webhook'])) {
        return;
    }

    if (!defined('DONOTCACHEPAGE')) {
        define('DONOTCACHEPAGE', true);
    }

    header('X-SVB-WEBHOOK: hit');

    $raw = file_get_contents('php://input');
    $payload = json_decode($raw, true);
    $sig_header = isset($_SERVER['HTTP_X_SIGN']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_X_SIGN'])) : '';
    $sig_present = !empty($sig_header);
    $sig_valid = svb_monobank_verify_signature($raw, $sig_header);

    if (function_exists('svb_dbg_write')) {
        svb_dbg_write('mono.webhook.hit', [
            'invoiceId' => $payload['invoiceId'] ?? '',
            'status' => $payload['status'] ?? '',
            'modifiedDate' => $payload['modifiedDate'] ?? '',
            'sig_present' => $sig_present,
            'sig_valid' => $sig_valid,
            'http_code_returned' => null,
        ]);
    }

    if ($sig_present && $sig_valid === false) {
        status_header(400);
        echo 'invalid signature';
        exit;
    }

    if (!is_array($payload)) {
        status_header(400);
        echo 'bad payload';
        exit;
    }

    $invoice_id = isset($payload['invoiceId']) ? sanitize_text_field($payload['invoiceId']) : '';
    $status = isset($payload['status']) ? sanitize_text_field($payload['status']) : '';
    $modified = isset($payload['modifiedDate']) ? sanitize_text_field($payload['modifiedDate']) : '';

    $order_row = $invoice_id ? svb_get_order_by_invoice_id($invoice_id) : null;
    if ($order_row && isset($order_row['order_id'])) {
        $existing_payment = svb_orders_normalize_payment(svb_orders_decode_payment($order_row['payment'] ?? []));
        $current_mod = $existing_payment['modified_date'] ?? '';

        if ($modified && (!$current_mod || $modified > $current_mod)) {
            $updates = [
                'status' => $status === 'success' ? 'success' : $status,
                'invoice_id' => $invoice_id,
                'modified_date' => $modified,
            ];

            if (!empty($existing_payment['fingerprint_at_invoice'])) {
                $updates['fingerprint_at_invoice'] = $existing_payment['fingerprint_at_invoice'];
            }

            svb_update_order_payment_by_id((int) $order_row['order_id'], $updates, [
                'current' => $order_row['fingerprint_current'] ?? '',
            ]);
        }
    }

    if (function_exists('svb_dbg_write')) {
        svb_dbg_write('mono.webhook.hit', [
            'invoiceId' => $payload['invoiceId'] ?? '',
            'status' => $payload['status'] ?? '',
            'modifiedDate' => $payload['modifiedDate'] ?? '',
            'sig_present' => $sig_present,
            'sig_valid' => $sig_valid,
            'http_code_returned' => 200,
        ]);
    }

    status_header(200);
    echo 'ok';
    exit;
}

function svb_monobank_get_invoice_status($invoice_id) {
    $token = svb_monobank_get_token();
    if (!$token) {
        return new WP_Error('svb_monobank_no_token', 'Monobank token is not configured');
    }

    $url = add_query_arg(
        ['invoiceId' => rawurlencode($invoice_id)],
        'https://api.monobank.ua/api/merchant/invoice/status'
    );

    $response = wp_remote_get(
        $url,
        [
            'headers' => [
                'X-Token' => $token,
            ],
            'timeout' => 20,
        ]
    );

    if (is_wp_error($response)) {
        return $response;
    }

    $code = wp_remote_retrieve_response_code($response);
    $body_raw = wp_remote_retrieve_body($response);
    $decoded = json_decode($body_raw, true);

    if ($code < 200 || $code >= 300 || !is_array($decoded)) {
        return new WP_Error('svb_monobank_bad_response', 'Invalid response from Monobank', [
            'status' => $code,
            'body'   => $body_raw,
        ]);
    }

    return $decoded;
}

function svb_handle_monobank_return() {
    if (empty($_GET['svb_payment_return'])) {
        return;
    }

    $order_data = svb_init_user_order();
    $uid = $order_data['uid'] ?? '';
    if (!$uid) {
        return;
    }

    $invoice_id = isset($_GET['invoiceId']) ? sanitize_text_field(wp_unslash($_GET['invoiceId'])) : '';
    $payment_state = svb_get_user_payment_state($uid);

    if (!$invoice_id && !empty($payment_state['invoice_id'])) {
        $invoice_id = $payment_state['invoice_id'];
    }

    if (!$invoice_id) {
        return;
    }

    $status = svb_monobank_get_invoice_status($invoice_id);
    if (is_wp_error($status)) {
        return;
    }

    $remote_status = $status['status'] ?? '';
    $normalized_status = 'pending';
    if ($remote_status === 'success') {
        $normalized_status = 'paid';
    } elseif (in_array($remote_status, ['failure', 'expired', 'canceled', 'reversed'], true)) {
        $normalized_status = 'failed';
    }

    svb_update_user_payment_state($uid, [
        'status' => $normalized_status,
        'invoice_id' => $invoice_id,
    ]);
}

