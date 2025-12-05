<?php
/**
 * AI Gemini Image Generator - Credit Tables
 * 
 * Additional table management for credit system.
 * Note: Main table creation is in inc/db/install.php
 */

if (!defined('ABSPATH')) exit;

/**
 * Check if credit tables exist
 * 
 * @return bool True if tables exist
 */
function ai_gemini_credit_tables_exist() {
    global $wpdb;
    
    $tables = [
        $wpdb->prefix . 'ai_gemini_guest_credits',
        $wpdb->prefix . 'ai_gemini_orders',
        $wpdb->prefix . 'ai_gemini_transactions',
    ];
    
    foreach ($tables as $table) {
        $table_exists = $wpdb->get_var($wpdb->prepare(
            "SHOW TABLES LIKE %s",
            $table
        ));
        
        if (!$table_exists) {
            return false;
        }
    }
    
    return true;
}

/**
 * Get table status for debugging
 * 
 * @return array Table information
 */
function ai_gemini_get_table_info() {
    global $wpdb;
    
    $tables = [
        'guest_credits' => $wpdb->prefix . 'ai_gemini_guest_credits',
        'orders' => $wpdb->prefix . 'ai_gemini_orders',
        'images' => $wpdb->prefix . 'ai_gemini_images',
        'transactions' => $wpdb->prefix . 'ai_gemini_transactions',
    ];
    
    $info = [];
    
    foreach ($tables as $key => $table) {
        $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
        $count = $exists ? $wpdb->get_var("SELECT COUNT(*) FROM $table") : 0;
        
        $info[$key] = [
            'table' => $table,
            'exists' => (bool) $exists,
            'count' => (int) $count,
        ];
    }
    
    return $info;
}

/**
 * Migrate user credits from old format if needed
 * 
 * @return int Number of users migrated
 */
function ai_gemini_migrate_credits() {
    // This function can be used if you need to migrate from an old credit system
    // Currently a placeholder for future use
    return 0;
}

/**
 * Export credit data for a user
 * 
 * @param int $user_id User ID
 * @return array User credit data
 */
function ai_gemini_export_user_credit_data($user_id) {
    global $wpdb;
    
    $table_orders = $wpdb->prefix . 'ai_gemini_orders';
    $table_transactions = $wpdb->prefix . 'ai_gemini_transactions';
    $table_images = $wpdb->prefix . 'ai_gemini_images';
    
    return [
        'credits' => ai_gemini_get_credit($user_id),
        'orders' => $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_orders WHERE user_id = %d",
            $user_id
        )),
        'transactions' => $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_transactions WHERE user_id = %d",
            $user_id
        )),
        'images' => $wpdb->get_results($wpdb->prepare(
            "SELECT id, prompt, style, is_unlocked, credits_used, created_at FROM $table_images WHERE user_id = %d",
            $user_id
        )),
    ];
}

/**
 * Delete all user credit data (for GDPR compliance)
 * 
 * @param int $user_id User ID
 * @return bool Success status
 */
function ai_gemini_delete_user_data($user_id) {
    global $wpdb;
    
    $table_orders = $wpdb->prefix . 'ai_gemini_orders';
    $table_transactions = $wpdb->prefix . 'ai_gemini_transactions';
    $table_images = $wpdb->prefix . 'ai_gemini_images';
    
    // Delete user's images files
    $images = $wpdb->get_results($wpdb->prepare(
        "SELECT preview_image_url, full_image_url FROM $table_images WHERE user_id = %d",
        $user_id
    ));
    
    $upload_dir = ai_gemini_get_upload_dir();
    foreach ($images as $image) {
        if ($image->preview_image_url) {
            $path = str_replace($upload_dir['url'], $upload_dir['path'], $image->preview_image_url);
            if (file_exists($path)) {
                wp_delete_file($path);
            }
        }
        if ($image->full_image_url) {
            $path = str_replace($upload_dir['url'], $upload_dir['path'], $image->full_image_url);
            if (file_exists($path)) {
                wp_delete_file($path);
            }
        }
    }
    
    // Delete database records
    $wpdb->delete($table_images, ['user_id' => $user_id], ['%d']);
    $wpdb->delete($table_transactions, ['user_id' => $user_id], ['%d']);
    $wpdb->delete($table_orders, ['user_id' => $user_id], ['%d']);
    
    // Delete user meta
    delete_user_meta($user_id, 'ai_gemini_credits');
    delete_user_meta($user_id, 'ai_gemini_used_trial');
    
    return true;
}

// Hook into WordPress user deletion
add_action('delete_user', 'ai_gemini_delete_user_data');
