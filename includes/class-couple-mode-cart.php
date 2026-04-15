<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Thready_Couple_Mode_Cart {

    public static function init() {

        // Store couple + note data into cart items
        add_filter( 'woocommerce_add_cart_item_data', [ __CLASS__, 'add_data_to_cart_item' ], 20, 3 );
        add_filter( 'woocommerce_get_cart_item_from_session', [ __CLASS__, 'restore_cart_item' ], 20, 3 );

        // Standard Woo templates (cart/checkout)
        add_filter( 'woocommerce_get_item_data', [ __CLASS__, 'display_data_in_cart' ], 20, 2 );

        // Mini-cart drawers/widgets usually rely on this
        add_filter( 'woocommerce_widget_cart_item_quantity', [ __CLASS__, 'append_data_to_widget_quantity' ], 20, 3 );

        // Some themes use this (keep it, but don’t rely on it)
        add_filter( 'woocommerce_cart_item_name', [ __CLASS__, 'append_data_to_item_name' ], 20, 3 );

        // IMPORTANT: append our selections to product permalink (cart + mini-cart links)
        add_filter( 'woocommerce_cart_item_permalink', [ __CLASS__, 'append_params_to_cart_item_permalink' ], 20, 3 );

        // Save to order item meta
        add_action( 'woocommerce_checkout_create_order_line_item', [ __CLASS__, 'add_data_to_order_meta' ], 20, 4 );
    }

    /* ---------------------------------------------------------
     * Helpers
     * --------------------------------------------------------- */

    private static function is_couple_mode_product( $product_id ) {
        return get_post_meta( $product_id, '_thready_couple_mode', true ) === 'yes';
    }

    private static function term_name( $taxonomy, $slug ) {
        $slug = sanitize_title( $slug );
        if ( ! $slug ) {
            return '';
        }
        $term = get_term_by( 'slug', $slug, $taxonomy );
        return $term ? $term->name : $slug;
    }

    /**
     * Build compact summary lines for display (mini-cart/cart)
     */
    private static function build_couple_summary_lines( $cart_item ) {
        $lines = [];

        $herParts = [];
        if ( ! empty( $cart_item['thready_her_size_name'] ) ) {
            $herParts[] = 'Veličina: ' . $cart_item['thready_her_size_name'];
        }
        if ( ! empty( $cart_item['thready_her_color_name'] ) ) {
            $herParts[] = 'Boja: ' . $cart_item['thready_her_color_name'];
        }
        if ( ! empty( $cart_item['thready_her_embroidery_color_name'] ) ) {
            $herParts[] = 'Boja veza: ' . $cart_item['thready_her_embroidery_color_name'];
        }
        if ( $herParts ) {
            $lines[] = 'Za nju: ' . implode( ', ', $herParts );
        }

        $himParts = [];
        if ( ! empty( $cart_item['thready_him_size_name'] ) ) {
            $himParts[] = 'Veličina: ' . $cart_item['thready_him_size_name'];
        }
        if ( ! empty( $cart_item['thready_him_color_name'] ) ) {
            $himParts[] = 'Boja: ' . $cart_item['thready_him_color_name'];
        }
        if ( ! empty( $cart_item['thready_him_embroidery_color_name'] ) ) {
            $himParts[] = 'Boja veza: ' . $cart_item['thready_him_embroidery_color_name'];
        }
        if ( $himParts ) {
            $lines[] = 'Za njega: ' . implode( ', ', $himParts );
        }

        return $lines;
    }

    /**
     * Get URL params for couple selections (slug values, not names)
     * NOTE: these are NOT attribute_pa_* params on purpose
     */
    private static function get_couple_query_args( $cart_item ) {
        $args = [];

        $keys = [
            'thready_her_size',
            'thready_her_color',
            'thready_her_embroidery_color',
            'thready_him_size',
            'thready_him_color',
            'thready_him_embroidery_color',
        ];

        foreach ( $keys as $k ) {
            if ( ! empty( $cart_item[ $k ] ) ) {
                $args[ $k ] = sanitize_title( $cart_item[ $k ] );
            }
        }

        return $args;
    }

    /* ---------------------------------------------------------
     * Add to cart
     * --------------------------------------------------------- */

    public static function add_data_to_cart_item( $cart_item_data, $product_id, $variation_id ) {

        // Only capture couple fields if product is couple mode
        if ( self::is_couple_mode_product( $product_id ) ) {

            $map = [
                'thready_her_size'             => 'pa_velicina',
                'thready_her_color'            => 'pa_boja',
                'thready_her_embroidery_color' => 'pa_boja',
                'thready_him_size'             => 'pa_velicina',
                'thready_him_color'            => 'pa_boja',
                'thready_him_embroidery_color' => 'pa_boja',
            ];

            $hasCouple = false;

            foreach ( $map as $key => $taxonomy ) {
                if ( empty( $_POST[ $key ] ) ) {
                    continue;
                }

                $slug = sanitize_text_field( wp_unslash( $_POST[ $key ] ) );

                $cart_item_data[ $key ] = sanitize_title( $slug );
                $cart_item_data[ "{$key}_name" ] = self::term_name( $taxonomy, $slug );

                $hasCouple = true;
            }

            if ( $hasCouple ) {
                $cart_item_data['thready_couple_mode'] = true;
            }
        }

        // Custom order note (ALWAYS allowed, regardless of couple/print)
        if ( ! empty( $_POST['thready_custom_order_note'] ) ) {
            $note = sanitize_textarea_field( wp_unslash( $_POST['thready_custom_order_note'] ) );
            $cart_item_data['thready_custom_order_note'] = $note;

            // prevent merging if two items have different notes
            $cart_item_data['thready_custom_note_hash'] = md5( $note );
        }

        // prevent merging when couple selections differ
        if ( ! empty( $cart_item_data['thready_couple_mode'] ) ) {
            $cart_item_data['thready_unique'] = md5( microtime() . wp_rand() );
        }

        return $cart_item_data;
    }

    public static function restore_cart_item( $cart_item, $values, $cart_item_key ) {
        foreach ( $values as $k => $v ) {
            if ( strpos( $k, 'thready_' ) === 0 ) {
                $cart_item[ $k ] = $v;
            }
        }
        return $cart_item;
    }

    /* ---------------------------------------------------------
     * Cart / Checkout item data (standard Woo output)
     * --------------------------------------------------------- */

    public static function display_data_in_cart( $item_data, $cart_item ) {

        // Compact couple summary (cart + checkout)
        if ( ! empty( $cart_item['thready_couple_mode'] ) ) {
            $lines = self::build_couple_summary_lines( $cart_item );

            if ( $lines ) {
                $item_data[] = [
                    'name'  => 'Odabrane opcije',
                    'value' => implode( '<br>', array_map( 'esc_html', $lines ) ),
                ];
            }
        }

        // Custom note: show in cart/checkout (NOT mini-cart)
        if ( ! empty( $cart_item['thready_custom_order_note'] ) ) {
            $item_data[] = [
                'name'  => __( 'Dodatne informacije', 'thready-product-customizer' ),
                'value' => nl2br( esc_html( $cart_item['thready_custom_order_note'] ) ),
            ];
        }

        return $item_data;
    }

    /* ---------------------------------------------------------
     * Mini-cart output helpers (themes often use this)
     * --------------------------------------------------------- */

    public static function append_data_to_widget_quantity( $qty_html, $cart_item, $cart_item_key ) {

        // Mini-cart: add couple summary under qty
        if ( ! empty( $cart_item['thready_couple_mode'] ) ) {
            $lines = self::build_couple_summary_lines( $cart_item );

            if ( $lines ) {
                $qty_html .= '<br><small class="thready-mini-summary">' .
                    esc_html( implode( ' | ', $lines ) ) .
                    '</small>';
            }
        }

        // Custom note: DO NOT show in mini-cart (per your request)
        return $qty_html;
    }

    public static function append_data_to_item_name( $name, $cart_item, $cart_item_key ) {

        // Some themes use this in mini-cart, some don’t.
        if ( ! empty( $cart_item['thready_couple_mode'] ) && ! is_cart() && ! is_checkout() ) {
            $lines = self::build_couple_summary_lines( $cart_item );
            if ( $lines ) {
                $name .= '<br><small class="thready-mini-summary">' .
                    esc_html( implode( ' | ', $lines ) ) .
                    '</small>';
            }
        }

        return $name;
    }

    /* ---------------------------------------------------------
     * Permalinks: append our selections so product page can read them
     * --------------------------------------------------------- */

    public static function append_params_to_cart_item_permalink( $permalink, $cart_item, $cart_item_key ) {

        if ( empty( $cart_item['thready_couple_mode'] ) ) {
            return $permalink;
        }

        $args = self::get_couple_query_args( $cart_item );
        if ( ! $args ) {
            return $permalink;
        }

        // do NOT include note in URL
        unset( $args['thready_custom_order_note'], $args['thready_custom_note_hash'] );

        return add_query_arg( $args, $permalink );
    }

    /* ---------------------------------------------------------
     * Order meta (admin + emails)
     * --------------------------------------------------------- */

    public static function add_data_to_order_meta( $item, $cart_item_key, $values, $order ) {

        if ( ! empty( $values['thready_couple_mode'] ) ) {

            $labels = [
                'thready_her_size_name'             => 'Za nju - Veličina',
                'thready_her_color_name'            => 'Za nju - Boja',
                'thready_her_embroidery_color_name' => 'Za nju - Boja veza',
                'thready_him_size_name'             => 'Za njega - Veličina',
                'thready_him_color_name'            => 'Za njega - Boja',
                'thready_him_embroidery_color_name' => 'Za njega - Boja veza',
            ];

            foreach ( $labels as $key => $label ) {
                if ( ! empty( $values[ $key ] ) ) {
                    $item->add_meta_data( $label, $values[ $key ], true );
                }
            }
        }

        // Custom note always stored
        if ( ! empty( $values['thready_custom_order_note'] ) ) {
            $item->add_meta_data(
                __( 'Dodatne informacije', 'thready-product-customizer' ),
                $values['thready_custom_order_note'],
                true
            );
        }
    }
}
