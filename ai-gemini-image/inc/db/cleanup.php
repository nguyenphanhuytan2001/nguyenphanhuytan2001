<?php
/**
 * AI Gemini Image Generator - Database Cleanup
 * 
 * Handles cleanup of old images and expired data via cron job.
 */

if (!defined('ABSPATH')) exit;

/**
 * Schedule cleanup cron job on plugin activation
 */
function ai_gemini_schedule_cleanup() {
    if (!wp_next_scheduled('ai_gemini_daily_cleanup')) {
        wp_schedule_event(time(), 'daily', 'ai_gemini_daily_cleanup');
    }
}
add_action('init', 'ai_gemini_schedule_cleanup');

/**
 * Unschedule cleanup on deactivation
 */
function ai_gemini_cleanup_on_deactivate() {
    wp_clear_scheduled_hook('ai_gemini_daily_cleanup');
}

/**
 * Daily cleanup task
 * - Remove expired preview images
 * - Clean up old guest credits
 * - Remove stale pending orders
 */
function ai_gemini_run_daily_cleanup() {
    global $wpdb;
    
    $table_images = $wpdb->prefix . 'ai_gemini_images';
    $table_orders = $wpdb->prefix . 'ai_gemini_orders';
    $table_guest_credits = $wpdb->prefix . 'ai_gemini_guest_credits';
    
    // Get upload directory
    $upload_dir = ai_gemini_get_upload_dir();
    
    // Delete expired preview images (older than 24 hours and not unlocked)
    $expired_images = $wpdb->get_results(
        "SELECT id, preview_image_url, full_image_url 
         FROM $table_images 
         WHERE is_unlocked = 0 
         AND expires_at < NOW()"
    );
    
    foreach ($expired_images as $image) {
        // Delete preview file
        if ($image->preview_image_url) {
            $preview_path = str_replace(
                ai_gemini_get_upload_dir()['url'],
                ai_gemini_get_upload_dir()['path'],
                $image->preview_image_url
            );
            if (file_exists($preview_path)) {
                wp_delete_file($preview_path);
            }
        }
        
        // Delete database record
        $wpdb->delete($table_images, ['id' => $image->id], ['%d']);
    }
    
    $deleted_images = count($expired_images);
    
    // Delete pending orders older than 7 days
    $deleted_orders = $wpdb->query(
        "DELETE FROM $table_orders 
         WHERE status = 'pending' 
         AND created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)"
    );
    
    // Clean up guest credits older than 30 days with 0 balance
    $deleted_guests = $wpdb->query(
        "DELETE FROM $table_guest_credits 
         WHERE credits = 0 
         AND updated_at < DATE_SUB(NOW(), INTERVAL 30 DAY)"
    );
    
    ai_gemini_log(
        "Cleanup completed: {$deleted_images} images, {$deleted_orders} orders, {$deleted_guests} guest records removed",
        'info'
    );
}
add_action('ai_gemini_daily_cleanup', 'ai_gemini_run_daily_cleanup');

/**
 * Manual cleanup trigger (admin only)
 */
function ai_gemini_manual_cleanup() {
    if (!current_user_can('manage_options')) {
        return false;
    }
    
    ai_gemini_run_daily_cleanup();
    return true;
}

/**
 * Get cleanup statistics
 * 
 * @return array Cleanup statistics
 */
function ai_gemini_get_cleanup_stats() {
    global $wpdb;
    
    $table_images = $wpdb->prefix . 'ai_gemini_images';
    $table_orders = $wpdb->prefix . 'ai_gemini_orders';
    
    return [
        'expired_images' => (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM $table_images WHERE is_unlocked = 0 AND expires_at < NOW()"
        ),
        'pending_orders' => (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM $table_orders WHERE status = 'pending' AND created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)"
        ),
        'next_cleanup' => wp_next_scheduled('ai_gemini_daily_cleanup'),
    ];
}
