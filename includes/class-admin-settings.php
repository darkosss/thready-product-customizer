<?php
class Thready_Admin_Settings {
    
    public static function init() {
        add_action('woocommerce_product_options_general_product_data', [__CLASS__, 'add_print_image_field']);
        add_action('woocommerce_process_product_meta', [__CLASS__, 'save_print_image']);
        add_action('woocommerce_variation_options_pricing', [__CLASS__, 'add_variation_settings_fields'], 10, 3);
        add_action('woocommerce_save_product_variation', [__CLASS__, 'save_variation_settings_fields'], 10, 2);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_admin_scripts']);
    }
    
    public static function enqueue_admin_scripts() {
        $screen = get_current_screen();
        
        if ($screen && $screen->id === 'product') {
            wp_enqueue_style('thready-admin', THREADY_PC_URL . 'assets/css/admin.css', [], THREADY_PC_VERSION);
            wp_enqueue_script('thready-admin', THREADY_PC_URL . 'assets/js/admin.js', ['jquery'], THREADY_PC_VERSION, true);
        }
    }
    
    public static function add_print_image_field() {
        global $post;
        $print_image_id = get_post_meta($post->ID, '_thready_print_image', true);
        $light_print_image_id = get_post_meta($post->ID, '_thready_light_print_image', true);
        $back_print_image_id = get_post_meta($post->ID, '_thready_back_print_image', true);
        ?>
        <div class="options_group thready-print-settings">
            <!-- DEFAULT PRINT IMAGE -->
            <p class="form-field">
                <label for="thready_print_image"><?php esc_html_e('Print Design Image', 'thready-product-customizer'); ?></label>
                <span id="thready_print_image_container">
                    <?php if ($print_image_id): ?>
                        <?php echo wp_get_attachment_image($print_image_id, 'thumbnail'); ?>
                    <?php endif; ?>
                </span>
                <input type="hidden" name="_thready_print_image" id="thready_print_image" value="<?php echo esc_attr($print_image_id); ?>">
                <button type="button" class="button" id="upload_print_image"><?php esc_html_e('Upload/Change', 'thready-product-customizer'); ?></button>
                <button type="button" class="button" id="remove_print_image" style="<?php echo $print_image_id ? '' : 'display:none;'; ?>"><?php esc_html_e('Remove', 'thready-product-customizer'); ?></button>
                <span class="description"><?php esc_html_e('Upload a transparent PNG of the print design', 'thready-product-customizer'); ?></span>
            </p>
            
            <!-- LIGHT PRINT IMAGE -->
            <p class="form-field">
                <label for="thready_light_print_image"><?php esc_html_e('Light Print Design Image', 'thready-product-customizer'); ?></label>
                <span id="thready_light_print_image_container">
                    <?php if ($light_print_image_id): ?>
                        <?php echo wp_get_attachment_image($light_print_image_id, 'thumbnail'); ?>
                    <?php endif; ?>
                </span>
                <input type="hidden" name="_thready_light_print_image" id="thready_light_print_image" value="<?php echo esc_attr($light_print_image_id); ?>">
                <button type="button" class="button" id="upload_light_print_image"><?php esc_html_e('Upload/Change', 'thready-product-customizer'); ?></button>
                <button type="button" class="button" id="remove_light_print_image" style="<?php echo $light_print_image_id ? '' : 'display:none;'; ?>"><?php esc_html_e('Remove', 'thready-product-customizer'); ?></button>
                <span class="description"><?php esc_html_e('Upload a transparent PNG of the light print design (optional)', 'thready-product-customizer'); ?></span>
            </p>
            
            <!-- BACK PRINT IMAGE -->
            <p class="form-field">
                <label for="thready_back_print_image"><?php esc_html_e('Back Print Design Image', 'thready-product-customizer'); ?></label>
                <span id="thready_back_print_image_container">
                    <?php if ($back_print_image_id): ?>
                        <?php echo wp_get_attachment_image($back_print_image_id, 'thumbnail'); ?>
                    <?php endif; ?>
                </span>
                <input type="hidden" name="_thready_back_print_image" id="thready_back_print_image" value="<?php echo esc_attr($back_print_image_id); ?>">
                <button type="button" class="button" id="upload_back_print_image"><?php esc_html_e('Upload/Change', 'thready-product-customizer'); ?></button>
                <button type="button" class="button" id="remove_back_print_image" style="<?php echo $back_print_image_id ? '' : 'display:none;'; ?>"><?php esc_html_e('Remove', 'thready-product-customizer'); ?></button>
                <span class="description"><?php esc_html_e('Upload a transparent PNG of the back print design (optional)', 'thready-product-customizer'); ?></span>
            </p>
            
            <!-- DEFAULT FRONT PRINT SETTINGS -->
            <p class="form-field">
                <label><?php esc_html_e('Default Front Print Settings', 'thready-product-customizer'); ?></label>
                <span class="description"><?php esc_html_e('Used when no variation is selected', 'thready-product-customizer'); ?></span>
            </p>
            
            <p class="form-field">
                <label for="_thready_default_print_x"><?php esc_html_e('Position X', 'thready-product-customizer'); ?></label>
                <input type="number" name="_thready_default_print_x" id="_thready_default_print_x" 
                    value="<?php echo esc_attr(get_post_meta($post->ID, '_thready_default_print_x', true) ?: '50'); ?>"
                    min="-100" max="100" step="1" required>
                <span class="description"><?php esc_html_e('Percentage from left (-100 to 100). Negative values position outside left edge.', 'thready-product-customizer'); ?></span>
            </p>
            
            <p class="form-field">
                <label for="_thready_default_print_y"><?php esc_html_e('Position Y', 'thready-product-customizer'); ?></label>
                <input type="number" name="_thready_default_print_y" id="_thready_default_print_y" 
                    value="<?php echo esc_attr(get_post_meta($post->ID, '_thready_default_print_y', true) ?: '25'); ?>"
                    min="-100" max="100" step="1" required>
                <span class="description"><?php esc_html_e('Percentage from top (-100 to 100). Negative values position outside top edge.', 'thready-product-customizer'); ?></span>
            </p>
            
            <p class="form-field">
                <label for="_thready_default_print_width"><?php esc_html_e('Width', 'thready-product-customizer'); ?></label>
                <input type="number" name="_thready_default_print_width" id="_thready_default_print_width" 
                    value="<?php echo esc_attr(get_post_meta($post->ID, '_thready_default_print_width', true) ?: '50'); ?>"
                    min="1" max="100" step="1" required>
                <span class="description"><?php esc_html_e('Percentage of base image width (1-100)', 'thready-product-customizer'); ?></span>
            </p>
            
            <!-- DEFAULT BACK PRINT SETTINGS -->
            <p class="form-field">
                <label><?php esc_html_e('Default Back Print Settings', 'thready-product-customizer'); ?></label>
                <span class="description"><?php esc_html_e('Used for back print when no variation settings specified', 'thready-product-customizer'); ?></span>
            </p>
            
            <p class="form-field">
                <label for="_thready_default_back_print_x"><?php esc_html_e('Position X', 'thready-product-customizer'); ?></label>
                <input type="number" name="_thready_default_back_print_x" id="_thready_default_back_print_x" 
                    value="<?php echo esc_attr(get_post_meta($post->ID, '_thready_default_back_print_x', true) ?: '50'); ?>"
                    min="-100" max="100" step="1" required>
                <span class="description"><?php esc_html_e('Percentage from left (-100 to 100). Negative values position outside left edge.', 'thready-product-customizer'); ?></span>
            </p>
            
            <p class="form-field">
                <label for="_thready_default_back_print_y"><?php esc_html_e('Position Y', 'thready-product-customizer'); ?></label>
                <input type="number" name="_thready_default_back_print_y" id="_thready_default_back_print_y" 
                    value="<?php echo esc_attr(get_post_meta($post->ID, '_thready_default_back_print_y', true) ?: '25'); ?>"
                    min="-100" max="100" step="1" required>
                <span class="description"><?php esc_html_e('Percentage from top (-100 to 100). Negative values position outside top edge.', 'thready-product-customizer'); ?></span>
            </p>
            
            <p class="form-field">
                <label for="_thready_default_back_print_width"><?php esc_html_e('Width', 'thready-product-customizer'); ?></label>
                <input type="number" name="_thready_default_back_print_width" id="_thready_default_back_print_width" 
                    value="<?php echo esc_attr(get_post_meta($post->ID, '_thready_default_back_print_width', true) ?: '50'); ?>"
                    min="1" max="100" step="1" required>
                <span class="description"><?php esc_html_e('Percentage of base image width (1-100)', 'thready-product-customizer'); ?></span>
            </p>
        </div>
        <script>
            jQuery(document).ready(function($) {
                // Default print image
                $('#upload_print_image').click(function() {
                    var frame = wp.media({
                        title: '<?php esc_js(__('Select Print Image', 'thready-product-customizer')); ?>',
                        multiple: false,
                        library: { type: 'image' }
                    });
                    
                    frame.on('select', function() {
                        var attachment = frame.state().get('selection').first().toJSON();
                        $('#thready_print_image_container').html('<img src="' + attachment.sizes.thumbnail.url + '" />');
                        $('#thready_print_image').val(attachment.id);
                        $('#remove_print_image').show();
                    });
                    
                    frame.open();
                });
                
                $('#remove_print_image').click(function() {
                    $('#thready_print_image_container').html('');
                    $('#thready_print_image').val('');
                    $(this).hide();
                });
                
                // Light print image
                $('#upload_light_print_image').click(function() {
                    var frame = wp.media({
                        title: '<?php esc_js(__('Select Light Print Image', 'thready-product-customizer')); ?>',
                        multiple: false,
                        library: { type: 'image' }
                    });
                    
                    frame.on('select', function() {
                        var attachment = frame.state().get('selection').first().toJSON();
                        $('#thready_light_print_image_container').html('<img src="' + attachment.sizes.thumbnail.url + '" />');
                        $('#thready_light_print_image').val(attachment.id);
                        $('#remove_light_print_image').show();
                    });
                    
                    frame.open();
                });
                
                $('#remove_light_print_image').click(function() {
                    $('#thready_light_print_image_container').html('');
                    $('#thready_light_print_image').val('');
                    $(this).hide();
                });
                
                // Back print image
                $('#upload_back_print_image').click(function() {
                    var frame = wp.media({
                        title: '<?php esc_js(__('Select Back Print Image', 'thready-product-customizer')); ?>',
                        multiple: false,
                        library: { type: 'image' }
                    });
                    
                    frame.on('select', function() {
                        var attachment = frame.state().get('selection').first().toJSON();
                        $('#thready_back_print_image_container').html('<img src="' + attachment.sizes.thumbnail.url + '" />');
                        $('#thready_back_print_image').val(attachment.id);
                        $('#remove_back_print_image').show();
                    });
                    
                    frame.open();
                });
                
                $('#remove_back_print_image').click(function() {
                    $('#thready_back_print_image_container').html('');
                    $('#thready_back_print_image').val('');
                    $(this).hide();
                });
            });
        </script>
        <?php
    }
    
