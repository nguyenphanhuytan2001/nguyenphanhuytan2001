<?php
/**
 * AI Gemini Image Generator - VietQR Payment Page
 * 
 * Handles the VietQR payment display and processing.
 */

if (!defined('ABSPATH')) exit;

/**
 * Register VietQR payment endpoint
 */
function ai_gemini_register_vietqr_endpoint() {
    add_rewrite_endpoint('ai-gemini-pay', EP_ROOT);
}
add_action('init', 'ai_gemini_register_vietqr_endpoint');

/**
 * Handle VietQR payment page
 */
function ai_gemini_handle_vietqr_page() {
    global $wp_query;
    
    if (!isset($wp_query->query_vars['ai-gemini-pay'])) {
        return;
    }
    
    $order_code = sanitize_text_field($wp_query->query_vars['ai-gemini-pay']);
    
    if (empty($order_code)) {
        wp_die(__('Invalid payment request', 'ai-gemini-image'));
    }
    
    // Get order
    global $wpdb;
    $table_orders = $wpdb->prefix . 'ai_gemini_orders';
    $order = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $table_orders WHERE order_code = %s",
        $order_code
    ));
    
    if (!$order) {
        wp_die(__('Order not found', 'ai-gemini-image'));
    }
    
    if ($order->status === 'completed') {
        wp_redirect(home_url('?payment=success&order=' . $order_code));
        exit;
    }
    
    // Display payment page
    ai_gemini_display_vietqr_page($order);
    exit;
}
add_action('template_redirect', 'ai_gemini_handle_vietqr_page');

/**
 * Display VietQR payment page
 * 
 * @param object $order Order object
 */
