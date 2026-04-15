<?php
/**
 * Thready Variation Factory
 *
 * Bulk-creates / syncs WooCommerce product variations directly via wpdb
 * and WC data-store calls — bypasses the admin variation form entirely.
 *
 * Public API
 * ----------
 * Thready_Variation_Factory::create_product( array $args ) : int|WP_Error
 * Thready_Variation_Factory::sync_variations( int $product_id, array $config ) : array
 * Thready_Variation_Factory::add_color( int $product_id, string $boja_slug ) : array
 * Thready_Variation_Factory::remove_color( int $product_id, string $boja_slug ) : int
 * Thready_Variation_Factory::add_size( int $product_id, string $velicina_slug ) : array
 * Thready_Variation_Factory::remove_size( int $product_id, string $velicina_slug ) : int
 * Thready_Variation_Factory::set_prices( int $product_id, string $tip_slug, float $regular, float|null $sale ) : int
 * Thready_Variation_Factory::get_summary( int $product_id ) : array
 */

defined( 'ABSPATH' ) || exit;

class Thready_Variation_Factory {

    // -------------------------------------------------------------------------
    // Constants
    // -------------------------------------------------------------------------

    const META_TIP_SLUG       = '_thready_pa_tip_slug';
    const META_RENDER_MODE    = '_thready_render_mode';   // 'canvas' | 'legacy'
    const META_DESIGN_VERSION = '_thready_design_version';
    const META_PRINT_FRONT    = '_thready_print_image';
    const META_PRINT_BACK     = '_thready_back_print_image';
    const META_POS_FRONT      = '_thready_position_front'; // JSON {x,y,width}
    const META_POS_BACK       = '_thready_position_back';  // JSON {x,y,width}

    // WC attribute taxonomy slugs used across the plugin
    
    
    

    // Batch size for variation inserts — keeps memory flat
    const BATCH_SIZE = 50;

    // -------------------------------------------------------------------------
    // create_product
    // -------------------------------------------------------------------------

    /**
     * Create a new variable product with all variations from the wizard config.
     *
     * $args = [
     *   'name'           => string,          // product title
     *   'tip_slug'       => string,           // pa_tip slug
     *   'boja_slugs'     => string[],         // pa_boja slugs to include
     *   'velicina_slugs' => string[],         // pa_velicina slugs to include
     *   'regular_price'  => float,
     *   'sale_price'     => float|null,
     *   'print_front_id' => int,              // attachment ID
     *   'print_back_id'  => int|null,
     *   'position_front' => [x,y,width],      // percentages
     *   'position_back'  => [x,y,width]|null,
     *   'description'    => string,
     *   'category_ids'   => int[],
     *   'status'         => 'publish'|'draft',
     * ]
     *
     * @return int|WP_Error  Product ID on success.
     */
    public static function create_product( array $args ) {
        $args = wp_parse_args( $args, [
            'name'           => '',
            'tip_slug'       => '',
            'boja_slugs'     => [],
            'velicina_slugs' => [],
            'regular_price'  => 0,
            'sale_price'     => null,
            'print_front_id' => 0,
            'print_back_id'  => null,
            'position_front' => [ 'x' => 50, 'y' => 25, 'width' => 50 ],
            'position_back'  => null,
            'description'    => '',
            'category_ids'   => [],
            'status'         => 'publish',
        ] );

        // ── Validate ────────────────────────────────────────────────────────
        $errors = self::validate_create_args( $args );
        if ( ! empty( $errors ) ) {
            return new WP_Error( 'thready_invalid_args', implode( ' | ', $errors ) );
        }

        // ── Create variable product ─────────────────────────────────────────
        $product = new WC_Product_Variable();
        $product->set_name( sanitize_text_field( $args['name'] ) );
        $product->set_status( $args['status'] );
        $product->set_description( wp_kses_post( $args['description'] ) );

        if ( ! empty( $args['category_ids'] ) ) {
            $product->set_category_ids( array_map( 'absint', $args['category_ids'] ) );
        }

        // ── Set attributes ──────────────────────────────────────────────────
        $wc_attrs = self::build_wc_attributes( $args['boja_slugs'], $args['velicina_slugs'] );
        if ( is_wp_error( $wc_attrs ) ) return $wc_attrs;

        $product->set_attributes( $wc_attrs );

        $product_id = $product->save();
        if ( ! $product_id ) {
            return new WP_Error( 'thready_save_failed', __( 'Failed to save product.', 'thready-product-customizer' ) );
        }

        // ── Store Thready meta ──────────────────────────────────────────────
        update_post_meta( $product_id, self::META_TIP_SLUG,       $args['tip_slug'] );
        update_post_meta( $product_id, self::META_RENDER_MODE,    'canvas' );
        update_post_meta( $product_id, self::META_DESIGN_VERSION, 1 );
        update_post_meta( $product_id, self::META_PRINT_FRONT,    absint( $args['print_front_id'] ) );
        update_post_meta( $product_id, self::META_POS_FRONT,      wp_json_encode( $args['position_front'] ) );

        if ( $args['print_back_id'] ) {
            update_post_meta( $product_id, self::META_PRINT_BACK, absint( $args['print_back_id'] ) );
        }
        if ( $args['position_back'] ) {
            update_post_meta( $product_id, self::META_POS_BACK, wp_json_encode( $args['position_back'] ) );
        }

        // ── Bulk-create variations ──────────────────────────────────────────
        $result = self::bulk_insert_variations(
            $product_id,
            $args['boja_slugs'],
            $args['velicina_slugs'],
            (float) $args['regular_price'],
            $args['sale_price'] !== null ? (float) $args['sale_price'] : null
        );

        if ( is_wp_error( $result ) ) {
            // Product was created but variations failed — still return ID
            // Caller can inspect $result for partial failure detail
            error_log( 'Thready VariationFactory: variation insert error — ' . $result->get_error_message() );
        }

        // Sync WC children cache
        WC_Product_Variable::sync( $product_id );
        wc_delete_product_transients( $product_id );

        return $product_id;
    }

