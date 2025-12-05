<?php
/**
 * AI Gemini Image Generator - VietQR Payment Checker
 * 
 * Checks for completed payments via bank API or webhook.
 */

if (!defined('ABSPATH')) exit;

/**
 * Register webhook endpoint for bank notifications
 */
function ai_gemini_register_payment_webhook() {
    register_rest_route('ai/v1', '/payment/webhook', [
        'methods' => 'POST',
        'callback' => 'ai_gemini_handle_payment_webhook',
        'permission_callback' => 'ai_gemini_verify_webhook_signature',
    ]);
}
add_action('rest_api_init', 'ai_gemini_register_payment_webhook');

/**
 * Verify webhook signature
 * 
 * @param WP_REST_Request $request Request object
 * @return bool|WP_Error True if valid, WP_Error otherwise
 */
function ai_gemini_verify_webhook_signature($request) {
    // Get signature from headers
    $signature = $request->get_header('X-Webhook-Signature');
    
    if (!$signature) {
        // Reject all unsigned webhook requests for security
        ai_gemini_log('Webhook request rejected: missing signature', 'warning');
        return new WP_Error('missing_signature', 'Webhook signature missing', ['status' => 401]);
    }
    
    $config = ai_gemini_get_vietqr_config();
    $secret = $config['api_secret'];
    
    if (empty($secret)) {
        return new WP_Error('not_configured', 'Webhook secret not configured', ['status' => 500]);
    }
    
    // Verify HMAC signature
    $body = $request->get_body();
    $expected_signature = hash_hmac('sha256', $body, $secret);
    
    if (!hash_equals($expected_signature, $signature)) {
        return new WP_Error('invalid_signature', 'Invalid webhook signature', ['status' => 401]);
    }
    
    return true;
}

/**
 * Handle payment webhook
 * 
 * @param WP_REST_Request $request Request object
 * @return WP_REST_Response Response
 */
function ai_gemini_handle_payment_webhook($request) {
    $data = $request->get_json_params();
    
    ai_gemini_log('Payment webhook received: ' . wp_json_encode($data), 'info');
    
    // Expected webhook format (varies by bank API provider)
    $amount = isset($data['amount']) ? (int) $data['amount'] : 0;
    $description = isset($data['description']) ? sanitize_text_field($data['description']) : '';
    $transaction_id = isset($data['transaction_id']) ? sanitize_text_field($data['transaction_id']) : '';
    
    // Extract order code from description
    $order_code = ai_gemini_extract_order_code($description);
    
    if (!$order_code) {
        ai_gemini_log('Could not extract order code from: ' . $description, 'warning');
        return rest_ensure_response(['status' => 'ignored', 'message' => 'No matching order code found']);
    }
    
    // Verify and complete the order
    global $wpdb;
    $table_orders = $wpdb->prefix . 'ai_gemini_orders';
    
    $order = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $table_orders WHERE order_code = %s AND status = 'pending'",
        $order_code
    ));
    
    if (!$order) {
        ai_gemini_log('No pending order found for code: ' . $order_code, 'warning');
        return rest_ensure_response(['status' => 'ignored', 'message' => 'Order not found or already processed']);
    }
    
    // Verify amount matches
    if ($amount !== (int) $order->amount) {
        ai_gemini_log("Amount mismatch for order {$order_code}: expected {$order->amount}, received {$amount}", 'warning');
        return rest_ensure_response(['status' => 'error', 'message' => 'Amount mismatch']);
    }
    
    // Complete the order
    $success = ai_gemini_complete_order($order_code, $transaction_id);
    
    if ($success) {
        ai_gemini_log("Order {$order_code} completed via webhook", 'info');
        return rest_ensure_response(['status' => 'success', 'message' => 'Order completed']);
    } else {
        ai_gemini_log("Failed to complete order {$order_code}", 'error');
        return rest_ensure_response(['status' => 'error', 'message' => 'Failed to complete order']);
    }
}

/**
 * Extract order code from payment description
 * 
 * @param string $description Payment description
 * @return string|false Order code or false if not found
 */