function ai_gemini_display_vietqr_page($order) {
    $config = ai_gemini_get_vietqr_config();
    $qr_url = ai_gemini_generate_vietqr_url($order->order_code, $order->amount);
    
    // Get package info
    $packages = ai_gemini_get_credit_packages();
    $package_name = '';
    foreach ($packages as $package) {
        if ($package['credits'] == $order->credits) {
            $package_name = $package['name'];
            break;
        }
    }
    
    wp_enqueue_style('ai-gemini-vietqr', AI_GEMINI_PLUGIN_URL . 'assets/css/vietqr.css', [], AI_GEMINI_VERSION);
    
    ?>
    <!DOCTYPE html>
    <html <?php language_attributes(); ?>>
    <head>
        <meta charset="<?php bloginfo('charset'); ?>">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?php echo esc_html__('Payment - AI Gemini', 'ai-gemini-image'); ?></title>
        <?php wp_head(); ?>
    </head>
    <body class="ai-gemini-payment-page">
        <div class="vietqr-container">
            <div class="vietqr-header">
                <h1><?php echo esc_html__('Complete Your Payment', 'ai-gemini-image'); ?></h1>
                <p><?php echo esc_html__('Scan the QR code below to pay', 'ai-gemini-image'); ?></p>
            </div>
            
            <div class="vietqr-content">
                <div class="order-summary">
                    <h3><?php echo esc_html__('Order Summary', 'ai-gemini-image'); ?></h3>
                    <div class="order-details">
                        <div class="detail-row">
                            <span class="label"><?php echo esc_html__('Order Code', 'ai-gemini-image'); ?></span>
                            <span class="value"><code><?php echo esc_html($order->order_code); ?></code></span>
                        </div>
                        <div class="detail-row">
                            <span class="label"><?php echo esc_html__('Package', 'ai-gemini-image'); ?></span>
                            <span class="value"><?php echo esc_html($package_name); ?></span>
                        </div>
                        <div class="detail-row">
                            <span class="label"><?php echo esc_html__('Credits', 'ai-gemini-image'); ?></span>
                            <span class="value"><?php echo esc_html(number_format_i18n($order->credits)); ?></span>
                        </div>
                        <div class="detail-row total">
                            <span class="label"><?php echo esc_html__('Total', 'ai-gemini-image'); ?></span>
                            <span class="value"><?php echo esc_html(number_format_i18n($order->amount)); ?>đ</span>
                        </div>
                    </div>
                </div>
                
                <div class="qr-section">
                    <div class="qr-code">
                        <?php if ($qr_url) : ?>
                            <img src="<?php echo esc_url($qr_url); ?>" alt="VietQR Code" id="vietqr-image">
                        <?php else : ?>
                            <p class="error"><?php echo esc_html__('Payment configuration error. Please contact support.', 'ai-gemini-image'); ?></p>
                        <?php endif; ?>
                    </div>
                    
                    <div class="bank-info">
                        <p><strong><?php echo esc_html__('Bank', 'ai-gemini-image'); ?>:</strong> <?php echo esc_html($config['bank_id']); ?></p>
                        <p><strong><?php echo esc_html__('Account', 'ai-gemini-image'); ?>:</strong> <?php echo esc_html($config['account_no']); ?></p>
                        <p><strong><?php echo esc_html__('Name', 'ai-gemini-image'); ?>:</strong> <?php echo esc_html($config['account_name']); ?></p>
                        <p><strong><?php echo esc_html__('Amount', 'ai-gemini-image'); ?>:</strong> <?php echo esc_html(number_format_i18n($order->amount)); ?>đ</p>
                        <p><strong><?php echo esc_html__('Description', 'ai-gemini-image'); ?>:</strong> AIGC <?php echo esc_html($order->order_code); ?></p>
                    </div>
                </div>
                
                <div class="payment-status" id="payment-status">
                    <div class="status-waiting">
                        <span class="spinner"></span>
                        <span><?php echo esc_html__('Waiting for payment...', 'ai-gemini-image'); ?></span>
                    </div>
                </div>
                
                <div class="payment-instructions">
                    <h4><?php echo esc_html__('Instructions', 'ai-gemini-image'); ?></h4>
                    <ol>
                        <li><?php echo esc_html__('Open your banking app', 'ai-gemini-image'); ?></li>
                        <li><?php echo esc_html__('Scan the QR code above', 'ai-gemini-image'); ?></li>
                        <li><?php echo esc_html__('Verify the amount and description', 'ai-gemini-image'); ?></li>
                        <li><?php echo esc_html__('Complete the transfer', 'ai-gemini-image'); ?></li>
                        <li><?php echo esc_html__('Wait for confirmation (usually within 1-2 minutes)', 'ai-gemini-image'); ?></li>
                    </ol>
                </div>
            </div>
            
            <div class="vietqr-footer">
                <a href="<?php echo esc_url(home_url()); ?>" class="btn-back"><?php echo esc_html__('← Back to Home', 'ai-gemini-image'); ?></a>
            </div>
        </div>
        
        <script>
        (function() {
            var orderCode = '<?php echo esc_js($order->order_code); ?>';
            var checkInterval = <?php echo esc_js($config['check_interval'] * 1000); ?>;
            var maxWaitTime = <?php echo esc_js($config['max_wait_time'] * 1000); ?>;
            var startTime = Date.now();
            
            function checkPaymentStatus() {
                if (Date.now() - startTime > maxWaitTime) {
                    document.getElementById('payment-status').innerHTML = 
                        '<div class="status-timeout"><?php echo esc_js(__('Payment timeout. Please refresh the page or contact support.', 'ai-gemini-image')); ?></div>';
                    return;
                }
                
                fetch('<?php echo esc_url(rest_url('ai/v1/credit/order/')); ?>' + orderCode)
                    .then(function(response) { return response.json(); })
                    .then(function(data) {
                        if (data.status === 'completed') {
                            document.getElementById('payment-status').innerHTML = 
                                '<div class="status-success">✓ <?php echo esc_js(__('Payment successful! Redirecting...', 'ai-gemini-image')); ?></div>';
                            setTimeout(function() {
                                window.location.href = '<?php echo esc_url(home_url('?payment=success&order=')); ?>' + orderCode;
                            }, 2000);
                        } else {
                            setTimeout(checkPaymentStatus, checkInterval);
                        }
                    })
                    .catch(function() {
                        setTimeout(checkPaymentStatus, checkInterval);
                    });
            }
            
            setTimeout(checkPaymentStatus, checkInterval);
        })();
        </script>
        
        <?php wp_footer(); ?>
    </body>
    </html>
    <?php
}

/**
 * Flush rewrite rules on activation
 */
function ai_gemini_flush_vietqr_rules() {
    ai_gemini_register_vietqr_endpoint();
    flush_rewrite_rules();
}
register_activation_hook(AI_GEMINI_PLUGIN_DIR . 'ai-gemini-image.php', 'ai_gemini_flush_vietqr_rules');