    // -------------------------------------------------------------------------
    // sync_variations  (used by wizard edit / re-save)
    // -------------------------------------------------------------------------

    /**
     * Reconcile existing variations with a new config.
     * Creates missing, marks removed ones out-of-stock. Never deletes.
     *
     * $config = [
     *   'boja_slugs'     => string[],
     *   'velicina_slugs' => string[],
     *   'regular_price'  => float,
     *   'sale_price'     => float|null,
     * ]
     *
     * @return array { created: int, deactivated: int, skipped: int }
     */
    public static function sync_variations( $product_id, array $config ) {
        $product = wc_get_product( $product_id );
        if ( ! $product || ! $product->is_type( 'variable' ) ) {
            return new WP_Error( 'thready_invalid_product', __( 'Product not found or not variable.', 'thready-product-customizer' ) );
        }

        $wanted_boja     = array_map( 'sanitize_title', $config['boja_slugs']     ?? [] );
        $wanted_velicina = array_map( 'sanitize_title', $config['velicina_slugs'] ?? [] );
        $regular_price   = (float) ( $config['regular_price'] ?? 0 );
        $sale_price      = isset( $config['sale_price'] ) && $config['sale_price'] !== '' ? (float) $config['sale_price'] : null;

        // Build the full desired set of (boja, velicina) pairs
        $desired = [];
        foreach ( $wanted_boja as $b ) {
            foreach ( $wanted_velicina as $v ) {
                $desired[ $b . '|' . $v ] = [ $b, $v ];
            }
        }

        // Map existing variations by their attribute combo
        $existing    = self::map_existing_variations( $product_id );
        $counters    = [ 'created' => 0, 'deactivated' => 0, 'skipped' => 0 ];
        $to_create_b = [];
        $to_create_v = [];

        foreach ( $desired as $key => [ $b, $v ] ) {
            if ( isset( $existing[ $key ] ) ) {
                $counters['skipped']++;
                // Update price on existing variation
                self::update_variation_price( $existing[ $key ], $regular_price, $sale_price );
            } else {
                $to_create_b[] = $b;
                $to_create_v[] = $v;
            }
        }

        // Deactivate (set OOS) variations no longer wanted
        foreach ( $existing as $key => $var_id ) {
            if ( ! isset( $desired[ $key ] ) ) {
                self::deactivate_variation( $var_id );
                $counters['deactivated']++;
            }
        }

        // Bulk-create new ones
        if ( ! empty( $to_create_b ) ) {
            $pairs = array_unique( array_map( null, $to_create_b, $to_create_v ), SORT_REGULAR );
            // Re-extract unique boja + velicina from pairs
            $new_b = array_unique( $to_create_b );
            $new_v = array_unique( $to_create_v );

            // Update product attributes to include new terms
            self::ensure_attributes_include( $product_id, $new_b, $new_v );

            // Insert only the missing combinations
            $inserted = self::insert_variation_pairs( $product_id, $pairs, $regular_price, $sale_price );
            $counters['created'] = is_wp_error( $inserted ) ? 0 : $inserted;
        }

        WC_Product_Variable::sync( $product_id );
        wc_delete_product_transients( $product_id );

        return $counters;
    }

