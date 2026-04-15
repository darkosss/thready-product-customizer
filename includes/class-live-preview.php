<?php
/**
 * Thready Live Preview
 *
 * Replaces server-generated variation images with a canvas-based system
 * for products in 'canvas' render mode.
 *
 * Responsibilities
 * ----------------
 * 1. Inject mockup image map + print data into product page as JSON
 * 2. Strip heavy image HTML from variation data for canvas products
 *    (reduces inline variation payload dramatically)
 * 3. Hook into WooCommerce add-to-cart to generate + cache a static
 *    image for cart/email thumbnails (Option B — on-demand generation)
 * 4. Serve the cached image for a variation when it exists
 *
 * Render mode detection
 * ---------------------
 * A product is in canvas mode when:
 *   get_post_meta( $product_id, '_thready_render_mode', true ) === 'canvas'
 *
 * Variation data flow (frontend)
 * ------------------------------
 * 1. WooCommerce inlines all variation data as JSON in the page
 * 2. Our filter strips image src/srcset/gallery HTML for canvas products
 * 3. live-preview.js reads threadyCanvas.mockups[tip|boja] for base images
 * 4. On found_variation event, JS composites base + print on <canvas>
 * 5. Canvas replaces the WooCommerce gallery image seamlessly
 */

defined( 'ABSPATH' ) || exit;

class Thready_Live_Preview {

    const META_CACHED_IMAGE   = '_thready_canvas_image_id';   // on variation
    const CACHE_DIR           = 'thready-canvas-cache';

    // -------------------------------------------------------------------------
    // Boot
    // -------------------------------------------------------------------------

