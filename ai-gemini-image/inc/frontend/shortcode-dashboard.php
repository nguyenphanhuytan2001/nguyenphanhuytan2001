<?php
/**
 * AI Gemini Image Generator - Dashboard Shortcode
 * 
 * Shortcode for user dashboard showing history and credits.
 */

if (!defined('ABSPATH')) exit;

/**
 * Register dashboard shortcode
 */
function ai_gemini_register_dashboard_shortcode() {
    add_shortcode('ai_gemini_dashboard', 'ai_gemini_dashboard_shortcode');
}
add_action('init', 'ai_gemini_register_dashboard_shortcode');

/**
 * Render dashboard shortcode
 * 
 * @param array $atts Shortcode attributes
 * @return string HTML output
 */
function ai_gemini_dashboard_shortcode($atts) {
    $atts = shortcode_atts([
        'show_history' => 'true',
        'history_limit' => 20,
    ], $atts, 'ai_gemini_dashboard');
    
    // Enqueue styles
    wp_enqueue_style(
        'ai-gemini-dashboard',
        AI_GEMINI_PLUGIN_URL . 'assets/css/generator.css',
        [],
        AI_GEMINI_VERSION
    );
    
    // Get user info
    $user_id = get_current_user_id();
    $credits = ai_gemini_get_credit($user_id ?: null);
    $total_spent = ai_gemini_get_total_spent($user_id ?: null);
    $total_purchased = ai_gemini_get_total_purchased($user_id ?: null);
    
    // Get images
    $images = ai_gemini_get_user_images($user_id ?: null, (int) $atts['history_limit']);
    $unlocked_count = 0;
    foreach ($images as $img) {
        if ($img->is_unlocked) {
            $unlocked_count++;
        }
    }
    
    ob_start();
    ?>
    <div class="ai-gemini-dashboard">
        <div class="dashboard-header">
            <h2><?php esc_html_e('Your AI Gemini Dashboard', 'ai-gemini-image'); ?></h2>
        </div>
        
        <div class="dashboard-stats">
            <div class="stat-card">
                <div class="stat-icon">💳</div>
                <div class="stat-info">
                    <span class="stat-value"><?php echo esc_html(number_format_i18n($credits)); ?></span>
                    <span class="stat-label"><?php esc_html_e('Available Credits', 'ai-gemini-image'); ?></span>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon">🖼️</div>
                <div class="stat-info">
                    <span class="stat-value"><?php echo esc_html(count($images)); ?></span>
                    <span class="stat-label"><?php esc_html_e('Images Generated', 'ai-gemini-image'); ?></span>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon">🔓</div>
                <div class="stat-info">
                    <span class="stat-value"><?php echo esc_html($unlocked_count); ?></span>
                    <span class="stat-label"><?php esc_html_e('Images Unlocked', 'ai-gemini-image'); ?></span>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon">📊</div>
                <div class="stat-info">
                    <span class="stat-value"><?php echo esc_html(number_format_i18n($total_spent)); ?></span>
                    <span class="stat-label"><?php esc_html_e('Credits Used', 'ai-gemini-image'); ?></span>
                </div>
            </div>
        </div>
        
        <div class="dashboard-actions">
            <a href="<?php echo esc_url(home_url('/generate')); ?>" class="btn-primary">
                <?php esc_html_e('+ Create New Image', 'ai-gemini-image'); ?>
            </a>
            <a href="<?php echo esc_url(home_url('/buy-credit')); ?>" class="btn-secondary">
                <?php esc_html_e('Buy More Credits', 'ai-gemini-image'); ?>
            </a>
        </div>
        
        <?php if ($atts['show_history'] === 'true') : ?>
        <div class="dashboard-history">
            <h3><?php esc_html_e('Your Image History', 'ai-gemini-image'); ?></h3>
            
            <?php if (!empty($images)) : ?>
                <div class="image-gallery">
                    <?php foreach ($images as $image) : ?>
                        <div class="gallery-item <?php echo $image->is_unlocked ? 'unlocked' : 'locked'; ?>">
                            <div class="gallery-image">
                                <?php if ($image->is_unlocked && $image->full_image_url) : ?>
                                    <img src="<?php echo esc_url($image->full_image_url); ?>" alt="Generated Image">
                                <?php elseif ($image->preview_image_url) : ?>
                                    <img src="<?php echo esc_url($image->preview_image_url); ?>" alt="Preview Image">
                                    <div class="locked-overlay">
                                        <span class="lock-icon">🔒</span>
                                    </div>
                                <?php else : ?>
                                    <div class="no-image">
                                        <span><?php esc_html_e('Image expired', 'ai-gemini-image'); ?></span>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="gallery-info">
                                <span class="gallery-style"><?php echo esc_html($image->style ?: 'Custom'); ?></span>
                                <span class="gallery-date"><?php echo esc_html(date_i18n(get_option('date_format'), strtotime($image->created_at))); ?></span>
                                <?php if ($image->is_unlocked) : ?>
                                    <span class="unlocked-badge"><?php esc_html_e('Unlocked', 'ai-gemini-image'); ?></span>
                                <?php endif; ?>
                            </div>
                            <?php if ($image->is_unlocked && $image->full_image_url) : ?>
                                <a href="<?php echo esc_url($image->full_image_url); ?>" class="btn-download" download>
                                    <?php esc_html_e('Download', 'ai-gemini-image'); ?>
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else : ?>
                <div class="no-history">
                    <p><?php esc_html_e('You haven\'t generated any images yet.', 'ai-gemini-image'); ?></p>
                    <a href="<?php echo esc_url(home_url('/generate')); ?>" class="btn-primary">
                        <?php esc_html_e('Create Your First Image', 'ai-gemini-image'); ?>
                    </a>
                </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        
        <div class="dashboard-transactions">
            <h3><?php esc_html_e('Recent Transactions', 'ai-gemini-image'); ?></h3>
            
            <?php 
            $transactions = ai_gemini_get_credit_history($user_id ?: null, 10);
            if (!empty($transactions)) : 
            ?>
                <table class="transactions-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Date', 'ai-gemini-image'); ?></th>
                            <th><?php esc_html_e('Type', 'ai-gemini-image'); ?></th>
                            <th><?php esc_html_e('Amount', 'ai-gemini-image'); ?></th>
                            <th><?php esc_html_e('Description', 'ai-gemini-image'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($transactions as $transaction) : ?>
                            <tr>
                                <td><?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($transaction->created_at))); ?></td>
                                <td>
                                    <span class="transaction-type type-<?php echo esc_attr($transaction->type); ?>">
                                        <?php echo esc_html(ucfirst(str_replace('_', ' ', $transaction->type))); ?>
                                    </span>
                                </td>
                                <td class="<?php echo $transaction->amount >= 0 ? 'positive' : 'negative'; ?>">
                                    <?php echo $transaction->amount >= 0 ? '+' : ''; ?><?php echo esc_html($transaction->amount); ?>
                                </td>
                                <td><?php echo esc_html($transaction->description); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else : ?>
                <p class="no-transactions"><?php esc_html_e('No transactions yet.', 'ai-gemini-image'); ?></p>
            <?php endif; ?>
        </div>
    </div>
    <?php
    
    return ob_get_clean();
}
