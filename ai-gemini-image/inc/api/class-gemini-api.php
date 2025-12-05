<?php
/**
 * AI Gemini Image Generator - Gemini API Class
 * 
 * Handles communication with Google Gemini API for image generation.
 */

if (!defined('ABSPATH')) exit;

/**
 * Class AI_GEMINI_API
 * 
 * Main class for interacting with Google Gemini 2.5 Flash Image API
 */
class AI_GEMINI_API {
    
    const API_BASE_URL     = 'https://generativelanguage.googleapis.com/v1beta';
    const FILES_UPLOAD_URL = 'https://generativelanguage.googleapis.com/upload/v1beta/files';
    const MODEL_NAME       = 'gemini-2.5-flash-image';
    
    private $api_key;
    private $last_error = '';

    /**
     * Giới hạn concurrency ở cấp plugin (thô, dựa trên transient)
     */
    const MAX_CONCURRENT_REQUESTS = 4;          // số request tối đa cùng lúc
    const CONCURRENCY_LOCK_KEY    = 'ai_gemini_active_requests';
    const CONCURRENCY_LOCK_TTL    = 60;         // giây
    const CONCURRENCY_WAIT_STEP   = 0.5;        // giây
    const CONCURRENCY_WAIT_MAX    = 5;          // giây tối đa chờ slot trống

    public function __construct($api_key = null) {
        $this->api_key = $api_key ?: ai_gemini_get_api_key();
    }
    
    public function is_configured() {
        return !empty($this->api_key);
    }
    
    public function get_last_error() {
        return $this->last_error;
    }

    /**
     * Generate image từ dữ liệu ảnh inline (base64).
     * Fallback khi Files API không dùng được.
     */
    public function generate_image_inline($source_image, $prompt, $style = '') {
        if (!$this->is_configured()) {
            $this->last_error = __('API key not configured', 'ai-gemini-image');
            return false;
        }

        $source_image = ai_gemini_validate_image_data($source_image);
        if (!$source_image) {
            $this->last_error = __('Invalid image data', 'ai-gemini-image');
            return false;
        }

        $decoded = base64_decode($source_image, true);
        if ($decoded === false) {
            $this->last_error = __('Failed to decode image data', 'ai-gemini-image');
            return false;
        }

        $optimized = $this->optimize_image_for_api($decoded);
        if (!$optimized || empty($optimized['binary']) || empty($optimized['mime_type'])) {
            $this->last_error = __('Failed to optimize image for API', 'ai-gemini-image');
            return false;
        }

        $optimized_base64 = base64_encode($optimized['binary']);
        $mime_type        = $optimized['mime_type'];

        $full_prompt = $this->build_prompt($prompt, $style);

        $request_body = [
            'contents' => [
                [
                    'parts' => [
                        [
                            'inlineData' => [
                                'mimeType' => $mime_type,
                                'data'     => $optimized_base64,
                            ],
                        ],
                        [
                            'text' => $full_prompt,
                        ],
                    ],
                ],
            ],
            'generationConfig' => [
                'responseModalities' => ['IMAGE'],
            ],
        ];

        $response = $this->make_request('generateContent', $request_body);
        if (!$response) {
            return false;
        }

        return $this->parse_response($response);
    }

    /**
     * Generate image sử dụng Files API (file_uri).
     */
    public function generate_image_from_file($file_uri, $mime_type, $prompt, $style = '') {
        if (!$this->is_configured()) {
            $this->last_error = __('API key not configured', 'ai-gemini-image');
            return false;
        }

        if (empty($file_uri) || empty($mime_type)) {
            $this->last_error = __('Missing file URI or MIME type for Files API', 'ai-gemini-image');
            return false;
        }

        $full_prompt = $this->build_prompt($prompt, $style);

        // REST spec snake_case là file_data/file_uri/mime_type; backend cũng chấp nhận camelCase như inlineData.
        $request_body = [
            'contents' => [
                [
                    'parts' => [
                        [
                            'fileData' => [
                                'mimeType' => $mime_type,
                                'fileUri'  => $file_uri,
                            ],
                        ],
                        [
                            'text' => $full_prompt,
                        ],
                    ],
                ],
            ],
            'generationConfig' => [
                'responseModalities' => ['IMAGE'],
            ],
        ];

        $response = $this->make_request('generateContent', $request_body);
        if (!$response) {
            return false;
        }

        return $this->parse_response($response);
    }

