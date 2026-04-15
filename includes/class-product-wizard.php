<?php
/**
 * Thready Product Wizard
 *
 * Full-page multi-step product creation interface.
 * Creates one WooCommerce variable product where:
 *   - Variation attributes: pa_tip-proizvoda × pa_boja
 *   - Sizes: stored as _thready_available_sizes meta (not variation attributes)
 *   - Print positioning: stored per tip slug on the product
 *
 * Steps
 * -----
 * 1 — Product name + types (pa_tip-proizvoda)
 * 2 — Colors (pa_boja) — shared across all types
 * 3 — Prices per type
 * 4 — Available sizes (pa_velicina) — shared, stored as meta
 * 5 — Print design upload + canvas positioning per type
 * 6 — Review & Create
 */

defined( 'ABSPATH' ) || exit;

class Thready_Product_Wizard {

    public static function init() {
        add_action( 'admin_menu',            [ __CLASS__, 'add_admin_page'  ] );
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_scripts' ] );
        add_action( 'restrict_manage_posts', [ __CLASS__, 'inject_button'   ] );

        add_action( 'wp_ajax_thready_wizard_create',       [ __CLASS__, 'ajax_create'       ] );
        add_action( 'wp_ajax_thready_wizard_upload_print', [ __CLASS__, 'ajax_upload_print' ] );
    }

    // -------------------------------------------------------------------------
    // Admin page
    // -------------------------------------------------------------------------

    public static function add_admin_page() {
        add_submenu_page(
            null,   // hidden from menu
            __( 'New Thready Product', 'thready-product-customizer' ),
            __( 'New Thready Product', 'thready-product-customizer' ),
            'manage_woocommerce',
            'thready-wizard',
            [ __CLASS__, 'render_page' ]
        );
    }

    public static function inject_button( $post_type ) {
        if ( $post_type !== 'product' ) return;
        $url = admin_url( 'admin.php?page=thready-wizard' );
        echo '<a href="' . esc_url( $url ) . '" class="button" style="margin-left:6px;">'
            . '<span class="dashicons dashicons-art" style="margin-top:3px;font-size:16px;"></span> '
            . esc_html__( 'New Thready Product', 'thready-product-customizer' )
            . '</a>';
    }

    // -------------------------------------------------------------------------
    // Scripts + data
    // -------------------------------------------------------------------------

    public static function enqueue_scripts( $hook ) {
        if ( ! isset( $_GET['page'] ) || $_GET['page'] !== 'thready-wizard' ) return;

        wp_enqueue_media();

        wp_enqueue_style(
            'thready-wizard',
            THREADY_PC_URL . 'assets/css/wizard.css',
            [],
            THREADY_PC_VERSION
        );

        wp_enqueue_script(
            'thready-wizard',
            THREADY_PC_URL . 'assets/js/wizard.js',
            [ 'jquery' ],
            THREADY_PC_VERSION,
            true
        );

        wp_localize_script( 'thready-wizard', 'threadyWizard', self::build_js_data() );
    }

