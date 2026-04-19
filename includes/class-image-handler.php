<?php
class Thready_Image_Handler {
    
    public static function init() {
        add_filter('intermediate_image_sizes_advanced', [__CLASS__, 'limit_image_sizes'], 10, 1);
        add_filter('wp_get_attachment_metadata', [__CLASS__, 'maybe_generate_sizes_on_demand'], 10, 2);
    }
    
    public static function limit_image_sizes($sizes) {
        $allowed_sizes = [];
        
        foreach (THREADY_IMAGE_SIZES as $size => $dimensions) {
            if ($size !== 'full' && isset($sizes[$size])) {
                $allowed_sizes[$size] = $sizes[$size];
            }
        }
        
        return $allowed_sizes;
    }
    
    public static function maybe_generate_sizes_on_demand($metadata, $attachment_id) {
        // Only process Thready images
        if (!get_post_meta($attachment_id, '_thready_variation_id', true)) {
            return $metadata;
        }
        
        // Check if sizes are missing or incomplete
        $needs_sizes = empty($metadata['sizes']) || 
                       !isset($metadata['sizes']['woocommerce']) || 
                       !isset($metadata['sizes']['thumbnail']);
        
        if (!$needs_sizes) {
            return $metadata;
        }
        
        $filepath = get_attached_file($attachment_id);
        if (!$filepath || !file_exists($filepath)) {
            return $metadata;
        }
        
        // Generate only the specific sizes we need
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        
        // Get the original image size if not set
        if (empty($metadata['width']) || empty($metadata['height'])) {
            $image_size = getimagesize($filepath);
            if ($image_size) {
                $metadata['width'] = $image_size[0];
                $metadata['height'] = $image_size[1];
            }
        }
        
        // Generate only our required sizes
        $metadata['sizes'] = self::generate_specific_sizes($attachment_id, $filepath, $metadata);
        
        // Update the metadata
        wp_update_attachment_metadata($attachment_id, $metadata);
        
        error_log("Thready: Generated on-demand sizes for attachment $attachment_id");
        
        return $metadata;
    }
    
    private static function generate_specific_sizes($attachment_id, $filepath, $metadata) {
        $sizes = [];
        
        // Generate only the sizes defined in THREADY_IMAGE_SIZES (excluding 'full')
        foreach (THREADY_IMAGE_SIZES as $size_name => $dimensions) {
            if ($size_name === 'full') continue;
            
            if (is_array($dimensions)) {
                $generated = image_make_intermediate_size($filepath, $dimensions[0], $dimensions[1], true);
                if ($generated) {
                    $sizes[$size_name] = $generated;
                }
            }
        }
        
        return $sizes;
    }
    