    // -------------------------------------------------------------------------
    // add_color  /  remove_color
    // -------------------------------------------------------------------------

    /**
     * Add a new color to a product — creates one variation per existing size.
     *
     * @return array { created: int } | WP_Error
     */
    public static function add_color( $product_id, $boja_slug ) {
        $boja_slug = sanitize_title( $boja_slug );
        $product   = wc_get_product( $product_id );

        if ( ! $product || ! $product->is_type( 'variable' ) ) {
            return new WP_Error( 'thready_invalid_product', __( 'Product not found.', 'thready-product-customizer' ) );
        }

        // Verify color exists in taxonomy
        if ( ! term_exists( $boja_slug, THREADY_TAX_BOJA ) ) {
            return new WP_Error( 'thready_invalid_term', sprintf( __( 'Color "%s" not found.', 'thready-product-customizer' ), $boja_slug ) );
        }

        $existing_sizes = self::get_product_sizes( $product_id );
        if ( empty( $existing_sizes ) ) {
            return new WP_Error( 'thready_no_sizes', __( 'Product has no sizes configured.', 'thready-product-customizer' ) );
        }

        // Build pairs
        $pairs = array_map( fn( $v ) => [ $boja_slug, $v ], $existing_sizes );

        // Ensure the color term is in the product's attribute
        self::ensure_attributes_include( $product_id, [ $boja_slug ], [] );

        $created = self::insert_variation_pairs(
            $product_id,
            $pairs,
            self::get_product_regular_price( $product_id ),
            self::get_product_sale_price( $product_id )
        );

        WC_Product_Variable::sync( $product_id );
        wc_delete_product_transients( $product_id );

        return is_wp_error( $created )
            ? $created
            : [ 'created' => $created ];
    }

    /**
     * Remove a color — marks all its variations out-of-stock.
     *
     * @return int  Number of variations deactivated.
     */
    public static function remove_color( $product_id, $boja_slug ) {
        $boja_slug = sanitize_title( $boja_slug );
        $existing  = self::map_existing_variations( $product_id );
        $count     = 0;

        foreach ( $existing as $key => $var_id ) {
            [ $b ] = explode( '|', $key );
            if ( $b === $boja_slug ) {
                self::deactivate_variation( $var_id );
                $count++;
            }
        }

        wc_delete_product_transients( $product_id );
        return $count;
    }

    // -------------------------------------------------------------------------
    // add_size  /  remove_size
    // -------------------------------------------------------------------------

