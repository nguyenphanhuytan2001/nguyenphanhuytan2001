<?php
/**
 * AI Gemini Image Generator - Credit AJAX Handlers
 * 
 * AJAX handlers for credit operations.
 */

if (!defined('ABSPATH')) exit;

/**
 * Register AJAX actions
 */
add_action('wp_ajax_ai_gemini_get_credit', 'ai_gemini_ajax_get_credit');
add_action('wp_ajax_nopriv_ai_gemini_get_credit', 'ai_gemini_ajax_get_credit');

add_action('wp_ajax_ai_gemini_check_order', 'ai_gemini_ajax_check_order');
add_action('wp_ajax_nopriv_ai_gemini_check_order', 'ai_gemini_ajax_check_order');

/**
 * AJAX handler: Get current credit balance
 */
function ai_gemini_ajax_get_credit() {
    $user_id = get_current_user_id();
    
    $credits = ai_gemini_get_credit($user_id ?: null);
    $has_trial = !ai_gemini_has_used_trial($user_id ?: null);
    
    wp_send_json_success([
        'credits' => $credits,
        'has_free_trial' => $has_trial,
        'formatted' => ai_gemini_format_credits($credits),
    ]);
}

/**
 * AJAX handler: Check order status
 */
function ai_gemini_ajax_check_order() {
    $order_code = isset($_POST['order_code']) ? sanitize_text_field(wp_unslash($_POST['order_code'])) : '';
    
    if (empty($order_code)) {
        wp_send_json_error(['message' => __('Order code is required', 'ai-gemini-image')]);
    }
    
    global $wpdb;
    $table_orders = $wpdb->prefix . 'ai_gemini_orders';
    
    $order = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $table_orders WHERE order_code = %s",
        $order_code
    ));
    
    if (!$order) {
        wp_send_json_error(['message' => __('Order not found', 'ai-gemini-image')]);
    }
    
    wp_send_json_success([
        'status' => $order->status,
        'credits' => (int) $order->credits,
        'amount' => (int) $order->amount,
    ]);
}

/**
 * Register additional AJAX actions for admin
 */
add_action('wp_ajax_ai_gemini_admin_add_credit', 'ai_gemini_ajax_admin_add_credit');
add_action('wp_ajax_ai_gemini_admin_get_stats', 'ai_gemini_ajax_admin_get_stats');

/**
 * AJAX handler: Admin add credits to user
 */
function ai_gemini_ajax_admin_add_credit() {
    // Check nonce and permissions
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => __('Permission denied', 'ai-gemini-image')]);
    }
    
    check_ajax_referer('ai_gemini_admin_nonce', 'nonce');
    
    $user_id = isset($_POST['user_id']) ? absint($_POST['user_id']) : 0;
    $amount = isset($_POST['amount']) ? intval($_POST['amount']) : 0;
    
    if (!$user_id || $amount === 0) {
        wp_send_json_error(['message' => __('Invalid parameters', 'ai-gemini-image')]);
    }
    
    // Update credits
    ai_gemini_update_credit($amount, $user_id);
    
    // Log transaction
    ai_gemini_log_transaction([
        'user_id' => $user_id,
        'type' => 'admin_adjustment',
        'amount' => $amount,
        'description' => $amount > 0 
            ? sprintf(__('Admin added %d credits', 'ai-gemini-image'), $amount)
            : sprintf(__('Admin removed %d credits', 'ai-gemini-image'), abs($amount)),
    ]);
    
    $new_balance = ai_gemini_get_credit($user_id);
    
    wp_send_json_success([
        'message' => __('Credits updated successfully', 'ai-gemini-image'),
        'new_balance' => $new_balance,
        'formatted' => ai_gemini_format_credits($new_balance),
    ]);
}

/**
 * AJAX handler: Get admin statistics
 */
function ai_gemini_ajax_admin_get_stats() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => __('Permission denied', 'ai-gemini-image')]);
    }
    
    global $wpdb;
    
    $table_orders = $wpdb->prefix . 'ai_gemini_orders';
    $table_images = $wpdb->prefix . 'ai_gemini_images';
    
    $stats = [
        'total_images' => (int) $wpdb->get_var("SELECT COUNT(*) FROM $table_images"),
        'images_today' => (int) $wpdb->get_var("SELECT COUNT(*) FROM $table_images WHERE DATE(created_at) = CURDATE()"),
        'unlocked_images' => (int) $wpdb->get_var("SELECT COUNT(*) FROM $table_images WHERE is_unlocked = 1"),
        'pending_orders' => (int) $wpdb->get_var("SELECT COUNT(*) FROM $table_orders WHERE status = 'pending'"),
        'completed_orders' => (int) $wpdb->get_var("SELECT COUNT(*) FROM $table_orders WHERE status = 'completed'"),
        'revenue_today' => (int) $wpdb->get_var("SELECT SUM(amount) FROM $table_orders WHERE status = 'completed' AND DATE(completed_at) = CURDATE()"),
        'revenue_total' => (int) $wpdb->get_var("SELECT SUM(amount) FROM $table_orders WHERE status = 'completed'"),
    ];
    
    wp_send_json_success($stats);
}

/**
 * Enqueue scripts for credit AJAX
 */
function ai_gemini_enqueue_credit_ajax_scripts() {
    wp_localize_script('ai-gemini-generator', 'AIGeminiAjax', [
        'ajaxurl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('ai_gemini_nonce'),
    ]);
}
add_action('wp_enqueue_scripts', 'ai_gemini_enqueue_credit_ajax_scripts', 20);
