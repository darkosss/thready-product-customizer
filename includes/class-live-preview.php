<?php
/**
 * Thready Live Preview
 *
 * Canvas-based live preview for products in 'canvas' render mode.
 * Works with the new multi-tip product structure (tip × boja variations).
 */

defined( 'ABSPATH' ) || exit;

class Thready_Live_Preview {

    const META_CACHED_IMAGE = '_thready_canvas_image_id';

    public static function init() {
        add_action( 'wp_enqueue_scripts',          [ __CLASS__, 'enqueue_scripts'       ] );
        add_filter( 'woocommerce_available_variation', [ __CLASS__, 'filter_variation_data' ], 20, 3 );
        add_filter( 'woocommerce_add_cart_item_data',  [ __CLASS__, 'maybe_generate_cache_image' ], 30, 3 );
        add_filter( 'woocommerce_product_get_image',   [ __CLASS__, 'maybe_use_cached_image'     ], 10, 5 );

        // Raise threshold — canvas products have lean variation payloads
        add_filter( 'woocommerce_ajax_variation_threshold',                       [ __CLASS__, 'raise_threshold'     ], 10, 2 );
        add_filter( 'woo_variation_swatches_global_ajax_variation_threshold_max', [ __CLASS__, 'raise_threshold_max' ], 10, 2 );
    }

    // -------------------------------------------------------------------------
    // Scripts + data
    // -------------------------------------------------------------------------

    public static function enqueue_scripts() {
        if ( ! is_product() ) return;

        global $product;
        if ( ! $product || ! self::is_canvas_product( $product->get_id() ) ) return;

        wp_enqueue_style(
            'thready-live-preview',
            THREADY_PC_URL . 'assets/css/live-preview.css',
            [],
            THREADY_PC_VERSION
        );

        wp_enqueue_script(
            'thready-live-preview',
            THREADY_PC_URL . 'assets/js/live-preview.js',
            [ 'jquery', 'wc-add-to-cart-variation' ],
            THREADY_PC_VERSION,
            true
        );

        wp_localize_script( 'thready-live-preview', 'threadyCanvas', self::build_canvas_data( $product ) );
    }

    /**
     * Build the data object for live-preview.js.
     *
     * Products now have multiple tips in one product (tip × boja variations).
     * Mockup map is keyed by "tip_slug|boja_slug".
     * Positions are stored in META_TIP_POSITIONS as JSON {tipSlug:{front,back}}.
     */
    private static function build_canvas_data( $product ) {
        $product_id = $product->get_id();

        // Print image URLs
        $front_id  = (int) get_post_meta( $product_id, Thready_Variation_Factory::META_PRINT_FRONT, true );
        $light_id  = (int) get_post_meta( $product_id, Thready_Variation_Factory::META_PRINT_LIGHT, true );
        $back_id   = (int) get_post_meta( $product_id, Thready_Variation_Factory::META_PRINT_BACK,  true );

        $front_url = $front_id ? wp_get_attachment_url( $front_id ) : '';
        $light_url = $light_id ? wp_get_attachment_url( $light_id ) : '';
        $back_url  = $back_id  ? wp_get_attachment_url( $back_id  ) : '';

        // Positions per tip — { tipSlug: { front:{x,y,width}, back:{x,y,width}|null } }
        $positions_raw = get_post_meta( $product_id, Thready_Variation_Factory::META_TIP_POSITIONS, true );
        $tip_positions = $positions_raw ? json_decode( $positions_raw, true ) : [];

        // Active tips on this product
        $active_tips  = self::get_active_tip_slugs( $product_id );
        $active_bojas = self::get_active_boja_slugs( $product_id );

        // Mockup map keyed "tip_slug|boja_slug" → {front, back}
        $mockup_map = [];
        foreach ( $active_tips as $tip_slug ) {
            foreach ( $active_bojas as $boja_slug ) {
                $urls = Thready_Mockup_Library::get_urls( $tip_slug, $boja_slug );
                if ( $urls ) {
                    $mockup_map[ $tip_slug . '|' . $boja_slug ] = [
                        'front' => $urls['front'] ?: '',
                        'back'  => $urls['back']  ?: '',
                    ];
                }
            }
        }

        return [
            'print_front'   => $front_url,
            'print_light'   => $light_url,
            'print_back'    => $back_url,
            'tip_positions' => $tip_positions,   // { tipSlug: { front, back } }
            'mockups'       => $mockup_map,       // { "tip|boja": { front, back } }
            'has_back'      => ! empty( $back_url ),
            'has_light'     => ! empty( $light_url ),
            'design_ver'    => (int) get_post_meta( $product_id, Thready_Variation_Factory::META_DESIGN_VERSION, true ),
            'ajax_url'      => admin_url( 'admin-ajax.php' ),
            'nonce'         => wp_create_nonce( 'thready_canvas_nonce' ),
            'tax_tip'       => THREADY_TAX_TIP,
            'tax_boja'      => THREADY_TAX_BOJA,
        ];
    }

