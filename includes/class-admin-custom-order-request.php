<?php
/**
 * Custom Order Request – Product-level textarea
 *
 * Allows admin to define a custom label + placeholder
 * and customer to submit a free-text request.
 *
 * Saved to order item meta only (not cart / mini-cart).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Thready_Custom_Order_Request {

    public static function init() {

        // Admin product settings
        add_action(
            'woocommerce_product_options_general_product_data',
            [ __CLASS__, 'add_admin_fields' ]
        );

        add_action(
            'woocommerce_admin_process_product_object',
            [ __CLASS__, 'save_admin_fields' ]
        );

        // Frontend display
        add_action(
            'woocommerce_before_add_to_cart_button',
            [ __CLASS__, 'render_frontend_field' ],
            99
        );

        // Save to cart item
        add_filter(
            'woocommerce_add_cart_item_data',
            [ __CLASS__, 'add_cart_item_data' ],
            10,
            2
        );

        // Save to order
        add_action(
            'woocommerce_checkout_create_order_line_item',
            [ __CLASS__, 'add_order_item_meta' ],
            10,
            4
        );
    }

    /* ---------------------------------------------------------
     * Admin: Product settings
     * --------------------------------------------------------- */

    public static function add_admin_fields() {

        echo '<div class="options_group">';

        woocommerce_wp_text_input([
            'id'          => '_thready_custom_note_label',
            'label'       => __( 'Custom Order Request – Label', 'thready-product-customizer' ),
            'description' => __( 'Shown above the textarea on product page.', 'thready-product-customizer' ),
            'desc_tip'    => true,
        ]);

        woocommerce_wp_text_input([
            'id'          => '_thready_custom_note_placeholder',
            'label'       => __( 'Custom Order Request – Placeholder', 'thready-product-customizer' ),
            'description' => __( 'Placeholder text inside the textarea.', 'thready-product-customizer' ),
            'desc_tip'    => true,
        ]);

        echo '</div>';
    }

    public static function save_admin_fields( $product ) {

        if ( isset( $_POST['_thready_custom_note_label'] ) ) {
            $product->update_meta_data(
                '_thready_custom_note_label',
                sanitize_text_field( $_POST['_thready_custom_note_label'] )
            );
        }

        if ( isset( $_POST['_thready_custom_note_placeholder'] ) ) {
            $product->update_meta_data(
                '_thready_custom_note_placeholder',
                sanitize_text_field( $_POST['_thready_custom_note_placeholder'] )
            );
        }
    }

    /* ---------------------------------------------------------
     * Frontend: Display field
     * --------------------------------------------------------- */

    public static function render_frontend_field() {

        global $product;

        if ( ! $product || ! $product->is_type( 'variable' ) ) {
            return;
        }

        $label       = $product->get_meta( '_thready_custom_note_label' );
        $placeholder = $product->get_meta( '_thready_custom_note_placeholder' );

        if ( ! $label && ! $placeholder ) {
            return;
        }

        // Preserve value on reload / validation errors
        $value = isset( $_POST['thready_custom_order_note'] )
            ? sanitize_textarea_field( $_POST['thready_custom_order_note'] )
            : '';

        ?>
        <div class="thready-custom-order-request" style="margin-bottom: 20px;">
            <?php if ( $label ) : ?>
                <label for="thready_custom_order_note" style="display:block;font-weight:600;margin-bottom:6px;">
                    <?php echo esc_html( $label ); ?>
                </label>
            <?php endif; ?>

            <textarea
                id="thready_custom_order_note"
                name="thready_custom_order_note"
                rows="3"
                placeholder="<?php echo esc_attr( $placeholder ); ?>"
                style="width:100%;"
            ><?php echo esc_textarea( $value ); ?></textarea>
        </div>
        <?php
    }

    /* ---------------------------------------------------------
     * Cart + Order
     * --------------------------------------------------------- */

    public static function add_cart_item_data( $cart_item_data, $product_id ) {

        if ( empty( $_POST['thready_custom_order_note'] ) ) {
            return $cart_item_data;
        }

        $note = sanitize_textarea_field( $_POST['thready_custom_order_note'] );

        $cart_item_data['thready_custom_order_note'] = $note;

        // Prevent cart item merging when notes differ
        $cart_item_data['thready_custom_note_hash'] = md5( $note . time() );

        return $cart_item_data;
    }

    public static function add_order_item_meta( $item, $cart_item_key, $values, $order ) {

        if ( empty( $values['thready_custom_order_note'] ) ) {
            return;
        }

        $item->add_meta_data(
            __( 'Dodatne informacije', 'thready-product-customizer' ),
            $values['thready_custom_order_note'],
            true
        );
    }
}
