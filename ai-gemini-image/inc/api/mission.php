<?php
if (!defined('ABSPATH')) exit;

// Hàm sinh OTP
if (!function_exists('ai_gemini_generate_otp')) {
    function ai_gemini_generate_otp($secret, $time_slice) {
        $binary_time = pack('N*', 0) . pack('N*', $time_slice);
        $hash = hash_hmac('sha1', $binary_time, $secret, true);
        $offset = ord(substr($hash, -1)) & 0x0F;
        $binary_code = (
            ((ord(substr($hash, $offset, 1)) & 0x7F) << 24) |
            ((ord(substr($hash, $offset + 1, 1)) & 0xFF) << 16) |
            ((ord(substr($hash, $offset + 2, 1)) & 0xFF) << 8) |
            (ord(substr($hash, $offset + 3, 1)) & 0xFF)
        );
        $otp = $binary_code % 1000000;
        return str_pad($otp, 6, '0', STR_PAD_LEFT);
    }
}

function ai_gemini_register_mission_api() {
    register_rest_route('ai/v1', '/mission/get', [
        'methods' => 'GET',
        'callback' => 'ai_gemini_handle_get_mission',
        'permission_callback' => '__return_true',
    ]);
    register_rest_route('ai/v1', '/mission/verify', [
        'methods' => 'POST',
        'callback' => 'ai_gemini_handle_verify_mission',
        'permission_callback' => '__return_true',
    ]);
}
add_action('rest_api_init', 'ai_gemini_register_mission_api');

// 1. API Lấy Nhiệm Vụ Random
function ai_gemini_handle_get_mission($request) {
    global $wpdb;
    $table_missions = $wpdb->prefix . 'ai_gemini_missions';
    $table_stats = $wpdb->prefix . 'ai_gemini_mission_stats';
    
    // Lấy danh sách nhiệm vụ khả dụng (Active + Chưa full limit)
    // LƯU Ý: Sử dụng cột 'description' thay vì 'steps'
    $missions = $wpdb->get_results("
        SELECT m.id, m.title, m.description, m.reward 
        FROM $table_missions m 
        LEFT JOIN $table_stats s ON m.id = s.mission_id AND s.date = CURDATE()
        WHERE m.is_active = 1 
        AND (m.daily_limit = 0 OR COALESCE(s.completed, 0) < m.daily_limit)
    ");
    
    if (empty($missions)) {
        return new WP_Error('no_mission', 'Tạm thời hết nhiệm vụ. Vui lòng quay lại sau.', ['status' => 404]);
    }
    
    // Random 1 nhiệm vụ
    $mission = $missions[array_rand($missions)];
    
    // Chuẩn hóa output: Frontend JS đang dùng 'steps', nhưng DB dùng 'description'
    // Ta gán alias để JS không bị lỗi
    $mission->steps = $mission->description;
    
    // Tăng View Counter
    $wpdb->query("
        INSERT INTO $table_stats (mission_id, date, views, completed) 
        VALUES ($mission->id, CURDATE(), 1, 0) 
        ON DUPLICATE KEY UPDATE views = views + 1
    ");
    
    return rest_ensure_response([
        'success' => true,
        'mission' => $mission
    ]);
}

// ... (Hàm verify giữ nguyên như cũ, không đổi logic) ...
function ai_gemini_handle_verify_mission($request) {
    global $wpdb;
    $ip = ai_gemini_get_client_ip();
    $user_code = sanitize_text_field($request->get_param('code'));
    $mission_id = absint($request->get_param('mission_id'));
    
    if (empty($user_code) || empty($mission_id)) return new WP_Error('missing_params', 'Thiếu mã hoặc ID.', ['status' => 400]);
    
    $secret = get_option('ai_gemini_mission_secret', '');
    $window_minutes = (int)get_option('ai_gemini_mission_window', 15);
    if (empty($secret)) return new WP_Error('config_error', 'Chưa cấu hình Secret Key.', ['status' => 503]);
    
    // Check Mission Active
    $table_missions = $wpdb->prefix . 'ai_gemini_missions';
    $mission = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_missions WHERE id = %d AND is_active = 1", $mission_id));
    if (!$mission) return new WP_Error('invalid_mission', 'Nhiệm vụ không hợp lệ.', ['status' => 400]);
    
    // Check Replay
    $table_logs = $wpdb->prefix . 'ai_gemini_mission_logs';
    $wpdb->query("DELETE FROM $table_logs WHERE verified_at < DATE_SUB(NOW(), INTERVAL 1 DAY)");
    $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table_logs WHERE otp_code = %s AND mission_id = %d", $user_code, $mission_id));
    if ($exists) return new WP_Error('code_used', 'Mã này đã được sử dụng.', ['status' => 400]);
    
    // Verify OTP
    $step = 30;
    $current_slice = floor(time() / $step);
    $slices = floor(($window_minutes * 60) / $step);
    $valid = false;
    for ($i = 0; $i <= $slices; $i++) {
        if (ai_gemini_generate_otp($secret, $current_slice - $i) === $user_code) { $valid = true; break; }
    }
    
    if (!$valid) return new WP_Error('invalid_otp', 'Mã không chính xác.', ['status' => 400]);
    
    // Success
    $wpdb->insert($table_logs, ['mission_id' => $mission_id, 'otp_code' => $user_code, 'guest_ip' => $ip]);
    $table_stats = $wpdb->prefix . 'ai_gemini_mission_stats';
    $wpdb->query("INSERT INTO $table_stats (mission_id, date, views, completed) VALUES ($mission_id, CURDATE(), 0, 1) ON DUPLICATE KEY UPDATE completed = completed + 1");
    
    ai_gemini_update_credit($mission->reward, null);
    ai_gemini_log_transaction([
        'guest_ip' => $ip,
        'type' => 'mission_reward',
        'amount' => $mission->reward,
        'description' => "Hoàn thành nhiệm vụ: " . $mission->title,
        'reference_id' => $mission_id
    ]);
    
    return rest_ensure_response([
        'success' => true,
        'credits_added' => $mission->reward,
        'total_credits' => ai_gemini_get_credit(null),
        'message' => "Thành công! +{$mission->reward} Credit."
    ]);
}