    /**
     * Upload binary image lên Files API (resumable upload).
     *
     * - Dựa sát theo ví dụ REST mà bạn đã test thành công.
     * - Bước 1: start -> lấy X-Goog-Upload-URL
     * - Bước 2: upload+finalize bytes
     *
     * Trả về ['file_uri' => 'https://.../files/xxx', 'mime_type' => 'image/jpeg'] hoặc false.
     */
    public function upload_image_to_files_api($binary, $mime_type = 'image/jpeg', $display_name = 'AI Gemini Image') {
        if (!$this->is_configured()) {
            $this->last_error = __('API key not configured', 'ai-gemini-image');
            return false;
        }

        if (empty($binary)) {
            $this->last_error = __('Empty image data for Files API upload', 'ai-gemini-image');
            return false;
        }

        $num_bytes = strlen($binary);
        if ($num_bytes <= 0) {
            $this->last_error = __('Empty image data (0 bytes) for Files API upload', 'ai-gemini-image');
            return false;
        }

        // ===== STEP 1: START resumable upload =====
        $start_url  = self::FILES_UPLOAD_URL . '?key=' . urlencode($this->api_key);
        $start_body = wp_json_encode([
            'file' => [
                'display_name' => $display_name,
            ],
        ]);

        $start_args = [
            'method'  => 'POST',
            'headers' => [
                'Content-Type'                         => 'application/json',
                'X-Goog-Upload-Protocol'               => 'resumable',
                'X-Goog-Upload-Command'                => 'start',
                'X-Goog-Upload-Header-Content-Length'  => $num_bytes,
                'X-Goog-Upload-Header-Content-Type'    => $mime_type,
            ],
            'body'    => $start_body,
            'timeout' => 30,
        ];

        $start_resp = wp_remote_request($start_url, $start_args);

        if (is_wp_error($start_resp)) {
            $this->last_error = $start_resp->get_error_message();
            ai_gemini_log('Files API start upload failed: ' . $this->last_error, 'error');
            return false;
        }

        $start_code = wp_remote_retrieve_response_code($start_resp);
        $start_hdrs = wp_remote_retrieve_headers($start_resp);
        $start_body_raw = wp_remote_retrieve_body($start_resp);

        if ($start_code !== 200 && $start_code !== 201) {
            ai_gemini_log('Files API start upload error response: ' . $start_body_raw, 'error');
            $data = json_decode($start_body_raw, true);
            $this->last_error = isset($data['error']['message'])
                ? $data['error']['message']
                : sprintf(__('Files API start upload failed: HTTP %d', 'ai-gemini-image'), $start_code);
            return false;
        }

        // Chuẩn hóa headers thành mảng lowercase key để truy cập X-Goog-Upload-URL
        $headers_array = [];

        if (is_array($start_hdrs)) {
            // WP có thể trả array( 'header-name' => 'value' ) hoặc 'header-name' => array('value')
            foreach ($start_hdrs as $k => $v) {
                $lk = strtolower($k);
                if (is_array($v)) {
                    $headers_array[$lk] = $v[0];
                } else {
                    $headers_array[$lk] = $v;
                }
            }
        } elseif (is_object($start_hdrs) && method_exists($start_hdrs, 'getAll')) {
            // Requests_Utility_CaseInsensitiveDictionary
            $all = $start_hdrs->getAll();
            foreach ($all as $k => $v) {
                $lk = strtolower($k);
                if (is_array($v)) {
                    $headers_array[$lk] = $v[0];
                } else {
                    $headers_array[$lk] = $v;
                }
            }
        }

        // Trong HTTP raw bạn gửi, header tên là "X-Goog-Upload-URL" -> lowercase: "x-goog-upload-url"
        $upload_url = '';
        if (!empty($headers_array['x-goog-upload-url'])) {
            $upload_url = $headers_array['x-goog-upload-url'];
        }

        if (empty($upload_url)) {
            $this->last_error = __('Files API start upload response missing upload URL', 'ai-gemini-image');
            ai_gemini_log(
                'Files API missing X-Goog-Upload-URL header. Headers: ' . print_r($headers_array, true),
                'error'
            );
            return false;
        }

        // ===== STEP 2: UPLOAD bytes (upload, finalize) =====
        $upload_args = [
            'method'  => 'POST',
            'headers' => [
                'Content-Length'        => $num_bytes,
                'X-Goog-Upload-Offset'  => 0,
                'X-Goog-Upload-Command' => 'upload, finalize',
                'Content-Type'          => $mime_type,
            ],
            'body'    => $binary,
            'timeout' => 120,
        ];

        $upload_resp = wp_remote_request($upload_url, $upload_args);

        if (is_wp_error($upload_resp)) {
            $this->last_error = $upload_resp->get_error_message();
            ai_gemini_log('Files API upload bytes failed: ' . $this->last_error, 'error');
            return false;
        }

        $upload_code = wp_remote_retrieve_response_code($upload_resp);
        $upload_body = wp_remote_retrieve_body($upload_resp);

        if ($upload_code !== 200 && $upload_code !== 201) {
            ai_gemini_log('Files API upload bytes error response: ' . $upload_body, 'error');
            $data = json_decode($upload_body, true);
            $this->last_error = isset($data['error']['message'])
                ? $data['error']['message']
                : sprintf(__('Files API upload failed: HTTP %d', 'ai-gemini-image'), $upload_code);
            return false;
        }

        $data = json_decode($upload_body, true);
        if (!isset($data['file']['uri'])) {
            $this->last_error = __('Files API upload response missing file URI', 'ai-gemini-image');
            ai_gemini_log('Files API upload invalid response: ' . $upload_body, 'error');
            return false;
        }

        $file_uri  = $data['file']['uri'];       // vd: https://generativelanguage.googleapis.com/v1beta/files/3rv9m0raeybi
        $mime_type = isset($data['file']['mimeType']) ? $data['file']['mimeType'] : $mime_type;

        return [
            'file_uri'  => $file_uri,
            'mime_type' => $mime_type,
        ];
    }

