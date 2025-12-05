<?php
/**
 * AI Gemini Image Generator - Credit Purchase Shortcode
 * 
 * Shortcode for credit purchase page.
 */

if (!defined('ABSPATH')) exit;

/**
 * Register credit shortcode
 */
function ai_gemini_register_credit_shortcode() {
    add_shortcode('ai_gemini_buy_credit', 'ai_gemini_credit_shortcode');
}
add_action('init', 'ai_gemini_register_credit_shortcode');

/**
 * Render credit purchase shortcode
 * 
 * @param array $atts Shortcode attributes
 * @return string HTML output
 */
function ai_gemini_credit_shortcode($atts) {
    $atts = shortcode_atts([
        'columns' => 4,
    ], $atts, 'ai_gemini_buy_credit');
    
    // Enqueue styles
    wp_enqueue_style(
        'ai-gemini-credit',
        AI_GEMINI_PLUGIN_URL . 'assets/css/credit.css',
        [],
        AI_GEMINI_VERSION
    );
    
    // Get user info
    $user_id = get_current_user_id();
    $credits = ai_gemini_get_credit($user_id ?: null);
    
    // Get packages
    $packages = ai_gemini_get_credit_packages();
    
    // Validate VietQR config
    $vietqr_valid = ai_gemini_validate_vietqr_config();
    
    ob_start();
    ?>
    <div class="ai-gemini-credit-page">
        <div class="credit-header">
            <h2><?php esc_html_e('Buy Credits', 'ai-gemini-image'); ?></h2>
            <p><?php esc_html_e('Purchase credits to unlock high-quality AI generated images.', 'ai-gemini-image'); ?></p>
            <div class="current-balance">
                <?php esc_html_e('Current Balance:', 'ai-gemini-image'); ?>
                <strong><?php echo esc_html(number_format_i18n($credits)); ?></strong>
                <?php esc_html_e('credits', 'ai-gemini-image'); ?>
            </div>
        </div>
        
        <?php if (!$vietqr_valid['valid']) : ?>
            <div class="credit-error">
                <p><?php esc_html_e('Payment system is not configured. Please contact administrator.', 'ai-gemini-image'); ?></p>
            </div>
        <?php else : ?>
            <div class="credit-packages" style="--columns: <?php echo esc_attr($atts['columns']); ?>;">
                <?php foreach ($packages as $package) : ?>
                    <div class="package-card <?php echo $package['popular'] ? 'popular' : ''; ?>" data-package-id="<?php echo esc_attr($package['id']); ?>">
                        <?php if ($package['popular']) : ?>
                            <div class="popular-badge"><?php esc_html_e('Most Popular', 'ai-gemini-image'); ?></div>
                        <?php endif; ?>
                        
                        <div class="package-name"><?php echo esc_html($package['name']); ?></div>
                        
                        <div class="package-credits">
                            <span class="credits-number"><?php echo esc_html(number_format_i18n($package['credits'])); ?></span>
                            <span class="credits-label"><?php esc_html_e('credits', 'ai-gemini-image'); ?></span>
                        </div>
                        
                        <div class="package-price">
                            <?php echo esc_html($package['price_formatted']); ?>
                        </div>
                        
                        <div class="package-rate">
                            <?php 
                            $rate = $package['price'] / $package['credits'];
                            printf(esc_html__('%sđ per credit', 'ai-gemini-image'), number_format_i18n($rate));
                            ?>
                        </div>
                        
                        <button type="button" class="btn-select-package" data-package="<?php echo esc_attr($package['id']); ?>">
                            <?php esc_html_e('Select Package', 'ai-gemini-image'); ?>
                        </button>
                    </div>
                <?php endforeach; ?>
            </div>
            
            <div class="payment-info">
                <h3><?php esc_html_e('How to Pay', 'ai-gemini-image'); ?></h3>
                <ol>
                    <li><?php esc_html_e('Select a credit package above', 'ai-gemini-image'); ?></li>
                    <li><?php esc_html_e('Scan the VietQR code with your banking app', 'ai-gemini-image'); ?></li>
                    <li><?php esc_html_e('Complete the transfer with the exact amount and description', 'ai-gemini-image'); ?></li>
                    <li><?php esc_html_e('Credits will be added automatically within 1-2 minutes', 'ai-gemini-image'); ?></li>
                </ol>
            </div>
            
            <div class="payment-methods">
                <h4><?php esc_html_e('Supported Banks', 'ai-gemini-image'); ?></h4>
                <p><?php esc_html_e('Works with all major Vietnamese banks supporting VietQR.', 'ai-gemini-image'); ?></p>
            </div>
        <?php endif; ?>
    </div>
    
    <script>
    (function($) {
        $('.btn-select-package').on('click', function() {
            var packageId = $(this).data('package');
            var $button = $(this);
            
            $button.prop('disabled', true).text('<?php echo esc_js(__('Processing...', 'ai-gemini-image')); ?>');
            
            $.ajax({
                url: '<?php echo esc_url(rest_url('ai/v1/credit/order')); ?>',
                method: 'POST',
                headers: {
                    'X-WP-Nonce': '<?php echo esc_js(wp_create_nonce('wp_rest')); ?>'
                },
                data: JSON.stringify({ package_id: packageId }),
                contentType: 'application/json',
                success: function(response) {
                    if (response.success && response.order_code) {
                        window.location.href = '<?php echo esc_url(home_url('/ai-gemini-pay/')); ?>' + response.order_code;
                    } else {
                        alert(response.message || '<?php echo esc_js(__('An error occurred. Please try again.', 'ai-gemini-image')); ?>');
                        $button.prop('disabled', false).text('<?php echo esc_js(__('Select Package', 'ai-gemini-image')); ?>');
                    }
                },
                error: function(xhr) {
                    var message = xhr.responseJSON && xhr.responseJSON.message 
                        ? xhr.responseJSON.message 
                        : '<?php echo esc_js(__('An error occurred. Please try again.', 'ai-gemini-image')); ?>';
                    alert(message);
                    $button.prop('disabled', false).text('<?php echo esc_js(__('Select Package', 'ai-gemini-image')); ?>');
                }
            });
        });
    })(jQuery);
    </script>
    <?php
    
    return ob_get_clean();
}
