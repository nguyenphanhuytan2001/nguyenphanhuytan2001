<?php
/**
 * AI Gemini Image Generator - Watermark & Image Versions
 *
 * Lưu:
 * - original (1K gốc từ Gemini, không watermark, trong originals/ - chặn truy cập trực tiếp)
 * - preview 512px (JPEG + watermark chéo kiểu Shutterstock, public)
 */

if (!defined('ABSPATH')) exit;

/**
 * Áp watermark kiểu Shutterstock (pattern chữ chéo phủ ảnh) trực tiếp lên resource GD (preview 512px)
 *
 * @param resource|GdImage $image
 * @param string|null      $watermark_text
 */
function ai_gemini_apply_watermark_gd($image, $watermark_text = null) {
    if (!is_resource($image) && !($image instanceof GdImage)) {
        return;
    }

    $settings = ai_gemini_get_watermark_settings();
    $text     = $watermark_text ?: (!empty($settings['text']) ? $settings['text'] : 'AI Gemini Preview');

    $width  = imagesx($image);
    $height = imagesy($image);

    // Màu chữ rất trong suốt
    $color_main   = imagecolorallocatealpha($image, 255, 255, 255, 90);  // trắng mờ
    $color_shadow = imagecolorallocatealpha($image,   0,   0,   0, 100); // bóng đen mờ

    $font_file = AI_GEMINI_PLUGIN_DIR . 'assets/fonts/OpenSans-Bold.ttf';

    // Fallback nếu không có font TTF
    if (!file_exists($font_file) || !function_exists('imagettftext')) {
        $font        = 3;
        $text_width  = imagefontwidth($font) * strlen($text);
        $text_height = imagefontheight($font);

        $step_x = max(160, $text_width + 40);
        $step_y = max(80,  $text_height + 40);

        for ($y = -$step_y; $y < $height + $step_y; $y += $step_y) {
            for ($x = -$step_x; $x < $width + $step_x; $x += $step_x) {
                imagestring($image, $font, $x + 1, $y + 1, $text, $color_shadow);
                imagestring($image, $font, $x,     $y,     $text, $color_main);
            }
        }
        return;
    }

    $base      = max($width, $height);
    $font_size = max(14, min(26, $base / 20));

    $bbox   = imagettfbbox($font_size, 0, $font_file, $text);
    $text_w = abs($bbox[4] - $bbox[0]);
    $text_h = abs($bbox[5] - $bbox[1]);

    $diag_angle = 30; // watermark nghiêng khoảng 30 độ

    $step_x = max(180, $text_w + 40);
    $step_y = max(140, $text_h + 40);

    for ($y = -$height; $y < $height * 2; $y += $step_y) {
        for ($x = -$width; $x < $width * 2; $x += $step_x) {
            @imagettftext($image, $font_size, $diag_angle, $x + 2, $y + 2, $color_shadow, $font_file, $text);
            @imagettftext($image, $font_size, $diag_angle, $x,     $y,     $color_main,   $font_file, $text);
        }
    }
}

/**
 * Hàm cũ: Add watermark từ binary → binary (fallback)
 */
function ai_gemini_add_watermark($image_data, $watermark_text = null) {
    $image = @imagecreatefromstring($image_data);
    if (!$image) {
        ai_gemini_log('Failed to create image from string for watermark', 'error');
        return $image_data;
    }

    ai_gemini_apply_watermark_gd($image, $watermark_text);

    ob_start();
    imagejpeg($image, null, 70);
    $watermarked_data = ob_get_clean();

    imagedestroy($image);
    return $watermarked_data;
}

/**
 * Lấy bản gốc (không watermark) từ thư mục originals, dựa theo image record
 */
function ai_gemini_get_original_path($image) {
    $upload_dir = ai_gemini_get_upload_dir();

    // Ưu tiên từ original_image_url, nếu không có thì fallback từ preview_image_url
    $base_url = !empty($image->original_image_url) ? $image->original_image_url : $image->preview_image_url;
    $filename = basename($base_url);

    // preview-xxx-preview.jpg -> preview-xxx-original.ext
    $orig_name = str_replace('-preview.jpg', '-original.' . pathinfo($filename, PATHINFO_EXTENSION), $filename);
    if ($orig_name === $filename) {
        // fallback chung: -preview. -> -original.
        $orig_name = str_replace('-preview.', '-original.', $filename);
    }

    $original_path = $upload_dir['path'] . '/originals/' . $orig_name;
    return $original_path;
}

/**
 * Store:
 * - original (1K gốc không watermark, trong originals/)
 * - preview 512px (jpeg + watermark)
 *
 * Không tạo bản full copy (tiết kiệm dung lượng).
 *
 * @param string $image_data       Binary từ Gemini
 * @param string $preview_filename Tên file preview gốc (ví dụ preview-uuid.png)
 * @return array
 */