    public static function save_print_image($product_id) {
        if (isset($_POST['_thready_print_image'])) {
            update_post_meta($product_id, '_thready_print_image', absint($_POST['_thready_print_image']));
        }
        
        if (isset($_POST['_thready_light_print_image'])) {
            update_post_meta($product_id, '_thready_light_print_image', absint($_POST['_thready_light_print_image']));
        }
        
        if (isset($_POST['_thready_back_print_image'])) {
            update_post_meta($product_id, '_thready_back_print_image', absint($_POST['_thready_back_print_image']));
        }
        
        // Save default front settings with validation
        $front_settings = [
            '_thready_default_print_x' => 50,
            '_thready_default_print_y' => 25,
            '_thready_default_print_width' => 50
        ];
        
        foreach ($front_settings as $key => $default) {
            if (isset($_POST[$key])) {
                $value = intval($_POST[$key]);
                
                // Validate ranges - allow -100 to 100 for position, 1-100 for width
                if ($key === '_thready_default_print_width') {
                    $value = max(1, min(100, $value));
                } else {
                    $value = max(-100, min(100, $value));
                }
                
                update_post_meta($product_id, $key, $value);
            }
        }
        
        // Save default back settings with validation
        $back_settings = [
            '_thready_default_back_print_x' => 50,
            '_thready_default_back_print_y' => 25,
            '_thready_default_back_print_width' => 50
        ];
        
        foreach ($back_settings as $key => $default) {
            if (isset($_POST[$key])) {
                $value = intval($_POST[$key]);
                
                // Validate ranges - allow -100 to 100 for position, 1-100 for width
                if (strpos($key, '_width') !== false) {
                    $value = max(1, min(100, $value));
                } else {
                    $value = max(-100, min(100, $value));
                }
                
                update_post_meta($product_id, $key, $value);
            }
        }
    }
    