    // -------------------------------------------------------------------------
    // Filter variation data
    // -------------------------------------------------------------------------

    public static function filter_variation_data( $data, $product, $variation ) {
        if ( ! self::is_canvas_product( $product->get_id() ) ) {
            return $data;
        }

        // Strip heavy image keys
        $strip_keys = [
            'image', 'image_id', 'image_src', 'image_srcset', 'image_sizes',
            'image_title', 'image_alt', 'image_caption', 'image_description',
            'image_link', 'variation_gallery_images',
        ];
        foreach ( $strip_keys as $key ) unset( $data[ $key ] );

        // Minimal image placeholder so WC JS doesn't error
        $data['image'] = [
            'src' => '', 'srcset' => '', 'sizes' => '', 'title' => '', 'alt' => '',
            'caption' => '', 'full_src' => '', 'gallery_thumbnail_src' => '',
            'src_w' => 0, 'src_h' => 0, 'full_src_w' => 0, 'full_src_h' => 0,
        ];

        // Pass both tip and boja slugs so JS can look up "tip|boja" in mockup map
        $data['thready_tip_slug']  = $variation->get_attribute( THREADY_TAX_TIP  );
        $data['thready_boja_slug'] = $variation->get_attribute( THREADY_TAX_BOJA );

        // Pass light print flag
        $data['thready_light_print'] = get_post_meta( $variation->get_id(), '_thready_use_light_print', true ) === 'yes';

        return $data;
    }

    // -------------------------------------------------------------------------
    // Threshold
    // -------------------------------------------------------------------------

    public static function raise_threshold( $threshold, $product = null ) {
        if ( $product && self::is_canvas_product( is_object( $product ) ? $product->get_id() : $product ) ) {
            return 999;
        }
        return $threshold;
    }

    public static function raise_threshold_max( $threshold, $product = null ) {
        if ( $product && self::is_canvas_product( is_object( $product ) ? $product->get_id() : $product ) ) {
            return 999;
        }
        return $threshold;
    }

    // -------------------------------------------------------------------------
    // On-demand cache image — generated on first add to cart
    // -------------------------------------------------------------------------

    public static function maybe_generate_cache_image( $cart_item_data, $product_id, $variation_id ) {
        if ( ! $variation_id ) return $cart_item_data;

        $variation = wc_get_product( $variation_id );
        if ( ! $variation ) return $cart_item_data;

        $parent_id = $variation->get_parent_id();
        if ( ! self::is_canvas_product( $parent_id ) ) return $cart_item_data;
        if ( ! function_exists( 'imagecreatetruecolor' ) ) return $cart_item_data;

        $boja_slug = $variation->get_attribute( THREADY_TAX_BOJA );
        $tip_slug  = $variation->get_attribute( THREADY_TAX_TIP  );
        if ( ! $boja_slug || ! $tip_slug ) return $cart_item_data;

        // Skip if already cached for this design version
        if ( self::get_valid_cached_image( $variation_id, $parent_id ) ) return $cart_item_data;

        self::generate_and_cache( $parent_id, $variation_id, $tip_slug, $boja_slug );

        return $cart_item_data;
    }

