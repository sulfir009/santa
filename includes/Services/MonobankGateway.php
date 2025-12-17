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