    public static function add_variation_settings_fields($loop, $variation_data, $variation) {
        $parent_id = $variation->post_parent;
        $print_image_id = get_post_meta($parent_id, '_thready_print_image', true);
        $light_print_image_id = get_post_meta($parent_id, '_thready_light_print_image', true);
        $back_print_image_id = get_post_meta($parent_id, '_thready_back_print_image', true);
        
        // Only show Thready settings if at least one print image exists
        if (!$print_image_id && !$light_print_image_id && !$back_print_image_id) return;
        
        $variation_id = $variation->ID;
        
        // Get current values - use empty string if using defaults
        $print_x = get_post_meta($variation_id, '_thready_print_x', true);
        $print_y = get_post_meta($variation_id, '_thready_print_y', true);
        $print_width = get_post_meta($variation_id, '_thready_print_width', true);
        $use_light_print = get_post_meta($variation_id, '_thready_use_light_print', true);
        $available_sizes = get_post_meta($variation_id, '_thready_available_sizes', true);
        
        // Back settings
        $back_base_image_id = get_post_meta($variation_id, '_thready_back_base_image_id', true);
        $back_print_x = get_post_meta($variation_id, '_thready_back_print_x', true);
        $back_print_y = get_post_meta($variation_id, '_thready_back_print_y', true);
        $back_print_width = get_post_meta($variation_id, '_thready_back_print_width', true);
        
        // Get default values for placeholders
        $defaults = self::get_default_settings($parent_id);
        $back_defaults = self::get_default_back_settings($parent_id);
        
        // Get all possible sizes from the size attribute
        $all_sizes = [];
        $size_terms = get_terms([
            'taxonomy' => 'pa_velicina',
            'hide_empty' => false
        ]);
        
        foreach ($size_terms as $term) {
            $all_sizes[$term->slug] = $term->name;
        }
        
        echo '<div class="thready-settings" style="border-top: 1px solid #eee; padding-top: 15px; margin-top: 15px;">';
        echo '<h4 style="margin: 0 0 10px 0; clear: both;">' . __('Front Print Settings', 'thready-product-customizer') . '</h4>';
        
        // Position settings
        woocommerce_wp_text_input([
            'id' => "_thready_print_x_{$loop}",
            'name' => "_thready_print_x[{$loop}]",
            'label' => __('Print Position X', 'thready-product-customizer'),
            'value' => $print_x !== '' ? $print_x : '',
            'placeholder' => __('Default: ', 'thready-product-customizer') . $defaults['print_x'] . '%',
            'type' => 'number',
            'custom_attributes' => array(
                'step' => '1',
                'min' => '-100',
                'max' => '100'
            ),
            'description' => __('Percentage from left (-100 to 100). Negative values position outside left edge.', 'thready-product-customizer'),
            'wrapper_class' => 'form-row form-row-first'
        ]);
        
        woocommerce_wp_text_input([
            'id' => "_thready_print_y_{$loop}",
            'name' => "_thready_print_y[{$loop}]",
            'label' => __('Print Position Y', 'thready-product-customizer'),
            'value' => $print_y !== '' ? $print_y : '',
            'placeholder' => __('Default: ', 'thready-product-customizer') . $defaults['print_y'] . '%',
            'type' => 'number',
            'custom_attributes' => array(
                'step' => '1',
                'min' => '-100',
                'max' => '100'
            ),
            'description' => __('Percentage from top (-100 to 100). Negative values position outside top edge.', 'thready-product-customizer'),
            'wrapper_class' => 'form-row form-row-last'
        ]);
        
        // Size setting
        woocommerce_wp_text_input([
            'id' => "_thready_print_width_{$loop}",
            'name' => "_thready_print_width[{$loop}]",
            'label' => __('Print Width', 'thready-product-customizer'),
            'value' => $print_width !== '' ? $print_width : '',
            'placeholder' => __('Default: ', 'thready-product-customizer') . $defaults['print_width'] . '%',
            'type' => 'number',
            'custom_attributes' => array(
                'step' => '1',
                'min' => '1',
                'max' => '100'
            ),
            'description' => __('Percentage of base image width (1-100)', 'thready-product-customizer'),
            'wrapper_class' => 'form-row form-row-first'
        ]);
        
        // Light print checkbox (only show if light print image exists)
        if ($light_print_image_id) {
            woocommerce_wp_checkbox([
                'id' => "_thready_use_light_print_{$loop}",
                'name' => "_thready_use_light_print[{$loop}]",
                'label' => __('Use Light Print', 'thready-product-customizer'),
                'value' => $use_light_print ? 'yes' : 'no',
                'description' => __('Check to use light print image for this variation', 'thready-product-customizer'),
                'wrapper_class' => 'form-row form-row-last'
            ]);
        }
        
        // Available sizes selector
        echo '<div class="form-row form-row-full">';
        echo '<label for="_thready_available_sizes_' . $loop . '">' . __('Available Sizes', 'thready-product-customizer') . '</label>';
        echo '<div style="max-height: 120px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; background: #fff;">';
        
        if (!empty($all_sizes)) {
            $available_sizes_array = !empty($available_sizes) ? explode(',', $available_sizes) : [];
            
            foreach ($all_sizes as $slug => $name) {
                $checked = in_array($slug, $available_sizes_array) ? 'checked' : '';
                echo '<label style="display: block; margin-bottom: 5px;">';
                echo '<input type="checkbox" name="_thready_available_sizes[' . $loop . '][]" value="' . esc_attr($slug) . '" ' . $checked . '> ';
                echo esc_html($name);
                echo '</label>';
            }
        } else {
            echo '<p>' . __('No sizes found. Please add sizes to the size attribute.', 'thready-product-customizer') . '</p>';
        }
        
        echo '</div>';
        echo '<span class="description">' . __('Select which sizes are available for this variation', 'thready-product-customizer') . '</span>';
        echo '</div>';
        
        // BACK PRINT SETTINGS
        if ($back_print_image_id) {
            echo '<div style="border-top: 1px solid #eee; padding-top: 15px; margin-top: 15px;">';
            echo '<h4 style="margin: 0 0 10px 0;">' . __('Back Print Settings', 'thready-product-customizer') . '</h4>';
            
            // Back base image upload
            echo '<div class="form-row form-row-full">';
            echo '<label>' . __('Back Base Image', 'thready-product-customizer') . '</label>';
            echo '<div id="thready_back_base_image_container_' . $loop . '" style="margin: 5px 0;">';
            if ($back_base_image_id) {
                echo wp_get_attachment_image($back_base_image_id, 'thumbnail');
            }
            echo '</div>';
            echo '<input type="hidden" name="_thready_back_base_image_id[' . $loop . ']" id="thready_back_base_image_id_' . $loop . '" value="' . esc_attr($back_base_image_id) . '">';
            echo '<button type="button" class="button upload_back_base_image" data-loop="' . $loop . '">' . __('Upload/Change', 'thready-product-customizer') . '</button>';
            echo '<button type="button" class="button remove_back_base_image" data-loop="' . $loop . '" style="' . ($back_base_image_id ? '' : 'display:none;') . '">' . __('Remove', 'thready-product-customizer') . '</button>';
            echo '<span class="description">' . __('Upload back base image for this variation (optional)', 'thready-product-customizer') . '</span>';
            echo '</div>';
            
            // Back position settings
            woocommerce_wp_text_input([
                'id' => "_thready_back_print_x_{$loop}",
                'name' => "_thready_back_print_x[{$loop}]",
                'label' => __('Back Print Position X', 'thready-product-customizer'),
                'value' => $back_print_x !== '' ? $back_print_x : '',
                'placeholder' => __('Default: ', 'thready-product-customizer') . $back_defaults['print_x'] . '%',
                'type' => 'number',
                'custom_attributes' => array(
                    'step' => '1',
                    'min' => '-100',
                    'max' => '100'
                ),
                'description' => __('Percentage from left (-100 to 100). Negative values position outside left edge.', 'thready-product-customizer'),
                'wrapper_class' => 'form-row form-row-first'
            ]);
            
            woocommerce_wp_text_input([
                'id' => "_thready_back_print_y_{$loop}",
                'name' => "_thready_back_print_y[{$loop}]",
                'label' => __('Back Print Position Y', 'thready-product-customizer'),
                'value' => $back_print_y !== '' ? $back_print_y : '',
                'placeholder' => __('Default: ', 'thready-product-customizer') . $back_defaults['print_y'] . '%',
                'type' => 'number',
                'custom_attributes' => array(
                    'step' => '1',
                    'min' => '-100',
                    'max' => '100'
                ),
                'description' => __('Percentage from top (-100 to 100). Negative values position outside top edge.', 'thready-product-customizer'),
                'wrapper_class' => 'form-row form-row-last'
            ]);
            
            // Back size setting
            woocommerce_wp_text_input([
                'id' => "_thready_back_print_width_{$loop}",
                'name' => "_thready_back_print_width[{$loop}]",
                'label' => __('Back Print Width', 'thready-product-customizer'),
                'value' => $back_print_width !== '' ? $back_print_width : '',
                'placeholder' => __('Default: ', 'thready-product-customizer') . $back_defaults['print_width'] . '%',
                'type' => 'number',
                'custom_attributes' => array(
                    'step' => '1',
                    'min' => '1',
                    'max' => '100'
                ),
                'description' => __('Percentage of base image width (1-100)', 'thready-product-customizer'),
                'wrapper_class' => 'form-row form-row-first'
            ]);
            
            echo '</div>';
        }
        
        echo '</div>';
    }
    