    /**
     * Add a new size — creates one variation per existing active color.
     *
     * @return array { created: int } | WP_Error
     */
    public static function add_size( $product_id, $velicina_slug ) {
        $velicina_slug = sanitize_title( $velicina_slug );
        $product       = wc_get_product( $product_id );

        if ( ! $product || ! $product->is_type( 'variable' ) ) {
            return new WP_Error( 'thready_invalid_product', __( 'Product not found.', 'thready-product-customizer' ) );
        }

        if ( ! term_exists( $velicina_slug, THREADY_TAX_VELICINA ) ) {
            return new WP_Error( 'thready_invalid_term', sprintf( __( 'Size "%s" not found.', 'thready-product-customizer' ), $velicina_slug ) );
        }

        $existing_colors = self::get_product_active_colors( $product_id );
        if ( empty( $existing_colors ) ) {
            return new WP_Error( 'thready_no_colors', __( 'Product has no active colors.', 'thready-product-customizer' ) );
        }

        $pairs = array_map( fn( $b ) => [ $b, $velicina_slug ], $existing_colors );

        self::ensure_attributes_include( $product_id, [], [ $velicina_slug ] );

        $created = self::insert_variation_pairs(
            $product_id,
            $pairs,
            self::get_product_regular_price( $product_id ),
            self::get_product_sale_price( $product_id )
        );

        WC_Product_Variable::sync( $product_id );
        wc_delete_product_transients( $product_id );

        return is_wp_error( $created )
            ? $created
            : [ 'created' => $created ];
    }

    /**
     * Remove a size — marks all its variations out-of-stock.
     *
     * @return int
     */
    public static function remove_size( $product_id, $velicina_slug ) {
        $velicina_slug = sanitize_title( $velicina_slug );
        $existing      = self::map_existing_variations( $product_id );
        $count         = 0;

        foreach ( $existing as $key => $var_id ) {
            [ , $v ] = explode( '|', $key );
            if ( $v === $velicina_slug ) {
                self::deactivate_variation( $var_id );
                $count++;
            }
        }

        wc_delete_product_transients( $product_id );
        return $count;
    }

    // -------------------------------------------------------------------------
    // set_prices
    // -------------------------------------------------------------------------

    /**
     * Update prices on all active variations of a product.
     *
     * @param  float      $regular
     * @param  float|null $sale     null = leave sale unchanged, 0 = clear sale
     * @return int  Number of variations updated.
     */
    public static function set_prices( $product_id, $regular, $sale = null ) {
        $existing = self::map_existing_variations( $product_id );
        $count    = 0;

        foreach ( $existing as $var_id ) {
            if ( self::update_variation_price( $var_id, (float) $regular, $sale ) ) {
                $count++;
            }
        }

        wc_delete_product_transients( $product_id );
        return $count;
    }

    // -------------------------------------------------------------------------
    // get_summary
    // -------------------------------------------------------------------------