    public static function generate_merged_image($product_id, $variation_id, $settings, $print_image_id = null, $image_type = 'front', $max_dimension = 1200) {
        // Increase memory limit for image processing
        wp_raise_memory_limit('image');
        
        // Use provided print image ID or get from product meta
        if ($print_image_id === null) {
            if ($image_type === 'back') {
                $print_image_id = get_post_meta($product_id, '_thready_back_print_image', true);
            } else {
                $print_image_id = get_post_meta($product_id, '_thready_print_image', true);
            }
        }
        
        $print_url = $print_image_id ? wp_get_attachment_url($print_image_id) : false;
        
        if (!$print_url) {
            error_log("Thready: No print image found for product $product_id");
            return false;
        }
        
        $base_url = $settings['base_image'];
        
        // Use WordPress uploads directory
        $upload_dir = wp_upload_dir();
        
        // Get product and variation data for SEO-friendly filename
        $product = wc_get_product($product_id);
        $product_title = $product ? $product->get_name() : 'product';
        
        // Sanitize the title for filename use — explicitly remove accents
        // first to handle Serbian/Croatian characters (ž, č, ć, š) before
        // sanitize_title strips them
        $clean_title = sanitize_title( remove_accents( $product_title ) );
        
        // Get variation attributes for more descriptive filename
        $variation_attributes = [];
        $variation = wc_get_product($variation_id);
        
        if ($variation) {
            $variation_attributes = $variation->get_attributes();
        }
        
        // Check if this is a light print
        $is_light_print = false;
        $light_print_image_id = get_post_meta($product_id, '_thready_light_print_image', true);
        if ($light_print_image_id && $print_image_id == $light_print_image_id) {
            $is_light_print = true;
        }
        
        // Create descriptive filename components
        $descriptive_parts = [];
        
        // Add product title (limited length)
        $max_title_length = 30;
        if (strlen($clean_title) > $max_title_length) {
            $clean_title = substr($clean_title, 0, $max_title_length);
        }
        $descriptive_parts[] = $clean_title;
        
        // Add variation attributes if available
        if (!empty($variation_attributes)) {
            foreach ($variation_attributes as $attribute) {
                if (!empty($attribute)) {
                    $clean_attr = sanitize_title( remove_accents( $attribute ) );
                    if ( $clean_attr !== '' ) {
                        $descriptive_parts[] = substr($clean_attr, 0, 15);
                    }
                }
            }
        }
        
        // Add light print indicator if applicable
        if ($is_light_print) {
            $descriptive_parts[] = 'light';
        }
        
        // Add image type indicator — always include it in the filename
        // so front, back, featured, gallery all get unique filenames
        // even when product_id + variation_id + timestamp are identical.
        if ($image_type && $image_type !== 'front') {
            $descriptive_parts[] = $image_type;
        }
        
        // Combine all parts
        $descriptive_name = implode('-', $descriptive_parts);
        $descriptive_name = preg_replace('/-+/', '-', $descriptive_name); // Remove duplicate hyphens
        $descriptive_name = trim($descriptive_name, '-'); // Trim leading/trailing hyphens
        
        // Generate unique filename with timestamp to prevent conflicts
        $timestamp = time();
        
        // Check if WebP is supported, otherwise fall back to PNG
        $webp_supported = function_exists('imagewebp');
        $extension = $webp_supported ? 'webp' : 'png';
        $filename = 'thready-' . $descriptive_name . '-' . $product_id . '-' . $variation_id . '-' . $timestamp . '.' . $extension;
        $filepath = $upload_dir['path'] . '/' . $filename;
        
        // Clean up any existing pending images for this variation and type
        self::cleanup_pending_images($variation_id, $image_type, $product_id);
        
        // Create image resources with memory optimization
        $base = self::create_image_resource($base_url);
        if (!$base) {
            error_log("Thready: Failed to create base image resource from $base_url");
            return false;
        }
        
        $print = self::create_image_resource($print_url);
        if (!$print) {
            error_log("Thready: Failed to create print image resource from $print_url");
            imagedestroy($base);
            return false;
        }
        
        // Get dimensions
        $base_width = imagesx($base);
        $base_height = imagesy($base);
        $print_width = imagesx($print);
        $print_height = imagesy($print);
        
        // Optimize: Scale down very large images for faster processing and less memory
        if ($base_width > $max_dimension || $base_height > $max_dimension) {
            $scale_factor = $max_dimension / max($base_width, $base_height);
            $new_width = round($base_width * $scale_factor);
            $new_height = round($base_height * $scale_factor);
            
            $scaled_base = imagescale($base, $new_width, $new_height);
            imagedestroy($base);
            $base = $scaled_base;
            $base_width = $new_width;
            $base_height = $new_height;
        }
        
        // Calculate print dimensions
        $target_width = round(($base_width * $settings['print_width']) / 100);
        $target_height = round(($target_width * $print_height) / $print_width);
        
        // Calculate position with different formulas for positive vs negative X values
        if ($settings['print_x'] > 0) {
            // For positive values: center-aligned (current behavior)
            $pos_x = round(($settings['print_x'] / 100) * $base_width) - round($target_width / 2);
        } else {
            // For negative values: adjusted calculation
            $pos_x = round(($settings['print_x'] / 100) * $base_width) + round($target_width / 2);
        }
        
        // Y position remains top-aligned (no change)
        $pos_y = round(($settings['print_y'] / 100) * $base_height);
        
        // Calculate the visible portion of the print image
        $source_x = 0;
        $source_y = 0;
        $draw_width = $target_width;
        $draw_height = $target_height;

        // Handle left edge cropping
        if ($pos_x < 0) {
            $source_x = abs($pos_x);
            $draw_width = $target_width - $source_x;
            $pos_x = 0;
        }

        // Handle top edge cropping
        if ($pos_y < 0) {
            $source_y = abs($pos_y);
            $draw_height = $target_height - $source_y;
            $pos_y = 0;
        }

        // Handle right edge cropping
        if ($pos_x + $draw_width > $base_width) {
            $draw_width = $base_width - $pos_x;
        }

        // Handle bottom edge cropping
        if ($pos_y + $draw_height > $base_height) {
            $draw_height = $base_height - $pos_y;
        }
        
        // Create result canvas
        $result = imagecreatetruecolor($base_width, $base_height);
        if (!$result) {
            error_log("Thready: Failed to create result canvas");
            imagedestroy($base);
            imagedestroy($print);
            return false;
        }
        
        // Preserve transparency
        imagesavealpha($result, true);
        $transparent = imagecolorallocatealpha($result, 0, 0, 0, 127);
        imagefill($result, 0, 0, $transparent);
        
        // Copy base image
        imagecopy($result, $base, 0, 0, 0, 0, $base_width, $base_height);
        
        // Only apply the print if there's something visible
        if ($draw_width > 0 && $draw_height > 0) {
            // Create scaled print layer
            $scaled_print = imagecreatetruecolor($target_width, $target_height);
            if (!$scaled_print) {
                error_log("Thready: Failed to create scaled print layer");
                imagedestroy($base);
                imagedestroy($print);
                imagedestroy($result);
                return false;
            }
            
            imagesavealpha($scaled_print, true);
            imagefill($scaled_print, 0, 0, imagecolorallocatealpha($scaled_print, 0, 0, 0, 127));
            
            // Resize print image with better quality
            imagecopyresampled(
                $scaled_print, $print,
                0, 0, 0, 0,
                $target_width, $target_height,
                $print_width, $print_height
            );
            
            // Apply cropped print to base image
            imagecopy(
                $result, $scaled_print,
                $pos_x, $pos_y,
                $source_x, $source_y,
                $draw_width, $draw_height
            );
            
            // Clean up scaled print
            imagedestroy($scaled_print);
        }
        
        // Save with appropriate format
        $success = false;
        if ($webp_supported) {
            $success = imagewebp($result, $filepath, 100); // 100% quality for the best visual results
        } else {
            $success = imagepng($result, $filepath, 6);
        }
        
        if ($success) {
            // Clean up resources immediately to free memory
            imagedestroy($base);
            imagedestroy($print);
            imagedestroy($result);
            
            // Create attachment but skip intermediate sizes
            $attachment_id = self::create_minimal_attachment($filepath, $product_id, $filename, $descriptive_name);
            
            if ($attachment_id) {
                // Store comprehensive settings hash for better caching
                $settings_hash = md5(serialize($settings) . $print_image_id . $image_type);
                update_post_meta($attachment_id, '_thready_settings_hash', $settings_hash);
                update_post_meta($attachment_id, '_thready_variation_id', $variation_id);
                update_post_meta($attachment_id, '_thready_is_light_print', $is_light_print ? 'yes' : 'no');
                update_post_meta($attachment_id, '_thready_image_type', $image_type);
                update_post_meta($attachment_id, '_thready_print_image_id', $print_image_id);
                
                return $attachment_id;
            }
        }
        
        // Clean up on failure
        if (isset($base)) imagedestroy($base);
        if (isset($print)) imagedestroy($print);
        if (isset($result)) imagedestroy($result);
        
        error_log("Thready: Failed to save merged image to $filepath");
        return false;
    }
    