    public static function save_variation_settings_fields($variation_id, $loop) {
        $variation = wc_get_product($variation_id);
        $parent_id = $variation->get_parent_id();
        $print_image_id = get_post_meta($parent_id, '_thready_print_image', true);
        $light_print_image_id = get_post_meta($parent_id, '_thready_light_print_image', true);
        $back_print_image_id = get_post_meta($parent_id, '_thready_back_print_image', true);
        
        // Only process Thready functionality if at least one print image exists
        if (!$print_image_id && !$light_print_image_id && !$back_print_image_id) {
            // Clean up any Thready-generated images if they exist
            $current_image_id = $variation->get_image_id();
            if ($current_image_id && self::is_thready_generated_image($current_image_id)) {
                self::cleanup_old_image($current_image_id, $variation_id);
                $variation->set_image_id('');
                $variation->save();
            }
            return;
        }
        
        // Store the original base image if it exists
        $base_image_id = $variation->get_image_id();
        if ($base_image_id && !get_post_meta($variation_id, '_thready_base_image_id', true)) {
            update_post_meta($variation_id, '_thready_base_image_id', $base_image_id);
        }
        
        // Process front print settings
        $fields = [
            '_thready_print_x' => [-100, 100],
            '_thready_print_y' => [-100, 100],
            '_thready_print_width' => [1, 100]
        ];
        
        foreach ($fields as $key => $range) {
            $post_key = $key . "[$loop]";
            
            if (isset($_POST[$key]) && isset($_POST[$key][$loop])) {
                $new_value = $_POST[$key][$loop] !== '' ? intval($_POST[$key][$loop]) : '';
                
                if ($new_value !== '') {
                    $value = max($range[0], min($range[1], $new_value));
                    update_post_meta($variation_id, $key, $value);
                } else {
                    // Remove the meta if empty (use defaults)
                    delete_post_meta($variation_id, $key);
                }
            }
        }
        
        // Save light print checkbox
        if (isset($_POST['_thready_use_light_print']) && isset($_POST['_thready_use_light_print'][$loop])) {
            update_post_meta($variation_id, '_thready_use_light_print', 'yes');
        } else {
            update_post_meta($variation_id, '_thready_use_light_print', 'no');
        }
        
        // Save available sizes
        if (isset($_POST['_thready_available_sizes']) && isset($_POST['_thready_available_sizes'][$loop])) {
            $available_sizes = implode(',', $_POST['_thready_available_sizes'][$loop]);
            update_post_meta($variation_id, '_thready_available_sizes', $available_sizes);
        } else {
            delete_post_meta($variation_id, '_thready_available_sizes');
        }
        
        // Process back settings
        if ($back_print_image_id) {
            // Save back base image
            if (isset($_POST['_thready_back_base_image_id']) && isset($_POST['_thready_back_base_image_id'][$loop])) {
                $back_base_image_id = absint($_POST['_thready_back_base_image_id'][$loop]);
                if ($back_base_image_id) {
                    update_post_meta($variation_id, '_thready_back_base_image_id', $back_base_image_id);
                } else {
                    delete_post_meta($variation_id, '_thready_back_base_image_id');
                }
            }
            
            // Process back print settings
            $back_fields = [
                '_thready_back_print_x' => [-100, 100],
                '_thready_back_print_y' => [-100, 100],
                '_thready_back_print_width' => [1, 100]
            ];
            
            foreach ($back_fields as $key => $range) {
                $post_key = $key . "[$loop]";
                
                if (isset($_POST[$key]) && isset($_POST[$key][$loop])) {
                    $new_value = $_POST[$key][$loop] !== '' ? intval($_POST[$key][$loop]) : '';
                    
                    if ($new_value !== '') {
                        $value = max($range[0], min($range[1], $new_value));
                        update_post_meta($variation_id, $key, $value);
                    } else {
                        // Remove the meta if empty (use defaults)
                        delete_post_meta($variation_id, $key);
                    }
                }
            }
            
            // Generate back merged image if back base image exists
            $back_base_image_id = get_post_meta($variation_id, '_thready_back_base_image_id', true);
            if ($back_base_image_id) {
                self::generate_back_merged_image($variation_id);
            }
        }
        
        // Auto-generate front merged image when variation is saved (only if we have a base image)
        if ($base_image_id) {
            self::generate_merged_image($variation_id, false);
        }
    }
    
