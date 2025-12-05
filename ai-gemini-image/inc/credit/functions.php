<?php
/**
 * AI Gemini Image Generator - Credit Functions
 * 
 * Core functions for managing credits.
 */

if (!defined('ABSPATH')) exit;

/**
 * Give free trial credits to new user/guest
 * 
 * @param int|null $user_id User ID or null for guest
 * @return bool Success status
 */
function ai_gemini_give_trial_credits($user_id = null) {
    // Check if already used trial
    if (ai_gemini_has_used_trial($user_id)) {
        return false;
    }
    
    $trial_credits = (int) get_option('ai_gemini_free_trial_credits', 1);
    
    if ($trial_credits <= 0) {
        return false;
    }
    
    // Add credits
    ai_gemini_update_credit($trial_credits, $user_id);
    
    // Mark trial as used
    ai_gemini_mark_trial_used($user_id);
    
    // Log transaction
    ai_gemini_log_transaction([
        'user_id' => $user_id,
        'guest_ip' => $user_id ? null : (ai_gemini_get_client_ip()),
        'type' => 'free_trial',
        'amount' => $trial_credits,
        'description' => __('Free trial credits', 'ai-gemini-image'),
    ]);
    
    return true;
}

/**
 * Check if user can afford an action
 * 
 * @param int $cost Credit cost
 * @param int|null $user_id User ID or null for guest
 * @return bool True if can afford
 */
function ai_gemini_can_afford($cost, $user_id = null) {
    $credits = ai_gemini_get_credit($user_id);
    return $credits >= $cost;
}

/**
 * Deduct credits for an action
 * 
 * @param int $cost Credit cost
 * @param string $reason Reason for deduction
 * @param int|null $user_id User ID or null for guest
 * @param int|null $reference_id Optional reference ID
 * @return bool Success status
 */
function ai_gemini_deduct_credits($cost, $reason, $user_id = null, $reference_id = null) {
    if (!ai_gemini_can_afford($cost, $user_id)) {
        return false;
    }
    
    ai_gemini_update_credit(-$cost, $user_id);
    
    // Log transaction
    ai_gemini_log_transaction([
        'user_id' => $user_id,
        'guest_ip' => $user_id ? null : (ai_gemini_get_client_ip()),
        'type' => 'deduction',
        'amount' => -$cost,
        'description' => $reason,
        'reference_id' => $reference_id,
    ]);
    
    return true;
}

/**
 * Refund credits
 * 
 * @param int $amount Amount to refund
 * @param string $reason Reason for refund
 * @param int|null $user_id User ID or null for guest
 * @param int|null $reference_id Optional reference ID
 * @return bool Success status
 */
function ai_gemini_refund_credits($amount, $reason, $user_id = null, $reference_id = null) {
    ai_gemini_update_credit($amount, $user_id);
    
    // Log transaction
    ai_gemini_log_transaction([
        'user_id' => $user_id,
        'guest_ip' => $user_id ? null : (ai_gemini_get_client_ip()),
        'type' => 'refund',
        'amount' => $amount,
        'description' => $reason,
        'reference_id' => $reference_id,
    ]);
    
    return true;
}

/**
 * Get user credit history
 * 
 * @param int|null $user_id User ID or null for guest
 * @param int $limit Maximum records to return
 * @param int $offset Offset for pagination
 * @return array Credit transactions
 */
function ai_gemini_get_credit_history($user_id = null, $limit = 20, $offset = 0) {
    global $wpdb;
    
    $table_transactions = $wpdb->prefix . 'ai_gemini_transactions';
    
    if ($user_id) {
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_transactions WHERE user_id = %d ORDER BY created_at DESC LIMIT %d OFFSET %d",
            $user_id,
            $limit,
            $offset
        ));
    } else {
        $ip = ai_gemini_get_client_ip();
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_transactions WHERE guest_ip = %s ORDER BY created_at DESC LIMIT %d OFFSET %d",
            $ip,
            $limit,
            $offset
        ));
    }
}

/**
 * Get total credits spent by user
 * 
 * @param int|null $user_id User ID or null for guest
 * @return int Total credits spent
 */
function ai_gemini_get_total_spent($user_id = null) {
    global $wpdb;
    
    $table_transactions = $wpdb->prefix . 'ai_gemini_transactions';
    
    if ($user_id) {
        return abs((int) $wpdb->get_var($wpdb->prepare(
            "SELECT SUM(amount) FROM $table_transactions WHERE user_id = %d AND amount < 0",
            $user_id
        )));
    } else {
        $ip = ai_gemini_get_client_ip();
        return abs((int) $wpdb->get_var($wpdb->prepare(
            "SELECT SUM(amount) FROM $table_transactions WHERE guest_ip = %s AND amount < 0",
            $ip
        )));
    }
}

/**
 * Get total credits purchased by user
 * 
 * @param int|null $user_id User ID or null for guest
 * @return int Total credits purchased
 */
function ai_gemini_get_total_purchased($user_id = null) {
    global $wpdb;
    
    $table_transactions = $wpdb->prefix . 'ai_gemini_transactions';
    
    if ($user_id) {
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT SUM(amount) FROM $table_transactions WHERE user_id = %d AND type = 'credit_purchase'",
            $user_id
        ));
    } else {
        $ip = ai_gemini_get_client_ip();
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT SUM(amount) FROM $table_transactions WHERE guest_ip = %s AND type = 'credit_purchase'",
            $ip
        ));
    }
}
