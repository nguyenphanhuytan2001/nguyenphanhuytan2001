<?php
if (!defined('ABSPATH')) exit;

function ai_gemini_mission_manager_page() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'ai_gemini_missions';
    $table_stats = $wpdb->prefix . 'ai_gemini_mission_stats';
    $message = '';
    $action = isset($_GET['action']) ? $_GET['action'] : 'list';
    $base_url = admin_url('admin.php?page=ai-gemini-missions');

    // Xử lý Submit
    if (isset($_POST['submit_mission']) && check_admin_referer('ai_gemini_mission_action')) {
        $title = sanitize_text_field($_POST['title']);
        // Dùng cột 'description' cho hướng dẫn HTML
        $description = wp_kses_post($_POST['description']); 
        $reward = absint($_POST['reward']);
        $daily_limit = absint($_POST['daily_limit']);
        $is_active = isset($_POST['is_active']) ? 1 : 0;

        $data = [
            'title' => $title,
            'description' => $description,
            'reward' => $reward,
            'daily_limit' => $daily_limit,
            'is_active' => $is_active
        ];

        if (isset($_POST['mission_id']) && !empty($_POST['mission_id'])) {
            // UPDATE
            $result = $wpdb->update(
                $table_name, 
                $data, 
                ['id' => intval($_POST['mission_id'])]
            );
            
            if ($result === false) {
                $message = '<div class="error notice"><p>Lỗi cập nhật: ' . $wpdb->last_error . '</p></div>';
            } else {
                $message = '<div class="updated notice"><p>Đã cập nhật nhiệm vụ thành công.</p></div>';
                $action = 'list';
            }
        } else {
            // INSERT
            $result = $wpdb->insert($table_name, $data);
            
            if ($result === false) {
                // Check lỗi thiếu cột
                $db_error = $wpdb->last_error;
                if (strpos($db_error, "Unknown column") !== false) {
                    $db_error .= " (Hãy tải lại trang Dashboard để cập nhật Database)";
                }
                $message = '<div class="error notice"><p>Lỗi thêm mới: ' . esc_html($db_error) . '</p></div>';
            } else {
                $message = '<div class="updated notice"><p>Đã thêm nhiệm vụ mới thành công.</p></div>';
                $action = 'list';
            }
        }
    }

    // Xử lý Xóa
    if ($action == 'delete' && isset($_GET['id'])) {
        $wpdb->delete($table_name, ['id' => intval($_GET['id'])]);
        $message = '<div class="updated notice"><p>Đã xóa nhiệm vụ.</p></div>';
        $action = 'list';
    }

    ?>
    <div class="wrap">
        <h1 class="wp-heading-inline">Quản Lý Nhiệm Vụ (Traffic User)</h1>
        <?php if ($action == 'list'): ?>
            <a href="<?php echo $base_url . '&action=add'; ?>" class="page-title-action">Thêm Mới</a>
        <?php endif; ?>
        <hr class="wp-header-end">
        <?php echo $message; ?>

        <?php if ($action == 'add' || $action == 'edit'): 
            $edit_data = null;
            if ($action == 'edit' && isset($_GET['id'])) {
                $edit_data = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", intval($_GET['id'])));
            }
        ?>
            <div class="card" style="max-width: 800px; padding: 20px;">
                <h2><?php echo $edit_data ? 'Sửa Nhiệm Vụ' : 'Thêm Nhiệm Vụ'; ?></h2>
                <form method="post">
                    <?php wp_nonce_field('ai_gemini_mission_action'); ?>
                    <?php if($edit_data): ?><input type="hidden" name="mission_id" value="<?php echo $edit_data->id; ?>"><?php endif; ?>

                    <table class="form-table">
                        <tr>
                            <th><label>Tên Nhiệm Vụ</label></th>
                            <td><input type="text" name="title" class="regular-text" required value="<?php echo $edit_data ? esc_attr($edit_data->title) : ''; ?>"></td>
                        </tr>
                        <tr>
                            <th><label>Hướng Dẫn (HTML)</label></th>
                            <td>
                                <?php 
                                    $content = $edit_data ? $edit_data->description : "<p>Bước 1: Vào Google tìm [KEYWORD]</p>\n<p>Bước 2: Vào Web [DOMAIN]</p>\n<p>Bước 3: Lấy mã cuối trang.</p>";
                                    wp_editor($content, 'description', ['media_buttons' => false, 'textarea_rows' => 8, 'teeny' => true]);
                                ?>
                                <p class="description">Soạn thảo nội dung hướng dẫn chi tiết sẽ hiện trong Popup.</p>
                            </td>
                        </tr>
                        <tr>
                            <th><label>Phần Thưởng (Credit)</label></th>
                            <td><input type="number" name="reward" class="small-text" min="1" value="<?php echo $edit_data ? esc_attr($edit_data->reward) : '1'; ?>"></td>
                        </tr>
                        <tr>
                            <th><label>Giới Hạn (Lần/Ngày)</label></th>
                            <td>
                                <input type="number" name="daily_limit" class="small-text" min="0" value="<?php echo $edit_data ? esc_attr($edit_data->daily_limit) : '0'; ?>">
                                <p class="description">0 = Không giới hạn. Nhập số để giới hạn tổng lượt hoàn thành trong ngày của toàn hệ thống.</p>
                            </td>
                        </tr>
                        <tr>
                            <th>Trạng Thái</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="is_active" <?php echo (!$edit_data || $edit_data->is_active) ? 'checked' : ''; ?>> 
                                    Kích hoạt (Hiển thị cho khách)
                                </label>
                            </td>
                        </tr>
                    </table>
                    <p class="submit">
                        <input type="submit" name="submit_mission" class="button button-primary" value="Lưu Nhiệm Vụ">
                        <a href="<?php echo $base_url; ?>" class="button">Hủy</a>
                    </p>
                </form>
            </div>

        <?php else: // LIST VIEW
            // Check bảng tồn tại trước
            if($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
                echo '<div class="error notice"><p>Bảng dữ liệu chưa được tạo. Vui lòng Reload trang để cập nhật Database.</p></div>';
            } else {
                $missions = $wpdb->get_results("
                    SELECT m.*, 
                        COALESCE(s.views, 0) as today_views, 
                        COALESCE(s.completed, 0) as today_completed 
                    FROM $table_name m 
                    LEFT JOIN $table_stats s ON m.id = s.mission_id AND s.date = CURDATE()
                    ORDER BY m.id DESC
                ");
        ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th width="50">ID</th>
                        <th>Tên Nhiệm Vụ</th>
                        <th>Thưởng</th>
                        <th>Giới Hạn</th>
                        <th>Hôm Nay (Xem/Xong)</th>
                        <th>Tỷ Lệ</th>
                        <th>Trạng Thái</th>
                        <th>Hành Động</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if($missions): foreach($missions as $m): 
                        $rate = ($m->today_views > 0) ? round(($m->today_completed/$m->today_views)*100, 1) : 0;
                        $desc_preview = isset($m->description) ? strip_tags($m->description) : '';
                    ?>
                    <tr>
                        <td><?php echo $m->id; ?></td>
                        <td>
                            <strong><?php echo esc_html($m->title); ?></strong><br>
                            <small style="color:#888"><?php echo mb_strimwidth($desc_preview, 0, 50, '...'); ?></small>
                        </td>
                        <td><span class="dashicons dashicons-star-filled" style="color:orange"></span> <?php echo $m->reward; ?></td>
                        <td><?php echo $m->daily_limit > 0 ? $m->daily_limit : '∞'; ?></td>
                        <td>
                            <span style="color:blue" title="Lượt xem"><?php echo $m->today_views; ?></span> / 
                            <span style="color:green; font-weight:bold;" title="Hoàn thành"><?php echo $m->today_completed; ?></span>
                        </td>
                        <td><?php echo $rate; ?>%</td>
                        <td>
                            <?php if (isset($m->is_active)): ?>
                                <?php echo $m->is_active ? '<span style="color:green;font-weight:bold">Bật</span>' : '<span style="color:red">Tắt</span>'; ?>
                            <?php else: ?>
                                <span style="color:#999">N/A</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a href="<?php echo $base_url . '&action=edit&id=' . $m->id; ?>" class="button button-small">Sửa</a>
                            <a href="<?php echo $base_url . '&action=delete&id=' . $m->id; ?>" class="button button-small" onclick="return confirm('Bạn có chắc muốn xóa nhiệm vụ này không?');">Xóa</a>
                        </td>
                    </tr>
                    <?php endforeach; else: ?>
                    <tr><td colspan="8">Chưa có nhiệm vụ nào. Hãy thêm mới!</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        <?php 
            } // End check table
        endif; ?>
    </div>
    <?php
}