    public static function generate_merged_image($variation_id, $preview_only = false) {
        // Get fresh variation data
        $variation = wc_get_product($variation_id);
        if (!$variation) {
            error_log("Thready: Variation $variation_id not found");
            return false;
        }

        $product_id = $variation->get_parent_id();
        if (!$product_id) {
            error_log("Thready: Parent product not found for variation $variation_id");
            return false;
        }

        $print_image_id = get_post_meta($product_id, '_thready_print_image', true);
        $light_print_image_id = get_post_meta($product_id, '_thready_light_print_image', true);
        $use_light_print = get_post_meta($variation_id, '_thready_use_light_print', true) === 'yes';
        
        // Determine which print image to use
        $selected_print_image_id = null;
        
        if ($use_light_print && $light_print_image_id) {
            $selected_print_image_id = $light_print_image_id;
        } elseif ($print_image_id) {
            $selected_print_image_id = $print_image_id;
        }
        
        // Only generate merged image if a print image exists
        if (!$selected_print_image_id) {
            return false;
        }

        // Get the base image from stored reference
        $base_image_id = get_post_meta($variation_id, '_thready_base_image_id', true);
        
        // If no stored base image, use current variation image
        if (!$base_image_id) {
            $base_image_id = $variation->get_image_id();
            if ($base_image_id) {
                update_post_meta($variation_id, '_thready_base_image_id', $base_image_id);
            }
        }
        
        // If no base image, don't generate
        if (!$base_image_id) {
            return false;
        }
        
        $base_image_url = wp_get_attachment_url($base_image_id);
        if (!$base_image_url) {
            return false;
        }

        // Get settings
        $defaults = self::get_default_settings($product_id);
        $settings = [
            'print_x' => get_post_meta($variation_id, '_thready_print_x', true) !== '' ? 
                         get_post_meta($variation_id, '_thready_print_x', true) : $defaults['print_x'],
            'print_y' => get_post_meta($variation_id, '_thready_print_y', true) !== '' ? 
                         get_post_meta($variation_id, '_thready_print_y', true) : $defaults['print_y'],
            'print_width' => get_post_meta($variation_id, '_thready_print_width', true) !== '' ? 
                            get_post_meta($variation_id, '_thready_print_width', true) : $defaults['print_width'],
            'base_image' => $base_image_url
        ];

        // Check if we already have a suitable image with these exact settings
        $existing_image_id = self::find_existing_image($variation_id, $settings, $selected_print_image_id, 'front');
        
        if ($existing_image_id) {
            error_log("Thready: Reusing existing front image ID: $existing_image_id for variation $variation_id");
            
            // Apply the existing image
            $current_image_id = $variation->get_image_id();
            
            // Only clean up old image if it's a Thready-generated image and different from the existing one
            if ($current_image_id && $current_image_id != $existing_image_id && self::is_thready_generated_image($current_image_id)) {
                self::cleanup_old_image($current_image_id, $variation_id);
            }
            
            self::apply_generated_image($variation_id, $existing_image_id, $current_image_id);
            
            return $existing_image_id;
        }

        error_log("Thready: Generating new front merged image for variation $variation_id");
        $attachment_id = Thready_Image_Handler::generate_merged_image(
            $product_id,
            $variation_id,
            $settings,
            $selected_print_image_id
        );

        if ($attachment_id && is_numeric($attachment_id)) {
            // Apply the new image
            $current_image_id = $variation->get_image_id();
            
            // Only clean up old image if it's a Thready-generated image
            if ($current_image_id && $current_image_id != $attachment_id && self::is_thready_generated_image($current_image_id)) {
                self::cleanup_old_image($current_image_id, $variation_id);
            }
            
            self::apply_generated_image($variation_id, $attachment_id, $current_image_id);
            
            return $attachment_id;
        }

        return false;
    }
    
