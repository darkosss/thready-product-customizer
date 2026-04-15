<?php
/**
 * Thready Product Wizard
 *
 * Full-page multi-step product creation interface.
 * Renders a data shell server-side; all step UI is built by wizard.js.
 *
 * Steps
 * -----
 * 1 — Design name + product types (pa_tip)
 * 2 — Colors per type (pa_boja) — with mockup thumbnail preview
 * 3 — Prices per type (regular + sale)
 * 4 — Sizes per type (pa_velicina)
 * 5 — Print design upload + canvas positioning per type
 * 6 — Review & Create
 *
 * AJAX
 * ----
 * thready_wizard_create         — validates + calls Variation Factory per type
 * thready_wizard_upload_print   — handles print PNG upload, returns attachment data
 * thready_wizard_mockup_preview — returns mockup image URL for a tip+boja combo
 */

defined( 'ABSPATH' ) || exit;

class Thready_Product_Wizard {

    // -------------------------------------------------------------------------
    // Boot
    // -------------------------------------------------------------------------

    public static function init() {
        add_action( 'admin_menu',            [ __CLASS__, 'add_admin_page'  ] );
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_scripts' ] );

        // Inject "New Thready Product" button on WC products list
        add_action( 'restrict_manage_posts', [ __CLASS__, 'inject_products_button' ] );

        // AJAX
        add_action( 'wp_ajax_thready_wizard_create',         [ __CLASS__, 'ajax_create'         ] );
        add_action( 'wp_ajax_thready_wizard_upload_print',   [ __CLASS__, 'ajax_upload_print'   ] );
        add_action( 'wp_ajax_thready_wizard_mockup_preview', [ __CLASS__, 'ajax_mockup_preview' ] );
    }

    // -------------------------------------------------------------------------
    // Admin page
    // -------------------------------------------------------------------------

    public static function add_admin_page() {
        add_submenu_page(
            null,                  // hidden from menu — accessed via button
            __( 'New Thready Product', 'thready-product-customizer' ),
            __( 'New Thready Product', 'thready-product-customizer' ),
            'manage_woocommerce',
            'thready-wizard',
            [ __CLASS__, 'render_page' ]
        );
    }

    public static function inject_products_button( $post_type ) {
        if ( $post_type !== 'product' ) return;

        $url = admin_url( 'admin.php?page=thready-wizard' );
        echo '<a href="' . esc_url( $url ) . '" class="button thready-new-btn" style="margin-left:6px;">'
            . '<span class="dashicons dashicons-art" style="margin-top:3px;font-size:16px;"></span> '
            . esc_html__( 'New Thready Product', 'thready-product-customizer' )
            . '</a>';
    }

    // -------------------------------------------------------------------------
    // Scripts & data
    // -------------------------------------------------------------------------

