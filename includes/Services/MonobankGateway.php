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

function svb_monobank_mask_invoice($invoice_id) {
    if (!$invoice_id) {
        return '';
    }

    $id = (string) $invoice_id;
    if (strlen($id) <= 6) {
        return $id;
    }

    return substr($id, 0, 6) . '***' . substr($id, -4);
}

function svb_monobank_prefix($value, $len = 8) {
    if (!$value) {
        return '';
    }

    return substr((string) $value, 0, $len);
}

function svb_monobank_extract_modified($payload) {
    if (is_array($payload)) {
        if (isset($payload['modifiedDate'])) {
            return (int) $payload['modifiedDate'];
        }
        if (isset($payload['paymentDetails']['modifiedDate'])) {
            return (int) $payload['paymentDetails']['modifiedDate'];
        }
    }

    return 0;
}

function svb_monobank_normalize_status($status) {
    $normalized = strtolower((string) $status);
    if ($normalized === 'success') {
        return 'success';
    }
    if (in_array($normalized, ['failure', 'expired', 'canceled', 'reversed'], true)) {
        return 'failure';
    }
    if (in_array($normalized, ['processing', 'hold'], true)) {
        return 'processing';
    }

    return 'pending';
}

function svb_monobank_extract_order_id($payload) {
    $reference = '';
    if (is_array($payload)) {
        if (!empty($payload['paymentDetails']['merchantPaymInfo']['reference'])) {
            $reference = (string) $payload['paymentDetails']['merchantPaymInfo']['reference'];
        } elseif (!empty($payload['reference'])) {
            $reference = (string) $payload['reference'];
        }
    }

    if (!$reference) {
        return 0;
    }

    if (preg_match('/SVB-(\d+)-/', $reference, $m)) {
        return absint($m[1]);
    }

    return 0;
}

function svb_monobank_apply_payment_status(array $order_row, array $status_payload) {
    $payment = svb_orders_normalize_payment(svb_orders_decode_payment($order_row['payment'] ?? []));
    $current_status = $payment['status'] ?? 'unpaid';
    $saved_modified = isset($payment['modifiedDate']) ? (int) $payment['modifiedDate'] : 0;

    $remote_status = isset($status_payload['status']) ? $status_payload['status'] : '';
    $normalized_status = svb_monobank_normalize_status($remote_status);
    $remote_modified = svb_monobank_extract_modified($status_payload);

    $should_update = ($remote_modified && $remote_modified > $saved_modified);
    if (!$should_update) {
        return $payment;
    }

    $updates = [
        'status' => $normalized_status,
        'invoice_id' => sanitize_text_field($status_payload['invoiceId'] ?? ($payment['invoice_id'] ?? '')),
        'modifiedDate' => $remote_modified ?: $saved_modified,
        'failureReason' => sanitize_text_field($status_payload['failureReason'] ?? ''),
    ];

    if (!empty($status_payload['paymentDetails']['merchantPaymInfo']['reference'])) {
        $updates['reference'] = sanitize_text_field($status_payload['paymentDetails']['merchantPaymInfo']['reference']);
    }

    if ($normalized_status === 'success') {
        $updates['paid_fingerprint'] = $payment['paid_fingerprint'] ?? ($order_row['fingerprint_current'] ?? '');
        if (!empty($status_payload['paymentDetails']['transactionId'])) {
            $updates['transaction_id'] = sanitize_text_field($status_payload['paymentDetails']['transactionId']);
        } elseif (!empty($status_payload['transactionId'])) {
            $updates['transaction_id'] = sanitize_text_field($status_payload['transactionId']);
        }
        if (empty($payment['paid_at'])) {
            $updates['paid_at'] = time();
        }
    }

    $updated_payment = svb_update_order_payment_by_order_id($order_row['order_id'], $updates);

    if ($updated_payment && function_exists('svb_pay_log')) {
        svb_pay_log('mono.status.sync', [
            'old_status' => $current_status,
            'new_status' => $updated_payment['status'] ?? $normalized_status,
            'old_modifiedDate' => $saved_modified,
            'new_modifiedDate' => $updated_payment['modifiedDate'] ?? $remote_modified,
        ], ['job_dir' => $order_row['job_dir'] ?? '']);
    }

    return $updated_payment ?: $payment;
}