    public static function generate_back_merged_image($variation_id) {
        // Get fresh variation data
        $variation = wc_get_product($variation_id);
        if (!$variation) {
            error_log("Thready: Variation $variation_id not found for back image");
            return false;
        }

        $product_id = $variation->get_parent_id();
        if (!$product_id) {
            error_log("Thready: Parent product not found for variation $variation_id");
            return false;
        }

        $back_print_image_id = get_post_meta($product_id, '_thready_back_print_image', true);
        
        // Only generate back merged image if back print image exists
        if (!$back_print_image_id) {
            return false;
        }

        // Get the back base image
        $back_base_image_id = get_post_meta($variation_id, '_thready_back_base_image_id', true);
        
        // If no back base image, don't generate
        if (!$back_base_image_id) {
            return false;
        }
        
        $back_base_image_url = wp_get_attachment_url($back_base_image_id);
        if (!$back_base_image_url) {
            return false;
        }

        // Get back settings
        $defaults = self::get_default_back_settings($product_id);
        $settings = [
            'print_x' => get_post_meta($variation_id, '_thready_back_print_x', true) !== '' ? 
                         get_post_meta($variation_id, '_thready_back_print_x', true) : $defaults['print_x'],
            'print_y' => get_post_meta($variation_id, '_thready_back_print_y', true) !== '' ? 
                         get_post_meta($variation_id, '_thready_back_print_y', true) : $defaults['print_y'],
            'print_width' => get_post_meta($variation_id, '_thready_back_print_width', true) !== '' ? 
                            get_post_meta($variation_id, '_thready_back_print_width', true) : $defaults['print_width'],
            'base_image' => $back_base_image_url
        ];

        // Check if we already have a suitable image with these exact settings
        $existing_image_id = self::find_existing_image($variation_id, $settings, $back_print_image_id, 'back');
        
        if ($existing_image_id) {
            error_log("Thready: Reusing existing back image ID: $existing_image_id for variation $variation_id");
            
            // Store the existing back image ID
            update_post_meta($variation_id, '_thready_back_image_id', $existing_image_id);
            
            return $existing_image_id;
        }

        error_log("Thready: Generating new back merged image for variation $variation_id");
        $attachment_id = Thready_Image_Handler::generate_merged_image(
            $product_id,
            $variation_id,
            $settings,
            $back_print_image_id,
            'back'
        );

        if ($attachment_id && is_numeric($attachment_id)) {
            // Store the back image ID but don't assign it to the variation
            update_post_meta($variation_id, '_thready_back_image_id', $attachment_id);
            
            return $attachment_id;
        }

        return false;
    }
    
