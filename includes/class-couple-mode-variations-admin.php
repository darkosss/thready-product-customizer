<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Thready_Couple_Mode_Variations_Admin {

    public static function init() {
        add_action(
            'woocommerce_variation_options_pricing',
            [ __CLASS__, 'render_couple_mode_fields' ],
            20,
            3
        );

        add_action(
            'woocommerce_save_product_variation',
            [ __CLASS__, 'save_couple_mode_fields' ],
            20,
            2
        );
    }

    private static function is_couple_mode_enabled( $product_id ) {
        return get_post_meta( $product_id, '_thready_couple_mode', true ) === 'yes';
    }

    private static function get_terms_for_taxonomy( $taxonomy ) {
        $terms = get_terms(
            [
                'taxonomy'   => $taxonomy,
                'hide_empty' => false,
            ]
        );

        return is_array( $terms ) ? $terms : [];
    }

    private static function render_multiselect( $label, $name, $terms, $selected_csv ) {
        $selected = array_filter( array_map( 'trim', explode( ',', (string) $selected_csv ) ) );

        echo '<p class="form-field">';
        echo '<label>' . esc_html( $label ) . '</label>';
        echo '<select class="wc-enhanced-select" multiple="multiple" style="width:100%;" name="' . esc_attr( $name ) . '[]">';

        foreach ( $terms as $term ) {
            $is_selected = in_array( $term->slug, $selected, true ) ? 'selected' : '';
            echo '<option value="' . esc_attr( $term->slug ) . '" ' . $is_selected . '>' . esc_html( $term->name ) . '</option>';
        }

        echo '</select>';
        echo '</p>';
    }

    public static function render_couple_mode_fields( $loop, $variation_data, $variation ) {

        $variation_id = $variation->ID;
        $product_id   = wp_get_post_parent_id( $variation_id );

        if ( ! self::is_couple_mode_enabled( $product_id ) ) {
            return;
        }

        $sizes  = self::get_terms_for_taxonomy( 'pa_velicina' );
        $colors = self::get_terms_for_taxonomy( 'pa_boja' );

        echo '<div class="options_group">';
        echo '<strong>Couple Mode – For Her</strong>';

        self::render_multiselect(
            'Sizes',
            "thready_her_sizes_{$loop}",
            $sizes,
            get_post_meta( $variation_id, '_thready_her_sizes', true )
        );

        self::render_multiselect(
            'Colors',
            "thready_her_colors_{$loop}",
            $colors,
            get_post_meta( $variation_id, '_thready_her_colors', true )
        );

        self::render_multiselect(
            'Embroidery Colors',
            "thready_her_embroidery_colors_{$loop}",
            $colors,
            get_post_meta( $variation_id, '_thready_her_embroidery_colors', true )
        );

        echo '</div>';

        echo '<div class="options_group">';
        echo '<strong>Couple Mode – For Him</strong>';

        self::render_multiselect(
            'Sizes',
            "thready_him_sizes_{$loop}",
            $sizes,
            get_post_meta( $variation_id, '_thready_him_sizes', true )
        );

        self::render_multiselect(
            'Colors',
            "thready_him_colors_{$loop}",
            $colors,
            get_post_meta( $variation_id, '_thready_him_colors', true )
        );

        self::render_multiselect(
            'Embroidery Colors',
            "thready_him_embroidery_colors_{$loop}",
            $colors,
            get_post_meta( $variation_id, '_thready_him_embroidery_colors', true )
        );

        echo '</div>';
    }

    public static function save_couple_mode_fields( $variation_id, $loop ) {
        $fields = [
            '_thready_her_sizes'             => "thready_her_sizes_{$loop}",
            '_thready_her_colors'            => "thready_her_colors_{$loop}",
            '_thready_her_embroidery_colors' => "thready_her_embroidery_colors_{$loop}",
            '_thready_him_sizes'             => "thready_him_sizes_{$loop}",
            '_thready_him_colors'            => "thready_him_colors_{$loop}",
            '_thready_him_embroidery_colors' => "thready_him_embroidery_colors_{$loop}",
        ];

        foreach ( $fields as $meta_key => $post_key ) {
            if ( isset( $_POST[ $post_key ] ) && is_array( $_POST[ $post_key ] ) ) {
                $values = array_map( 'sanitize_title', wp_unslash( $_POST[ $post_key ] ) );
                update_post_meta( $variation_id, $meta_key, implode( ',', $values ) );
            } else {
                delete_post_meta( $variation_id, $meta_key );
            }
        }
    }
}