    public static function init() {
        // Frontend only
        add_action( 'wp_enqueue_scripts',       [ __CLASS__, 'enqueue_scripts'          ] );
        add_filter( 'woocommerce_available_variation', [ __CLASS__, 'filter_variation_data' ], 20, 3 );

        // On-demand cache generation when item added to cart
        add_filter( 'woocommerce_add_cart_item_data', [ __CLASS__, 'maybe_generate_cache_image' ], 30, 3 );

        // Serve cached image for cart/order thumbnails
        add_filter( 'woocommerce_product_get_image',  [ __CLASS__, 'maybe_use_cached_image'     ], 10, 5 );

        // Raise variation threshold so canvas products always inline their data
        add_filter( 'woocommerce_ajax_variation_threshold',                    [ __CLASS__, 'raise_threshold' ], 10, 2 );
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
     * Build the data object passed to live-preview.js.
     */
    private static function build_canvas_data( $product ) {
        $product_id  = $product->get_id();
        $tip_slug    = get_post_meta( $product_id, Thready_Variation_Factory::META_TIP_SLUG, true );

        // Print image URLs
        $front_id  = (int) get_post_meta( $product_id, Thready_Variation_Factory::META_PRINT_FRONT, true );
        $back_id   = (int) get_post_meta( $product_id, Thready_Variation_Factory::META_PRINT_BACK,  true );

        $front_url = $front_id ? wp_get_attachment_url( $front_id ) : '';
        $back_url  = $back_id  ? wp_get_attachment_url( $back_id  ) : '';

        // Positioning (per product — same for all colors of the same type)
        $pos_front_raw = get_post_meta( $product_id, Thready_Variation_Factory::META_POS_FRONT, true );
        $pos_back_raw  = get_post_meta( $product_id, Thready_Variation_Factory::META_POS_BACK,  true );

        $pos_front = $pos_front_raw ? json_decode( $pos_front_raw, true ) : [ 'x' => 50, 'y' => 25, 'width' => 50 ];
        $pos_back  = $pos_back_raw  ? json_decode( $pos_back_raw,  true ) : null;

        // Mockup map for this product's tip — only the colors that exist on this product
        $active_colors = self::get_active_boja_slugs( $product_id );
        $mockup_map    = [];

        foreach ( $active_colors as $boja_slug ) {
            $urls = Thready_Mockup_Library::get_urls( $tip_slug, $boja_slug );
            if ( $urls ) {
                $mockup_map[ $boja_slug ] = [
                    'front' => $urls['front'] ?: '',
                    'back'  => $urls['back']  ?: '',
                ];
            }
        }

        return [
            'tip_slug'    => $tip_slug,
            'print_front' => $front_url,
            'print_back'  => $back_url,
            'pos_front'   => $pos_front,
            'pos_back'    => $pos_back,
            'mockups'     => $mockup_map,    // { boja_slug: { front: url, back: url } }
            'has_back'    => ! empty( $back_url ),
            'design_ver'  => (int) get_post_meta( $product_id, Thready_Variation_Factory::META_DESIGN_VERSION, true ),
            'ajax_url'    => admin_url( 'admin-ajax.php' ),
            'nonce'       => wp_create_nonce( 'thready_canvas_nonce' ),
            'tax_boja'    => THREADY_TAX_BOJA,
        ];
    }

    // -------------------------------------------------------------------------
    // Filter variation data — strip heavy image payload for canvas products
    // -------------------------------------------------------------------------

    /**
     * WooCommerce inlines ~2KB of image HTML per variation.
     * For canvas products we don't need any of it — strip it down to a
     * minimal object so the inline JSON stays small even with 200 variations.
     *
     * We keep: variation_id, attributes, price_html, availability_html,
     *          is_in_stock, is_purchasable, is_active, min_qty, max_qty
     * We remove: image (src, srcset, full_src, gallery_thumbnail_src, etc.)
     *
     * Runs at priority 20 (after WVS at 10) so WVS data is already present.
     */
    public static function filter_variation_data( $data, $product, $variation ) {
        if ( ! self::is_canvas_product( $product->get_id() ) ) {
            return $data;
        }

        // Keys added by WooCommerce core that contain large image HTML/URLs
        $strip_keys = [
            'image',
            'image_id',
            'image_src',
            'image_srcset',
            'image_sizes',
            'image_title',
            'image_alt',
            'image_caption',
            'image_description',
            'image_link',
            'variation_gallery_images',
        ];

        foreach ( $strip_keys as $key ) {
            unset( $data[ $key ] );
        }

        // Replace image block with a bare placeholder so WC JS doesn't error
        $data['image'] = [
            'src'                   => '',
            'srcset'                => '',
            'sizes'                 => '',
            'src_w'                 => 0,
            'src_h'                 => 0,
            'full_src'              => '',
            'full_src_w'            => 0,
            'full_src_h'            => 0,
            'gallery_thumbnail_src' => '',
            'thumb_src'             => '',
            'alt'                   => '',
            'caption'               => '',
            'title'                 => '',
        ];

        // Add boja slug so canvas JS can look up the right mockup image
        $data['thready_boja_slug'] = $variation->get_attribute( THREADY_TAX_BOJA );

        return $data;
    }

    // -------------------------------------------------------------------------
    // Threshold — keep variation data inline
    // -------------------------------------------------------------------------

    /**
     * Raise threshold_min so WC never switches to AJAX loading.
     * Safe because our stripped variation objects are tiny.
     */
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
    // On-demand cache image — generated when added to cart (Option B)
    // -------------------------------------------------------------------------

    /**
     * When a canvas product is added to cart, generate + cache a static
     * merged image for that specific boja/velicina combination if one
     * doesn't already exist for the current design version.
     *
     * Does nothing if:
     * - Product is not canvas mode
     * - GD is not available
     * - No mockup image found for this boja
     */
    public static function maybe_generate_cache_image( $cart_item_data, $product_id, $variation_id ) {
        if ( ! $variation_id ) return $cart_item_data;

        $variation = wc_get_product( $variation_id );
        if ( ! $variation ) return $cart_item_data;

        $parent_id = $variation->get_parent_id();
        if ( ! self::is_canvas_product( $parent_id ) ) return $cart_item_data;
        if ( ! function_exists( 'imagecreatetruecolor' ) ) return $cart_item_data;

        $boja_slug = $variation->get_attribute( THREADY_TAX_BOJA );
        if ( ! $boja_slug ) return $cart_item_data;

        // Check if a valid cached image already exists for this design version
        $cached_id = self::get_valid_cached_image( $variation_id, $parent_id );
        if ( $cached_id ) return $cart_item_data;

        // Generate in background — we do it synchronously here because it's
        // a single image at add-to-cart time, not bulk
        self::generate_and_cache( $parent_id, $variation_id, $boja_slug );

        return $cart_item_data;
    }

    /**
     * Generate a merged front image and attach it to the variation.
     */
    private static function generate_and_cache( $product_id, $variation_id, $boja_slug ) {
        $tip_slug = get_post_meta( $product_id, Thready_Variation_Factory::META_TIP_SLUG, true );
        if ( ! $tip_slug ) return false;

        $mockup_urls = Thready_Mockup_Library::get_urls( $tip_slug, $boja_slug );
        if ( ! $mockup_urls || ! $mockup_urls['front'] ) return false;

        $front_print_id = (int) get_post_meta( $product_id, Thready_Variation_Factory::META_PRINT_FRONT, true );
        if ( ! $front_print_id ) return false;

        $pos_front_raw = get_post_meta( $product_id, Thready_Variation_Factory::META_POS_FRONT, true );
        $pos_front     = $pos_front_raw ? json_decode( $pos_front_raw, true ) : [ 'x' => 50, 'y' => 25, 'width' => 50 ];

        $settings = [
            'print_x'    => $pos_front['x'],
            'print_y'    => $pos_front['y'],
            'print_width'=> $pos_front['width'],
            'base_image' => $mockup_urls['front'],
        ];

        // Re-use the existing image handler
        $attachment_id = Thready_Image_Handler::generate_merged_image(
            $product_id,
            $variation_id,
            $settings,
            $front_print_id,
            'front'
        );

        if ( $attachment_id && ! is_wp_error( $attachment_id ) ) {
            $design_ver = (int) get_post_meta( $product_id, Thready_Variation_Factory::META_DESIGN_VERSION, true );
            update_post_meta( $variation_id, self::META_CACHED_IMAGE, $attachment_id );
            update_post_meta( $variation_id, '_thready_cached_design_ver', $design_ver );
            return $attachment_id;
        }

        return false;
    }

    /**
     * Check if a cached image exists and is still valid for the current design version.
     */
    private static function get_valid_cached_image( $variation_id, $product_id ) {
        $cached_id  = (int) get_post_meta( $variation_id, self::META_CACHED_IMAGE, true );
        if ( ! $cached_id ) return false;

        // Check design version
        $cached_ver  = (int) get_post_meta( $variation_id, '_thready_cached_design_ver', true );
        $current_ver = (int) get_post_meta( $product_id, Thready_Variation_Factory::META_DESIGN_VERSION, true );

        if ( $cached_ver !== $current_ver ) {
            // Stale — clear the reference (file stays, cleanup job removes it)
            delete_post_meta( $variation_id, self::META_CACHED_IMAGE );
            delete_post_meta( $variation_id, '_thready_cached_design_ver' );
            return false;
        }

        // Verify attachment still exists
        if ( ! wp_attachment_is_image( $cached_id ) ) {
            delete_post_meta( $variation_id, self::META_CACHED_IMAGE );
            return false;
        }

        return $cached_id;
    }

    // -------------------------------------------------------------------------
    // Serve cached image for cart / order thumbnails
    // -------------------------------------------------------------------------

    /**
     * If a cart/order thumbnail is requested for a canvas variation that has
     * a cached merged image, return that image instead of the blank base.
     *
     * This filter fires when WooCommerce renders product images in cart,
     * checkout, order emails, and admin order view.
     */
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

    /**
     * Get distinct active pa_boja slugs from a product's variations.
     */
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
            AND    pm.meta_key   = %s
            AND    pm2.meta_key  = '_stock_status'
            AND    pm2.meta_value = 'instock'
            ORDER  BY pm.meta_value
        ", $product_id, $meta_key ) );
    }
}
