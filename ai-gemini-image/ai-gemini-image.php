<?php
/**
 * Plugin Name: AI Gemini Image Generator
 * Description: Plugin tạo hình ảnh bằng Google Gemini 2.5 Flash Image với hệ thống credit và nhiệm vụ
 * Version: 1.0.1
 * Author: Your Name
 * Text Domain: ai-gemini-image
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) exit;

// Define plugin constants
define('AI_GEMINI_VERSION', '1.0.1');
define('AI_GEMINI_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('AI_GEMINI_PLUGIN_URL', plugin_dir_url(__FILE__));

/**
 * URL time server chuẩn dùng cho TOTP
 * Bạn đã triển khai endpoint này trên VPS:
 *   https://khungtranhtreotuong.com/api-time.php
 * Trả về JSON: {"timestamp":1764645274,"datetime":"2025-12-02T03:14:34+00:00"}
 */
if (!defined('AI_GEMINI_TIME_SERVER_URL')) {
    define('AI_GEMINI_TIME_SERVER_URL', 'https://khungtranhtreotuong.com/api-time.php');
}

// Autoload files in organized order
$ai_gemini_includes = [
    // Database
    'inc/db/install.php',
    'inc/db/cleanup.php',
    
    // Admin
    'inc/admin/menu.php',
    'inc/admin/credit-manager.php',
    'inc/admin/prompt-manager.php',
    'inc/admin/mission-manager.php', // <--- FILE MỚI: Quản lý nhiệm vụ
    
    // API
    'inc/api/class-gemini-api.php',
    'inc/api/preview.php',
    'inc/api/unlock.php',
    'inc/api/credit.php',
    'inc/api/mission.php', // <--- FILE MỚI: API nhiệm vụ
	'inc/api/download.php',
    
    // Credit System
    'inc/credit/functions.php',
    'inc/credit/tables.php',
    'inc/credit/ajax.php',
    
    // Payment
    'inc/payment/vietqr-config.php',
    'inc/payment/vietqr-payment.php',
    'inc/payment/vietqr-checker.php',
    
    // Frontend
    'inc/frontend/shortcode-generator.php',
    'inc/frontend/shortcode-dashboard.php',
    'inc/frontend/shortcode-credit.php',
    
    // Utilities
    'inc/watermark.php',
    'inc/helpers.php',
];

foreach ($ai_gemini_includes as $file) {
    $filepath = AI_GEMINI_PLUGIN_DIR . $file;
    if (file_exists($filepath)) {
        require_once $filepath;
    }
}

// Activation hook
register_activation_hook(__FILE__, 'ai_gemini_install_tables');

// Deactivation hook
register_deactivation_hook(__FILE__, 'ai_gemini_cleanup_on_deactivate');