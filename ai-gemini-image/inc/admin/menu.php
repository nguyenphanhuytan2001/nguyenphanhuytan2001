<?php
/**
 * AI Gemini Image Generator - Admin Menu
 */

if (!defined('ABSPATH')) exit;

function ai_gemini_admin_menu() {
    add_menu_page('AI Gemini', 'AI Gemini', 'manage_options', 'ai-gemini-dashboard', 'ai_gemini_dashboard_page', 'dashicons-format-image', 30);
    add_submenu_page('ai-gemini-dashboard', 'Bảng Tin', 'Bảng Tin', 'manage_options', 'ai-gemini-dashboard', 'ai_gemini_dashboard_page');
    add_submenu_page('ai-gemini-dashboard', 'Cài Đặt', 'Cài Đặt', 'manage_options', 'ai-gemini-settings', 'ai_gemini_settings_page');
    add_submenu_page('ai-gemini-dashboard', 'Quản lý Prompts', 'Quản lý Prompts', 'manage_options', 'ai-gemini-prompts', 'ai_gemini_prompt_manager_page');
    
    // MISSION MANAGER MENU
    add_submenu_page('ai-gemini-dashboard', 'Quản lý Nhiệm Vụ', 'Nhiệm Vụ (Traffic)', 'manage_options', 'ai-gemini-missions', 'ai_gemini_mission_manager_page');
    
    add_submenu_page('ai-gemini-dashboard', 'Quản lý Credit', 'Quản lý Credit', 'manage_options', 'ai-gemini-credits', 'ai_gemini_credit_manager_page');
    add_submenu_page('ai-gemini-dashboard', 'Đơn Hàng', 'Đơn Hàng', 'manage_options', 'ai-gemini-orders', 'ai_gemini_orders_page');
}
add_action('admin_menu', 'ai_gemini_admin_menu');

// Dashboard
function ai_gemini_dashboard_page() {
    echo '<div class="wrap"><h1>Tổng Quan</h1><p>Chào mừng đến với AI Gemini.</p></div>';
}

// Settings
function ai_gemini_settings_page() {
    if (isset($_POST['ai_gemini_save_settings']) && check_admin_referer('ai_gemini_settings_nonce')) {
        update_option('ai_gemini_api_key', sanitize_text_field($_POST['ai_gemini_api_key']));
        update_option('ai_gemini_preview_credit', absint($_POST['ai_gemini_preview_credit']));
        update_option('ai_gemini_unlock_credit', absint($_POST['ai_gemini_unlock_credit']));
        update_option('ai_gemini_free_trial_credits', absint($_POST['ai_gemini_free_trial_credits']));
        
        // Mission Settings
        update_option('ai_gemini_mission_secret', sanitize_text_field($_POST['ai_gemini_mission_secret']));
        update_option('ai_gemini_mission_window', absint($_POST['ai_gemini_mission_window']));

        // Watermark Settings
        if (isset($_POST['ai_gemini_watermark_text'])) {
            update_option(
                'ai_gemini_watermark_text',
                sanitize_text_field( wp_unslash($_POST['ai_gemini_watermark_text']) )
            );
        }
        
        echo '<div class="notice notice-success"><p>Đã lưu cài đặt!</p></div>';
    }
    
    $api_key           = get_option('ai_gemini_api_key', '');
    $preview_credit    = get_option('ai_gemini_preview_credit', 0);
    $unlock_credit     = get_option('ai_gemini_unlock_credit', 1);
    $free_trial_credits= get_option('ai_gemini_free_trial_credits', 1);
    $mission_secret    = get_option('ai_gemini_mission_secret', '');
    $mission_window    = get_option('ai_gemini_mission_window', 15);
    $watermark_text    = get_option('ai_gemini_watermark_text', 'AI Gemini Preview');
    ?>
    <div class="wrap">
        <h1>Cài Đặt AI Gemini</h1>
        <form method="post">
            <?php wp_nonce_field('ai_gemini_settings_nonce'); ?>
            <table class="form-table">
                <tr>
                    <th>Gemini API Key</th>
                    <td><input type="password" name="ai_gemini_api_key" value="<?php echo esc_attr($api_key); ?>" class="regular-text"></td>
                </tr>
                <tr>
                    <th>Phí xem trước</th>
                    <td><input type="number" name="ai_gemini_preview_credit" value="<?php echo esc_attr($preview_credit); ?>" min="0" class="small-text"></td>
                </tr>
                <tr>
                    <th>Phí mở khóa</th>
                    <td><input type="number" name="ai_gemini_unlock_credit" value="<?php echo esc_attr($unlock_credit); ?>" min="1" class="small-text"></td>
                </tr>
                <tr>
                    <th>Credit dùng thử</th>
                    <td><input type="number" name="ai_gemini_free_trial_credits" value="<?php echo esc_attr($free_trial_credits); ?>" min="0" class="small-text"></td>
                </tr>
                <tr>
                    <th>Watermark preview text</th>
                    <td>
                        <input type="text"
                               name="ai_gemini_watermark_text"
                               value="<?php echo esc_attr($watermark_text); ?>"
                               class="regular-text">
                        <p class="description">
                            Dòng chữ dùng cho watermark chéo trên ảnh preview (kiểu Shutterstock).
                        </p>
                    </td>
                </tr>
            </table>
            <hr>
            <h2>Cấu Hình Nhiệm Vụ (Mission 2FA)</h2>
            <table class="form-table">
                <tr>
                    <th>Secret Key (2FA)</th>
                    <td>
                        <input type="text" name="ai_gemini_mission_secret" id="sec_key" value="<?php echo esc_attr($mission_secret); ?>" class="regular-text">
                        <button type="button" class="button" onclick="document.getElementById('sec_key').value = Math.random().toString(36).slice(-12).toUpperCase();">Tạo mới</button>
                        <p class="description">Key chung cho tất cả nhiệm vụ. Cần dán vào file PHP trên web vệ tinh.</p>
                    </td>
                </tr>
                <tr>
                    <th>Thời gian hiệu lực</th>
                    <td>
                        <input type="number" name="ai_gemini_mission_window" value="<?php echo esc_attr($mission_window); ?>" min="1" max="60" class="small-text"> phút
                        <p class="description">Cho phép mã cũ có hiệu lực trong khoảng này (tránh lệch giờ).</p>
                    </td>
                </tr>
            </table>
            <p class="submit"><input type="submit" name="ai_gemini_save_settings" class="button button-primary" value="Lưu Cài Đặt"></p>
        </form>
    </div>
    <?php
}

// Orders page
function ai_gemini_orders_page() { 
    echo '<div class="wrap"><h1>Đơn Hàng</h1><p>Danh sách đơn hàng...</p></div>'; 
}

function ai_gemini_admin_enqueue_scripts($hook) {
    if (strpos($hook, 'ai-gemini') === false) return;
    wp_enqueue_style('ai-gemini-admin', AI_GEMINI_PLUGIN_URL . 'assets/css/admin.css', [], AI_GEMINI_VERSION);
}
add_action('admin_enqueue_scripts', 'ai_gemini_admin_enqueue_scripts');