<?php
/**
 * AI Gemini Image Generator - Helper Functions
 * 
 * Common utility functions used throughout the plugin.
 */

if (!defined('ABSPATH')) exit;

/**
 * ============================================================================
 *  ĐỒNG BỘ THỜI GIAN VỚI TIME SERVER T (CHO TOTP)
 * ============================================================================
 */

/**
 * Cập nhật offset thời gian giữa server A (chạy plugin) và time server T.
 *
 * offset = timestamp_T - time()
 * Lưu vào option 'ai_gemini_time_offset' (int, giây).
 *
 * Endpoint time server trả JSON dạng:
 *  {
 *      "timestamp": 1764645274,
 *      "datetime": "2025-12-02T03:14:34+00:00"
 *  }
 */
function ai_gemini_update_time_offset() {
    if (!defined('AI_GEMINI_TIME_SERVER_URL') || empty(AI_GEMINI_TIME_SERVER_URL)) {
        return;
    }

    $response = wp_remote_get(AI_GEMINI_TIME_SERVER_URL, [
        'timeout' => 5,
    ]);

    if (is_wp_error($response)) {
        // Nếu muốn debug, mở comment dòng dưới:
        // ai_gemini_log('Time server request failed: ' . $response->get_error_message(), 'error');
        return;
    }

    $code = wp_remote_retrieve_response_code($response);
    if ($code !== 200) {
        // ai_gemini_log('Time server HTTP code: ' . $code, 'error');
        return;
    }

    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    if (!is_array($data) || !isset($data['timestamp'])) {
        // ai_gemini_log('Invalid time server response: ' . $body, 'error');
        return;
    }

    $timestamp_T = (int) $data['timestamp'];
    $time_A      = time();

    $offset = $timestamp_T - $time_A;

    update_option('ai_gemini_time_offset', $offset, false);
}

/**
 * Lấy thời gian chuẩn (UNIX timestamp, giây) đã hiệu chỉnh theo time server.
 *
 * Nếu chưa từng sync hoặc option chưa được set, hàm sẽ trả về time() bình thường.
 *
 * @return int
 */
function ai_gemini_get_central_time() {
    $offset = (int) get_option('ai_gemini_time_offset', 0);
    return time() + $offset;
}

/**
 * Mỗi khi có request vào site, nếu lần sync trước đã quá 12h thì sync lại.
 *
 * Không cần tạo cron riêng, chỉ cần site có traffic.
 */
function ai_gemini_maybe_sync_time_offset() {
    $last_sync = (int) get_option('ai_gemini_time_last_sync', 0);
    $now       = time();

    // 12 giờ
    $interval = 12 * HOUR_IN_SECONDS;

    if (($now - $last_sync) < $interval) {
        return;
    }

    ai_gemini_update_time_offset();
    update_option('ai_gemini_time_last_sync', $now, false);
}
add_action('init', 'ai_gemini_maybe_sync_time_offset');

/**
 * ============================================================================
 *  CÁC HÀM HELPERS GỐC
 * ============================================================================
 */

/**
 * Get user or guest credit balance
 * 
 * @param int|null $user_id User ID or null for guest
 * @return int Credit balance
 */
function ai_gemini_get_credit($user_id = null) {
    if ($user_id) {
        return (int)get_user_meta($user_id, 'ai_gemini_credits', true);
    } else {
        // Guest credit by IP
        global $wpdb;
        $ip = ai_gemini_get_client_ip();
        $table_name = $wpdb->prefix . 'ai_gemini_guest_credits';
        
        $guest = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE ip=%s", 
            $ip
        ));
        return $guest ? (int)$guest->credits : 0;
    }
}

/**
 * Get client IP address with proxy support
 * 
 * @return string Client IP address
 */
function ai_gemini_get_client_ip() {
    $ip = '';
    
    // Check for common proxy headers (in order of preference)
    $headers = [
        'HTTP_CF_CONNECTING_IP',     // Cloudflare
        'HTTP_X_REAL_IP',            // Nginx proxy
        'HTTP_X_FORWARDED_FOR',      // Standard proxy header
        'REMOTE_ADDR',               // Direct connection
    ];
    
    foreach ($headers as $header) {
        if (!empty($_SERVER[$header])) {
            $ip_list = sanitize_text_field(wp_unslash($_SERVER[$header]));
            // X-Forwarded-For may contain multiple IPs, get the first one
            $ips = explode(',', $ip_list);
            $ip = trim($ips[0]);
            
            // Validate IP format
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                break;
            }
            $ip = '';
        }
    }
    
    // Fallback to empty string if no valid IP found
    return $ip;
}

/**
 * Update user or guest credit balance
 * 
 * @param int $amount Amount to add (can be negative)
 * @param int|null $user_id User ID or null for guest
 * @return bool Success status
 */
