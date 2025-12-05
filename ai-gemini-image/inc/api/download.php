<?php
/**
 * AI Gemini Image Generator - Download API
 *
 * Trả ảnh gốc từ thư mục originals sau khi kiểm tra:
 * - image_id hợp lệ
 * - nonce token
 * - user/IP sở hữu
 * - is_unlocked = 1
 */

if (!defined('ABSPATH')) exit;

/**
 * Register download API endpoint
 */
function ai_gemini_register_download_api() {
    register_rest_route('ai/v1', '/download', [
        'methods'             => 'GET',
        'callback'            => 'ai_gemini_handle_download_request',
        'permission_callback' => '__return_true',
    ]);
}
add_action('rest_api_init', 'ai_gemini_register_download_api');

/**
 * Handle download request
 */
function ai_gemini_handle_download_request($request) {
    global $wpdb;

    $image_id = (int) $request->get_param('image_id');
    $token    = $request->get_param('token');
    $user_id  = get_current_user_id();

    if (empty($image_id) || empty($token)) {
        return new WP_Error(
            'missing_params',
            'Thiếu thông tin tải ảnh.',
            ['status' => 400]
        );
    }

    if (!wp_verify_nonce($token, 'ai_gemini_download_' . $image_id)) {
        return new WP_Error(
            'invalid_token',
            'Token tải ảnh không hợp lệ hoặc đã hết hạn.',
            ['status' => 403]
        );
    }

    $table_images = $wpdb->prefix . 'ai_gemini_images';
    $image = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $table_images WHERE id = %d",
        $image_id
    ));

    if (!$image) {
        return new WP_Error(
            'image_not_found',
            'Không tìm thấy hình ảnh.',
            ['status' => 404]
        );
    }

    // Kiểm tra ownership
    if ($image->user_id) {
        if ($user_id != $image->user_id) {
            return new WP_Error(
                'unauthorized',
                'Bạn không có quyền tải ảnh này.',
                ['status' => 403]
            );
        }
    } else {
        $ip = ai_gemini_get_client_ip();
        if ($image->guest_ip !== $ip) {
            return new WP_Error(
                'unauthorized',
                'Bạn không có quyền tải ảnh này (Sai IP).',
                ['status' => 403]
            );
        }
    }

    // Chỉ cho tải nếu đã unlock
    if (!$image->is_unlocked) {
        return new WP_Error(
            'not_unlocked',
            'Ảnh chưa được mở khóa.',
            ['status' => 403]
        );
    }

    if (!function_exists('ai_gemini_get_original_path')) {
        return new WP_Error(
            'missing_helper',
            'Không tìm thấy hàm lấy đường dẫn ảnh gốc.',
            ['status' => 500]
        );
    }

    $original_path = ai_gemini_get_original_path($image);
    if (!$original_path || !file_exists($original_path)) {
        return new WP_Error(
            'file_not_found',
            'Không tìm thấy file ảnh gốc trên server.',
            ['status' => 500]
        );
    }

    ai_gemini_log('Download original for image_id=' . $image_id . ' path=' . $original_path, 'info');

    $mime_type = function_exists('mime_content_type') ? mime_content_type($original_path) : 'image/jpeg';
    $filename  = basename($original_path);

    header('Content-Type: ' . $mime_type);
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($original_path));
    header('Cache-Control: private, max-age=0, no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    readfile($original_path);
    exit;
}