    /**
     * Tối ưu ảnh trước khi gửi Gemini:
     *  - Chuẩn hóa về JPEG chất lượng ~65
     *  - Resize với chiều cao tối đa ~768px, giữ nguyên tỉ lệ
     */
    private function optimize_image_for_api($binary) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->buffer($binary);

        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) {
            return false;
        }

        $src = @imagecreatefromstring($binary);
        if (!$src) {
            return false;
        }

        $width  = imagesx($src);
        $height = imagesy($src);

        if (!$width || !$height) {
            imagedestroy($src);
            return false;
        }

        $max_height = 768;
        $scale      = min($max_height / $height, 1);

        $new_width  = (int) floor($width * $scale);
        $new_height = (int) floor($height * $scale);

        if ($scale < 1) {
            $dst   = imagecreatetruecolor($new_width, $new_height);
            $white = imagecolorallocate($dst, 255, 255, 255);
            imagefill($dst, 0, 0, $white);

            imagecopyresampled(
                $dst,
                $src,
                0,
                0,
                0,
                0,
                $new_width,
                $new_height,
                $width,
                $height
            );

            imagedestroy($src);
            $src = $dst;
        }

        ob_start();
        imagejpeg($src, null, 65);
        $jpeg_data = ob_get_clean();
        imagedestroy($src);

        if (!$jpeg_data) {
            return false;
        }

        return [
            'binary'    => $jpeg_data,
            'mime_type' => 'image/jpeg',
        ];
    }
    
    private function build_prompt($prompt, $style = '') {
        // Không thêm gì vào prompt, hoàn toàn do bạn kiểm soát
        return $prompt;
    }

    /**
     * Cơ chế "batch / queue nhẹ" ở cấp plugin.
     */
    private function make_request($endpoint, $body) {
        $url = sprintf(
            '%s/models/%s:%s?key=%s',
            self::API_BASE_URL,
            self::MODEL_NAME,
            $endpoint,
            $this->api_key
        );
        
        $args = [
            'method'  => 'POST',
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'body'    => wp_json_encode($body),
            'timeout' => 120,
        ];
        
        $max_retries = 2;
        $attempt     = 0;

        // ====== Concurrency control (simple queue) ======
        $start_wait = microtime(true);
        while (true) {
            $active = (int) get_transient(self::CONCURRENCY_LOCK_KEY);

            if ($active < self::MAX_CONCURRENT_REQUESTS) {
                $active++;
                set_transient(self::CONCURRENCY_LOCK_KEY, $active, self::CONCURRENCY_LOCK_TTL);
                break;
            }

            usleep((int) (self::CONCURRENCY_WAIT_STEP * 1_000_000));

            if ((microtime(true) - $start_wait) > self::CONCURRENCY_WAIT_MAX) {
                $this->last_error = __('Server is busy processing too many image requests. Please try again in a moment.', 'ai-gemini-image');
                ai_gemini_log('Concurrency limit reached, rejecting new request.', 'warning');
                return false;
            }
        }
        // ====== END Concurrency control ======

        try {
            do {
                $attempt++;
                ai_gemini_log('Making API request to: ' . $endpoint . ' (attempt ' . $attempt . ')', 'info');

                $response = wp_remote_post($url, $args);

                if (is_wp_error($response)) {
                    $this->last_error = $response->get_error_message();
                    ai_gemini_log('API request failed: ' . $this->last_error, 'error');
                    return false;
                }

                $response_code = wp_remote_retrieve_response_code($response);
                $response_body = wp_remote_retrieve_body($response);

                if ($response_code === 200) {
                    $data = json_decode($response_body, true);

                    if (json_last_error() !== JSON_ERROR_NONE) {
                        $this->last_error = __('Invalid JSON response from API', 'ai-gemini-image');
                        return false;
                    }

                    return $data;
                }

                if ($response_code !== 500) {
                    $error_data = json_decode($response_body, true);
                    $this->last_error = isset($error_data['error']['message']) 
                        ? $error_data['error']['message'] 
                        : sprintf(__('API error: HTTP %d', 'ai-gemini-image'), $response_code);
                    ai_gemini_log('API error response: ' . $response_body, 'error');
                    return false;
                }

                $error_data = json_decode($response_body, true);
                $error_msg  = isset($error_data['error']['message']) ? $error_data['error']['message'] : '';

                ai_gemini_log('API 500 INTERNAL: ' . $error_msg, 'error');

                $should_retry = (strpos($error_msg, 'An internal error has occurred') !== false)
                    || (isset($error_data['error']['status']) && $error_data['error']['status'] === 'INTERNAL');

                if (!$should_retry || $attempt > $max_retries) {
                    $this->last_error = $error_msg ?: sprintf(__('API error: HTTP %d', 'ai-gemini-image'), $response_code);
                    return false;
                }

                usleep(500000); // 0.5s

            } while ($attempt <= $max_retries);
        } finally {
            $active = (int) get_transient(self::CONCURRENCY_LOCK_KEY);
            $active = max(0, $active - 1);
            set_transient(self::CONCURRENCY_LOCK_KEY, $active, self::CONCURRENCY_LOCK_TTL);
        }

        $this->last_error = __('API internal error after retries', 'ai-gemini-image');
        return false;
    }
    
    private function parse_response($response) {
        if (!isset($response['candidates'][0]['content']['parts'])) {
            $this->last_error = __('Invalid response structure', 'ai-gemini-image');
            return false;
        }
        
        $parts = $response['candidates'][0]['content']['parts'];
        $result = [
            'image_data' => null,
            'mime_type'  => null,
            'text'       => '',
        ];
        
        foreach ($parts as $part) {
            if (isset($part['inlineData'])) {
                $result['image_data'] = $part['inlineData']['data'];
                $result['mime_type']  = $part['inlineData']['mimeType'];
            } elseif (isset($part['text'])) {
                $result['text'] = $part['text'];
            }
        }
        
        if (!$result['image_data']) {
            $this->last_error = __('No image data in response', 'ai-gemini-image');
            ai_gemini_log('Response without image: ' . wp_json_encode($response), 'warning');
            return false;
        }
        
        return $result;
    }
    
    public static function get_styles() {
        return [
            'anime'       => __('Anime', 'ai-gemini-image'),
            'cartoon'     => __('3D Cartoon', 'ai-gemini-image'),
            'oil_painting'=> __('Oil Painting', 'ai-gemini-image'),
            'watercolor'  => __('Watercolor', 'ai-gemini-image'),
            'sketch'      => __('Pencil Sketch', 'ai-gemini-image'),
            'pop_art'     => __('Pop Art', 'ai-gemini-image'),
            'cyberpunk'   => __('Cyberpunk', 'ai-gemini-image'),
            'fantasy'     => __('Fantasy', 'ai-gemini-image'),
        ];
    }
    
    public function test_connection() {
        if (!$this->is_configured()) {
            $this->last_error = __('API key not configured', 'ai-gemini-image');
            return false;
        }
        
        $url = sprintf(
            '%s/models?key=%s',
            self::API_BASE_URL,
            $this->api_key
        );
        
        $response = wp_remote_get($url, ['timeout' => 10]);
        
        if (is_wp_error($response)) {
            $this->last_error = $response->get_error_message();
            return false;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        
        if ($response_code !== 200) {
            $this->last_error = sprintf(__('API test failed: HTTP %d', 'ai-gemini-image'), $response_code);
            return false;
        }
        
        return true;
    }
}