    private static function build_js_data() {
        // pa_tip-proizvoda terms
        $tip_terms = get_terms( [ 'taxonomy' => THREADY_TAX_TIP, 'hide_empty' => false, 'orderby' => 'name' ] );
        $tips = [];
        if ( ! is_wp_error( $tip_terms ) ) {
            foreach ( $tip_terms as $t ) {
                $tips[] = [ 'slug' => $t->slug, 'name' => $t->name ];
            }
        }

        // pa_boja terms with hex color
        $boja_terms = get_terms( [ 'taxonomy' => THREADY_TAX_BOJA, 'hide_empty' => false, 'orderby' => 'name' ] );
        $bojas = [];
        if ( ! is_wp_error( $boja_terms ) ) {
            foreach ( $boja_terms as $t ) {
                $bojas[] = [
                    'slug' => $t->slug,
                    'name' => $t->name,
                    'hex'  => get_term_meta( $t->term_id, 'product_attribute_color', true ) ?: '',
                ];
            }
        }

        // pa_velicina terms
        $velicina_terms = get_terms( [ 'taxonomy' => THREADY_TAX_VELICINA, 'hide_empty' => false, 'orderby' => 'name' ] );
        $velicinas = [];
        if ( ! is_wp_error( $velicina_terms ) ) {
            foreach ( $velicina_terms as $t ) {
                $velicinas[] = [ 'slug' => $t->slug, 'name' => $t->name ];
            }
        }

        // Mockup availability map: tip|boja → {has_front, has_back, front_thumb, front_url, back_url}
        $mockup_map = [];
        foreach ( $tips as $tip ) {
            $tip_mockups = Thready_Mockup_Library::get_for_tip( $tip['slug'] );
            foreach ( $bojas as $boja ) {
                $row = $tip_mockups[ $boja['slug'] ] ?? null;
                $key = $tip['slug'] . '|' . $boja['slug'];
                $mockup_map[ $key ] = [
                    'has_front'   => $row && $row->front_image ? true : false,
                    'has_back'    => $row && $row->back_image  ? true : false,
                    'front_thumb' => ( $row && $row->front_image ) ? wp_get_attachment_image_url( (int) $row->front_image, 'thumbnail' ) : '',
                    'front_url'   => ( $row && $row->front_image ) ? wp_get_attachment_url( (int) $row->front_image ) : '',
                    'back_url'    => ( $row && $row->back_image  ) ? wp_get_attachment_url( (int) $row->back_image  ) : '',
                ];
            }
        }

        // Edit mode pre-fill
        $edit_data = null;
        if ( ! empty( $_GET['product_id'] ) ) {
            $pid = absint( $_GET['product_id'] );
            if ( $pid ) $edit_data = self::build_edit_data( $pid );
        }

        return [
            'ajax_url'       => admin_url( 'admin-ajax.php' ),
            'nonce'          => wp_create_nonce( 'thready_wizard_nonce' ),
            'tips'           => $tips,
            'bojas'          => $bojas,
            'velicinas'      => $velicinas,
            'mockup_map'     => $mockup_map,
            'edit_data'      => $edit_data,
            'products_url'   => admin_url( 'edit.php?post_type=product' ),
            'mockup_lib_url' => admin_url( 'admin.php?page=thready-mockup-library' ),
            'i18n'           => [
                'next'   => __( 'Next →',          'thready-product-customizer' ),
                'back'   => __( '← Back',          'thready-product-customizer' ),
                'create' => __( 'Create Product',  'thready-product-customizer' ),
                'update' => __( 'Save Changes',    'thready-product-customizer' ),
            ],
        ];
    }

    private static function build_edit_data( $product_id ) {
        $product = wc_get_product( $product_id );
        if ( ! $product || ! $product->is_type( 'variable' ) ) return null;

        $summary = Thready_Variation_Factory::get_summary( $product_id );

        return [
            'product_id'    => $product_id,
            'product_name'  => $product->get_name(),
            'tips'          => $summary['tips'],
            'tip_colors'    => $summary['tip_colors'],
            'tip_sizes'     => $summary['tip_sizes'],
            'tip_prices'    => $summary['tip_prices'],
            'tip_positions' => $summary['tip_positions'],
            'print_front_id'=> $summary['print_front'],
            'print_light_id'=> $summary['print_light'],
            'print_back_id' => $summary['print_back'],
            'render_mode'   => $summary['render_mode'],
        ];
    }

    // -------------------------------------------------------------------------
    // Page render — shell only, JS builds the steps
    // -------------------------------------------------------------------------

    public static function render_page() {
        $is_edit    = ! empty( $_GET['product_id'] );
        $page_title = $is_edit
            ? __( 'Edit Thready Product', 'thready-product-customizer' )
            : __( 'New Thready Product',  'thready-product-customizer' );

        $currency_symbol = html_entity_decode( get_woocommerce_currency_symbol() );
        ?>
        <div class="thready-wizard-wrap">

            <div class="wizard-back-bar">
                <a href="<?php echo esc_url( admin_url( 'edit.php?post_type=product' ) ); ?>" class="wizard-back-link">
                    ← <?php esc_html_e( 'Back to Products', 'thready-product-customizer' ); ?>
                </a>
            </div>

            <div class="thready-wizard"
                 id="thready-wizard"
                 data-edit="<?php echo $is_edit ? '1' : '0'; ?>"
                 data-currency="<?php echo esc_attr( $currency_symbol ); ?>">

                <div class="wizard-header">
                    <div class="wizard-title"><?php echo esc_html( $page_title ); ?></div>
                    <ol class="wizard-steps" id="wizard-steps">
                        <?php
                        $labels = [
                            __( 'Type',    'thready-product-customizer' ),
                            __( 'Colors',  'thready-product-customizer' ),
                            __( 'Pricing', 'thready-product-customizer' ),
                            __( 'Sizes',   'thready-product-customizer' ),
                            __( 'Design',  'thready-product-customizer' ),
                            __( 'Review',  'thready-product-customizer' ),
                        ];
                        foreach ( $labels as $i => $label ) : ?>
                            <li class="wizard-step-indicator <?php echo $i === 0 ? 'active' : ''; ?>"
                                data-step="<?php echo $i + 1; ?>">
                                <span class="step-num"><?php echo $i + 1; ?></span>
                                <span class="step-label"><?php echo esc_html( $label ); ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                </div>

                <div class="wizard-body" id="wizard-body">
                    <div class="wizard-loading">
                        <span class="spinner is-active"></span>
                        <?php esc_html_e( 'Loading…', 'thready-product-customizer' ); ?>
                    </div>
                </div>

                <div class="wizard-footer" id="wizard-footer">
                    <button type="button" class="button wizard-btn-back" id="wizard-btn-back" style="display:none;">
                        ← <?php esc_html_e( 'Back', 'thready-product-customizer' ); ?>
                    </button>
                    <div class="wizard-footer-right">
                        <span class="wizard-error-msg" id="wizard-error-msg" role="alert"></span>
                        <button type="button" class="button button-primary" id="wizard-btn-next">
                            <?php esc_html_e( 'Next →', 'thready-product-customizer' ); ?>
                        </button>
                    </div>
                </div>

            </div>
        </div>
        <?php
    }