    /**
     * Return a summary array suitable for the edit panel and Tools page.
     *
     * @return array{
     *   tip_slug: string,
     *   render_mode: string,
     *   colors: string[],
     *   sizes: string[],
     *   total_variations: int,
     *   active_variations: int,
     *   regular_price: float,
     *   sale_price: float|null,
     *   print_front: int,
     *   print_back: int,
     *   position_front: array,
     *   position_back: array|null,
     * }
     */
    public static function get_summary( $product_id ) {
        $product = wc_get_product( $product_id );

        $pos_front_raw = get_post_meta( $product_id, self::META_POS_FRONT, true );
        $pos_back_raw  = get_post_meta( $product_id, self::META_POS_BACK,  true );

        return [
            'tip_slug'         => get_post_meta( $product_id, self::META_TIP_SLUG,    true ) ?: '',
            'render_mode'      => get_post_meta( $product_id, self::META_RENDER_MODE,  true ) ?: 'legacy',
            'design_version'   => (int) get_post_meta( $product_id, self::META_DESIGN_VERSION, true ),
            'colors'           => self::get_product_active_colors( $product_id ),
            'sizes'            => self::get_product_sizes( $product_id ),
            'total_variations' => $product ? count( $product->get_children() ) : 0,
            'active_variations'=> self::count_active_variations( $product_id ),
            'regular_price'    => (float) self::get_product_regular_price( $product_id ),
            'sale_price'       => self::get_product_sale_price( $product_id ),
            'print_front'      => (int) get_post_meta( $product_id, self::META_PRINT_FRONT, true ),
            'print_back'       => (int) get_post_meta( $product_id, self::META_PRINT_BACK,  true ),
            'position_front'   => $pos_front_raw ? json_decode( $pos_front_raw, true ) : [ 'x' => 50, 'y' => 25, 'width' => 50 ],
            'position_back'    => $pos_back_raw  ? json_decode( $pos_back_raw,  true ) : null,
        ];
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    // ── Argument validation ──────────────────────────────────────────────────

    private static function validate_create_args( array $args ) {
        $errors = [];

        if ( empty( $args['name'] ) ) {
            $errors[] = __( 'Product name is required.', 'thready-product-customizer' );
        }

        if ( empty( $args['tip_slug'] ) ) {
            $errors[] = __( 'Product type (pa_tip) is required.', 'thready-product-customizer' );
        } elseif ( ! term_exists( $args['tip_slug'], THREADY_TAX_TIP ) ) {
            $errors[] = sprintf( __( 'Product type "%s" not found.', 'thready-product-customizer' ), $args['tip_slug'] );
        }

        if ( empty( $args['boja_slugs'] ) ) {
            $errors[] = __( 'At least one color is required.', 'thready-product-customizer' );
        }

        if ( empty( $args['velicina_slugs'] ) ) {
            $errors[] = __( 'At least one size is required.', 'thready-product-customizer' );
        }

        if ( empty( $args['print_front_id'] ) || ! wp_attachment_is_image( $args['print_front_id'] ) ) {
            $errors[] = __( 'A valid front print image is required.', 'thready-product-customizer' );
        }

        if ( (float) $args['regular_price'] <= 0 ) {
            $errors[] = __( 'Regular price must be greater than 0.', 'thready-product-customizer' );
        }

        return $errors;
    }

    // ── WC attribute objects ─────────────────────────────────────────────────

    /**
     * Build WC_Product_Attribute objects for boja + velicina.
     *
     * @return WC_Product_Attribute[]|WP_Error
     */
    private static function build_wc_attributes( array $boja_slugs, array $velicina_slugs ) {
        $attrs = [];

        $tax_map = [
            THREADY_TAX_BOJA     => $boja_slugs,
            THREADY_TAX_VELICINA => $velicina_slugs,
        ];

        foreach ( $tax_map as $taxonomy => $slugs ) {
            if ( empty( $slugs ) ) continue;

            // Resolve term IDs
            $term_ids = [];
            foreach ( $slugs as $slug ) {
                $term = get_term_by( 'slug', sanitize_title( $slug ), $taxonomy );
                if ( ! $term ) {
                    return new WP_Error(
                        'thready_invalid_term',
                        sprintf( __( 'Term "%s" not found in %s.', 'thready-product-customizer' ), $slug, $taxonomy )
                    );
                }
                $term_ids[] = $term->term_id;
            }

            $wc_attr = new WC_Product_Attribute();
            $wc_attr->set_id( wc_attribute_taxonomy_id_by_name( $taxonomy ) );
            $wc_attr->set_name( $taxonomy );
            $wc_attr->set_options( $term_ids );
            $wc_attr->set_visible( true );
            $wc_attr->set_variation( true );

            $attrs[] = $wc_attr;
        }

        return $attrs;
    }

    // ── ensure_attributes_include ────────────────────────────────────────────

    /**
     * Add new boja/velicina slugs to an existing product's attribute term list
     * without touching anything else.
     */
    private static function ensure_attributes_include( $product_id, array $new_boja, array $new_velicina ) {
        $product = wc_get_product( $product_id );
        if ( ! $product ) return;

        $attributes = $product->get_attributes();
        $changed    = false;

        $additions = [
            THREADY_TAX_BOJA     => $new_boja,
            THREADY_TAX_VELICINA => $new_velicina,
        ];

        foreach ( $additions as $taxonomy => $slugs ) {
            if ( empty( $slugs ) ) continue;

            if ( ! isset( $attributes[ $taxonomy ] ) ) {
                // Attribute doesn't exist on product at all — create it
                $new_ids  = [];
                foreach ( $slugs as $slug ) {
                    $term = get_term_by( 'slug', sanitize_title( $slug ), $taxonomy );
                    if ( $term ) $new_ids[] = $term->term_id;
                }
                if ( ! empty( $new_ids ) ) {
                    $wc_attr = new WC_Product_Attribute();
                    $wc_attr->set_id( wc_attribute_taxonomy_id_by_name( $taxonomy ) );
                    $wc_attr->set_name( $taxonomy );
                    $wc_attr->set_options( $new_ids );
                    $wc_attr->set_visible( true );
                    $wc_attr->set_variation( true );
                    $attributes[ $taxonomy ] = $wc_attr;
                    $changed = true;
                }
                continue;
            }

            $attr         = $attributes[ $taxonomy ];
            $current_ids  = $attr->get_options(); // term IDs
            $added        = false;

            foreach ( $slugs as $slug ) {
                $term = get_term_by( 'slug', sanitize_title( $slug ), $taxonomy );
                if ( $term && ! in_array( $term->term_id, $current_ids, true ) ) {
                    $current_ids[] = $term->term_id;
                    $added = true;
                }
            }

            if ( $added ) {
                $attr->set_options( $current_ids );
                $attributes[ $taxonomy ] = $attr;
                $changed = true;
            }
        }

        if ( $changed ) {
            $product->set_attributes( $attributes );
            $product->save();
        }
    }

    // ── bulk_insert_variations ───────────────────────────────────────────────

    /**
     * Create all boja × velicina combinations in batches.
     *
     * @return int|WP_Error  Total variations created.
     */
    private static function bulk_insert_variations( $product_id, array $boja_slugs, array $velicina_slugs, float $regular_price, ?float $sale_price ) {
        $pairs = [];
        foreach ( $boja_slugs as $b ) {
            foreach ( $velicina_slugs as $v ) {
                $pairs[] = [ sanitize_title( $b ), sanitize_title( $v ) ];
            }
        }

        return self::insert_variation_pairs( $product_id, $pairs, $regular_price, $sale_price );
    }

    /**
     * Insert an explicit list of (boja, velicina) pairs.
     * Skips combinations that already exist.
     *
     * @param  array[]    $pairs          [ [boja_slug, velicina_slug], … ]
     * @return int|WP_Error
     */
    private static function insert_variation_pairs( $product_id, array $pairs, float $regular_price, ?float $sale_price ) {
        if ( empty( $pairs ) ) return 0;

        $existing = self::map_existing_variations( $product_id );
        $total    = 0;
        $now      = current_time( 'mysql' );

        // Process in batches to keep memory stable
        $batches = array_chunk( $pairs, self::BATCH_SIZE );

        foreach ( $batches as $batch ) {
            foreach ( $batch as [ $boja_slug, $velicina_slug ] ) {
                $key = $boja_slug . '|' . $velicina_slug;

                if ( isset( $existing[ $key ] ) ) {
                    // Reactivate if previously deactivated
                    $var = wc_get_product( $existing[ $key ] );
                    if ( $var && ! $var->get_stock_status() !== 'instock' ) {
                        $var->set_stock_status( 'instock' );
                        $var->set_status( 'publish' );
                        $var->save();
                    }
                    continue;
                }

                $var_id = self::insert_single_variation(
                    $product_id,
                    $boja_slug,
                    $velicina_slug,
                    $regular_price,
                    $sale_price,
                    $now
                );

                if ( $var_id ) {
                    $existing[ $key ] = $var_id;
                    $total++;
                }
            }

            // Free WC object cache between batches
            wp_cache_flush_group( 'posts' );
        }

        return $total;
    }

    /**
     * Insert one variation post + meta.
     * Direct wpdb insert is ~10× faster than WC_Product_Variation::save()
     * for bulk operations.
     */
    private static function insert_single_variation( $product_id, $boja_slug, $velicina_slug, float $regular_price, ?float $sale_price, $now ) {
        global $wpdb;

        // Resolve term slugs — WC stores them as slugs in variation meta
        $boja_term     = get_term_by( 'slug', $boja_slug,     THREADY_TAX_BOJA     );
        $velicina_term = get_term_by( 'slug', $velicina_slug, THREADY_TAX_VELICINA );

        if ( ! $boja_term || ! $velicina_term ) {
            error_log( "Thready Factory: term not found for $boja_slug / $velicina_slug" );
            return false;
        }

        // Insert post
        $wpdb->insert( $wpdb->posts, [
            'post_author'       => get_current_user_id() ?: 1,
            'post_date'         => $now,
            'post_date_gmt'     => get_gmt_from_date( $now ),
            'post_modified'     => $now,
            'post_modified_gmt' => get_gmt_from_date( $now ),
            'post_status'       => 'publish',
            'post_title'        => 'Product #' . $product_id . ' — ' . $boja_slug . ' — ' . $velicina_slug,
            'post_name'         => $product_id . '-' . $boja_slug . '-' . $velicina_slug,
            'post_type'         => 'product_variation',
            'post_parent'       => $product_id,
            'menu_order'        => 0,
            'guid'              => '',
            'comment_status'    => 'closed',
            'ping_status'       => 'closed',
        ] );

        $var_id = (int) $wpdb->insert_id;
        if ( ! $var_id ) return false;

        // Attribute meta — WC reads these as 'attribute_pa_boja' etc.
        add_post_meta( $var_id, 'attribute_' . THREADY_TAX_BOJA,     $boja_slug );
        add_post_meta( $var_id, 'attribute_' . THREADY_TAX_VELICINA, $velicina_slug );

        // Price meta
        add_post_meta( $var_id, '_price',         (string) $regular_price );
        add_post_meta( $var_id, '_regular_price', (string) $regular_price );

        if ( $sale_price !== null && $sale_price > 0 ) {
            add_post_meta( $var_id, '_sale_price', (string) $sale_price );
            add_post_meta( $var_id, '_price',      (string) $sale_price, true ); // WC uses lowest price
            // Override the regular price meta we just added
            update_post_meta( $var_id, '_price', (string) $sale_price );
        }

        // Stock
        add_post_meta( $var_id, '_stock_status',   'instock' );
        add_post_meta( $var_id, '_manage_stock',   'no' );
        add_post_meta( $var_id, '_backorders',     'no' );
        add_post_meta( $var_id, '_downloadable',   'no' );
        add_post_meta( $var_id, '_virtual',        'no' );

        // Thready render mode flag — canvas system reads this
        add_post_meta( $var_id, self::META_RENDER_MODE, 'canvas' );

        // Clear post cache for this variation
        clean_post_cache( $var_id );

        return $var_id;
    }

    // ── Price update ─────────────────────────────────────────────────────────

    private static function update_variation_price( $var_id, float $regular, ?float $sale ) {
        $var = wc_get_product( $var_id );
        if ( ! $var ) return false;

        $var->set_regular_price( $regular );

        if ( $sale === null ) {
            // Leave sale untouched
        } elseif ( $sale <= 0 ) {
            $var->set_sale_price( '' );
        } else {
            $var->set_sale_price( $sale );
        }

        $var->save();
        return true;
    }

    // ── Deactivate (out-of-stock, not delete) ────────────────────────────────

    private static function deactivate_variation( $var_id ) {
        global $wpdb;

        $wpdb->update(
            $wpdb->postmeta,
            [ 'meta_value' => 'outofstock' ],
            [ 'post_id' => $var_id, 'meta_key' => '_stock_status' ]
        );

        clean_post_cache( $var_id );
    }

    // ── Existing variation map ───────────────────────────────────────────────

    /**
     * Returns [ 'boja_slug|velicina_slug' => variation_id, … ]
     */
    private static function map_existing_variations( $product_id ) {
        global $wpdb;

        $rows = $wpdb->get_results( $wpdb->prepare( "
            SELECT p.ID,
                   MAX( CASE WHEN pm.meta_key = %s THEN pm.meta_value END ) AS boja,
                   MAX( CASE WHEN pm.meta_key = %s THEN pm.meta_value END ) AS velicina
            FROM   {$wpdb->posts} p
            JOIN   {$wpdb->postmeta} pm ON pm.post_id = p.ID
            WHERE  p.post_parent  = %d
            AND    p.post_type    = 'product_variation'
            AND    p.post_status != 'trash'
            AND    pm.meta_key   IN (%s, %s)
            GROUP  BY p.ID
        ",
            'attribute_' . THREADY_TAX_BOJA,
            'attribute_' . THREADY_TAX_VELICINA,
            $product_id,
            'attribute_' . THREADY_TAX_BOJA,
            'attribute_' . THREADY_TAX_VELICINA
        ) );

        $map = [];
        foreach ( $rows as $row ) {
            if ( $row->boja && $row->velicina ) {
                $map[ $row->boja . '|' . $row->velicina ] = (int) $row->ID;
            }
        }
        return $map;
    }

    // ── Active colors / sizes on a product ───────────────────────────────────

    private static function get_product_active_colors( $product_id ) {
        return self::get_active_attribute_values( $product_id, THREADY_TAX_BOJA );
    }

    private static function get_product_sizes( $product_id ) {
        return self::get_active_attribute_values( $product_id, THREADY_TAX_VELICINA );
    }

    /**
     * Returns distinct in-stock attribute slug values from variation meta.
     */
    private static function get_active_attribute_values( $product_id, $taxonomy ) {
        global $wpdb;

        return $wpdb->get_col( $wpdb->prepare( "
            SELECT DISTINCT pm.meta_value
            FROM   {$wpdb->posts} p
            JOIN   {$wpdb->postmeta} pm  ON pm.post_id   = p.ID
            JOIN   {$wpdb->postmeta} pm2 ON pm2.post_id  = p.ID
            WHERE  p.post_parent  = %d
            AND    p.post_type    = 'product_variation'
            AND    p.post_status != 'trash'
            AND    pm.meta_key   = %s
            AND    pm2.meta_key  = '_stock_status'
            AND    pm2.meta_value = 'instock'
            ORDER  BY pm.meta_value
        ",
            $product_id,
            'attribute_' . $taxonomy
        ) );
    }

    // ── Price getters ─────────────────────────────────────────────────────────

    /**
     * Representative regular price — taken from the first active variation.
     */
    private static function get_product_regular_price( $product_id ) {
        global $wpdb;
        return (float) $wpdb->get_var( $wpdb->prepare( "
            SELECT pm.meta_value
            FROM   {$wpdb->posts} p
            JOIN   {$wpdb->postmeta} pm ON pm.post_id = p.ID
            WHERE  p.post_parent  = %d
            AND    p.post_type    = 'product_variation'
            AND    p.post_status != 'trash'
            AND    pm.meta_key   = '_regular_price'
            LIMIT  1
        ", $product_id ) );
    }

    private static function get_product_sale_price( $product_id ) {
        global $wpdb;
        $val = $wpdb->get_var( $wpdb->prepare( "
            SELECT pm.meta_value
            FROM   {$wpdb->posts} p
            JOIN   {$wpdb->postmeta} pm ON pm.post_id = p.ID
            WHERE  p.post_parent  = %d
            AND    p.post_type    = 'product_variation'
            AND    p.post_status != 'trash'
            AND    pm.meta_key   = '_sale_price'
            AND    pm.meta_value != ''
            LIMIT  1
        ", $product_id ) );

        return $val !== null ? (float) $val : null;
    }

    // ── Active variation count ────────────────────────────────────────────────

    private static function count_active_variations( $product_id ) {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare( "
            SELECT COUNT(*)
            FROM   {$wpdb->posts} p
            JOIN   {$wpdb->postmeta} pm ON pm.post_id = p.ID
            WHERE  p.post_parent  = %d
            AND    p.post_type    = 'product_variation'
            AND    p.post_status != 'trash'
            AND    pm.meta_key   = '_stock_status'
            AND    pm.meta_value = 'instock'
        ", $product_id ) );
    }
}
