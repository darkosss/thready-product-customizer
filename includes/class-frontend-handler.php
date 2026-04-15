<?php
class Thready_Frontend_Handler {

    public static function init() {
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_scripts']);
        add_action('woocommerce_before_variations_form', [__CLASS__, 'add_size_selector']);
        add_filter('woocommerce_available_variation', [__CLASS__, 'add_available_sizes_to_variation'], 10, 3);
        add_action('wp_head', [__CLASS__, 'hide_original_size_dropdown']);
        add_action('wp_enqueue_scripts', [__CLASS__, 'localize_script_strings']);
        add_filter('woocommerce_add_cart_item_data', [__CLASS__, 'add_size_to_cart_item_data'], 10, 3);
        add_filter('woocommerce_get_item_data', [__CLASS__, 'display_size_in_cart'], 10, 2);
        add_action('woocommerce_checkout_create_order_line_item', [__CLASS__, 'add_size_to_order_meta'], 10, 4);
        add_filter('woocommerce_get_cart_item_from_session', [__CLASS__, 'get_cart_item_from_session'], 10, 3);
        add_filter('woocommerce_cart_item_name', [__CLASS__, 'add_size_to_cart_item_name'], 10, 3);
        add_action('wp_ajax_thready_ajax_get_size_data', [__CLASS__, 'ajax_get_size_data']);
        add_action('wp_ajax_nopriv_thready_ajax_get_size_data', [__CLASS__, 'ajax_get_size_data']);
        add_filter('woocommerce_cart_item_permalink', [__CLASS__, 'add_size_to_cart_item_permalink'], 10, 3);

        // Couple Mode body class
        add_filter('body_class', [__CLASS__, 'add_body_class']);
    }

    public static function add_body_class( $classes ) {
        if ( is_product() ) {
            global $product;
            if ( $product && get_post_meta( $product->get_id(), '_thready_couple_mode', true ) === 'yes' ) {
                $classes[] = 'thready-couple-mode';
            }
        }
        return $classes;
    }

    private static function is_couple_mode_product( $product_id ) {
        return get_post_meta( $product_id, '_thready_couple_mode', true ) === 'yes';
    }

    public static function enqueue_scripts() {
        if ( is_product() ) {

            wp_enqueue_script(
                'thready-frontend',
                THREADY_PC_URL . 'assets/js/frontend-min.js',
                ['jquery', 'wc-add-to-cart-variation'],
                THREADY_PC_VERSION,
                true
            );

            // Couple Mode frontend script
            wp_enqueue_script(
                'thready-couple-mode',
                THREADY_PC_URL . 'assets/js/couple-mode-min.js',
                ['jquery', 'wc-add-to-cart-variation'],
                THREADY_PC_VERSION,
                true
            );

            // Couple Mode frontend styles
            wp_enqueue_style(
                'thready-couple-mode',
                THREADY_PC_URL . 'assets/css/couple-mode.css',
                [],
                THREADY_PC_VERSION
            );

            if ( ! wp_script_is('wc-add-to-cart-variation', 'enqueued') ) {
                wp_enqueue_script('wc-add-to-cart-variation');
            }

            wp_localize_script('thready-frontend', 'thready_theme_compat', [
                'ajax_url' => admin_url('admin-ajax.php'),
            ]);
        }
    }

    public static function localize_script_strings() {
        if ( ! is_product() ) {
            return;
        }

        // Size labels (existing logic)
        $size_terms = get_terms([
            'taxonomy'   => 'pa_velicina',
            'hide_empty' => false
        ]);

        $size_names = [];
        foreach ( $size_terms as $term ) {
            $size_names[ $term->slug ] = $term->name;
        }

        wp_localize_script('thready-frontend', 'thready_frontend_params', [
            'ajax_url'               => admin_url('admin-ajax.php'),
            'nonce'                  => wp_create_nonce('thready_frontend_nonce'),
            'size_label'             => __('Size', 'thready-product-customizer'),
            'select_variation_first' => __('Please select a variation first', 'thready-product-customizer'),
            'no_sizes_available'     => __('No sizes available for this variation', 'thready-product-customizer'),
            'select_size_required'   => __('Please select a size', 'thready-product-customizer'),
            'size_names'             => $size_names
        ]);

        // Color hex map for Couple Mode (pa_boja)
        $color_terms = get_terms([
            'taxonomy'   => 'pa_boja',
            'hide_empty' => false
        ]);

        $color_map = [];

        foreach ( $color_terms as $term ) {
            $hex = get_term_meta( $term->term_id, 'product_attribute_color', true );
            if ( $hex ) {
                $color_map[ $term->slug ] = $hex;
            }
        }

        wp_localize_script('thready-couple-mode', 'thready_color_map', $color_map);
    }