function ai_gemini_update_credit($amount, $user_id = null) {
    if ($user_id) {
        $current = ai_gemini_get_credit($user_id);
        $new_balance = max(0, $current + $amount);
        return update_user_meta($user_id, 'ai_gemini_credits', $new_balance);
    } else {
        global $wpdb;
        $ip = ai_gemini_get_client_ip();
        $table_name = $wpdb->prefix . 'ai_gemini_guest_credits';
        
        $current = ai_gemini_get_credit(null);
        $new_balance = max(0, $current + $amount);
        
        return $wpdb->replace(
            $table_name,
            [
                'ip' => $ip,
                'credits' => $new_balance,
                'updated_at' => current_time('mysql')
            ],
            ['%s', '%d', '%s']
        );
    }
}

/**
 * Check if user/guest has used free trial
 * 
 * @param int|null $user_id User ID or null for guest
 * @return bool True if trial has been used
 */
function ai_gemini_has_used_trial($user_id = null) {
    global $wpdb;
    
    if ($user_id) {
        return get_user_meta($user_id, 'ai_gemini_used_trial', true) == '1';
    } else {
        $ip = ai_gemini_get_client_ip();
        $table_name = $wpdb->prefix . 'ai_gemini_guest_credits';
        
        $guest = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE ip=%s", 
            $ip
        ));
        return $guest && $guest->used_trial == 1;
    }
}

/**
 * Mark trial as used for user/guest
 * 
 * @param int|null $user_id User ID or null for guest
 * @return bool Success status
 */
function ai_gemini_mark_trial_used($user_id = null) {
    if ($user_id) {
        return update_user_meta($user_id, 'ai_gemini_used_trial', '1');
    } else {
        global $wpdb;
        $ip = ai_gemini_get_client_ip();
        $table_name = $wpdb->prefix . 'ai_gemini_guest_credits';
        
        return $wpdb->update(
            $table_name,
            ['used_trial' => 1],
            ['ip' => $ip],
            ['%d'],
            ['%s']
        );
    }
}

/**
 * Log error for debugging
 * 
 * @param string $message Log message
 * @param string $type Log type (info, error, warning)
 */
function ai_gemini_log($message, $type = 'info') {
    if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
        error_log("[AI Gemini {$type}] " . $message);
    }
}

/**
 * Get Gemini API key from options
 * 
 * @return string|false API key or false if not set
 */
function ai_gemini_get_api_key() {
    return get_option('ai_gemini_api_key', false);
}

/**
 * Check if current user can use the generator
 * 
 * @return bool True if user has permission
 */
function ai_gemini_user_can_generate() {
    // Allow logged in users or guests with credits
    $user_id = get_current_user_id();
    $credits = ai_gemini_get_credit($user_id ?: null);
    
    return $credits > 0 || !ai_gemini_has_used_trial($user_id ?: null);
}

/**
 * Format credit amount for display
 * 
 * @param int $amount Credit amount
 * @return string Formatted credit string
 */
function ai_gemini_format_credits($amount) {
    return number_format_i18n($amount) . ' ' . _n('credit', 'credits', $amount, 'ai-gemini-image');
}

/**
 * Get upload directory for generated images
 * 
 * @return array Upload directory info
 */
function ai_gemini_get_upload_dir() {
    $upload_dir = wp_upload_dir();
    $gemini_dir = $upload_dir['basedir'] . '/ai-gemini-images';
    $gemini_url = $upload_dir['baseurl'] . '/ai-gemini-images';
    
    // Create directory if it doesn't exist
    if (!file_exists($gemini_dir)) {
        wp_mkdir_p($gemini_dir);
    }
    
    return [
        'path' => $gemini_dir,
        'url' => $gemini_url,
    ];
}

/**
 * Generate a unique filename for image
 * 
 * @param string $prefix Filename prefix
 * @param string $extension File extension
 * @return string Unique filename
 */
function ai_gemini_generate_filename($prefix = 'gemini', $extension = 'png') {
    return $prefix . '-' . wp_generate_uuid4() . '.' . $extension;
}

/**
 * Sanitize and validate image data
 * 
 * @param string $image_data Base64 encoded image data
 * @return string|false Cleaned image data or false if invalid
 */
function ai_gemini_validate_image_data($image_data) {
    // Remove data URI prefix if present
    if (strpos($image_data, 'data:image/') === 0) {
        $image_data = preg_replace('/^data:image\/\w+;base64,/', '', $image_data);
    }
    
    // Validate base64
    $decoded = base64_decode($image_data, true);
    if ($decoded === false) {
        return false;
    }
    
    // Check if it's a valid image
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->buffer($decoded);
    
    $allowed_mimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    if (!in_array($mime, $allowed_mimes)) {
        return false;
    }
    
    return $image_data;
}

/**
 * --- NEW FUNCTIONS FOR PROMPTS ---
 */

/**
 * Lấy danh sách tất cả prompts đang active
 */
function ai_gemini_get_active_prompts() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'ai_gemini_prompts';
    // Check if table exists before query to avoid errors on fresh install before dbDelta runs
    if($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
        return [];
    }
    return $wpdb->get_results("SELECT * FROM $table_name WHERE is_active = 1 ORDER BY title ASC");
}

/**
 * Lấy prompt cụ thể theo slug hoặc ID
 */
function ai_gemini_get_prompt_by_key($key) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'ai_gemini_prompts';
    
    if (is_numeric($key)) {
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $key));
    } else {
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE slug = %s", $key));
    }
}