function ai_gemini_extract_order_code($description) {
    // Format: AIGC XXXXXXXX
    if (preg_match('/AIGC\s*([A-Z0-9]{8,10})/i', $description, $matches)) {
        return strtoupper($matches[1]);
    }
    
    // Alternative format: Just the order code
    if (preg_match('/AG[A-Z0-9]{8}/i', $description, $matches)) {
        return strtoupper($matches[0]);
    }
    
    return false;
}

/**
 * Manual payment verification (for admin)
 * 
 * @param string $order_code Order code
 * @param string $transaction_id Transaction ID (optional)
 * @return bool Success status
 */
function ai_gemini_manual_verify_payment($order_code, $transaction_id = '') {
    if (!current_user_can('manage_options')) {
        return false;
    }
    
    return ai_gemini_complete_order($order_code, $transaction_id);
}

/**
 * Schedule automatic payment check (optional - for polling-based verification)
 */
function ai_gemini_schedule_payment_check() {
    if (!wp_next_scheduled('ai_gemini_check_pending_payments')) {
        wp_schedule_event(time(), 'every_five_minutes', 'ai_gemini_check_pending_payments');
    }
}
add_action('init', 'ai_gemini_schedule_payment_check');

/**
 * Register custom cron interval
 */
function ai_gemini_cron_schedules($schedules) {
    $schedules['every_five_minutes'] = [
        'interval' => 300,
        'display' => __('Every 5 Minutes', 'ai-gemini-image'),
    ];
    return $schedules;
}
add_filter('cron_schedules', 'ai_gemini_cron_schedules');

/**
 * Check pending payments (via API polling - optional)
 * This is a fallback method if webhooks are not available
 */
function ai_gemini_check_pending_payments() {
    $config = ai_gemini_get_vietqr_config();
    
    // Only run if API is configured
    if (empty($config['api_key'])) {
        return;
    }
    
    global $wpdb;
    $table_orders = $wpdb->prefix . 'ai_gemini_orders';
    
    // Get pending orders from last 24 hours
    $pending_orders = $wpdb->get_results(
        "SELECT * FROM $table_orders 
         WHERE status = 'pending' 
         AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)"
    );
    
    if (empty($pending_orders)) {
        return;
    }
    
    // Check each order with bank API
    foreach ($pending_orders as $order) {
        $verified = ai_gemini_verify_payment_with_bank($order);
        
        if ($verified) {
            ai_gemini_complete_order($order->order_code, $verified['transaction_id']);
            ai_gemini_log("Order {$order->order_code} completed via API check", 'info');
        }
    }
}
add_action('ai_gemini_check_pending_payments', 'ai_gemini_check_pending_payments');

/**
 * Verify payment with bank API
 * 
 * @param object $order Order object
 * @return array|false Verification result or false
 */
function ai_gemini_verify_payment_with_bank($order) {
    $config = ai_gemini_get_vietqr_config();
    
    // This is a placeholder - implement based on your specific bank API
    // Each bank/payment provider has different API requirements
    
    /*
    Example implementation for Casso/PayOS:
    
    $response = wp_remote_get('https://api.casso.vn/v2/transactions', [
        'headers' => [
            'Authorization' => 'Bearer ' . $config['api_key'],
        ],
    ]);
    
    if (is_wp_error($response)) {
        return false;
    }
    
    $transactions = json_decode(wp_remote_retrieve_body($response), true);
    
    foreach ($transactions['data'] as $transaction) {
        if (strpos($transaction['description'], $order->order_code) !== false 
            && $transaction['amount'] == $order->amount) {
            return [
                'transaction_id' => $transaction['id'],
                'amount' => $transaction['amount'],
            ];
        }
    }
    */
    
    return false;
}

/**
 * Clear payment check schedule on deactivation
 */
function ai_gemini_clear_payment_schedule() {
    wp_clear_scheduled_hook('ai_gemini_check_pending_payments');
}
register_deactivation_hook(AI_GEMINI_PLUGIN_DIR . 'ai-gemini-image.php', 'ai_gemini_clear_payment_schedule');
