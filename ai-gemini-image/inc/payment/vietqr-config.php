<?php
/**
 * AI Gemini Image Generator - VietQR Configuration
 * 
 * Configuration for VietQR payment integration.
 */

if (!defined('ABSPATH')) exit;

/**
 * Get VietQR configuration
 * 
 * @return array VietQR configuration
 */
function ai_gemini_get_vietqr_config() {
    return apply_filters('ai_gemini_vietqr_config', [
        // Bank information
        'bank_id' => get_option('ai_gemini_vietqr_bank_id', 'MB'), // MB, VCB, TCB, BIDV, ACB, etc.
        'account_no' => get_option('ai_gemini_vietqr_account_no', ''),
        'account_name' => get_option('ai_gemini_vietqr_account_name', ''),
        
        // QR settings
        'template' => 'compact2', // compact, compact2, qr_only, print
        
        // API settings (if using API verification)
        'api_key' => get_option('ai_gemini_vietqr_api_key', ''),
        'api_secret' => get_option('ai_gemini_vietqr_api_secret', ''),
        
        // Check interval in seconds
        'check_interval' => 10,
        
        // Maximum wait time in seconds
        'max_wait_time' => 600, // 10 minutes
    ]);
}

/**
 * Generate VietQR URL
 * 
 * @param string $order_code Order code for description
 * @param int $amount Amount in VND
 * @return string VietQR URL
 */
function ai_gemini_generate_vietqr_url($order_code, $amount) {
    $config = ai_gemini_get_vietqr_config();
    
    if (empty($config['bank_id']) || empty($config['account_no'])) {
        return '';
    }
    
    // Format description for bank transfer
    $description = 'AIGC ' . $order_code;
    
    // VietQR URL format
    $url = sprintf(
        'https://img.vietqr.io/image/%s-%s-%s.png?amount=%d&addInfo=%s&accountName=%s',
        $config['bank_id'],
        $config['account_no'],
        $config['template'],
        $amount,
        rawurlencode($description),
        rawurlencode($config['account_name'])
    );
    
    return $url;
}

/**
 * Get list of supported banks
 * 
 * @return array Bank list
 */
function ai_gemini_get_supported_banks() {
    return [
        'MB' => 'MB Bank',
        'VCB' => 'Vietcombank',
        'TCB' => 'Techcombank',
        'BIDV' => 'BIDV',
        'ACB' => 'ACB',
        'VPB' => 'VPBank',
        'TPB' => 'TPBank',
        'STB' => 'Sacombank',
        'HDB' => 'HDBank',
        'VIB' => 'VIB',
        'MSB' => 'MSB',
        'SHB' => 'SHB',
        'EIB' => 'Eximbank',
        'OCB' => 'OCB',
        'LPB' => 'LienVietPostBank',
        'NAB' => 'Nam A Bank',
        'CAKE' => 'Cake',
        'MOMO' => 'MoMo',
    ];
}

/**
 * Validate VietQR configuration
 * 
 * @return array Validation result
 */
function ai_gemini_validate_vietqr_config() {
    $config = ai_gemini_get_vietqr_config();
    $errors = [];
    
    if (empty($config['bank_id'])) {
        $errors[] = __('Bank ID is not configured', 'ai-gemini-image');
    }
    
    if (empty($config['account_no'])) {
        $errors[] = __('Account number is not configured', 'ai-gemini-image');
    }
    
    if (empty($config['account_name'])) {
        $errors[] = __('Account name is not configured', 'ai-gemini-image');
    }
    
    return [
        'valid' => empty($errors),
        'errors' => $errors,
    ];
}

/**
 * Add VietQR settings to admin
 */
function ai_gemini_add_vietqr_settings() {
    add_settings_section(
        'ai_gemini_vietqr_section',
        __('VietQR Payment Settings', 'ai-gemini-image'),
        'ai_gemini_vietqr_section_callback',
        'ai-gemini-settings'
    );
    
    register_setting('ai_gemini_settings', 'ai_gemini_vietqr_bank_id');
    register_setting('ai_gemini_settings', 'ai_gemini_vietqr_account_no');
    register_setting('ai_gemini_settings', 'ai_gemini_vietqr_account_name');
}

/**
 * Section callback
 */
function ai_gemini_vietqr_section_callback() {
    echo '<p>' . esc_html__('Configure your bank account for VietQR payments.', 'ai-gemini-image') . '</p>';
}