    private static function find_existing_image($variation_id, $settings, $print_image_id, $image_type = 'front') {
        // Create a unique hash for these specific settings
        $settings_hash = md5(serialize($settings) . $print_image_id . $image_type);
        
        // Look for existing images with the same settings hash and variation ID
        $args = [
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'posts_per_page' => 1,
            'meta_query' => [
                'relation' => 'AND',
                [
                    'key' => '_thready_variation_id',
                    'value' => $variation_id,
                    'compare' => '='
                ],
                [
                    'key' => '_thready_settings_hash',
                    'value' => $settings_hash,
                    'compare' => '='
                ],
                [
                    'key' => '_thready_image_type',
                    'value' => $image_type,
                    'compare' => '='
                ]
            ]
        ];
        
        $attachments = get_posts($args);
        
        if (!empty($attachments)) {
            $attachment_id = $attachments[0]->ID;
            
            // Verify the attachment still exists and is valid
            if (wp_attachment_is_image($attachment_id)) {
                return $attachment_id;
            }
        }
        
        return false;
    }
    
    private static function apply_generated_image($variation_id, $new_image_id, $old_image_id = null) {
        if (!$old_image_id) {
            $variation = wc_get_product($variation_id);
            $old_image_id = $variation->get_image_id();
        }
        
        // Update the variation with the new image
        $fresh_variation = wc_get_product($variation_id);
        $fresh_variation->set_image_id($new_image_id);
        $fresh_variation->save();
        
        // Clear caches
        wc_delete_product_transients($variation_id);
        clean_post_cache($variation_id);
        clean_post_cache($new_image_id);
        
        return true;
    }
    