    public static function ajax_get_size_data() {
        if ( ! isset($_POST['nonce']) || ! wp_verify_nonce($_POST['nonce'], 'thready_frontend_nonce') ) {
            wp_send_json_error('Invalid nonce');
            return;
        }

        if ( isset($_POST['size_slug']) ) {
            $size_slug = sanitize_text_field($_POST['size_slug']);
            $term      = get_term_by('slug', $size_slug, 'pa_velicina');

            wp_send_json_success([
                'size_name' => $term ? $term->name : $size_slug,
                'size_slug' => $size_slug
            ]);
        }

        wp_send_json_error('No size data provided');
    }

    public static function add_size_selector() {
        // HTML generated by JS
    }

    public static function add_available_sizes_to_variation( $data, $product, $variation ) {

        $product_id   = $product->get_id();
        $variation_id = $variation->get_id();

        // Couple Mode meta (always exposed)
        $data['thready_her_sizes']             = get_post_meta( $variation_id, '_thready_her_sizes', true );
        $data['thready_her_colors']            = get_post_meta( $variation_id, '_thready_her_colors', true );
        $data['thready_her_embroidery_colors'] = get_post_meta( $variation_id, '_thready_her_embroidery_colors', true );

        $data['thready_him_sizes']             = get_post_meta( $variation_id, '_thready_him_sizes', true );
        $data['thready_him_colors']            = get_post_meta( $variation_id, '_thready_him_colors', true );
        $data['thready_him_embroidery_colors'] = get_post_meta( $variation_id, '_thready_him_embroidery_colors', true );

        if ( self::is_couple_mode_product( $product_id ) ) {
            return $data;
        }

        // Existing print-based size logic (unchanged)
        $print_image_id = get_post_meta( $product_id, '_thready_print_image', true );
        if ( ! $print_image_id ) {
            return $data;
        }

        $available_sizes = get_post_meta( $variation_id, '_thready_available_sizes', true );

        if ( $available_sizes && trim($available_sizes) !== '' ) {
            $data['thready_available_sizes'] = $available_sizes;

            $size_names = [];
            foreach ( explode(',', $available_sizes) as $slug ) {
                $slug = trim($slug);
                if ( ! $slug ) continue;

                $term = get_term_by('slug', $slug, 'pa_velicina');
                $size_names[] = $term ? $term->name : $slug;
            }

            $data['thready_available_size_names'] = implode(', ', $size_names);
        }

        return $data;
    }

    public static function hide_original_size_dropdown() {
        if ( ! is_product() ) {
            return;
        }

        global $product;
        if ( ! $product ) {
            return;
        }

        if ( self::is_couple_mode_product( $product->get_id() ) ) {
            return;
        }

        $print_image_id = get_post_meta( $product->get_id(), '_thready_print_image', true );
        if ( $print_image_id ) {
            echo '<style>.variations tr:has(.label:contains("Veličina")){display:none!important}</style>';
        }
    }

    public static function add_size_to_cart_item_data( $cart_item_data, $product_id, $variation_id ) {

        if ( self::is_couple_mode_product( $product_id ) ) {
            return $cart_item_data;
        }

        $print_image_id = get_post_meta( $product_id, '_thready_print_image', true );
        if ( ! $print_image_id ) {
            return $cart_item_data;
        }

        if ( ! empty($_POST['thready_size']) ) {
            $size_slug = sanitize_text_field($_POST['thready_size']);
            $term      = get_term_by('slug', $size_slug, 'pa_velicina');

            $cart_item_data['thready_size']      = $size_slug;
            $cart_item_data['thready_size_name'] = $term ? $term->name : $size_slug;
        }

        return $cart_item_data;
    }

    public static function get_cart_item_from_session( $cart_item, $values, $cart_item_key ) {
        if ( isset($values['thready_size']) ) {
            $cart_item['thready_size']      = $values['thready_size'];
            $cart_item['thready_size_name'] = $values['thready_size_name'];
        }
        return $cart_item;
    }

    public static function display_size_in_cart( $item_data, $cart_item ) {
        if ( isset($cart_item['thready_size_name']) ) {
            $item_data[] = [
                'name'  => 'Veličina',
                'value' => $cart_item['thready_size_name']
            ];
        }
        return $item_data;
    }

    public static function add_size_to_order_meta( $item, $cart_item_key, $values, $order ) {
        if ( isset($values['thready_size_name']) ) {
            $item->add_meta_data('Veličina', $values['thready_size_name'], true);
        }
    }

    public static function add_size_to_cart_item_name( $name, $cart_item, $cart_item_key ) {
        if ( isset($cart_item['thready_size_name']) && is_cart() ) {
            $name .= '<br><small>Veličina: ' . esc_html($cart_item['thready_size_name']) . '</small>';
        }
        return $name;
    }

    public static function add_size_to_cart_item_permalink( $permalink, $cart_item, $cart_item_key ) {
        if ( ! empty($cart_item['thready_size']) ) {
            $separator = strpos($permalink, '?') === false ? '?' : '&';
            $permalink .= $separator . 'attribute_pa_velicina=' . urlencode($cart_item['thready_size']);
        }
        return $permalink;
    }
}