    private static function cleanup_pending_images($variation_id, $image_type = 'front', $product_id = 0) {
        // Find existing images for this variation/product and type
        $meta_query = [
            'relation' => 'AND',
            [
                'key' => '_thready_variation_id',
                'value' => $variation_id,
                'compare' => '='
            ],
            [
                'key' => '_thready_image_type',
                'value' => $image_type,
                'compare' => '='
            ]
        ];

        $args = [
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'posts_per_page' => -1,
            'meta_query' => $meta_query,
        ];

        // CRITICAL: When variation_id is 0 (featured images), scope by
        // post_parent so we ONLY delete images belonging to THIS product.
        // Without this, creating product B deletes product A's featured image
        // because both have _thready_variation_id = 0.
        if ( $product_id > 0 ) {
            $args['post_parent'] = $product_id;
        }
        
        $attachments = get_posts($args);
        
        foreach ($attachments as $attachment) {
            $filename = get_post_meta($attachment->ID, '_wp_attached_file', true);
            $file_path = wp_upload_dir()['basedir'] . '/' . $filename;
            
            // Delete the physical file
            if (file_exists($file_path)) {
                wp_delete_file($file_path);
            }
            
            // Delete the attachment post
            wp_delete_attachment($attachment->ID, true);
        }
    }
    