    private static function is_thready_generated_image($image_id) {
        if (!$image_id) return false;
        
        $filename = get_post_meta($image_id, '_wp_attached_file', true);
        if ($filename && strpos($filename, 'thready-') === 0) {
            return true;
        }
        
        $attachment = get_post($image_id);
        if ($attachment && strpos($attachment->post_title, 'thready-') === 0) {
            return true;
        }
        
        return false;
    }
    
    private static function cleanup_old_image($image_id, $variation_id) {
        if (self::is_thready_generated_image($image_id)) {
            $upload_dir = wp_upload_dir();
            $filename = get_post_meta($image_id, '_wp_attached_file', true);
            $file_path = wp_upload_dir()['basedir'] . '/' . $filename;
            
            if (file_exists($file_path)) {
                wp_delete_file($file_path);
            }
            
            if (wp_attachment_is_image($image_id)) {
                wp_delete_attachment($image_id, true);
            }
            
            return true;
        }
        
        return false;
    }
    
    public static function get_default_settings($product_id) {
        return [
            'print_x' => max(-100, min(100, get_post_meta($product_id, '_thready_default_print_x', true) ?: 50)),
            'print_y' => max(-100, min(100, get_post_meta($product_id, '_thready_default_print_y', true) ?: 25)),
            'print_width' => max(1, min(100, get_post_meta($product_id, '_thready_default_print_width', true) ?: 50))
        ];
    }
    
    public static function get_default_back_settings($product_id) {
        return [
            'print_x' => max(-100, min(100, get_post_meta($product_id, '_thready_default_back_print_x', true) ?: 50)),
            'print_y' => max(-100, min(100, get_post_meta($product_id, '_thready_default_back_print_y', true) ?: 25)),
            'print_width' => max(1, min(100, get_post_meta($product_id, '_thready_default_back_print_width', true) ?: 50))
        ];
    }
}