<?php
if (!defined('ABSPATH')) exit;

/**
 * Quản lý Prompts & Styles Page
 */
function ai_gemini_prompt_manager_page() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'ai_gemini_prompts';
    $message = '';
    $action = isset($_GET['action']) ? $_GET['action'] : 'list';
    $base_url = admin_url('admin.php?page=ai-gemini-prompts');

    // --- 1. XỬ LÝ FORM SUBMIT ---
    if (isset($_POST['submit_prompt']) && check_admin_referer('ai_gemini_prompt_action')) {
        $title = sanitize_text_field($_POST['title']);
        $slug = sanitize_title($_POST['slug']);
        
        // Auto Slug nếu user để trống
        if (empty($slug)) {
            $slug = sanitize_title($title);
        }

        $prompt_text = sanitize_textarea_field($_POST['prompt_text']);
        $sample_image = isset($_POST['sample_image']) ? esc_url_raw($_POST['sample_image']) : '';
        
        // Dữ liệu chuẩn bị lưu (Khớp chính xác danh sách cột bạn cung cấp: id, slug, title, prompt_text, sample_image)
        // Cột created_at tự động sinh ra bởi MySQL
        $data = [
            'title' => $title, 
            'slug' => $slug, 
            'prompt_text' => $prompt_text, 
            'sample_image' => $sample_image
        ];

        // Định dạng dữ liệu
        $format = ['%s', '%s', '%s', '%s'];

        if (isset($_POST['prompt_id']) && !empty($_POST['prompt_id'])) {
            // UPDATE
            $result = $wpdb->update(
                $table_name,
                $data,
                ['id' => intval($_POST['prompt_id'])],
                $format,
                ['%d']
            );
            
            if ($result === false) {
                 $message = '<div class="error notice is-dismissible"><p>Lỗi cập nhật: ' . $wpdb->last_error . '</p></div>';
            } else {
                 $message = '<div class="updated notice is-dismissible"><p>Đã cập nhật Prompt thành công.</p></div>';
                 $action = 'list'; 
            }
        } else {
            // INSERT
            $result = $wpdb->insert($table_name, $data, $format);
            
            if ($result) {
                $message = '<div class="updated notice is-dismissible"><p>Đã thêm Prompt mới thành công.</p></div>';
                $action = 'list'; 
            } else {
                $db_error = $wpdb->last_error;
                $message = '<div class="error notice is-dismissible"><p>Có lỗi xảy ra: ' . esc_html($db_error) . '</p></div>';
            }
        }
    }

    // --- 2. XỬ LÝ XÓA ---
    if ($action == 'delete' && isset($_GET['id'])) {
        if (isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'delete_prompt_' . $_GET['id'])) {
            $wpdb->delete($table_name, ['id' => intval($_GET['id'])]);
            $message = '<div class="updated notice is-dismissible"><p>Đã xóa Prompt.</p></div>';
            $action = 'list';
        } else {
            $message = '<div class="error notice is-dismissible"><p>Lỗi xác thực bảo mật.</p></div>';
        }
    }

    // --- 3. HIỂN THỊ GIAO DIỆN ---
    ?>
    <div class="wrap">
        <h1 class="wp-heading-inline">Quản lý Prompts & Styles</h1>
        
        <?php if ($action == 'list'): ?>
            <a href="<?php echo $base_url . '&action=add'; ?>" class="page-title-action">Thêm Mới</a>
        <?php endif; ?>
        
        <hr class="wp-header-end">
        
        <?php echo $message; ?>

        <?php 
        // --- VIEW: FORM (ADD/EDIT) ---
        if ($action == 'add' || $action == 'edit'): 
            $edit_data = null;
            if ($action == 'edit' && isset($_GET['id'])) {
                $edit_data = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", intval($_GET['id'])));
            }
        ?>
            <div class="card" style="max-width: 100%; padding: 20px;">
                <h2><?php echo $edit_data ? 'Chỉnh sửa Prompt' : 'Thêm Prompt mới'; ?></h2>
                <form method="post">
                    <?php wp_nonce_field('ai_gemini_prompt_action'); ?>
                    <?php if($edit_data): ?>
                        <input type="hidden" name="prompt_id" value="<?php echo $edit_data->id; ?>">
                    <?php endif; ?>

                    <table class="form-table">
                        <tr>
                            <th><label for="title">Tên Style (Hiển thị) <span style="color:red">*</span></label></th>
                            <td>
                                <input type="text" name="title" id="prompt_title" class="regular-text" required value="<?php echo $edit_data ? esc_attr($edit_data->title) : ''; ?>">
                            </td>
                        </tr>
                        <tr>
                            <th><label for="slug">Slug (Mã định danh) <span style="color:red">*</span></label></th>
                            <td>
                                <input type="text" name="slug" id="prompt_slug" class="regular-text" value="<?php echo $edit_data ? esc_attr($edit_data->slug) : ''; ?>">
                                <p class="description">Tự động tạo từ tên nếu để trống. Dùng trong shortcode: <code>[ai_gemini_generator style="slug"]</code></p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="prompt_text">Prompt Text <span style="color:red">*</span></label></th>
                            <td>
                                <textarea name="prompt_text" id="prompt_text" rows="6" class="large-text" required placeholder="VD: Convert this image into anime style..."><?php echo $edit_data ? esc_textarea($edit_data->prompt_text) : ''; ?></textarea>
                                <p class="description">Đây là câu lệnh gửi cho Gemini AI.</p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="sample_image">Ảnh mẫu (URL)</label></th>
                            <td>
                                <input type="url" name="sample_image" id="sample_image" class="regular-text" value="<?php echo $edit_data ? esc_attr($edit_data->sample_image) : ''; ?>">
                                <p class="description">Link ảnh minh họa cho style này (Không bắt buộc).</p>
                            </td>
                        </tr>
                    </table>
                    
                    <p class="submit">
                        <input type="submit" name="submit_prompt" id="submit" class="button button-primary" value="<?php echo $edit_data ? 'Cập nhật' : 'Thêm mới'; ?>">
                        <a href="<?php echo $base_url; ?>" class="button">Hủy bỏ</a>
                    </p>
                </form>
            </div>

            <!-- Script Auto Slug -->
            <script type="text/javascript">
            jQuery(document).ready(function($) {
                function toSlug(title) {
                    // Chuyển hết sang chữ thường
                    let slug = title.toLowerCase();
                    // Xóa dấu tiếng Việt
                    slug = slug.replace(/[áàảạãăắằẳẵặâấầẩẫậ]/g, 'a');
                    slug = slug.replace(/[éèẻẽẹêếềểễệ]/g, 'e');
                    slug = slug.replace(/[iíìỉĩị]/g, 'i');
                    slug = slug.replace(/[óòỏõọôốồổỗộơớờởỡợ]/g, 'o');
                    slug = slug.replace(/[úùủũụưứừửữự]/g, 'u');
                    slug = slug.replace(/[ýỳỷỹỵ]/g, 'y');
                    slug = slug.replace(/đ/g, 'd');
                    // Xóa ký tự đặc biệt
                    slug = slug.replace(/[^a-z0-9 -]/g, '')
                               .replace(/\s+/g, '-')
                               .replace(/-+/g, '-');
                    return slug;
                }

                // Khi gõ vào Title, tự động điền vào Slug nếu Slug chưa được sửa thủ công
                $('#prompt_title').on('input', function() {
                    let title = $(this).val();
                    let slug = toSlug(title);
                    $('#prompt_slug').val(slug);
                });
            });
            </script>

        <?php 
        // --- VIEW: LIST TABLE ---
        else: 
            // Kiểm tra bảng tồn tại và cột tồn tại chưa
            $has_table = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name;
            if (!$has_table) {
                echo '<div class="error notice"><p>Lỗi: Bảng dữ liệu chưa được tạo. Hãy Deactivate và Activate lại Plugin.</p></div>';
            } else {
                $prompts = $wpdb->get_results("SELECT * FROM $table_name ORDER BY id DESC");
        ?>
            <table class="wp-list-table widefat fixed striped table-view-list">
                <thead>
                    <tr>
                        <th width="50">ID</th>
                        <th width="60">Ảnh</th>
                        <th width="200">Tên Style</th>
                        <th width="150">Slug</th>
                        <th>Prompt Text</th>
                        <th width="150">Hành động</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if($prompts): foreach($prompts as $p): 
                        $edit_link = $base_url . '&action=edit&id=' . $p->id;
                        $delete_link = wp_nonce_url($base_url . '&action=delete&id=' . $p->id, 'delete_prompt_' . $p->id);
                        $display_prompt = isset($p->prompt_text) ? $p->prompt_text : '(Trống)';
                        // Hiển thị ảnh nhỏ
                        $thumb_html = '';
                        if (!empty($p->sample_image)) {
                            $thumb_html = '<img src="' . esc_url($p->sample_image) . '" style="width: 50px; height: 50px; object-fit: cover; border-radius: 4px;">';
                        }
                    ?>
                    <tr>
                        <td><?php echo $p->id; ?></td>
                        <td><?php echo $thumb_html; ?></td>
                        <td>
                            <strong><a href="<?php echo $edit_link; ?>"><?php echo esc_html($p->title); ?></a></strong>
                        </td>
                        <td><code><?php echo esc_html($p->slug); ?></code></td>
                        <td><?php echo esc_html(mb_strimwidth($display_prompt, 0, 80, '...')); ?></td>
                        <td>
                            <a href="<?php echo $edit_link; ?>" class="button button-small">Sửa</a>
                            <a href="<?php echo $delete_link; ?>" class="button button-small button-link-delete" onclick="return confirm('Bạn có chắc muốn xóa style này?');">Xóa</a>
                        </td>
                    </tr>
                    <?php endforeach; else: ?>
                    <tr><td colspan="6">Chưa có prompt nào. Hãy bấm "Thêm Mới".</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        <?php 
            } // end check table
        endif; ?>
    </div>
    <?php
}