function svb_monobank_get_webhook_public_key() {
    if (defined('SVB_MONOBANK_WEBHOOK_KEY') && SVB_MONOBANK_WEBHOOK_KEY) {
        return SVB_MONOBANK_WEBHOOK_KEY;
    }

    $stored = get_option('svb_monobank_webhook_key');
    if ($stored) {
        return (string) $stored;
    }

    return '';
}

function svb_monobank_verify_signature($body, $signature_b64) {
    $public_key = svb_monobank_get_webhook_public_key();
    if (!$public_key || !$signature_b64) {
        return false;
    }

    $decoded = base64_decode($signature_b64, true);
    if ($decoded === false) {
        return false;
    }

    $res = openssl_pkey_get_public($public_key);
    if (!$res) {
        return false;
    }

    $verified = openssl_verify($body, $decoded, $res, OPENSSL_ALGO_SHA256) === 1;
    openssl_free_key($res);

    return $verified;
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

function svb_monobank_invalidate_invoice_request($invoice_id) {
    $token = svb_monobank_get_token();
    if (!$token) {
        return new WP_Error('svb_monobank_no_token', 'Monobank token is not configured');
    }

    $payload = [
        'invoiceId' => sanitize_text_field($invoice_id),
    ];

    $response = wp_remote_post(
        'https://api.monobank.ua/api/merchant/invoice/cancel',
        [
            'headers' => [
                'X-Token' => $token,
                'Content-Type' => 'application/json',
            ],
            'body'    => wp_json_encode($payload),
            'timeout' => 20,
        ]
    );

    if (is_wp_error($response)) {
        return $response;
    }

    $code = wp_remote_retrieve_response_code($response);
    $body_raw = wp_remote_retrieve_body($response);
    $decoded = json_decode($body_raw, true);

    if ($code < 200 || $code >= 300) {
        return new WP_Error('svb_monobank_bad_response', 'Invalid response from Monobank', [
            'status' => $code,
            'body'   => $body_raw,
        ]);
    }

    return is_array($decoded) ? $decoded : ['_raw_body' => $body_raw, '_http_status' => $code];
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

function svb_handle_monobank_webhook(WP_REST_Request $request) {
    $body = $request->get_body();
    $signature = isset($_SERVER['HTTP_X_SIGN']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_X_SIGN'])) : '';
    $payload = json_decode($body, true);
    $invoice_id = is_array($payload) && isset($payload['invoiceId']) ? sanitize_text_field($payload['invoiceId']) : '';
    $status = is_array($payload) && isset($payload['status']) ? sanitize_text_field($payload['status']) : '';
    $modified_date = svb_monobank_extract_modified(is_array($payload) ? $payload : []);

    $sig_valid = svb_monobank_verify_signature($body, $signature);
    $sig_present = ($signature !== '');

    svb_pay_log('mono.webhook.hit', [
        'status' => $status,
        'modifiedDate' => $modified_date,
        'sig_present' => $sig_present,
        'sig_valid' => $sig_valid,
        'invoiceId_masked' => svb_monobank_mask_invoice($invoice_id),
    ]);

    $response = new WP_REST_Response(['received' => true]);
    $response->set_status($sig_valid ? 200 : 400);
    $response->header('X-SVB-WEBHOOK', 'hit');

    if (!$sig_valid) {
        return $response;
    }

    $order_id = svb_monobank_extract_order_id(is_array($payload) ? $payload : []);
    if ($order_id) {
        $order_row = svb_get_order_by_id($order_id);
        if ($order_row) {
            svb_monobank_apply_payment_status($order_row, is_array($payload) ? $payload : []);
        }
    }

    return $response;
}

add_action('rest_api_init', function() {
    register_rest_route('svb/v1', '/monobank/webhook', [
        'methods' => 'POST',
        'callback' => 'svb_handle_monobank_webhook',
        'permission_callback' => '__return_true',
    ]);
});

