<?php
/**
 * Thready Variation Factory
 *
 * Creates one variable product where variations = pa_tip-proizvoda × pa_boja.
 * Colors can differ per type. Sizes stored as _thready_available_sizes meta.
 * Print positioning stored per tip. Light print flag stored per variation.
 */

defined( 'ABSPATH' ) || exit;

class Thready_Variation_Factory {

    const META_RENDER_MODE    = '_thready_render_mode';
    const META_DESIGN_VERSION = '_thready_design_version';
    const META_PRINT_FRONT    = '_thready_print_image';
    const META_PRINT_LIGHT    = '_thready_light_print_image';
    const META_PRINT_BACK     = '_thready_back_print_image';
    const META_TIP_POSITIONS  = '_thready_tip_positions';

    const BATCH_SIZE = 50;

    /**
     * Register hooks for auto-generating thumbnails when variations are
     * created / saved via the standard WooCommerce product-edit screen.
     */
    public static function init() {
        add_action( 'woocommerce_save_product_variation', [ __CLASS__, 'on_variation_save' ], 20, 2 );
    }

    /**
     * After a variation is saved from the WooCommerce product-edit screen,
     * generate its 150×150 front (and optionally back) thumbnail using the
     * tip_positions stored on the parent product — so the admin doesn't have
     * to re-enter positioning data for manually added variations.
     */
    public static function on_variation_save( $variation_id, $index ) {
        $variation = wc_get_product( $variation_id );
        if ( ! $variation ) return;

        $product_id = $variation->get_parent_id();
        if ( ! $product_id ) return;

        // Only canvas-mode products
        if ( get_post_meta( $product_id, self::META_RENDER_MODE, true ) !== 'canvas' ) return;

        // Already has a thumbnail? Skip to avoid regenerating on every save.
        $existing_thumb = (int) get_post_meta( $variation_id, '_thumbnail_id', true );
        if ( $existing_thumb && wp_attachment_is_image( $existing_thumb ) ) return;

        // Read slugs directly from postmeta — get_attribute() returns the
        // term NAME for taxonomy attributes, which breaks mockup lookups
        // when color names contain ž/č/ć/š.
        $tip_slug  = (string) get_post_meta( $variation_id, 'attribute_' . THREADY_TAX_TIP,  true );
        $boja_slug = (string) get_post_meta( $variation_id, 'attribute_' . THREADY_TAX_BOJA, true );
        if ( ! $tip_slug || ! $boja_slug ) return;

        $mockup = Thready_Mockup_Library::get_urls( $tip_slug, $boja_slug );
        if ( ! $mockup || ! $mockup['front'] ) return;

        $positions_raw = get_post_meta( $product_id, self::META_TIP_POSITIONS, true );
        $tip_positions = $positions_raw ? json_decode( $positions_raw, true ) : [];
        $pos_front     = $tip_positions[ $tip_slug ]['front'] ?? [ 'x' => 50, 'y' => 25, 'width' => 50 ];

        $front_print_id = (int) get_post_meta( $product_id, self::META_PRINT_FRONT, true );
        $light_print_id = (int) get_post_meta( $product_id, self::META_PRINT_LIGHT, true );
        $use_light      = get_post_meta( $variation_id, '_thready_use_light_print', true ) === 'yes';
        $print_id       = ( $use_light && $light_print_id ) ? $light_print_id : $front_print_id;
        if ( ! $print_id ) return;

        // Generate front thumbnail
        $front_attach = Thready_Image_Handler::generate_merged_image(
            $product_id, $variation_id,
            [ 'print_x' => $pos_front['x'], 'print_y' => $pos_front['y'],
              'print_width' => $pos_front['width'], 'base_image' => $mockup['front'] ],
            $print_id, 'front', 150
        );

        if ( $front_attach && ! is_wp_error( $front_attach ) ) {
            // Use direct meta to avoid triggering another save hook cycle
            update_post_meta( $variation_id, '_thumbnail_id', $front_attach );
        }

        // Generate back thumbnail if back print + back mockup exist
        $back_print_id = (int) get_post_meta( $product_id, self::META_PRINT_BACK, true );
        if ( $back_print_id && ! empty( $mockup['back'] ) ) {
            $pos_back = $tip_positions[ $tip_slug ]['back'] ?? [ 'x' => 50, 'y' => 25, 'width' => 50 ];
            $back_attach = Thready_Image_Handler::generate_merged_image(
                $product_id, $variation_id,
                [ 'print_x' => $pos_back['x'], 'print_y' => $pos_back['y'],
                  'print_width' => $pos_back['width'], 'base_image' => $mockup['back'] ],
                $back_print_id, 'back', 150
            );

            if ( $back_attach && ! is_wp_error( $back_attach ) ) {
                update_post_meta( $variation_id, '_thready_back_thumb_id', $back_attach );
                // Add to variation gallery via direct meta
                update_post_meta( $variation_id, '_product_image_gallery', (string) $back_attach );
            }
        }
    }

    // -------------------------------------------------------------------------
    // create_product
    // -------------------------------------------------------------------------

