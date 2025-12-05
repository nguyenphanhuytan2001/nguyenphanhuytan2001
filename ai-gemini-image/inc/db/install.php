<?php
/**
 * AI Gemini Image Generator - DB Install
 *
 * Create or update database tables on plugin activation.
 */

if (!defined('ABSPATH')) exit;

function ai_gemini_install_tables() {
    global $wpdb;

    $charset_collate = $wpdb->get_charset_collate();
    $prefix          = $wpdb->prefix;

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    // Guest credits table
    $table_guest_credits = $prefix . 'ai_gemini_guest_credits';
    $sql_guest_credits   = "CREATE TABLE {$table_guest_credits} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        ip VARCHAR(64) NOT NULL,
        credits INT NOT NULL DEFAULT 0,
        used_trial TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        UNIQUE KEY ip (ip)
    ) {$charset_collate};";

    // Orders table
    $table_orders = $prefix . 'ai_gemini_orders';
    $sql_orders   = "CREATE TABLE {$table_orders} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT UNSIGNED NULL,
        guest_ip VARCHAR(64) NULL,
        order_code VARCHAR(64) NOT NULL,
        amount DECIMAL(18,2) NOT NULL DEFAULT 0,
        credits INT NOT NULL DEFAULT 0,
        status VARCHAR(32) NOT NULL DEFAULT 'pending',
        payment_method VARCHAR(50) NULL,
        transaction_id VARCHAR(128) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        completed_at DATETIME NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY order_code (order_code),
        KEY user_id (user_id),
        KEY guest_ip (guest_ip)
    ) {$charset_collate};";

    // Images table
    $table_images = $prefix . 'ai_gemini_images';
    $sql_images   = "CREATE TABLE {$table_images} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT UNSIGNED NULL,
        guest_ip VARCHAR(64) NULL,
        original_image_url TEXT NULL,
        preview_image_url TEXT NULL,
        full_image_url TEXT NULL,
        prompt LONGTEXT NULL,
        style VARCHAR(100) NULL,
        is_unlocked TINYINT(1) NOT NULL DEFAULT 0,
        credits_used INT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        expires_at DATETIME NULL,
        -- NEW: store Gemini Files API metadata for reuse
        gemini_file_uri VARCHAR(255) NULL,
        gemini_mime_type VARCHAR(100) NULL,
        PRIMARY KEY  (id),
        KEY user_id (user_id),
        KEY guest_ip (guest_ip),
        KEY style (style),
        KEY is_unlocked (is_unlocked)
    ) {$charset_collate};";

    // Transactions table
    $table_transactions = $prefix . 'ai_gemini_transactions';
    $sql_transactions   = "CREATE TABLE {$table_transactions} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT UNSIGNED NULL,
        guest_ip VARCHAR(64) NULL,
        type VARCHAR(50) NOT NULL,
        amount INT NOT NULL DEFAULT 0,
        balance_after INT NOT NULL DEFAULT 0,
        description TEXT NULL,
        reference_id BIGINT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY user_id (user_id),
        KEY guest_ip (guest_ip),
        KEY type (type)
    ) {$charset_collate};";

    // Prompts table (if bạn đã có)
    $table_prompts = $prefix . 'ai_gemini_prompts';
    $sql_prompts   = "CREATE TABLE {$table_prompts} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        slug VARCHAR(191) NOT NULL,
        title VARCHAR(255) NOT NULL,
        prompt_text LONGTEXT NOT NULL,
        sample_image TEXT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY slug (slug),
        KEY is_active (is_active)
    ) {$charset_collate};";

    // Run dbDelta
    dbDelta($sql_guest_credits);
    dbDelta($sql_orders);
    dbDelta($sql_images);
    dbDelta($sql_transactions);
    dbDelta($sql_prompts);

    // SAFE MIGRATION: ensure new columns exist even on older installs
    ai_gemini_maybe_add_files_api_columns();
}

/**
 * Ensure Files API columns exist on ai_gemini_images table.
 * This is safe to run multiple times.
 */
function ai_gemini_maybe_add_files_api_columns() {
    global $wpdb;
    $table_images = $wpdb->prefix . 'ai_gemini_images';

    // Check gemini_file_uri
    $has_gemini_file_uri = $wpdb->get_results(
        $wpdb->prepare(
            "SHOW COLUMNS FROM {$table_images} LIKE %s",
            'gemini_file_uri'
        )
    );
    if (empty($has_gemini_file_uri)) {
        $wpdb->query("ALTER TABLE {$table_images} ADD COLUMN gemini_file_uri VARCHAR(255) NULL AFTER expires_at");
    }

    // Check gemini_mime_type
    $has_gemini_mime_type = $wpdb->get_results(
        $wpdb->prepare(
            "SHOW COLUMNS FROM {$table_images} LIKE %s",
            'gemini_mime_type'
        )
    );
    if (empty($has_gemini_mime_type)) {
        $wpdb->query("ALTER TABLE {$table_images} ADD COLUMN gemini_mime_type VARCHAR(100) NULL AFTER gemini_file_uri");
    }
}