    public static function enqueue_scripts( $hook ) {
        // Load on wizard page and on product edit page (edit panel uses same data)
        $is_wizard = ( isset( $_GET['page'] ) && $_GET['page'] === 'thready-wizard' );
        if ( ! $is_wizard ) return;

        wp_enqueue_media();
        wp_enqueue_style( 'wp-color-picker' );

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

    /**
     * Build the full data object passed to wizard.js.
     */
    private static function build_js_data() {
        // pa_tip terms
        $tip_terms = get_terms( [ 'taxonomy' => THREADY_TAX_TIP, 'hide_empty' => false, 'orderby' => 'name' ] );
        $tips = [];
        if ( ! is_wp_error( $tip_terms ) ) {
            foreach ( $tip_terms as $t ) {
                $tips[] = [ 'slug' => $t->slug, 'name' => $t->name ];
            }
        }

        // pa_boja terms — include hex from WVS
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

        // Mockup availability map — tip_slug|boja_slug → {has_front, has_back, front_url}
        $mockup_map = [];
        foreach ( $tips as $tip ) {
            $tip_mockups = Thready_Mockup_Library::get_for_tip( $tip['slug'] );
            foreach ( $bojas as $boja ) {
                $row = $tip_mockups[ $boja['slug'] ] ?? null;
                $key = $tip['slug'] . '|' . $boja['slug'];
                $mockup_map[ $key ] = [
                    'has_front'  => $row && $row->front_image ? true : false,
                    'has_back'   => $row && $row->back_image  ? true : false,
                    'front_thumb'=> ( $row && $row->front_image )
                                    ? wp_get_attachment_image_url( (int) $row->front_image, 'thumbnail' )
                                    : '',
                    'front_url'  => ( $row && $row->front_image )
                                    ? wp_get_attachment_url( (int) $row->front_image )
                                    : '',
                    'back_url'   => ( $row && $row->back_image )
                                    ? wp_get_attachment_url( (int) $row->back_image )
                                    : '',
                ];
            }
        }

        // Edit mode — pre-fill from existing product
        $edit_data = null;
        if ( ! empty( $_GET['product_id'] ) ) {
            $pid = absint( $_GET['product_id'] );
            if ( $pid ) {
                $edit_data = self::build_edit_data( $pid );
            }
        }

        return [
            'ajax_url'    => admin_url( 'admin-ajax.php' ),
            'nonce'       => wp_create_nonce( 'thready_wizard_nonce' ),
            'tips'        => $tips,
            'bojas'       => $bojas,
            'velicinas'   => $velicinas,
            'mockup_map'  => $mockup_map,
            'edit_data'   => $edit_data,
            'products_url'=> admin_url( 'edit.php?post_type=product' ),
            'mockup_lib_url' => admin_url( 'admin.php?page=thready-mockup-library' ),
            'i18n'        => self::i18n(),
        ];
    }

    private static function build_edit_data( $product_id ) {
        $product = wc_get_product( $product_id );
        if ( ! $product || ! $product->is_type( 'variable' ) ) return null;

        $summary = Thready_Variation_Factory::get_summary( $product_id );

        return [
            'product_id'    => $product_id,
            'product_name'  => $product->get_name(),
            'tip_slug'      => $summary['tip_slug'],
            'colors'        => $summary['colors'],
            'sizes'         => $summary['sizes'],
            'regular_price' => $summary['regular_price'],
            'sale_price'    => $summary['sale_price'],
            'print_front_id'=> $summary['print_front'],
            'print_back_id' => $summary['print_back'],
            'pos_front'     => $summary['position_front'],
            'pos_back'      => $summary['position_back'],
            'render_mode'   => $summary['render_mode'],
        ];
    }

    private static function i18n() {
        return [
            'step_labels'        => [
                __( 'Product Type',   'thready-product-customizer' ),
                __( 'Colors',         'thready-product-customizer' ),
                __( 'Pricing',        'thready-product-customizer' ),
                __( 'Sizes',          'thready-product-customizer' ),
                __( 'Print Design',   'thready-product-customizer' ),
                __( 'Review',         'thready-product-customizer' ),
            ],
            'next'               => __( 'Next →',          'thready-product-customizer' ),
            'back'               => __( '← Back',          'thready-product-customizer' ),
            'create'             => __( 'Create Products',  'thready-product-customizer' ),
            'update'             => __( 'Save Changes',     'thready-product-customizer' ),
            'creating'           => __( 'Creating…',        'thready-product-customizer' ),
            'select_front_print' => __( 'Select Front Print Image', 'thready-product-customizer' ),
            'select_back_print'  => __( 'Select Back Print Image',  'thready-product-customizer' ),
            'use_image'          => __( 'Use this image',   'thready-product-customizer' ),
            'no_mockup_warning'  => __( 'No mockup image found in library for this combination.', 'thready-product-customizer' ),
            'required_field'     => __( 'This field is required.',  'thready-product-customizer' ),
            'err_no_name'        => __( 'Enter a design name.',     'thready-product-customizer' ),
            'err_no_tips'        => __( 'Select at least one product type.',   'thready-product-customizer' ),
            'err_no_colors'      => __( 'Select at least one color for each product type.',  'thready-product-customizer' ),
            'err_no_price'       => __( 'Enter a price for each product type.',              'thready-product-customizer' ),
            'err_no_sizes'       => __( 'Select at least one size for each product type.',   'thready-product-customizer' ),
            'err_no_print'       => __( 'Upload a front print image.',          'thready-product-customizer' ),
            'created_ok'         => __( 'Product created successfully.',        'thready-product-customizer' ),
            'view_product'       => __( 'View Product',     'thready-product-customizer' ),
            'edit_product'       => __( 'Edit Product',     'thready-product-customizer' ),
            'add_to_library'     => __( 'Add to Mockup Library →', 'thready-product-customizer' ),
            'front_label'        => __( 'Front',            'thready-product-customizer' ),
            'back_label'         => __( 'Back',             'thready-product-customizer' ),
            'pos_x'              => __( 'Position X (%)',   'thready-product-customizer' ),
            'pos_y'              => __( 'Position Y (%)',   'thready-product-customizer' ),
            'pos_width'          => __( 'Width (%)',        'thready-product-customizer' ),
            'preview_note'       => __( 'Showing first selected color as preview.', 'thready-product-customizer' ),
            'no_preview'         => __( 'No base image — add one in the Mockup Library first.', 'thready-product-customizer' ),
            'uploading'          => __( 'Uploading…',       'thready-product-customizer' ),
            'upload_front'       => __( 'Upload Front PNG', 'thready-product-customizer' ),
            'upload_back'        => __( 'Upload Back PNG (optional)', 'thready-product-customizer' ),
            'change'             => __( 'Change',           'thready-product-customizer' ),
            'remove'             => __( 'Remove',           'thready-product-customizer' ),
            'sale_price_label'   => __( 'Sale Price (optional)', 'thready-product-customizer' ),
            'regular_price_label'=> __( 'Regular Price',   'thready-product-customizer' ),
            'select_all'         => __( 'All',              'thready-product-customizer' ),
            'select_none'        => __( 'None',             'thready-product-customizer' ),
            'variations_count'   => __( 'variations',       'thready-product-customizer' ),
        ];
    }

    // -------------------------------------------------------------------------
    // Page render — shell only, JS builds the steps
    // -------------------------------------------------------------------------

    public static function render_page() {
        $is_edit     = ! empty( $_GET['product_id'] );
        $page_title  = $is_edit
            ? __( 'Edit Thready Product', 'thready-product-customizer' )
            : __( 'New Thready Product',  'thready-product-customizer' );
        ?>
        <div class="thready-wizard-wrap">

            <?php /* Back link */ ?>
            <div class="wizard-back-bar">
                <a href="<?php echo esc_url( admin_url( 'edit.php?post_type=product' ) ); ?>" class="wizard-back-link">
                    ← <?php esc_html_e( 'Back to Products', 'thready-product-customizer' ); ?>
                </a>
            </div>

            <div class="thready-wizard" id="thready-wizard" data-edit="<?php echo $is_edit ? '1' : '0'; ?>" data-currency="<?php echo esc_attr( html_entity_decode( get_woocommerce_currency_symbol() ) ); ?>">

                <?php /* Step indicator bar */ ?>
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

                <?php /* Dynamic content area — filled by JS */ ?>
                <div class="wizard-body" id="wizard-body">
                    <div class="wizard-loading">
                        <span class="spinner is-active"></span>
                        <?php esc_html_e( 'Loading…', 'thready-product-customizer' ); ?>
                    </div>
                </div>

                <?php /* Navigation footer */ ?>
                <div class="wizard-footer" id="wizard-footer">
                    <button type="button" class="button wizard-btn-back" id="wizard-btn-back" style="display:none;">
                        ← <?php esc_html_e( 'Back', 'thready-product-customizer' ); ?>
                    </button>
                    <div class="wizard-footer-right">
                        <span class="wizard-error-msg" id="wizard-error-msg" role="alert"></span>
                        <button type="button" class="button button-primary wizard-btn-next" id="wizard-btn-next">
                            <?php esc_html_e( 'Next →', 'thready-product-customizer' ); ?>
                        </button>
                    </div>
                </div>

            </div><!-- .thready-wizard -->
        </div><!-- .thready-wizard-wrap -->
        <?php
    }

    // -------------------------------------------------------------------------
    // AJAX — create products
    // -------------------------------------------------------------------------

    public static function ajax_create() {
        check_ajax_referer( 'thready_wizard_nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => __( 'Unauthorized', 'thready-product-customizer' ) ], 403 );
        }

        $raw = json_decode( wp_unslash( $_POST['payload'] ?? '{}' ), true );
        if ( ! $raw ) {
            wp_send_json_error( [ 'message' => __( 'Invalid payload', 'thready-product-customizer' ) ] );
        }

        $design_name    = sanitize_text_field( $raw['design_name'] ?? '' );
        $print_front_id = absint( $raw['print_front_id'] ?? 0 );
        $print_back_id  = absint( $raw['print_back_id']  ?? 0 );
        $tips           = $raw['tips'] ?? [];

        // Server-side validation
        $errors = [];

        if ( ! $design_name ) {
            $errors[] = __( 'Design name is required.', 'thready-product-customizer' );
        }
        if ( ! $print_front_id || ! wp_attachment_is_image( $print_front_id ) ) {
            $errors[] = __( 'A valid front print image is required.', 'thready-product-customizer' );
        }
        if ( empty( $tips ) ) {
            $errors[] = __( 'At least one product type must be configured.', 'thready-product-customizer' );
        }

        if ( ! empty( $errors ) ) {
            wp_send_json_error( [ 'message' => implode( ' ', $errors ) ] );
        }

        // Create one product per tip
        $results    = [];
        $had_errors = false;

        foreach ( $tips as $tip_config ) {
            $tip_slug = sanitize_key( $tip_config['tip_slug'] ?? '' );

            if ( ! $tip_slug ) continue;

            // Get tip term name for product title
            $tip_term     = get_term_by( 'slug', $tip_slug, THREADY_TAX_TIP );
            $tip_name     = $tip_term ? $tip_term->name : ucfirst( $tip_slug );
            $product_name = $design_name . ' — ' . $tip_name;

            $boja_slugs     = array_map( 'sanitize_title', $tip_config['boja_slugs']     ?? [] );
            $velicina_slugs = array_map( 'sanitize_title', $tip_config['velicina_slugs'] ?? [] );
            $regular_price  = (float) ( $tip_config['regular_price'] ?? 0 );
            $sale_price     = isset( $tip_config['sale_price'] ) && $tip_config['sale_price'] !== ''
                                ? (float) $tip_config['sale_price']
                                : null;

            $pos_front = [
                'x'     => (int) ( $tip_config['pos_front']['x']     ?? 50 ),
                'y'     => (int) ( $tip_config['pos_front']['y']     ?? 25 ),
                'width' => (int) ( $tip_config['pos_front']['width'] ?? 50 ),
            ];
            $pos_back = null;
            if ( $print_back_id && ! empty( $tip_config['pos_back'] ) ) {
                $pos_back = [
                    'x'     => (int) ( $tip_config['pos_back']['x']     ?? 50 ),
                    'y'     => (int) ( $tip_config['pos_back']['y']     ?? 25 ),
                    'width' => (int) ( $tip_config['pos_back']['width'] ?? 50 ),
                ];
            }

            $product_id = Thready_Variation_Factory::create_product( [
                'name'           => $product_name,
                'tip_slug'       => $tip_slug,
                'boja_slugs'     => $boja_slugs,
                'velicina_slugs' => $velicina_slugs,
                'regular_price'  => $regular_price,
                'sale_price'     => $sale_price,
                'print_front_id' => $print_front_id,
                'print_back_id'  => $print_back_id ?: null,
                'position_front' => $pos_front,
                'position_back'  => $pos_back,
            ] );

            if ( is_wp_error( $product_id ) ) {
                $had_errors = true;
                $results[]  = [
                    'tip_slug' => $tip_slug,
                    'tip_name' => $tip_name,
                    'success'  => false,
                    'message'  => $product_id->get_error_message(),
                ];
            } else {
                $variation_count = count( get_posts( [
                    'post_type'   => 'product_variation',
                    'post_parent' => $product_id,
                    'post_status' => 'publish',
                    'numberposts' => -1,
                    'fields'      => 'ids',
                ] ) );

                $results[] = [
                    'tip_slug'        => $tip_slug,
                    'tip_name'        => $tip_name,
                    'success'         => true,
                    'product_id'      => $product_id,
                    'variation_count' => $variation_count,
                    'edit_url'        => get_edit_post_link( $product_id, 'raw' ),
                    'view_url'        => get_permalink( $product_id ),
                ];
            }
        }

        if ( $had_errors && count( array_filter( $results, fn( $r ) => ! $r['success'] ) ) === count( $results ) ) {
            wp_send_json_error( [ 'message' => __( 'All product creations failed.', 'thready-product-customizer' ), 'results' => $results ] );
        }

        wp_send_json_success( [ 'results' => $results ] );
    }

    // -------------------------------------------------------------------------
    // AJAX — upload print image
    // -------------------------------------------------------------------------

    public static function ajax_upload_print() {
        check_ajax_referer( 'thready_wizard_nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => __( 'Unauthorized', 'thready-product-customizer' ) ], 403 );
        }

        if ( empty( $_FILES['file'] ) || $_FILES['file']['error'] !== UPLOAD_ERR_OK ) {
            wp_send_json_error( [ 'message' => __( 'Upload failed.', 'thready-product-customizer' ) ] );
        }

        // Only allow PNG (print designs must be transparent PNGs)
        $finfo = finfo_open( FILEINFO_MIME_TYPE );
        $mime  = finfo_file( $finfo, $_FILES['file']['tmp_name'] );
        finfo_close( $finfo );

        if ( $mime !== 'image/png' ) {
            wp_send_json_error( [ 'message' => __( 'Print images must be PNG files with a transparent background.', 'thready-product-customizer' ) ] );
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

    // -------------------------------------------------------------------------
    // AJAX — get mockup preview URL
    // -------------------------------------------------------------------------

    public static function ajax_mockup_preview() {
        check_ajax_referer( 'thready_wizard_nonce' );

        $tip_slug  = sanitize_key( $_POST['tip_slug']  ?? '' );
        $boja_slug = sanitize_key( $_POST['boja_slug'] ?? '' );

        if ( ! $tip_slug || ! $boja_slug ) {
            wp_send_json_error( [ 'message' => 'Missing params' ] );
        }

        $urls = Thready_Mockup_Library::get_urls( $tip_slug, $boja_slug );
        if ( ! $urls ) {
            wp_send_json_error( [ 'message' => 'No mockup found' ] );
        }

        wp_send_json_success( $urls );
    }
}