    /**
     * $args = [
     *   'name'            => string,
     *   'tip_slugs'       => string[],     pa_tip-proizvoda slugs
     *   'tip_colors'      => [             colors per type (can differ)
     *                          tipSlug => [ ['slug'=>string, 'light_print'=>bool], … ]
     *                        ],
     *   'tip_sizes'       => [             sizes per type
     *                          tipSlug => string[]
     *                        ],
     *   'tip_prices'      => [
     *                          tipSlug => ['regular'=>float, 'sale'=>float|null]
     *                        ],
     *   'tip_positions'   => [
     *                          tipSlug => [
     *                            'front' => ['x'=>int,'y'=>int,'width'=>int],
     *                            'back'  => ['x'=>int,'y'=>int,'width'=>int]|null
     *                          ]
     *                        ],
     *   'print_front_id'  => int,
     *   'print_light_id'  => int|null,
     *   'print_back_id'   => int|null,
     *   'description'     => string,
     *   'status'          => 'publish'|'draft',
     * ]
     *
     * @return int|WP_Error
     */
    public static function create_product( array $args ) {
        $args = wp_parse_args( $args, [
            'name'               => '',
            'sku'                => '',
            'short_description'  => '',
            'category_ids'       => [],
            'tag_names'          => [],
            'tip_slugs'          => [],
            'tip_colors'         => [],
            'tip_sizes'          => [],
            'tip_prices'         => [],
            'tip_positions'      => [],
            'print_front_id'     => 0,
            'print_light_id'     => null,
            'print_back_id'      => null,
            'description'        => '',
            'status'             => 'publish',
            'featured_tip_slug'  => '',
            'featured_boja_slug' => '',
            'featured_side'      => 'front',
        ] );

        $errors = self::validate( $args );
        if ( ! empty( $errors ) ) {
            return new WP_Error( 'thready_invalid_args', implode( ' | ', $errors ) );
        }

        // Collect all unique boja slugs across all types for product attributes
        $all_boja_slugs = self::collect_all_bojas( $args['tip_colors'] );

        $wc_attrs = self::build_attributes( $args['tip_slugs'], $all_boja_slugs );
        if ( is_wp_error( $wc_attrs ) ) return $wc_attrs;

        $product = new WC_Product_Variable();
        $product->set_name( sanitize_text_field( $args['name'] ) );
        $product->set_status( $args['status'] );
        $product->set_description( wp_kses_post( $args['description'] ) );
        $product->set_short_description( wp_kses_post( $args['short_description'] ) );
        $product->set_attributes( $wc_attrs );

        if ( ! empty( $args['sku'] ) ) {
            $product->set_sku( sanitize_text_field( $args['sku'] ) );
        }
        if ( ! empty( $args['category_ids'] ) ) {
            $product->set_category_ids( array_map( 'absint', $args['category_ids'] ) );
        }

        // Resolve tag names to IDs — creates new product_tag terms as needed
        if ( ! empty( $args['tag_names'] ) ) {
            $tag_ids = [];
            foreach ( (array) $args['tag_names'] as $name ) {
                $name = sanitize_text_field( $name );
                if ( ! $name ) continue;
                $term = get_term_by( 'name', $name, 'product_tag' );
                if ( $term ) {
                    $tag_ids[] = $term->term_id;
                } else {
                    $new_term = wp_insert_term( $name, 'product_tag' );
                    if ( ! is_wp_error( $new_term ) ) {
                        $tag_ids[] = $new_term['term_id'];
                    }
                }
            }
            if ( ! empty( $tag_ids ) ) {
                $product->set_tag_ids( $tag_ids );
            }
        }

        $product_id = $product->save();
        if ( ! $product_id ) {
            return new WP_Error( 'thready_save_failed', 'Failed to save product.' );
        }

        // Store Thready meta on product
        update_post_meta( $product_id, self::META_RENDER_MODE,    'canvas' );
        update_post_meta( $product_id, self::META_DESIGN_VERSION, 1 );
        update_post_meta( $product_id, self::META_PRINT_FRONT,    absint( $args['print_front_id'] ) );
        update_post_meta( $product_id, self::META_TIP_POSITIONS,  wp_json_encode( $args['tip_positions'] ) );

        if ( $args['print_light_id'] ) {
            update_post_meta( $product_id, self::META_PRINT_LIGHT, absint( $args['print_light_id'] ) );
        }
        if ( $args['print_back_id'] ) {
            update_post_meta( $product_id, self::META_PRINT_BACK, absint( $args['print_back_id'] ) );
        }

        // Bulk-create variations per type
        $result = self::bulk_insert_from_tip_colors(
            $product_id,
            $args['tip_colors'],
            $args['tip_sizes'],
            $args['tip_prices']
        );

        if ( is_wp_error( $result ) ) {
            error_log( 'Thready Factory: ' . $result->get_error_message() );
        }

        WC_Product_Variable::sync( $product_id );
        wc_delete_product_transients( $product_id );

        // Set default variation attributes (first tip + first boja)
        // so canvas fires on page load without customer interaction
        $first_tip  = $args['tip_slugs'][0] ?? '';
        $first_boja = $args['tip_colors'][ $first_tip ][0]['slug'] ?? '';
        if ( $first_tip && $first_boja ) {
            self::set_default_attributes( $product_id, $first_tip, $first_boja );
        }

        // Pre-generate 150×150 variation thumbnails (cart, email, admin, gallery nav)
        self::generate_variation_thumbnails( $product_id );

        // Generate 800×800 featured image + optional back gallery image.
        if ( $args['featured_tip_slug'] && $args['featured_boja_slug'] ) {
            $featured_id = self::generate_featured_image(
                $product_id,
                $args['featured_tip_slug'],
                $args['featured_boja_slug'],
                $args['featured_side']
            );

            // Flush WC object cache so we get a fresh product with current DB state
            clean_post_cache( $product_id );
            $product = wc_get_product( $product_id );
            if ( $product ) {
                if ( $featured_id && ! is_wp_error( $featured_id ) ) {
                    $product->set_image_id( $featured_id );
                }
                $product->save();
            }

            // Generate back gallery image and add it to the product gallery
            // via direct meta update AFTER save — this avoids triggering
            // Greenshift's save_post hook which syncs _product_image_gallery
            // into its own greenshiftwoo360_image_gallery meta.
            if ( $args['print_back_id'] ) {
                $back_side = $args['featured_side'] === 'back' ? 'front' : 'back';
                $back_gallery_id = self::generate_featured_image(
                    $product_id,
                    $args['featured_tip_slug'],
                    $args['featured_boja_slug'],
                    $back_side,
                    'gallery'
                );
                if ( $back_gallery_id && ! is_wp_error( $back_gallery_id ) ) {
                    update_post_meta( $product_id, '_product_image_gallery', (string) $back_gallery_id );
                }
            }
        }

        return $product_id;
    }

    // -------------------------------------------------------------------------
    // sync_variations
    // -------------------------------------------------------------------------

