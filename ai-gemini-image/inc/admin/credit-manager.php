<?php
/**
 * AI Gemini Image Generator - Credit Manager
 * 
 * Admin interface for managing user credits.
 */

if (!defined('ABSPATH')) exit;

/**
 * Credit Manager page content
 */
function ai_gemini_credit_manager_page() {
    global $wpdb;
    
    $table_guest_credits = $wpdb->prefix . 'ai_gemini_guest_credits';
    
    // Handle credit adjustment
    if (isset($_POST['ai_gemini_adjust_credit']) && check_admin_referer('ai_gemini_credit_action')) {
        $user_id = isset($_POST['user_id']) ? absint($_POST['user_id']) : 0;
        $amount = isset($_POST['credit_amount']) ? intval($_POST['credit_amount']) : 0;
        $action = isset($_POST['credit_action']) ? sanitize_text_field(wp_unslash($_POST['credit_action'])) : 'add';
        
        if ($user_id && $amount > 0) {
            $actual_amount = $action === 'subtract' ? -$amount : $amount;
            ai_gemini_update_credit($actual_amount, $user_id);
            
            // Log transaction
            ai_gemini_log_transaction([
                'user_id' => $user_id,
                'type' => 'admin_adjustment',
                'amount' => $actual_amount,
                'description' => $action === 'add' 
                    ? sprintf(__('Admin added %d credits', 'ai-gemini-image'), $amount)
                    : sprintf(__('Admin removed %d credits', 'ai-gemini-image'), $amount)
            ]);
            
            echo '<div class="notice notice-success"><p>' . esc_html__('Credits updated successfully!', 'ai-gemini-image') . '</p></div>';
        }
    }
    
    // Search users
    $search = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
    $user_query_args = [
        'number' => 20,
        'paged' => isset($_GET['paged']) ? absint($_GET['paged']) : 1,
    ];
    
    if ($search) {
        $user_query_args['search'] = '*' . $search . '*';
        $user_query_args['search_columns'] = ['user_login', 'user_email', 'display_name'];
    }
    
    $user_query = new WP_User_Query($user_query_args);
    $users = $user_query->get_results();
    $total_users = $user_query->get_total();
    
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('Credit Manager', 'ai-gemini-image'); ?></h1>
        
        <!-- Search Form -->
        <form method="get" class="search-form">
            <input type="hidden" name="page" value="ai-gemini-credits">
            <p class="search-box">
                <label class="screen-reader-text" for="user-search-input"><?php esc_html_e('Search Users', 'ai-gemini-image'); ?></label>
                <input type="search" id="user-search-input" name="s" value="<?php echo esc_attr($search); ?>" placeholder="<?php esc_attr_e('Search users...', 'ai-gemini-image'); ?>">
                <input type="submit" class="button" value="<?php esc_attr_e('Search', 'ai-gemini-image'); ?>">
            </p>
        </form>
        
        <!-- Quick Add Credits Form -->
        <div style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); margin: 20px 0;">
            <h2><?php esc_html_e('Quick Credit Adjustment', 'ai-gemini-image'); ?></h2>
            <form method="post" style="display: flex; gap: 10px; align-items: flex-end; flex-wrap: wrap;">
                <?php wp_nonce_field('ai_gemini_credit_action'); ?>
                
                <div>
                    <label for="user_id"><?php esc_html_e('User ID', 'ai-gemini-image'); ?></label><br>
                    <input type="number" name="user_id" id="user_id" min="1" required class="regular-text">
                </div>
                
                <div>
                    <label for="credit_action"><?php esc_html_e('Action', 'ai-gemini-image'); ?></label><br>
                    <select name="credit_action" id="credit_action">
                        <option value="add"><?php esc_html_e('Add Credits', 'ai-gemini-image'); ?></option>
                        <option value="subtract"><?php esc_html_e('Subtract Credits', 'ai-gemini-image'); ?></option>
                    </select>
                </div>
                
                <div>
                    <label for="credit_amount"><?php esc_html_e('Amount', 'ai-gemini-image'); ?></label><br>
                    <input type="number" name="credit_amount" id="credit_amount" min="1" required class="small-text">
                </div>
                
                <div>
                    <input type="submit" name="ai_gemini_adjust_credit" class="button button-primary" value="<?php esc_attr_e('Apply', 'ai-gemini-image'); ?>">
                </div>
            </form>
        </div>
        
        <!-- Users Table -->
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th style="width: 60px;"><?php esc_html_e('ID', 'ai-gemini-image'); ?></th>
                    <th><?php esc_html_e('Username', 'ai-gemini-image'); ?></th>
                    <th><?php esc_html_e('Email', 'ai-gemini-image'); ?></th>
                    <th style="width: 100px;"><?php esc_html_e('Credits', 'ai-gemini-image'); ?></th>
                    <th style="width: 100px;"><?php esc_html_e('Trial Used', 'ai-gemini-image'); ?></th>
                    <th style="width: 200px;"><?php esc_html_e('Actions', 'ai-gemini-image'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ($users) : ?>
                    <?php foreach ($users as $user) : ?>
                        <?php 
                        $credits = ai_gemini_get_credit($user->ID);
                        $trial_used = ai_gemini_has_used_trial($user->ID);
                        ?>
                        <tr>
                            <td><?php echo esc_html($user->ID); ?></td>
                            <td>
                                <strong><?php echo esc_html($user->user_login); ?></strong>
                                <br><small><?php echo esc_html($user->display_name); ?></small>
                            </td>
                            <td><?php echo esc_html($user->user_email); ?></td>
                            <td>
                                <span style="font-size: 16px; font-weight: bold; color: <?php echo $credits > 0 ? '#46b450' : '#dc3232'; ?>;">
                                    <?php echo esc_html(number_format_i18n($credits)); ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($trial_used) : ?>
                                    <span style="color: #999;"><?php esc_html_e('Yes', 'ai-gemini-image'); ?></span>
                                <?php else : ?>
                                    <span style="color: #46b450;"><?php esc_html_e('No', 'ai-gemini-image'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <form method="post" style="display: inline-flex; gap: 5px;">
                                    <?php wp_nonce_field('ai_gemini_credit_action'); ?>
                                    <input type="hidden" name="user_id" value="<?php echo esc_attr($user->ID); ?>">
                                    <input type="hidden" name="credit_action" value="add">
                                    <input type="number" name="credit_amount" min="1" value="10" style="width: 60px;">
                                    <button type="submit" name="ai_gemini_adjust_credit" class="button button-small"><?php esc_html_e('+Add', 'ai-gemini-image'); ?></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else : ?>
                    <tr>
                        <td colspan="6"><?php esc_html_e('No users found.', 'ai-gemini-image'); ?></td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
        
        <?php
        // Pagination
        $total_pages = ceil($total_users / 20);
        if ($total_pages > 1) {
            echo '<div class="tablenav"><div class="tablenav-pages">';
            echo wp_kses_post(paginate_links([
                'base' => add_query_arg('paged', '%#%'),
                'format' => '',
                'current' => isset($_GET['paged']) ? absint($_GET['paged']) : 1,
                'total' => $total_pages,
            ]));
            echo '</div></div>';
        }
        ?>
        
        <!-- Guest Credits Section -->
        <h2 style="margin-top: 40px;"><?php esc_html_e('Guest Credits', 'ai-gemini-image'); ?></h2>
        <?php
        $guests = $wpdb->get_results("SELECT * FROM $table_guest_credits ORDER BY updated_at DESC LIMIT 50");
        ?>
        
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th><?php esc_html_e('IP Address', 'ai-gemini-image'); ?></th>
                    <th style="width: 100px;"><?php esc_html_e('Credits', 'ai-gemini-image'); ?></th>
                    <th style="width: 100px;"><?php esc_html_e('Trial Used', 'ai-gemini-image'); ?></th>
                    <th><?php esc_html_e('Last Updated', 'ai-gemini-image'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ($guests) : ?>
                    <?php foreach ($guests as $guest) : ?>
                        <tr>
                            <td><code><?php echo esc_html($guest->ip); ?></code></td>
                            <td><?php echo esc_html(number_format_i18n($guest->credits)); ?></td>
                            <td><?php echo $guest->used_trial ? esc_html__('Yes', 'ai-gemini-image') : esc_html__('No', 'ai-gemini-image'); ?></td>
                            <td><?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($guest->updated_at))); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else : ?>
                    <tr>
                        <td colspan="4"><?php esc_html_e('No guest credits found.', 'ai-gemini-image'); ?></td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}

/**
 * Log a credit transaction
 * 
 * @param array $data Transaction data
 * @return int|false Transaction ID or false on failure
 */
function ai_gemini_log_transaction($data) {
    global $wpdb;
    
    $table_transactions = $wpdb->prefix . 'ai_gemini_transactions';
    
    $defaults = [
        'user_id' => null,
        'guest_ip' => null,
        'type' => 'unknown',
        'amount' => 0,
        'balance_after' => 0,
        'description' => '',
        'reference_id' => null,
    ];
    
    $data = wp_parse_args($data, $defaults);
    
    // Calculate balance after if not provided
    if ($data['balance_after'] === 0 && $data['user_id']) {
        $data['balance_after'] = ai_gemini_get_credit($data['user_id']);
    }
    
    $result = $wpdb->insert(
        $table_transactions,
        [
            'user_id' => $data['user_id'],
            'guest_ip' => $data['guest_ip'],
            'type' => $data['type'],
            'amount' => $data['amount'],
            'balance_after' => $data['balance_after'],
            'description' => $data['description'],
            'reference_id' => $data['reference_id'],
        ],
        ['%d', '%s', '%s', '%d', '%d', '%s', '%d']
    );
    
    return $result ? $wpdb->insert_id : false;
}
