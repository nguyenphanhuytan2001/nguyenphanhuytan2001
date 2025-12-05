<?php
/**
 * AI Gemini Image Generator - Credit API
 * 
 * REST API endpoint for credit management.
 */

if (!defined('ABSPATH')) exit;

/**
 * Register credit API endpoints
 */
function ai_gemini_register_credit_api() {
    // Get credit balance
    register_rest_route('ai/v1', '/credit', [
        'methods' => 'GET',
        'callback' => 'ai_gemini_handle_get_credit',
        'permission_callback' => '__return_true',
    ]);
    
    // Get credit packages
    register_rest_route('ai/v1', '/credit/packages', [
        'methods' => 'GET',
        'callback' => 'ai_gemini_handle_get_packages',
        'permission_callback' => '__return_true',
    ]);
    
    // Create credit order
    register_rest_route('ai/v1', '/credit/order', [
        'methods' => 'POST',
        'callback' => 'ai_gemini_handle_create_order',
        'permission_callback' => '__return_true',
    ]);
    
    // Check order status
    register_rest_route('ai/v1', '/credit/order/(?P<order_code>[a-zA-Z0-9]+)', [
        'methods' => 'GET',
        'callback' => 'ai_gemini_handle_check_order',
        'permission_callback' => '__return_true',
    ]);
}
add_action('rest_api_init', 'ai_gemini_register_credit_api');

/**
 * Handle get credit request
 * 
 * @param WP_REST_Request $request Request object
 * @return WP_REST_Response Response
 */
function ai_gemini_handle_get_credit($request) {
    $user_id = get_current_user_id();
    
    $credits = ai_gemini_get_credit($user_id ?: null);
    $has_trial = !ai_gemini_has_used_trial($user_id ?: null);
    
    return rest_ensure_response([
        'credits' => $credits,
        'has_free_trial' => $has_trial,
        'preview_cost' => (int) get_option('ai_gemini_preview_credit', 0),
        'unlock_cost' => (int) get_option('ai_gemini_unlock_credit', 1),
    ]);
}

/**
 * Handle get packages request
 * 
 * @param WP_REST_Request $request Request object
 * @return WP_REST_Response Response
 */
function ai_gemini_handle_get_packages($request) {
    $packages = ai_gemini_get_credit_packages();
    
    return rest_ensure_response([
        'packages' => $packages,
    ]);
}

/**
 * Get available credit packages
 * 
 * @return array Array of credit packages
 */
function ai_gemini_get_credit_packages() {
    return apply_filters('ai_gemini_credit_packages', [
        [
            'id' => 'basic',
            'name' => __('Basic', 'ai-gemini-image'),
            'credits' => 10,
            'price' => 20000,
            'price_formatted' => '20,000đ',
            'popular' => false,
        ],
        [
            'id' => 'standard',
            'name' => __('Standard', 'ai-gemini-image'),
            'credits' => 30,
            'price' => 50000,
            'price_formatted' => '50,000đ',
            'popular' => true,
        ],
        [
            'id' => 'premium',
            'name' => __('Premium', 'ai-gemini-image'),
            'credits' => 100,
            'price' => 150000,
            'price_formatted' => '150,000đ',
            'popular' => false,
        ],
        [
            'id' => 'pro',
            'name' => __('Pro', 'ai-gemini-image'),
            'credits' => 250,
            'price' => 300000,
            'price_formatted' => '300,000đ',
            'popular' => false,
        ],
    ]);
}

/**
 * Handle create order request
 * 
 * @param WP_REST_Request $request Request object
 * @return WP_REST_Response|WP_Error Response or error
 */