    public static function sync_variations( $product_id, array $config ) {
        $product = wc_get_product( $product_id );
        if ( ! $product || ! $product->is_type( 'variable' ) ) {
            return new WP_Error( 'thready_invalid_product', 'Product not found or not variable.' );
        }

        $tip_colors    = $config['tip_colors']    ?? [];
        $tip_sizes     = $config['tip_sizes']     ?? [];
        $tip_prices    = $config['tip_prices']    ?? [];
        $tip_positions = $config['tip_positions'] ?? [];

        // Build desired set: tip|boja → [light_print, sizes_csv, price]
        $desired   = [];
        $all_bojas = self::collect_all_bojas( $tip_colors );

        foreach ( $tip_colors as $tip_slug => $colors ) {
            $sizes_csv = implode( ',', array_map( 'sanitize_title', $tip_sizes[ $tip_slug ] ?? [] ) );
            foreach ( $colors as $color ) {
                $boja_slug = sanitize_title( $color['slug'] );
                $desired[ $tip_slug . '|' . $boja_slug ] = [
                    'tip_slug'    => sanitize_title( $tip_slug ),
                    'boja_slug'   => $boja_slug,
                    'light_print' => ! empty( $color['light_print'] ),
                    'sizes_csv'   => $sizes_csv,
                    'price'       => $tip_prices[ $tip_slug ] ?? [],
                ];
            }
        }

        $existing  = self::map_existing( $product_id );
        $counters  = [ 'created' => 0, 'deactivated' => 0, 'skipped' => 0 ];
        $to_create = [];

        foreach ( $desired as $key => $info ) {
            if ( isset( $existing[ $key ] ) ) {
                $counters['skipped']++;
                $var_id  = $existing[ $key ];
                $price   = $info['price'];
                $regular = (float) ( $price['regular'] ?? 0 );
                $sale    = isset( $price['sale'] ) && $price['sale'] !== '' ? (float) $price['sale'] : null;
                self::update_price( $var_id, $regular, $sale );
                update_post_meta( $var_id, '_thready_available_sizes',  $info['sizes_csv'] );
                update_post_meta( $var_id, '_thready_use_light_print',  $info['light_print'] ? 'yes' : 'no' );
            } else {
                $to_create[] = $info;
            }
        }

        foreach ( $existing as $key => $var_id ) {
            if ( ! isset( $desired[ $key ] ) ) {
                self::deactivate( $var_id );
                $counters['deactivated']++;
            }
        }

        if ( ! empty( $to_create ) ) {
            $new_tips  = array_unique( array_column( $to_create, 'tip_slug' ) );
            $new_bojas = array_unique( array_column( $to_create, 'boja_slug' ) );
            self::ensure_attributes_include( $product_id, $new_tips, $new_bojas );

            $now = current_time( 'mysql' );
            foreach ( array_chunk( $to_create, self::BATCH_SIZE ) as $batch ) {
                foreach ( $batch as $info ) {
                    $price   = $info['price'];
                    $regular = (float) ( $price['regular'] ?? 0 );
                    $sale    = isset( $price['sale'] ) && $price['sale'] !== '' ? (float) $price['sale'] : null;
                    $var_id  = self::insert_one(
                        $product_id,
                        $info['tip_slug'],
                        $info['boja_slug'],
                        $regular, $sale,
                        $info['sizes_csv'],
                        $info['light_print'],
                        $now
                    );
                    if ( $var_id ) $counters['created']++;
                }
                wp_cache_flush_group( 'posts' );
            }
        }

        update_post_meta( $product_id, self::META_TIP_POSITIONS, wp_json_encode( $tip_positions ) );

        // Rebuild product attributes to match only the active variations.
        // This removes stale color/type terms that were deactivated.
        self::rebuild_product_attributes( $product_id );

        WC_Product_Variable::sync( $product_id );
        wc_delete_product_transients( $product_id );

        return $counters;
    }