function ai_gemini_store_image_versions($image_data, $preview_filename) {
    $t_start = microtime(true);
    ai_gemini_log('ai_gemini_store_image_versions start for ' . $preview_filename, 'info');

    $upload_dir = ai_gemini_get_upload_dir();
    
    // 1. originals/ (bản gốc không watermark, giữ nguyên định dạng)
    $originals_dir = $upload_dir['path'] . '/originals';
    if (!file_exists($originals_dir)) {
        wp_mkdir_p($originals_dir);
        // Chặn truy cập trực tiếp
        file_put_contents($originals_dir . '/.htaccess', "Deny from all\n");
    }

    $path_info   = pathinfo($preview_filename);
    $ext         = isset($path_info['extension']) ? $path_info['extension'] : 'png';
    $name_no_ext = $path_info['filename']; // preview-uuid

    // original: preview-xxx-original.[ext]
    $original_filename = $name_no_ext . '-original.' . $ext;
    // preview:  preview-xxx-preview.jpg
    $preview_jpg_name  = $name_no_ext . '-preview.jpg';
    
    // Lưu original
    $t0 = microtime(true);
    $original_path = $originals_dir . '/' . $original_filename;
    file_put_contents($original_path, $image_data);
    $t1 = microtime(true);
    ai_gemini_log('Original save took ' . round(($t1 - $t0) * 1000, 2) . ' ms', 'info');
    
    // 2. Tạo preview 512px (jpeg + watermark)
    $src = @imagecreatefromstring($image_data);
    $preview_path = null;
    $preview_url  = null;

    if ($src) {
        $width  = imagesx($src);
        $height = imagesy($src);
        ai_gemini_log('Source size: ' . $width . 'x' . $height, 'info');

        $t4 = microtime(true);

        $max_preview = 512;
        $long_side   = max($width, $height);
        $scale_prev  = ($long_side > $max_preview) ? ($max_preview / $long_side) : 1.0;

        $prev_w = (int) round($width  * $scale_prev);
        $prev_h = (int) round($height * $scale_prev);

        $preview_img = imagecreatetruecolor($prev_w, $prev_h);
        imagecopyresampled($preview_img, $src, 0, 0, 0, 0, $prev_w, $prev_h, $width, $height);

        ai_gemini_apply_watermark_gd($preview_img);

        $preview_path = $upload_dir['path'] . '/' . $preview_jpg_name;
        $preview_url  = $upload_dir['url']  . '/' . $preview_jpg_name;

        ob_start();
        imagejpeg($preview_img, null, 70);
        $preview_data = ob_get_clean();
        file_put_contents($preview_path, $preview_data);

        imagedestroy($preview_img);
        imagedestroy($src);

        $t5 = microtime(true);
        ai_gemini_log(
            'Preview 512px (resize + Shutterstock watermark + save) took ' .
            round(($t5 - $t4) * 1000, 2) . ' ms; final size ' .
            $prev_w . 'x' . $prev_h,
            'info'
        );
    } else {
        ai_gemini_log('Failed to create GD image from image_data in store_image_versions', 'error');
        $watermarked_data = ai_gemini_add_watermark($image_data);
        $preview_path     = $upload_dir['path'] . '/' . $preview_jpg_name;
        $preview_url      = $upload_dir['url']  . '/' . $preview_jpg_name;
        file_put_contents($preview_path, $watermarked_data);
    }

    $t_end = microtime(true);
    ai_gemini_log(
        'ai_gemini_store_image_versions total took ' .
        round(($t_end - $t_start) * 1000, 2) . ' ms',
        'info'
    );

    return [
        'preview_path' => $preview_path,
        'preview_url'  => $preview_url,
        'original_path'=> $original_path,
        'full_path'    => null,
        'full_url'     => null, // KHÔNG DÙNG NỮA
    ];
}

/**
 * Get watermark settings
 */
function ai_gemini_get_watermark_settings() {
    return [
        'text'     => get_option('ai_gemini_watermark_text', 'AI Gemini Preview'),
        'position' => get_option('ai_gemini_watermark_position', 'bottom-right'),
        'opacity'  => (int) get_option('ai_gemini_watermark_opacity', 50),
        'diagonal' => get_option('ai_gemini_watermark_diagonal', 'yes') === 'yes',
    ];
}

/**
 * Update watermark settings
 */
function ai_gemini_update_watermark_settings($settings) {
    if (isset($settings['text'])) {
        update_option('ai_gemini_watermark_text', sanitize_text_field($settings['text']));
    }
    if (isset($settings['position'])) {
        update_option('ai_gemini_watermark_position', sanitize_text_field($settings['position']));
    }
    if (isset($settings['opacity'])) {
        update_option('ai_gemini_watermark_opacity', absint($settings['opacity']));
    }
    if (isset($settings['diagonal'])) {
        update_option('ai_gemini_watermark_diagonal', $settings['diagonal'] ? 'yes' : 'no');
    }
    
    return true;
}