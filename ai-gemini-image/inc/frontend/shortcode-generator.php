<?php
/**
 * AI Gemini Image Generator - Generator Shortcode
 */

if (!defined('ABSPATH')) exit;

function ai_gemini_register_generator_shortcode() {
    add_shortcode('ai_gemini_generator', 'ai_gemini_generator_shortcode');
}
add_action('init', 'ai_gemini_register_generator_shortcode');

function ai_gemini_generator_shortcode($atts) {
    $atts = shortcode_atts([
        'show_credits'   => 'true',
        'show_styles'    => 'true',
        'style'          => '',
        'default_style'  => '',
    ], $atts, 'ai_gemini_generator');
    
    ai_gemini_enqueue_generator_assets();
    
    $user_id       = get_current_user_id();
    $credits       = ai_gemini_get_credit($user_id ?: null);
    $has_trial     = !ai_gemini_has_used_trial($user_id ?: null);
    $preview_cost  = (int) get_option('ai_gemini_preview_credit', 0);
    $unlock_cost   = (int) get_option('ai_gemini_unlock_credit', 1);
    $styles        = ai_gemini_get_active_prompts();
    $selected_style_slug = $atts['style'] ?: ($atts['default_style'] ?: ($styles ? $styles[0]->slug : ''));
    $forced_style  = !empty($atts['style']);

    ob_start();
    ?>
    <div class="ai-gemini-generator" id="ai-gemini-generator">
        <!-- Credit Bar -->
        <?php if ($atts['show_credits'] === 'true') : ?>
        <div class="gemini-credits-bar">
            <div class="credits-info">
                <span class="credits-label">Số dư:</span>
                <span class="credits-value" id="gemini-credits-display"><?php echo esc_html(number_format_i18n($credits)); ?></span>
            </div>
            <div class="credits-actions">
                <!-- Nút làm nhiệm vụ chủ động -->
                <button type="button" class="btn-earn-free" style="background:#28a745;color:#fff;border:none;padding:5px 10px;border-radius:4px;margin-right:5px;cursor:pointer;">
                    <span class="dashicons dashicons-awards"></span> Kiếm Free
                </button>
                <a href="<?php echo esc_url(home_url('/buy-credit')); ?>" class="btn-buy-credits">+ Mua</a>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Main Form -->
        <div class="gemini-generator-form">
            <div class="upload-section">
                <div class="upload-area" id="upload-area">
                    <div class="upload-placeholder">
                        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><circle cx="8.5" cy="8.5" r="1.5"></circle><polyline points="21 15 16 10 5 21"></polyline></svg>
                        <p>Kéo thả ảnh hoặc nhấn để tải lên</p>
                        <span class="upload-hint">JPG, PNG, WebP (Max 10MB)</span>
                    </div>
                    <div class="upload-preview" id="upload-preview" style="display:none;">
                        <img src="" alt="Preview" id="preview-image">
                        <button type="button" class="remove-image" id="remove-image">&times;</button>
                    </div>
                    <input type="file" id="image-input" accept="image/*" style="display:none;">
                </div>
            </div>
            
            <!-- Style Selection -->
            <?php if ($forced_style): ?>
                <input type="hidden" id="selected-style-slug" value="<?php echo esc_attr($selected_style_slug); ?>">
            <?php elseif ($atts['show_styles'] === 'true' && !empty($styles)) : ?>
                <div class="style-section">
                    <label>Chọn phong cách:</label>
                    <div class="style-options" id="style-options">
                        <?php foreach ($styles as $style) : ?>
                            <div class="style-option <?php echo $style->slug === $selected_style_slug ? 'active' : ''; ?>" data-style="<?php echo esc_attr($style->slug); ?>">
                                <?php if(!empty($style->sample_image)): ?><img src="<?php echo esc_url($style->sample_image); ?>" class="style-thumb"><?php endif; ?>
                                <span class="style-name"><?php echo esc_html($style->title); ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <input type="hidden" id="selected-style-slug" value="<?php echo esc_attr($selected_style_slug); ?>">
                </div>
            <?php else: ?>
                 <p style="color:red;">Chưa có Style nào.</p>
            <?php endif; ?>
            
            <div class="prompt-section" style="display:none;">
                <label>Yêu cầu thêm:</label>
                <textarea id="custom-prompt" placeholder="Mô tả chi tiết..."></textarea>
            </div>
            
            <div class="action-section">
                <button type="button" class="btn-generate" id="btn-generate" disabled>
                    <span class="btn-text">Tạo Ảnh Xem Trước</span>
                    <span class="btn-loading" style="display:none;"><span class="spinner"></span> Đang tạo...</span>
                </button>
                <p class="cost-info"><?php echo $preview_cost > 0 ? "Phí: $preview_cost credit" : "Miễn phí"; ?></p>
            </div>
        </div>
        
        <!-- Result Sections -->
        <div class="gemini-result" id="gemini-result" style="display:none;">
            <div class="result-header"><h3>Kết Quả</h3></div>
            <div class="result-image"><img src="" id="result-image"><div class="watermark-notice">Ảnh xem trước (Low Res)</div></div>
            <div class="result-actions">
                <button type="button" class="btn-unlock" id="btn-unlock">Mở Khóa Ảnh Gốc</button>
                <button type="button" class="btn-regenerate" id="btn-regenerate">Thử Lại</button>
            </div>
        </div>
        
        <div class="gemini-unlocked" id="gemini-unlocked" style="display:none;">
            <div class="unlocked-header"><h3>🎉 Thành Công!</h3></div>
            <div class="unlocked-image"><img src="" id="unlocked-image"></div>
            <div class="unlocked-actions">
                <a href="#" class="btn-download" id="btn-download" download>Tải Ảnh</a>
                <button type="button" class="btn-new" id="btn-new">Tạo Mới</button>
            </div>
        </div>
        
        <!-- Error / Suggestion Section -->
        <div class="gemini-error" id="gemini-error" style="display:none;">
            <p id="error-message"></p>
            
            <!-- Nút Gợi ý Nhiệm vụ sẽ hiện khi hết tiền -->
            <div id="mission-suggestion" style="display:none; margin-top:15px;">
                <p style="color:#666; margin-bottom:10px;">💡 Mẹo: Bạn có thể kiếm thêm Credit miễn phí ngay bây giờ!</p>
                <button type="button" class="btn-earn-free" style="background:#0073aa; color:white; border:none; padding:10px 20px; border-radius:4px; cursor:pointer; font-weight:bold;">
                    Làm Nhiệm Vụ Ngay &rarr;
                </button>
            </div>
            
            <button type="button" class="btn-retry" id="btn-retry" style="margin-top:15px;">Đóng</button>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

function ai_gemini_enqueue_generator_assets() {
    wp_enqueue_style('ai-gemini-generator', AI_GEMINI_PLUGIN_URL . 'assets/css/generator.css', [], AI_GEMINI_VERSION);
    wp_enqueue_script('ai-gemini-generator', AI_GEMINI_PLUGIN_URL . 'assets/js/generator.js', ['jquery'], AI_GEMINI_VERSION, true);
    wp_localize_script('ai-gemini-generator', 'AIGeminiConfig', [
        'api_preview' => rest_url('ai/v1/preview'),
        'api_unlock'  => rest_url('ai/v1/unlock'),
        'api_credit'  => rest_url('ai/v1/credit'),
        'nonce'       => wp_create_nonce('wp_rest'),
        'max_file_size' => 10 * 1024 * 1024,
        'strings' => [
            'error_file_size' => 'File quá lớn (Max 10MB).',
            'error_file_type' => 'Chỉ hỗ trợ JPG, PNG, WebP.',
            'error_upload'    => 'Lỗi tải ảnh.',
            'error_generate'  => 'Lỗi tạo ảnh.',
            'error_unlock'    => 'Lỗi mở khóa.',
            'generating'      => 'Đang xử lý...',
            'unlocking'       => 'Đang mở khóa...',
        ]
    ]);
}