    /**
     * Rebuild the product's pa_tip-proizvoda and pa_boja attributes
     * from active (non-trashed, in-stock) variations only.
     *
     * Removes stale terms that no longer have active variations,
     * updates WC attribute options, and cleans up term relationships.
     */
    public static function rebuild_product_attributes( $product_id ) {
        global $wpdb;

        $tip_key  = 'attribute_' . THREADY_TAX_TIP;
        $boja_key = 'attribute_' . THREADY_TAX_BOJA;

        // Get all active tip + boja slugs from non-trashed, instock variations
        $rows = $wpdb->get_results( $wpdb->prepare( "
            SELECT MAX( CASE WHEN pm.meta_key = %s THEN pm.meta_value END ) AS tip,
                   MAX( CASE WHEN pm.meta_key = %s THEN pm.meta_value END ) AS boja
            FROM   {$wpdb->posts} p
            JOIN   {$wpdb->postmeta} pm  ON pm.post_id = p.ID
            JOIN   {$wpdb->postmeta} pm2 ON pm2.post_id = p.ID
            WHERE  p.post_parent = %d
            AND    p.post_type   = 'product_variation'
            AND    p.post_status != 'trash'
            AND    pm2.meta_key  = '_stock_status'
            AND    pm2.meta_value = 'instock'
            GROUP  BY p.ID
        ", $tip_key, $boja_key, $product_id ) );

        $active_tips  = [];
        $active_bojas = [];
        foreach ( $rows as $row ) {
            if ( $row->tip )  $active_tips[]  = $row->tip;
            if ( $row->boja ) $active_bojas[] = $row->boja;
        }
        $active_tips  = array_values( array_unique( $active_tips ) );
        $active_bojas = array_values( array_unique( $active_bojas ) );

        $product = wc_get_product( $product_id );
        if ( ! $product ) return;

        $attributes = $product->get_attributes();

        foreach ( [ THREADY_TAX_TIP => $active_tips, THREADY_TAX_BOJA => $active_bojas ] as $taxonomy => $active_slugs ) {
            if ( ! isset( $attributes[ $taxonomy ] ) ) continue;

            // Resolve slugs to term IDs
            $term_ids = [];
            foreach ( $active_slugs as $slug ) {
                $term = get_term_by( 'slug', $slug, $taxonomy );
                if ( $term ) $term_ids[] = $term->term_id;
            }

            // Update WC attribute options
            $attr = $attributes[ $taxonomy ];
            $attr->set_options( $term_ids );
            $attributes[ $taxonomy ] = $attr;

            // Update term relationships
            wp_set_object_terms( $product_id, $term_ids, $taxonomy );
        }

        $product->set_attributes( $attributes );
        $product->save();
    }

    // -------------------------------------------------------------------------
    // add / remove color
    // -------------------------------------------------------------------------

    public static function add_color( $product_id, $tip_slug, $boja_slug, $light_print = false, $overrides = [] ) {
        $tip_slug  = sanitize_title( $tip_slug );
        $boja_slug = sanitize_title( $boja_slug );

        if ( ! term_exists( $boja_slug, THREADY_TAX_BOJA ) ) {
            return new WP_Error( 'thready_invalid_term', "Color $boja_slug not found." );
        }

        // Use overrides if provided (for new types not yet on the product),
        // otherwise inherit from existing variations of the same type.
        if ( ! empty( $overrides['regular'] ) ) {
            $regular   = (float) $overrides['regular'];
            $sale      = isset( $overrides['sale'] ) && $overrides['sale'] !== '' && $overrides['sale'] !== null
                       ? (float) $overrides['sale'] : null;
        } else {
            $prices  = self::get_tip_prices( $product_id );
            $price   = $prices[ $tip_slug ] ?? [ 'regular' => 0, 'sale' => null ];
            $regular = (float) ( $price['regular'] ?? 0 );
            $sale    = $price['sale'] ?? null;
        }

        if ( ! empty( $overrides['sizes_csv'] ) ) {
            $sizes_csv = sanitize_text_field( $overrides['sizes_csv'] );
        } else {
            $sizes_csv = self::get_sizes_csv_for_tip( $product_id, $tip_slug );
        }

        // Update product attributes to include the new tip + color
        self::ensure_attributes_include( $product_id, [ $tip_slug ], [ $boja_slug ] );

        // Also explicitly set term relationships
        $tip_term = get_term_by( 'slug', $tip_slug, THREADY_TAX_TIP );
        if ( $tip_term ) {
            wp_set_object_terms( $product_id, $tip_term->term_id, THREADY_TAX_TIP, true );
        }
        $boja_term = get_term_by( 'slug', $boja_slug, THREADY_TAX_BOJA );
        if ( $boja_term ) {
            wp_set_object_terms( $product_id, $boja_term->term_id, THREADY_TAX_BOJA, true );
        }

        $var_id = self::insert_one( $product_id, $tip_slug, $boja_slug, $regular, $sale, $sizes_csv, $light_print, current_time( 'mysql' ) );

        if ( $var_id ) {
            WC_Product_Variable::sync( $product_id );
            wc_delete_product_transients( $product_id );
            return [ 'created' => 1, 'variation_id' => $var_id ];
        }
        return new WP_Error( 'thready_insert_failed', 'Could not create variation.' );
    }

    public static function remove_color( $product_id, $boja_slug ) {
        $boja_slug = sanitize_title( $boja_slug );
        $count     = 0;
        foreach ( self::map_existing( $product_id ) as $key => $var_id ) {
            [ , $b ] = explode( '|', $key );
            if ( $b === $boja_slug ) { self::deactivate( $var_id ); $count++; }
        }
        wc_delete_product_transients( $product_id );
        return $count;
    }

    // -------------------------------------------------------------------------
    // add / remove tip
    // -------------------------------------------------------------------------

    public static function add_tip( $product_id, $tip_slug, array $boja_configs, array $sizes, $regular_price, $sale_price = null ) {
        $tip_slug = sanitize_title( $tip_slug );
        if ( ! term_exists( $tip_slug, THREADY_TAX_TIP ) ) {
            return new WP_Error( 'thready_invalid_term', "Type $tip_slug not found." );
        }

        $sizes_csv = implode( ',', array_map( 'sanitize_title', $sizes ) );
        $all_bojas = array_map( fn( $c ) => sanitize_title( $c['slug'] ), $boja_configs );

        self::ensure_attributes_include( $product_id, [ $tip_slug ], $all_bojas );

        $now     = current_time( 'mysql' );
        $created = 0;
        foreach ( $boja_configs as $color ) {
            $var_id = self::insert_one(
                $product_id,
                $tip_slug,
                sanitize_title( $color['slug'] ),
                (float) $regular_price,
                $sale_price ? (float) $sale_price : null,
                $sizes_csv,
                ! empty( $color['light_print'] ),
                $now
            );
            if ( $var_id ) $created++;
        }

        WC_Product_Variable::sync( $product_id );
        wc_delete_product_transients( $product_id );
        return [ 'created' => $created ];
    }

    public static function remove_tip( $product_id, $tip_slug ) {
        $tip_slug = sanitize_title( $tip_slug );
        $count    = 0;
        foreach ( self::map_existing( $product_id ) as $key => $var_id ) {
            [ $t ] = explode( '|', $key );
            if ( $t === $tip_slug ) { self::deactivate( $var_id ); $count++; }
        }
        wc_delete_product_transients( $product_id );
        return $count;
    }

    // -------------------------------------------------------------------------
    // set_tip_price
    // -------------------------------------------------------------------------

    public static function set_tip_price( $product_id, $tip_slug, $regular, $sale = null ) {
        $tip_slug = sanitize_title( $tip_slug );
        $count    = 0;
        foreach ( self::map_existing( $product_id ) as $key => $var_id ) {
            [ $t ] = explode( '|', $key );
            if ( $t === $tip_slug ) { self::update_price( $var_id, (float) $regular, $sale ); $count++; }
        }
        wc_delete_product_transients( $product_id );
        return $count;
    }

    // -------------------------------------------------------------------------
    // get_summary
    // -------------------------------------------------------------------------

    public static function get_summary( $product_id ) {
        $product       = wc_get_product( $product_id );
        $positions_raw = get_post_meta( $product_id, self::META_TIP_POSITIONS, true );

        return [
            'render_mode'      => get_post_meta( $product_id, self::META_RENDER_MODE,    true ) ?: 'legacy',
            'design_version'   => (int) get_post_meta( $product_id, self::META_DESIGN_VERSION, true ),
            'tips'             => self::get_active_tips( $product_id ),
            'colors'           => self::get_active_bojas( $product_id ),
            'tip_colors'       => self::get_tip_colors_meta( $product_id ),
            'tip_sizes'        => self::get_tip_sizes_meta( $product_id ),
            'total_variations' => $product ? count( $product->get_children() ) : 0,
            'active_variations'=> self::count_active( $product_id ),
            'tip_prices'       => self::get_tip_prices( $product_id ),
            'print_front'      => (int) get_post_meta( $product_id, self::META_PRINT_FRONT, true ),
            'print_light'      => (int) get_post_meta( $product_id, self::META_PRINT_LIGHT, true ),
            'print_back'       => (int) get_post_meta( $product_id, self::META_PRINT_BACK,  true ),
            'tip_positions'    => $positions_raw ? json_decode( $positions_raw, true ) : [],
        ];
    }

    // =========================================================================
    // Ordering, default attributes, image generation
    // =========================================================================

    /**
     * Get attribute terms in the order configured in WC > Attributes.
     *
     * Delegates to WooCommerce's own ordering (wc_attribute_orderby +
     * wc_terms_clauses filter) so the result matches exactly what the
     * dropdowns and swatches use everywhere else on the site.
     *
     * Respects whichever "Default sort order" the attribute is set to:
     *   - Custom ordering (drag-and-drop → order_{taxonomy} term meta)
     *   - Term ID
     *   - Name
     *   - Name (numeric)
     */
    public static function get_ordered_terms( $taxonomy ) {
        $args = [
            'taxonomy'   => $taxonomy,
            'hide_empty' => false,
        ];

        // Only WC attribute taxonomies support the menu_order argument.
        if ( function_exists( 'wc_attribute_orderby' ) && function_exists( 'taxonomy_is_product_attribute' ) && taxonomy_is_product_attribute( $taxonomy ) ) {
            $orderby = wc_attribute_orderby( $taxonomy );

            switch ( $orderby ) {
                case 'name':
                    $args['orderby'] = 'name';
                    $args['order']   = 'ASC';
                    break;
                case 'name_num':
                    $args['orderby'] = 'name_num';
                    $args['order']   = 'ASC';
                    break;
                case 'id':
                    $args['orderby'] = 'id';
                    $args['order']   = 'ASC';
                    break;
                case 'menu_order':
                default:
                    // Custom (drag-and-drop) ordering — handled by WC's
                    // wc_terms_clauses() filter which joins order_{taxonomy}
                    // meta via LEFT JOIN so terms without meta still appear.
                    $args['menu_order'] = 'ASC';
                    break;
            }
        } else {
            $args['orderby'] = 'name';
            $args['order']   = 'ASC';
        }

        $terms = get_terms( $args );
        return is_wp_error( $terms ) ? [] : $terms;
    }

    /**
     * Set WooCommerce default variation attributes so canvas fires on page load.
     */
    public static function set_default_attributes( $product_id, $tip_slug, $boja_slug ) {
        $product = wc_get_product( $product_id );
        if ( ! $product ) return;

        $product->set_default_attributes( [
            THREADY_TAX_TIP  => sanitize_title( $tip_slug  ),
            THREADY_TAX_BOJA => sanitize_title( $boja_slug ),
        ] );
        $product->save();
    }

    /**
     * Pre-generate 150×150 merged thumbnails for every variation.
     * Sets the result as the variation image (used in cart, email, admin, gallery nav).
     * Also generates back thumbnail and stores it as _thready_back_thumb_id.
     *
     * @return int  Number of variations processed.
     */
    public static function generate_variation_thumbnails( $product_id ) {
        global $wpdb;

        $tip_key  = 'attribute_' . THREADY_TAX_TIP;
        $boja_key = 'attribute_' . THREADY_TAX_BOJA;

        $rows = $wpdb->get_results( $wpdb->prepare( "
            SELECT p.ID,
                   MAX( CASE WHEN pm.meta_key = %s                        THEN pm.meta_value END ) AS tip,
                   MAX( CASE WHEN pm.meta_key = %s                        THEN pm.meta_value END ) AS boja,
                   MAX( CASE WHEN pm.meta_key = '_thready_use_light_print' THEN pm.meta_value END ) AS light_print
            FROM   {$wpdb->posts} p
            JOIN   {$wpdb->postmeta} pm ON pm.post_id = p.ID
            WHERE  p.post_parent = %d
            AND    p.post_type   = 'product_variation'
            AND    p.post_status = 'publish'
            GROUP  BY p.ID
        ", $tip_key, $boja_key, $product_id ) );

        $positions_raw = get_post_meta( $product_id, self::META_TIP_POSITIONS, true );
        $tip_positions = $positions_raw ? json_decode( $positions_raw, true ) : [];

        $front_print_id = (int) get_post_meta( $product_id, self::META_PRINT_FRONT, true );
        $light_print_id = (int) get_post_meta( $product_id, self::META_PRINT_LIGHT, true );
        $back_print_id  = (int) get_post_meta( $product_id, self::META_PRINT_BACK,  true );

        $generated = 0;

        foreach ( $rows as $row ) {
            if ( ! $row->tip || ! $row->boja ) continue;

            $mockup = Thready_Mockup_Library::get_urls( $row->tip, $row->boja );
            if ( ! $mockup || ! $mockup['front'] ) continue;

            // Determine which front print to use
            $use_light  = $row->light_print === 'yes';
            $print_id   = ( $use_light && $light_print_id ) ? $light_print_id : $front_print_id;
            if ( ! $print_id ) continue;

            $pos_front = $tip_positions[ $row->tip ]['front'] ?? [ 'x' => 50, 'y' => 25, 'width' => 50 ];

            // Generate FRONT thumbnail
            $front_attach = Thready_Image_Handler::generate_merged_image(
                $product_id, (int) $row->ID,
                [ 'print_x' => $pos_front['x'], 'print_y' => $pos_front['y'],
                  'print_width' => $pos_front['width'], 'base_image' => $mockup['front'] ],
                $print_id, 'front', 150
            );

            if ( $front_attach && ! is_wp_error( $front_attach ) ) {
                $generated++;
            }

            // Generate BACK thumbnail (if back print + back base image exist)
            $back_attach = null;
            if ( $back_print_id && $mockup['back'] ) {
                $pos_back = $tip_positions[ $row->tip ]['back'] ?? [ 'x' => 50, 'y' => 25, 'width' => 50 ];

                $back_attach = Thready_Image_Handler::generate_merged_image(
                    $product_id, (int) $row->ID,
                    [ 'print_x' => $pos_back['x'], 'print_y' => $pos_back['y'],
                      'print_width' => $pos_back['width'], 'base_image' => $mockup['back'] ],
                    $back_print_id, 'back', 150
                );

                if ( $back_attach && ! is_wp_error( $back_attach ) ) {
                    update_post_meta( (int) $row->ID, '_thready_back_thumb_id', $back_attach );
                } else {
                    $back_attach = null;
                }
            }

            // Save front image + back gallery in ONE wc_get_product/save call
            // to avoid CRUD caching conflicts from multiple loads of the same ID.
            $var = wc_get_product( (int) $row->ID );
            if ( $var ) {
                if ( $front_attach && ! is_wp_error( $front_attach ) ) {
                    $var->set_image_id( $front_attach );
                }
                if ( $back_attach ) {
                    $var->set_gallery_image_ids( [ $back_attach ] );
                }
                $var->save();
            }
        }

        return $generated;
    }

    /**
     * Generate thumbnails for a SINGLE variation (front + optional back).
     * Used by Quick Add Color to avoid regenerating all variations.
     *
     * @param  int $product_id   Parent product ID.
     * @param  int $variation_id Variation ID.
     * @return bool True on success.
     */
    public static function generate_single_variation_thumbnail( $product_id, $variation_id ) {
        $tip_slug  = (string) get_post_meta( $variation_id, 'attribute_' . THREADY_TAX_TIP,  true );
        $boja_slug = (string) get_post_meta( $variation_id, 'attribute_' . THREADY_TAX_BOJA, true );
        if ( ! $tip_slug || ! $boja_slug ) return false;

        $mockup = Thready_Mockup_Library::get_urls( $tip_slug, $boja_slug );
        if ( ! $mockup || ! $mockup['front'] ) return false;

        $positions_raw  = get_post_meta( $product_id, self::META_TIP_POSITIONS, true );
        $tip_positions  = $positions_raw ? json_decode( $positions_raw, true ) : [];
        $front_print_id = (int) get_post_meta( $product_id, self::META_PRINT_FRONT, true );
        $light_print_id = (int) get_post_meta( $product_id, self::META_PRINT_LIGHT, true );
        $back_print_id  = (int) get_post_meta( $product_id, self::META_PRINT_BACK,  true );

        $use_light = get_post_meta( $variation_id, '_thready_use_light_print', true ) === 'yes';
        $print_id  = ( $use_light && $light_print_id ) ? $light_print_id : $front_print_id;
        if ( ! $print_id ) return false;

        $pos_front = $tip_positions[ $tip_slug ]['front'] ?? [ 'x' => 50, 'y' => 25, 'width' => 50 ];

        // Generate front thumbnail
        $front_attach = Thready_Image_Handler::generate_merged_image(
            $product_id, $variation_id,
            [ 'print_x' => $pos_front['x'], 'print_y' => $pos_front['y'],
              'print_width' => $pos_front['width'], 'base_image' => $mockup['front'] ],
            $print_id, 'front', 150
        );

        // Generate back thumbnail
        $back_attach = null;
        if ( $back_print_id && ! empty( $mockup['back'] ) ) {
            $pos_back = $tip_positions[ $tip_slug ]['back'] ?? [ 'x' => 50, 'y' => 25, 'width' => 50 ];
            $back_attach = Thready_Image_Handler::generate_merged_image(
                $product_id, $variation_id,
                [ 'print_x' => $pos_back['x'], 'print_y' => $pos_back['y'],
                  'print_width' => $pos_back['width'], 'base_image' => $mockup['back'] ],
                $back_print_id, 'back', 150
            );
            if ( $back_attach && ! is_wp_error( $back_attach ) ) {
                update_post_meta( $variation_id, '_thready_back_thumb_id', $back_attach );
            } else {
                $back_attach = null;
            }
        }

        // Save front + back in one call
        $var = wc_get_product( $variation_id );
        if ( $var ) {
            if ( $front_attach && ! is_wp_error( $front_attach ) ) {
                $var->set_image_id( $front_attach );
            }
            if ( $back_attach ) {
                $var->set_gallery_image_ids( [ $back_attach ] );
            }
            $var->save();
        }

        return true;
    }

    /**
     * Generate an 800×800 merged image for a specific tip+boja+side combination.
     * Used as the product featured image.
     *
     * @return int|WP_Error  Attachment ID on success.
     */
    public static function generate_featured_image( $product_id, $tip_slug, $boja_slug, $side = 'front', $image_type = 'featured' ) {
        $mockup = Thready_Mockup_Library::get_urls( $tip_slug, $boja_slug );
        if ( ! $mockup ) return new WP_Error( 'no_mockup', 'No mockup found.' );

        $base_url = $side === 'back' ? ( $mockup['back'] ?: $mockup['front'] ) : $mockup['front'];
        if ( ! $base_url ) return new WP_Error( 'no_base', 'No base image.' );

        $positions_raw = get_post_meta( $product_id, self::META_TIP_POSITIONS, true );
        $tip_positions = $positions_raw ? json_decode( $positions_raw, true ) : [];
        $pos           = $tip_positions[ $tip_slug ][ $side ] ?? [ 'x' => 50, 'y' => 25, 'width' => 50 ];

        if ( $side === 'back' ) {
            $print_id = (int) get_post_meta( $product_id, self::META_PRINT_BACK, true );
        } else {
            $print_id = (int) get_post_meta( $product_id, self::META_PRINT_FRONT, true );
        }

        if ( ! $print_id ) return new WP_Error( 'no_print', 'No print image.' );

        return Thready_Image_Handler::generate_merged_image(
            $product_id, 0,
            [ 'print_x' => $pos['x'], 'print_y' => $pos['y'],
              'print_width' => $pos['width'], 'base_image' => $base_url ],
            $print_id, $image_type, 800
        );
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    private static function validate( array $args ) {
        $errors = [];
        if ( empty( $args['name'] ) )       $errors[] = 'Product name is required.';
        if ( empty( $args['tip_slugs'] ) )   $errors[] = 'At least one product type is required.';
        if ( empty( $args['tip_colors'] ) )  $errors[] = 'At least one color per type is required.';
        if ( empty( $args['print_front_id'] ) || ! wp_attachment_is_image( $args['print_front_id'] ) ) {
            $errors[] = 'A valid front print image is required.';
        }
        foreach ( $args['tip_slugs'] as $t ) {
            $colors = $args['tip_colors'][ $t ] ?? [];
            if ( empty( $colors ) ) $errors[] = "No colors selected for type: $t";

            $price = $args['tip_prices'][ $t ] ?? [];
            if ( empty( $price['regular'] ) || (float) $price['regular'] <= 0 ) {
                $errors[] = "Price required for type: $t";
            }
        }
        return $errors;
    }

    /**
     * Collect union of all boja slugs across all types.
     */
    private static function collect_all_bojas( array $tip_colors ) {
        $all = [];
        foreach ( $tip_colors as $colors ) {
            foreach ( $colors as $color ) {
                $all[] = sanitize_title( $color['slug'] );
            }
        }
        return array_values( array_unique( $all ) );
    }

    private static function build_attributes( array $tip_slugs, array $boja_slugs ) {
        $attrs = [];
        foreach ( [ THREADY_TAX_TIP => $tip_slugs, THREADY_TAX_BOJA => $boja_slugs ] as $taxonomy => $slugs ) {
            $term_ids = [];
            foreach ( $slugs as $slug ) {
                $term = get_term_by( 'slug', sanitize_title( $slug ), $taxonomy );
                if ( ! $term ) {
                    return new WP_Error( 'thready_invalid_term', "Term $slug not found in $taxonomy." );
                }
                $term_ids[] = $term->term_id;
            }
            $attr = new WC_Product_Attribute();
            $attr->set_id( wc_attribute_taxonomy_id_by_name( $taxonomy ) );
            $attr->set_name( $taxonomy );
            $attr->set_options( $term_ids );
            $attr->set_visible( true );
            $attr->set_variation( true );
            $attrs[] = $attr;
        }
        return $attrs;
    }

    public static function ensure_attributes_include( $product_id, array $new_tips, array $new_bojas ) {
        $product = wc_get_product( $product_id );
        if ( ! $product ) return;

        $attributes = $product->get_attributes();
        $changed    = false;

        foreach ( [ THREADY_TAX_TIP => $new_tips, THREADY_TAX_BOJA => $new_bojas ] as $taxonomy => $slugs ) {
            if ( empty( $slugs ) || ! isset( $attributes[ $taxonomy ] ) ) continue;

            $attr    = $attributes[ $taxonomy ];
            $cur_ids = $attr->get_options();
            $added   = false;

            foreach ( $slugs as $slug ) {
                $term = get_term_by( 'slug', sanitize_title( $slug ), $taxonomy );
                if ( $term && ! in_array( $term->term_id, $cur_ids, true ) ) {
                    $cur_ids[] = $term->term_id;
                    $added     = true;
                }
            }

            if ( $added ) {
                $attr->set_options( $cur_ids );
                $attributes[ $taxonomy ] = $attr;
                $changed = true;
            }
        }

        if ( $changed ) { $product->set_attributes( $attributes ); $product->save(); }
    }

    private static function bulk_insert_from_tip_colors( $product_id, array $tip_colors, array $tip_sizes, array $tip_prices ) {
        $now   = current_time( 'mysql' );
        $total = 0;

        foreach ( $tip_colors as $tip_slug => $colors ) {
            $tip_slug  = sanitize_title( $tip_slug );
            $sizes_csv = implode( ',', array_map( 'sanitize_title', $tip_sizes[ $tip_slug ] ?? [] ) );
            $price     = $tip_prices[ $tip_slug ] ?? [];
            $regular   = (float) ( $price['regular'] ?? 0 );
            $sale      = isset( $price['sale'] ) && $price['sale'] !== '' ? (float) $price['sale'] : null;

            foreach ( array_chunk( $colors, self::BATCH_SIZE ) as $batch ) {
                foreach ( $batch as $color ) {
                    $boja_slug   = sanitize_title( $color['slug'] );
                    $light_print = ! empty( $color['light_print'] );

                    $var_id = self::insert_one( $product_id, $tip_slug, $boja_slug, $regular, $sale, $sizes_csv, $light_print, $now );
                    if ( $var_id ) $total++;
                }
                wp_cache_flush_group( 'posts' );
            }
        }

        return $total;
    }

    private static function insert_one( $product_id, $tip_slug, $boja_slug, float $regular, ?float $sale, $sizes_csv, bool $light_print, $now ) {
        global $wpdb;

        $tip_term  = get_term_by( 'slug', $tip_slug,  THREADY_TAX_TIP  );
        $boja_term = get_term_by( 'slug', $boja_slug, THREADY_TAX_BOJA );
        if ( ! $tip_term || ! $boja_term ) return false;

        $wpdb->insert( $wpdb->posts, [
            'post_author'       => get_current_user_id() ?: 1,
            'post_date'         => $now,
            'post_date_gmt'     => get_gmt_from_date( $now ),
            'post_modified'     => $now,
            'post_modified_gmt' => get_gmt_from_date( $now ),
            'post_status'       => 'publish',
            'post_title'        => "Variation #$product_id – $tip_slug – $boja_slug",
            'post_name'         => "$product_id-$tip_slug-$boja_slug",
            'post_type'         => 'product_variation',
            'post_parent'       => $product_id,
            'menu_order'        => 0,
            'guid'              => '',
            'comment_status'    => 'closed',
            'ping_status'       => 'closed',
        ] );

        $var_id = (int) $wpdb->insert_id;
        if ( ! $var_id ) return false;

        add_post_meta( $var_id, 'attribute_' . THREADY_TAX_TIP,  $tip_slug  );
        add_post_meta( $var_id, 'attribute_' . THREADY_TAX_BOJA, $boja_slug );

        add_post_meta( $var_id, '_regular_price', (string) $regular );
        add_post_meta( $var_id, '_price',         (string) ( $sale ?? $regular ) );
        if ( $sale !== null ) add_post_meta( $var_id, '_sale_price', (string) $sale );

        add_post_meta( $var_id, '_stock_status',             'instock' );
        add_post_meta( $var_id, '_manage_stock',             'no'      );
        add_post_meta( $var_id, '_backorders',               'no'      );
        add_post_meta( $var_id, '_downloadable',             'no'      );
        add_post_meta( $var_id, '_virtual',                  'no'      );
        add_post_meta( $var_id, '_thready_available_sizes',  $sizes_csv );
        add_post_meta( $var_id, '_thready_use_light_print',  $light_print ? 'yes' : 'no' );
        add_post_meta( $var_id, self::META_RENDER_MODE,      'canvas'  );

        clean_post_cache( $var_id );
        return $var_id;
    }

    private static function update_price( $var_id, float $regular, ?float $sale ) {
        $var = wc_get_product( $var_id );
        if ( ! $var ) return false;
        $var->set_regular_price( $regular );
        if ( $sale === null )   { /* leave */ }
        elseif ( $sale <= 0 )  $var->set_sale_price( '' );
        else                   $var->set_sale_price( $sale );
        $var->save();
        return true;
    }

    private static function deactivate( $var_id ) {
        global $wpdb;
        $wpdb->update( $wpdb->postmeta,
            [ 'meta_value' => 'outofstock' ],
            [ 'post_id' => $var_id, 'meta_key' => '_stock_status' ]
        );
        clean_post_cache( $var_id );
    }

    // ── Query helpers ─────────────────────────────────────────────────────────

    private static function map_existing( $product_id ) {
        global $wpdb;
        $tip_key  = 'attribute_' . THREADY_TAX_TIP;
        $boja_key = 'attribute_' . THREADY_TAX_BOJA;

        $rows = $wpdb->get_results( $wpdb->prepare( "
            SELECT p.ID,
                   MAX( CASE WHEN pm.meta_key = %s THEN pm.meta_value END ) AS tip,
                   MAX( CASE WHEN pm.meta_key = %s THEN pm.meta_value END ) AS boja
            FROM   {$wpdb->posts} p
            JOIN   {$wpdb->postmeta} pm ON pm.post_id = p.ID
            WHERE  p.post_parent = %d
            AND    p.post_type   = 'product_variation'
            AND    p.post_status != 'trash'
            AND    pm.meta_key  IN (%s, %s)
            GROUP  BY p.ID
        ", $tip_key, $boja_key, $product_id, $tip_key, $boja_key ) );

        $map = [];
        foreach ( $rows as $row ) {
            if ( $row->tip && $row->boja ) $map[ $row->tip . '|' . $row->boja ] = (int) $row->ID;
        }
        return $map;
    }

    private static function get_active_tips( $product_id ) {
        return self::get_distinct_attr( $product_id, THREADY_TAX_TIP );
    }

    private static function get_active_bojas( $product_id ) {
        return self::get_distinct_attr( $product_id, THREADY_TAX_BOJA );
    }

    private static function get_distinct_attr( $product_id, $taxonomy ) {
        global $wpdb;
        $meta_key = 'attribute_' . $taxonomy;
        return $wpdb->get_col( $wpdb->prepare( "
            SELECT DISTINCT pm.meta_value
            FROM   {$wpdb->posts} p
            JOIN   {$wpdb->postmeta} pm  ON pm.post_id  = p.ID
            JOIN   {$wpdb->postmeta} pm2 ON pm2.post_id = p.ID
            WHERE  p.post_parent  = %d AND p.post_type = 'product_variation'
            AND    p.post_status != 'trash'
            AND    pm.meta_key    = %s
            AND    pm2.meta_key   = '_stock_status' AND pm2.meta_value = 'instock'
        ", $product_id, $meta_key ) );
    }

    /**
     * Rebuild tip_colors structure from existing variation meta
     * for edit mode pre-fill.
     */
    private static function get_tip_colors_meta( $product_id ) {
        global $wpdb;
        $tip_key  = 'attribute_' . THREADY_TAX_TIP;
        $boja_key = 'attribute_' . THREADY_TAX_BOJA;

        $rows = $wpdb->get_results( $wpdb->prepare( "
            SELECT p.ID,
                   MAX( CASE WHEN pm.meta_key = %s   THEN pm.meta_value END ) AS tip,
                   MAX( CASE WHEN pm.meta_key = %s   THEN pm.meta_value END ) AS boja,
                   MAX( CASE WHEN pm.meta_key = '_thready_use_light_print' THEN pm.meta_value END ) AS light_print,
                   MAX( CASE WHEN pm.meta_key = '_stock_status' THEN pm.meta_value END ) AS stock
            FROM   {$wpdb->posts} p
            JOIN   {$wpdb->postmeta} pm ON pm.post_id = p.ID
            WHERE  p.post_parent = %d AND p.post_type = 'product_variation' AND p.post_status != 'trash'
            GROUP  BY p.ID
        ", $tip_key, $boja_key, $product_id ) );

        $result = [];
        foreach ( $rows as $row ) {
            if ( ! $row->tip || ! $row->boja || $row->stock === 'outofstock' ) continue;
            if ( ! isset( $result[ $row->tip ] ) ) $result[ $row->tip ] = [];
            $result[ $row->tip ][ $row->boja ] = [
                'selected'   => true,
                'lightPrint' => $row->light_print === 'yes',
            ];
        }
        return $result;
    }

    private static function get_tip_sizes_meta( $product_id ) {
        global $wpdb;
        $tip_key = 'attribute_' . THREADY_TAX_TIP;

        $rows = $wpdb->get_results( $wpdb->prepare( "
            SELECT MAX( CASE WHEN pm.meta_key = %s THEN pm.meta_value END ) AS tip,
                   MAX( CASE WHEN pm.meta_key = '_thready_available_sizes' THEN pm.meta_value END ) AS sizes
            FROM   {$wpdb->posts} p
            JOIN   {$wpdb->postmeta} pm ON pm.post_id = p.ID
            WHERE  p.post_parent = %d AND p.post_type = 'product_variation' AND p.post_status != 'trash'
            GROUP  BY p.ID
        ", $tip_key, $product_id ) );

        $result = [];
        foreach ( $rows as $row ) {
            if ( ! $row->tip || isset( $result[ $row->tip ] ) ) continue;
            $result[ $row->tip ] = $row->sizes ? array_filter( explode( ',', $row->sizes ) ) : [];
        }
        return $result;
    }

    public static function get_tip_prices( $product_id ) {
        global $wpdb;
        $tip_key = 'attribute_' . THREADY_TAX_TIP;

        $rows = $wpdb->get_results( $wpdb->prepare( "
            SELECT MAX( CASE WHEN pm.meta_key = %s             THEN pm.meta_value END ) AS tip,
                   MAX( CASE WHEN pm.meta_key = '_regular_price' THEN pm.meta_value END ) AS regular,
                   MAX( CASE WHEN pm.meta_key = '_sale_price'    THEN pm.meta_value END ) AS sale
            FROM   {$wpdb->posts} p
            JOIN   {$wpdb->postmeta} pm ON pm.post_id = p.ID
            WHERE  p.post_parent = %d AND p.post_type = 'product_variation' AND p.post_status != 'trash'
            GROUP  BY p.ID
        ", $tip_key, $product_id ) );

        $prices = [];
        foreach ( $rows as $row ) {
            if ( ! $row->tip || isset( $prices[ $row->tip ] ) ) continue;
            $prices[ $row->tip ] = [
                'regular' => (float) $row->regular,
                'sale'    => $row->sale !== '' && $row->sale !== null ? (float) $row->sale : null,
            ];
        }
        return $prices;
    }

    public static function get_sizes_csv_for_tip( $product_id, $tip_slug ) {
        global $wpdb;
        $tip_key = 'attribute_' . THREADY_TAX_TIP;
        return (string) $wpdb->get_var( $wpdb->prepare( "
            SELECT pm2.meta_value
            FROM   {$wpdb->posts} p
            JOIN   {$wpdb->postmeta} pm  ON pm.post_id  = p.ID AND pm.meta_key  = %s  AND pm.meta_value = %s
            JOIN   {$wpdb->postmeta} pm2 ON pm2.post_id = p.ID AND pm2.meta_key = '_thready_available_sizes'
            WHERE  p.post_parent = %d AND p.post_type = 'product_variation' AND p.post_status != 'trash'
            LIMIT  1
        ", $tip_key, $tip_slug, $product_id ) );
    }

    private static function count_active( $product_id ) {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare( "
            SELECT COUNT(*) FROM {$wpdb->posts} p
            JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
            WHERE p.post_parent = %d AND p.post_type = 'product_variation' AND p.post_status != 'trash'
            AND pm.meta_key = '_stock_status' AND pm.meta_value = 'instock'
        ", $product_id ) );
    }
}