    // -------------------------------------------------------------------------
    // AJAX — create product
    // -------------------------------------------------------------------------

    public static function ajax_create() {
        check_ajax_referer( 'thready_wizard_nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
        }

        $raw = json_decode( wp_unslash( $_POST['payload'] ?? '{}' ), true );
        if ( ! $raw ) {
            wp_send_json_error( [ 'message' => 'Invalid payload' ] );
        }

        // Sanitize tip_colors: { tipSlug: [ { slug, light_print }, … ] }
        $tip_colors_raw = $raw['tip_colors'] ?? [];
        $tip_colors = [];
        foreach ( $tip_colors_raw as $tip_slug => $colors ) {
            $tip_slug = sanitize_title( $tip_slug );
            $tip_colors[ $tip_slug ] = [];
            foreach ( (array) $colors as $color ) {
                $tip_colors[ $tip_slug ][] = [
                    'slug'        => sanitize_title( $color['slug'] ?? '' ),
                    'light_print' => ! empty( $color['light_print'] ),
                ];
            }
        }

        // Sanitize tip_sizes: { tipSlug: string[] }
        $tip_sizes_raw = $raw['tip_sizes'] ?? [];
        $tip_sizes = [];
        foreach ( $tip_sizes_raw as $tip_slug => $sizes ) {
            $tip_sizes[ sanitize_title( $tip_slug ) ] = array_map( 'sanitize_title', (array) $sizes );
        }

        $product_id = Thready_Variation_Factory::create_product( [
            'name'           => sanitize_text_field( $raw['name']           ?? '' ),
            'tip_slugs'      => array_map( 'sanitize_title', $raw['tip_slugs'] ?? [] ),
            'tip_colors'     => $tip_colors,
            'tip_sizes'      => $tip_sizes,
            'tip_prices'     => $raw['tip_prices']    ?? [],
            'tip_positions'  => $raw['tip_positions'] ?? [],
            'print_front_id' => absint( $raw['print_front_id'] ?? 0 ),
            'print_light_id' => absint( $raw['print_light_id'] ?? 0 ) ?: null,
            'print_back_id'  => absint( $raw['print_back_id']  ?? 0 ) ?: null,
        ] );

        if ( is_wp_error( $product_id ) ) {
            wp_send_json_error( [ 'message' => $product_id->get_error_message() ] );
        }

        $var_count = (int) ( new WC_Product_Variable( $product_id ) )->get_available_variations( 'count' );

        wp_send_json_success( [
            'product_id'      => $product_id,
            'variation_count' => $var_count,
            'edit_url'        => get_edit_post_link( $product_id, 'raw' ),
            'view_url'        => get_permalink( $product_id ),
        ] );
    }

    // -------------------------------------------------------------------------
    // AJAX — upload print image
    // -------------------------------------------------------------------------

    public static function ajax_upload_print() {
        check_ajax_referer( 'thready_wizard_nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
        }

        if ( empty( $_FILES['file'] ) || $_FILES['file']['error'] !== UPLOAD_ERR_OK ) {
            wp_send_json_error( [ 'message' => 'Upload failed.' ] );
        }

        // Allow PNG only for print designs (transparent backgrounds)
        $finfo = finfo_open( FILEINFO_MIME_TYPE );
        $mime  = finfo_file( $finfo, $_FILES['file']['tmp_name'] );
        finfo_close( $finfo );

        if ( $mime !== 'image/png' ) {
            wp_send_json_error( [ 'message' => 'Print images must be PNG files with a transparent background.' ] );
        }

        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        $attachment_id = media_handle_upload( 'file', 0 );

        if ( is_wp_error( $attachment_id ) ) {
            wp_send_json_error( [ 'message' => $attachment_id->get_error_message() ] );
        }

        wp_send_json_success( [
            'attachment_id' => $attachment_id,
            'url'           => wp_get_attachment_url( $attachment_id ),
            'thumb_url'     => wp_get_attachment_image_url( $attachment_id, 'thumbnail' ),
        ] );
    }
}