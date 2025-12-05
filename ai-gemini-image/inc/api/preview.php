<?php
/**
 * AI Gemini Image Generator - Preview API
 */

if (!defined('ABSPATH')) exit;

/**
 * Register preview API endpoint
 */
function ai_gemini_register_preview_api() {
    register_rest_route('ai/v1', '/preview', [
        'methods'             => 'POST',
        'callback'            => 'ai_gemini_handle_preview_request',
        'permission_callback' => 'ai_gemini_preview_permission_check',
    ]);
}
add_action('rest_api_init', 'ai_gemini_register_preview_api');

/**
 * Permission check
 */
function ai_gemini_preview_permission_check($request) {
    $user_id      = get_current_user_id();
    $credits      = ai_gemini_get_credit($user_id ?: null);
    $preview_cost = (int) get_option('ai_gemini_preview_credit', 0);
    
    if ($preview_cost > 0 && $credits < $preview_cost) {
        if (ai_gemini_has_used_trial($user_id ?: null)) {
            return new WP_Error(
                'insufficient_credits',
                'Bạn không đủ tín dụng. Vui lòng nạp thêm để tiếp tục.',
                ['status' => 402]
            );
        }
    }
    return true;
}

/**
 * Handle preview generation request
 */
function ai_gemini_handle_preview_request($request) {
    global $wpdb;
    
    $user_id = get_current_user_id();
    $ip      = ai_gemini_get_client_ip();
    
    $image_data        = $request->get_param('image'); // base64
    $image_session_id  = (int) $request->get_param('image_session_id');
    $style_slug        = sanitize_text_field($request->get_param('style') ?: '');
    $user_prompt       = sanitize_textarea_field($request->get_param('prompt') ?: '');
    
    // ====== PROMPT ======
    $final_prompt_text = '';
    $style_title       = 'Custom';
    
    if (!empty($style_slug)) {
        $prompt_obj = ai_gemini_get_prompt_by_key($style_slug);
        if ($prompt_obj) {
            $final_prompt_text = $prompt_obj->prompt_text;
            $style_title       = $prompt_obj->title;
        } else {
             return new WP_Error(
                'invalid_style',
                'Kiểu phong cách (style) không hợp lệ hoặc đã bị xóa.',
                ['status' => 400]
            );
        }
    }
    
    if (!empty($user_prompt)) {
        $final_prompt_text .= "\nAdditional User Instruction: " . $user_prompt;
    }

    $final_prompt_text .= "\nTechnical Instruction: Render at native 1K resolution with crisp edges, clear micro-details, and high local contrast. Avoid blur, ringing, or oversharpening artifacts. Do not change the subject, composition, or content of the image.";
    
    $api = new AI_GEMINI_API();
    
    if (!$api->is_configured()) {
        return new WP_Error(
            'api_not_configured',
            'Dịch vụ chưa được cấu hình API Key. Vui lòng liên hệ Admin.',
            ['status' => 503]
        );
    }
    
    // ====== CREDIT PREVIEW ======
    $preview_cost  = (int) get_option('ai_gemini_preview_credit', 0);
    $credits_used  = 0;
    
    if ($preview_cost > 0) {
        $credits = ai_gemini_get_credit($user_id ?: null);
        if ($credits >= $preview_cost) {
            ai_gemini_update_credit(-$preview_cost, $user_id ?: null);
            $credits_used = $preview_cost;
        } else {
            if (!ai_gemini_has_used_trial($user_id ?: null)) {
                ai_gemini_mark_trial_used($user_id ?: null);
            } else {
                return new WP_Error(
                    'insufficient_credits',
                    'Không đủ tín dụng',
                    ['status' => 402]
                );
            }
        }
    }

    $table_images = $wpdb->prefix . 'ai_gemini_images';

    // ========= INPUT IMAGE & FILE URI =========

    $input_image_base64 = null;
    $original_image_url = null;
    $gemini_file_uri    = null;
    $gemini_mime_type   = null;

    if ($image_session_id > 0) {
        $parent = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_images WHERE id = %d",
            $image_session_id
        ));

        if ($parent) {
            $original_image_url = $parent->original_image_url;
            $gemini_file_uri    = $parent->gemini_file_uri;
            $gemini_mime_type   = $parent->gemini_mime_type;

            ai_gemini_log(
                'Loaded parent session id=' . $image_session_id .
                ' file_uri=' . ($gemini_file_uri ?: 'NULL') .
                ' mime=' . ($gemini_mime_type !== null ? $gemini_mime_type : 'NULL'),
                'info'
            );

            if (!empty($gemini_file_uri) && (empty($gemini_mime_type) || $gemini_mime_type === '0' || $gemini_mime_type === 0)) {
                $gemini_mime_type = 'image/jpeg';
            }

            if (empty($gemini_file_uri) && !empty($original_image_url)) {
                $file_path = ai_gemini_url_to_path($original_image_url);
                if ($file_path && file_exists($file_path)) {
                    $binary = file_get_contents($file_path);
                    if ($binary !== false) {
                        $input_image_base64 = base64_encode($binary);
                    }
                }
            }
        } else {
            if (empty($image_data)) {
                return new WP_Error(
                    'missing_image',
                    'Phiên ảnh đã hết hạn hoặc không tồn tại. Vui lòng tải ảnh mới.',
                    ['status' => 400]
                );
            }
        }
    }

    if (!$input_image_base64 && empty($gemini_file_uri)) {
        if (empty($image_data)) {
            return new WP_Error(
                'missing_image',
                'Vui lòng tải lên một bức ảnh.',
                ['status' => 400]
            );
        }
        $input_image_base64 = $image_data;
    }

    // ========= UPLOAD TO FILES API (NẾU CẦN) =========

    if (empty($gemini_file_uri)) {
        $validated = ai_gemini_validate_image_data($input_image_base64);
        if ($validated === false) {
            if ($credits_used > 0) {
                ai_gemini_update_credit($credits_used, $user_id ?: null);
            }
            return new WP_Error(
                'invalid_image',
                'Định dạng ảnh không hợp lệ.',
                ['status' => 400]
            );
        }

        $binary = base64_decode($validated, true);
        if ($binary === false) {
            if ($credits_used > 0) {
                ai_gemini_update_credit($credits_used, $user_id ?: null);
            }
            return new WP_Error(
                'decode_failed',
                'Không thể giải mã dữ liệu ảnh.',
                ['status' => 400]
            );
        }

        $optimized = $api->upload_image_to_files_api(
            $binary,
            'image/jpeg',
            'AI Gemini User Image'
        );

        if ($optimized === false) {
            ai_gemini_log('Files API upload failed, falling back to inlineData: ' . $api->get_last_error(), 'warning');
            $gemini_file_uri  = null;
            $gemini_mime_type = null;
        } else {
            $gemini_file_uri  = $optimized['file_uri'];
            $gemini_mime_type = !empty($optimized['mime_type']) ? $optimized['mime_type'] : 'image/jpeg';
        }
    }

    // ========= GỌI GEMINI =========

    if (!empty($gemini_file_uri)) {
        if (empty($gemini_mime_type) || $gemini_mime_type === '0' || $gemini_mime_type === 0) {
            $gemini_mime_type = 'image/jpeg';
        }
        ai_gemini_log('Using Files API with file_uri=' . $gemini_file_uri . ' mime=' . $gemini_mime_type, 'info');
        $result = $api->generate_image_from_file($gemini_file_uri, $gemini_mime_type, $final_prompt_text, $style_title);
    } else {
        ai_gemini_log('Using inlineData (no file_uri)', 'info');
        $result = $api->generate_image_inline($input_image_base64, $final_prompt_text, $style_title);
    }
    
    if (!$result) {
        if ($credits_used > 0) {
            ai_gemini_update_credit($credits_used, $user_id ?: null);
        }
        return new WP_Error(
            'generation_failed',
            $api->get_last_error() ?: 'Tạo ảnh thất bại, vui lòng thử lại.',
            ['status' => 500]
        );
    }

    // ========= LƯU ẢNH (original 1K + preview 512) =========

    $filename      = ai_gemini_generate_filename('preview'); // ví dụ preview-uuid.png
    $image_binary  = base64_decode($result['image_data']);

    $t0 = microtime(true);
    $stored = ai_gemini_store_image_versions($image_binary, $filename);
    $t1 = microtime(true);
    ai_gemini_log('ai_gemini_store_image_versions took ' . round(($t1 - $t0) * 1000, 2) . ' ms', 'info');
    
    if (
        !$stored ||
        !isset($stored['preview_path'], $stored['preview_url']) ||
        !file_exists($stored['preview_path'])
    ) {
        return new WP_Error(
            'save_failed',
            'Lỗi khi lưu ảnh xuống server.',
            ['status' => 500]
        );
    }
    
    $preview_url = $stored['preview_url'];                    // 512px + watermark

    // original_image_url lưu đường dẫn URL tương ứng originals (không public, chủ yếu để tham chiếu)
    // có thể dựng từ preview_url:
    $upload_dir = ai_gemini_get_upload_dir();
    $preview_basename = basename($preview_url); // preview-xxx-preview.jpg
    $orig_name  = str_replace('-preview.jpg', '-original.' . pathinfo($filename, PATHINFO_EXTENSION), $preview_basename);
    $original_image_url = $upload_dir['url'] . '/originals/' . $orig_name;

    // Lưu record DB
    $wpdb->insert(
        $table_images,
        [
            'user_id'           => $user_id ?: null,
            'guest_ip'          => $user_id ? null : $ip,
            'original_image_url'=> $original_image_url,
            'preview_image_url' => $preview_url,
            'full_image_url'    => null, // không dùng URL full public nữa
            'prompt'            => $final_prompt_text,
            'style'             => $style_slug,
            'is_unlocked'       => 0,
            'credits_used'      => $credits_used,
            'created_at'        => current_time('mysql'),
            'expires_at'        => date('Y-m-d H:i:s', strtotime('+24 hours')),
            'gemini_file_uri'   => $gemini_file_uri,
            'gemini_mime_type'  => $gemini_mime_type,
        ],
        ['%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s']
    );
    
    $image_id = $wpdb->insert_id;

    // image_session_id dùng để tái sử dụng Files API
    $image_session_id_to_return = $image_session_id > 0 ? $image_session_id : $image_id;
    
    if ($credits_used > 0) {
        ai_gemini_log_transaction([
            'user_id'      => $user_id ?: null,
            'guest_ip'     => $user_id ? null : $ip,
            'type'         => 'preview_generation',
            'amount'       => -$credits_used,
            'description'  => 'Tạo ảnh xem trước',
            'reference_id' => $image_id,
        ]);
    }
    
    $remaining_credits = ai_gemini_get_credit($user_id ?: null);
    $unlock_cost       = (int) get_option('ai_gemini_unlock_credit', 1);
    
    return rest_ensure_response([
        'success'          => true,
        'image_id'         => $image_id,
        'image_session_id' => $image_session_id_to_return,
        'preview_url'      => $preview_url,
        'credits_remaining'=> $remaining_credits,
        'unlock_cost'      => $unlock_cost,
        'can_unlock'       => $remaining_credits >= $unlock_cost,
        'message'          => 'Đã tạo ảnh thành công!',
    ]);
}

/**
 * Helper: convert URL trong uploads → đường dẫn file trên server
 */
function ai_gemini_url_to_path($url) {
    $upload_dir = wp_upload_dir();
    if (strpos($url, $upload_dir['baseurl']) === 0) {
        $relative = substr($url, strlen($upload_dir['baseurl']));
        return $upload_dir['basedir'] . $relative;
    }
    return false;
}