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
            'name'          => '',
            'tip_slugs'     => [],
            'tip_colors'    => [],
            'tip_sizes'     => [],
            'tip_prices'    => [],
            'tip_positions' => [],
            'print_front_id'=> 0,
            'print_light_id'=> null,
            'print_back_id' => null,
            'description'   => '',
            'status'        => 'publish',
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
        $product->set_attributes( $wc_attrs );

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

        WC_Product_Variable::sync( $product_id );
        wc_delete_product_transients( $product_id );

        return $counters;
    }

    // -------------------------------------------------------------------------
    // add / remove color
    // -------------------------------------------------------------------------

    public static function add_color( $product_id, $tip_slug, $boja_slug, $light_print = false ) {
        $tip_slug  = sanitize_title( $tip_slug );
        $boja_slug = sanitize_title( $boja_slug );

        if ( ! term_exists( $boja_slug, THREADY_TAX_BOJA ) ) {
            return new WP_Error( 'thready_invalid_term', "Color $boja_slug not found." );
        }

        $prices    = self::get_tip_prices( $product_id );
        $sizes_csv = self::get_sizes_csv_for_tip( $product_id, $tip_slug );
        $price     = $prices[ $tip_slug ] ?? [ 'regular' => 0, 'sale' => null ];
        $regular   = (float) ( $price['regular'] ?? 0 );
        $sale      = $price['sale'] ?? null;

        self::ensure_attributes_include( $product_id, [], [ $boja_slug ] );
        $var_id = self::insert_one( $product_id, $tip_slug, $boja_slug, $regular, $sale, $sizes_csv, $light_print, current_time( 'mysql' ) );

        if ( $var_id ) {
            WC_Product_Variable::sync( $product_id );
            wc_delete_product_transients( $product_id );
            return [ 'created' => 1 ];
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

    private static function ensure_attributes_include( $product_id, array $new_tips, array $new_bojas ) {
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

    private static function get_tip_prices( $product_id ) {
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

    private static function get_sizes_csv_for_tip( $product_id, $tip_slug ) {
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