    private static function generate_and_cache( $product_id, $variation_id, $tip_slug, $boja_slug ) {
        $mockup_urls = Thready_Mockup_Library::get_urls( $tip_slug, $boja_slug );
        if ( ! $mockup_urls || ! $mockup_urls['front'] ) return false;

        $front_print_id = (int) get_post_meta( $product_id, Thready_Variation_Factory::META_PRINT_FRONT, true );
        if ( ! $front_print_id ) return false;

        // Determine if this variation uses light print
        $use_light    = get_post_meta( $variation_id, '_thready_use_light_print', true ) === 'yes';
        $light_id     = (int) get_post_meta( $product_id, Thready_Variation_Factory::META_PRINT_LIGHT, true );
        $print_id     = ( $use_light && $light_id ) ? $light_id : $front_print_id;

        // Get position for this tip
        $positions_raw = get_post_meta( $product_id, Thready_Variation_Factory::META_TIP_POSITIONS, true );
        $tip_positions = $positions_raw ? json_decode( $positions_raw, true ) : [];
        $pos           = $tip_positions[ $tip_slug ]['front'] ?? [ 'x' => 50, 'y' => 25, 'width' => 50 ];

        $settings = [
            'print_x'    => $pos['x'],
            'print_y'    => $pos['y'],
            'print_width'=> $pos['width'],
            'base_image' => $mockup_urls['front'],
        ];

        $attachment_id = Thready_Image_Handler::generate_merged_image(
            $product_id, $variation_id, $settings, $print_id, 'front'
        );

        if ( $attachment_id && ! is_wp_error( $attachment_id ) ) {
            $design_ver = (int) get_post_meta( $product_id, Thready_Variation_Factory::META_DESIGN_VERSION, true );
            update_post_meta( $variation_id, self::META_CACHED_IMAGE,        $attachment_id );
            update_post_meta( $variation_id, '_thready_cached_design_ver',   $design_ver    );
            return $attachment_id;
        }

        return false;
    }

    private static function get_valid_cached_image( $variation_id, $product_id ) {
        $cached_id = (int) get_post_meta( $variation_id, self::META_CACHED_IMAGE, true );
        if ( ! $cached_id ) return false;

        $cached_ver  = (int) get_post_meta( $variation_id, '_thready_cached_design_ver', true );
        $current_ver = (int) get_post_meta( $product_id,   Thready_Variation_Factory::META_DESIGN_VERSION, true );

        if ( $cached_ver !== $current_ver ) {
            delete_post_meta( $variation_id, self::META_CACHED_IMAGE );
            delete_post_meta( $variation_id, '_thready_cached_design_ver' );
            return false;
        }

        if ( ! wp_attachment_is_image( $cached_id ) ) {
            delete_post_meta( $variation_id, self::META_CACHED_IMAGE );
            return false;
        }

        return $cached_id;
    }

    // -------------------------------------------------------------------------
    // Cart / order thumbnail
    // -------------------------------------------------------------------------

    public static function maybe_use_cached_image( $image, $product, $size, $attr, $placeholder ) {
        if ( ! $product || ! $product->is_type( 'variation' ) ) return $image;

        $parent_id = $product->get_parent_id();
        if ( ! self::is_canvas_product( $parent_id ) ) return $image;

        $cached_id = self::get_valid_cached_image( $product->get_id(), $parent_id );
        if ( ! $cached_id ) return $image;

        return wp_get_attachment_image( $cached_id, $size, false, $attr );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    public static function is_canvas_product( $product_id ) {
        return get_post_meta( $product_id, Thready_Variation_Factory::META_RENDER_MODE, true ) === 'canvas';
    }

    private static function get_active_tip_slugs( $product_id ) {
        global $wpdb;
        $meta_key = 'attribute_' . THREADY_TAX_TIP;
        return $wpdb->get_col( $wpdb->prepare( "
            SELECT DISTINCT pm.meta_value
            FROM   {$wpdb->posts} p
            JOIN   {$wpdb->postmeta} pm ON pm.post_id = p.ID
            WHERE  p.post_parent  = %d
            AND    p.post_type    = 'product_variation'
            AND    p.post_status != 'trash'
            AND    pm.meta_key    = %s
            AND    pm.meta_value != ''
        ", $product_id, $meta_key ) );
    }

    private static function get_active_boja_slugs( $product_id ) {
        global $wpdb;
        $meta_key = 'attribute_' . THREADY_TAX_BOJA;
        return $wpdb->get_col( $wpdb->prepare( "
            SELECT DISTINCT pm.meta_value
            FROM   {$wpdb->posts} p
            JOIN   {$wpdb->postmeta} pm  ON pm.post_id  = p.ID
            JOIN   {$wpdb->postmeta} pm2 ON pm2.post_id = p.ID
            WHERE  p.post_parent  = %d
            AND    p.post_type    = 'product_variation'
            AND    p.post_status != 'trash'
            AND    pm.meta_key    = %s
            AND    pm2.meta_key   = '_stock_status'
            AND    pm2.meta_value = 'instock'
        ", $product_id, $meta_key ) );
    }
}