    private static function create_minimal_attachment($filepath, $parent_id, $filename, $descriptive_name) {
        $wp_filetype = wp_check_filetype($filename, null);
        
        // Create cleaner title and alt text without variation ID and timestamp
        $clean_title = 'thready-' . $descriptive_name;
        $clean_title = sanitize_text_field($clean_title);

        $attachment = [
            'post_mime_type' => $wp_filetype['type'],
            'post_title' => $clean_title,
            'post_content' => '',
            'post_status' => 'inherit'
        ];
        
        $attach_id = wp_insert_attachment($attachment, $filepath, $parent_id);
        
        if (is_wp_error($attach_id)) {
            error_log("Thready: Attachment creation failed - " . $attach_id->get_error_message());
            return false;
        }
        
        // Generate minimal metadata only (No image sizes)
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        
        // Get basic image data without generating sizes
        $image_size = getimagesize($filepath);
        $metadata = [
            'width' => $image_size[0],
            'height' => $image_size[1],
            'file' => _wp_relative_upload_path($filepath),
            'sizes' => [] // Empty sizes array to skip generation
        ];
        
        wp_update_attachment_metadata($attach_id, $metadata);
        
        // Also update the alt text
        update_post_meta($attach_id, '_wp_attachment_image_alt', $clean_title);
        
        return $attach_id;
    }
    
    private static function create_image_resource($image_url) {
        if (!filter_var($image_url, FILTER_VALIDATE_URL)) {
            // Already a local path — accept it
            if ( ! file_exists( $image_url ) ) {
                error_log("Thready: Invalid image URL - $image_url");
                return false;
            }
        } else {
            // Use local path if possible for better performance AND to avoid
            // URL-encoded UTF-8 sequences (e.g. %C5%BE for ž) that file_exists
            // and GD can't resolve.
            $upload_dir = wp_upload_dir();
            $local_path = str_replace($upload_dir['baseurl'], $upload_dir['basedir'], $image_url);
            $local_path = rawurldecode($local_path);

            if (file_exists($local_path)) {
                $image_url = $local_path;
            }
        }

        $image_info = @getimagesize($image_url);
        
        if (!$image_info) {
            error_log("Thready: Could not get image size for $image_url");
            return false;
        }
        
        $mime_type = $image_info['mime'];
        
        switch ($mime_type) {
            case 'image/jpeg':
                return @imagecreatefromjpeg($image_url);
            case 'image/png':
                $image = @imagecreatefrompng($image_url);
                if ($image) {
                    imagealphablending($image, true);
                    imagesavealpha($image, true);
                }
                return $image;
            case 'image/gif':
                return @imagecreatefromgif($image_url);
            case 'image/webp':
                if (function_exists('imagecreatefromwebp')) {
                    $image = @imagecreatefromwebp($image_url);
                    if ($image) {
                        imagealphablending($image, true);
                        imagesavealpha($image, true);
                    }
                    return $image;
                }
                error_log("Thready: WebP not supported - $image_url");
                return false;
            default:
                error_log("Thready: Unsupported image type ($mime_type) for $image_url");
                return false;
        }
    }
}