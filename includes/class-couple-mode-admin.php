<?php
/**
 * Couple Mode – Admin Product Setting
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Thready_Couple_Mode_Admin {

    public function __construct() {
        add_action( 'woocommerce_product_options_general_product_data', [ $this, 'add_couple_mode_field' ] );
        add_action( 'woocommerce_admin_process_product_object', [ $this, 'save_couple_mode_field' ] );
        add_action( 'admin_head', [ $this, 'admin_styles_and_scripts' ] );
    }

    /**
     * Check if product already uses print images
     */
    private function has_print_images( $product_id ) {
        return (
            get_post_meta( $product_id, '_thready_print_image', true ) ||
            get_post_meta( $product_id, '_thready_light_print_image', true ) ||
            get_post_meta( $product_id, '_thready_back_print_image', true )
        );
    }

    /**
     * Add checkbox to Product Data > General tab
     */
    public function add_couple_mode_field() {
        global $post;

        if ( ! $post ) {
            return;
        }

        $has_print_images = $this->has_print_images( $post->ID );

        echo '<div class="options_group thready-couple-mode-group">';

        woocommerce_wp_checkbox(
            [
                'id'                => '_thready_couple_mode',
                'label'             => __( 'Enable Couple Mode', 'thready-product-customizer' ),
                'description'       => __(
                    'Enable couple-specific size and color selection (For Her / For Him).',
                    'thready-product-customizer'
                ),
                'custom_attributes' => $has_print_images
                    ? [ 'disabled' => 'disabled' ]
                    : [],
            ]
        );

        if ( $has_print_images ) {
            echo '<p class="description thready-couple-warning">';
            esc_html_e(
                'Couple Mode cannot be enabled because this product already uses print images.',
                'thready-product-customizer'
            );
            echo '</p>';
        } else {
            echo '<p class="thready-couple-save-wrap">';
            echo '<button type="button" class="button button-primary thready-save-couple-mode">';
            esc_html_e( 'Save Couple Mode Settings', 'thready-product-customizer' );
            echo '</button>';
            echo '</p>';
        }

        echo '</div>';
    }

    /**
     * Save product meta (hard safety)
     */
    public function save_couple_mode_field( $product ) {
        $product_id = $product->get_id();

        // Never allow Couple Mode if print images exist
        if ( $this->has_print_images( $product_id ) ) {
            $product->update_meta_data( '_thready_couple_mode', 'no' );
            return;
        }

        $enabled = isset( $_POST['_thready_couple_mode'] ) ? 'yes' : 'no';
        $product->update_meta_data( '_thready_couple_mode', $enabled );
    }

    /**
     * Admin styles + scoped JS
     */
    public function admin_styles_and_scripts() {
        ?>
        <style>
            .thready-couple-mode-group {
                border: 2px solid #2271b1 !important;
                background: #f0f6fc;
                padding: 12px;
                margin: 15px;
                border-radius: 4px;
            }

            .thready-couple-mode-group label {
                font-weight: 600;
            }

            .thready-couple-warning {
                color: #b32d2e;
                margin-top: 8px;
            }

            .thready-couple-save-wrap {
                margin-top: 10px;
            }
        </style>

        <script>
            jQuery(function ($) {
                $('.thready-save-couple-mode').on('click', function () {
                    const confirmed = confirm(
                        '<?php echo esc_js( __( 'Save Couple Mode changes and update the product?', 'thready-product-customizer' ) ); ?>'
                    );

                    if (!confirmed) {
                        return;
                    }

                    // Trigger main WooCommerce Update button
                    $('#publish').trigger('click');
                });
            });
        </script>
        <?php
    }
}