function ai_gemini_handle_create_order($request) {
    global $wpdb;
    
    $package_id = sanitize_text_field($request->get_param('package_id'));
    
    if (empty($package_id)) {
        return new WP_Error(
            'missing_package',
            __('Please select a package', 'ai-gemini-image'),
            ['status' => 400]
        );
    }
    
    // Find package
    $packages = ai_gemini_get_credit_packages();
    $selected_package = null;
    
    foreach ($packages as $package) {
        if ($package['id'] === $package_id) {
            $selected_package = $package;
            break;
        }
    }
    
    if (!$selected_package) {
        return new WP_Error(
            'invalid_package',
            __('Invalid package selected', 'ai-gemini-image'),
            ['status' => 400]
        );
    }
    
    $user_id = get_current_user_id();
    $ip = ai_gemini_get_client_ip();
    
    // Generate unique order code
    $order_code = ai_gemini_generate_order_code();
    
    // Create order
    $table_orders = $wpdb->prefix . 'ai_gemini_orders';
    $inserted = $wpdb->insert(
        $table_orders,
        [
            'user_id' => $user_id ?: null,
            'guest_ip' => $user_id ? null : $ip,
            'order_code' => $order_code,
            'amount' => $selected_package['price'],
            'credits' => $selected_package['credits'],
            'status' => 'pending',
            'payment_method' => 'vietqr',
            'created_at' => current_time('mysql'),
        ],
        ['%d', '%s', '%s', '%d', '%d', '%s', '%s', '%s']
    );
    
    if (!$inserted) {
        return new WP_Error(
            'order_failed',
            __('Failed to create order', 'ai-gemini-image'),
            ['status' => 500]
        );
    }
    
    $order_id = $wpdb->insert_id;
    
    // Generate VietQR payment URL
    $vietqr_url = ai_gemini_generate_vietqr_url($order_code, $selected_package['price']);
    
    return rest_ensure_response([
        'success' => true,
        'order_id' => $order_id,
        'order_code' => $order_code,
        'package' => $selected_package,
        'payment_url' => $vietqr_url,
        'message' => __('Order created successfully', 'ai-gemini-image'),
    ]);
}

/**
 * Handle check order status request
 * 
 * @param WP_REST_Request $request Request object
 * @return WP_REST_Response|WP_Error Response or error
 */
function ai_gemini_handle_check_order($request) {
    global $wpdb;
    
    $order_code = sanitize_text_field($request->get_param('order_code'));
    
    $table_orders = $wpdb->prefix . 'ai_gemini_orders';
    $order = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $table_orders WHERE order_code = %s",
        $order_code
    ));
    
    if (!$order) {
        return new WP_Error(
            'order_not_found',
            __('Order not found', 'ai-gemini-image'),
            ['status' => 404]
        );
    }
    
    return rest_ensure_response([
        'order_code' => $order->order_code,
        'status' => $order->status,
        'amount' => (int) $order->amount,
        'credits' => (int) $order->credits,
        'created_at' => $order->created_at,
        'completed_at' => $order->completed_at,
    ]);
}

/**
 * Generate unique order code
 * 
 * @return string Order code
 */
function ai_gemini_generate_order_code() {
    return 'AG' . strtoupper(substr(md5(uniqid(wp_rand(), true)), 0, 8));
}

/**
 * Complete an order and add credits
 * 
 * @param string $order_code Order code
 * @param string $transaction_id Optional transaction ID
 * @return bool Success status
 */
function ai_gemini_complete_order($order_code, $transaction_id = '') {
    global $wpdb;
    
    $table_orders = $wpdb->prefix . 'ai_gemini_orders';
    
    // Get order
    $order = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $table_orders WHERE order_code = %s AND status = 'pending'",
        $order_code
    ));
    
    if (!$order) {
        return false;
    }
    
    // Update order status
    $updated = $wpdb->update(
        $table_orders,
        [
            'status' => 'completed',
            'transaction_id' => $transaction_id,
            'completed_at' => current_time('mysql'),
        ],
        ['order_code' => $order_code],
        ['%s', '%s', '%s'],
        ['%s']
    );
    
    if (!$updated) {
        return false;
    }
    
    // Add credits to user
    ai_gemini_update_credit($order->credits, $order->user_id ?: null);
    
    // Log transaction
    ai_gemini_log_transaction([
        'user_id' => $order->user_id,
        'guest_ip' => $order->guest_ip,
        'type' => 'credit_purchase',
        'amount' => $order->credits,
        'description' => sprintf(__('Purchased %d credits - Order %s', 'ai-gemini-image'), $order->credits, $order_code),
        'reference_id' => $order->id,
    ]);
    
    ai_gemini_log("Order {$order_code} completed, added {$order->credits} credits", 'info');
    
    return true;
}
