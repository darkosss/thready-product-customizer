<?php
/**
 * Thready Mockup Library
 *
 * Manages blank product base images organised by pa_tip × pa_boja.
 * Data lives in a custom DB table for O(1) key lookups at runtime.
 *
 * Table: {prefix}thready_mockups
 *   id            bigint PK
 *   tip_slug      varchar(100)   — pa_tip term slug
 *   boja_slug     varchar(100)   — pa_boja term slug
 *   front_image   bigint|NULL    — WP attachment ID
 *   back_image    bigint|NULL    — WP attachment ID
 *   created_at    datetime
 *   updated_at    datetime
 */

defined( 'ABSPATH' ) || exit;

class Thready_Mockup_Library {

    const TABLE_SUFFIX = 'thready_mockups';
    const DB_VERSION   = '1.0';
    const DB_KEY       = 'thready_mockup_db_version';

    // -------------------------------------------------------------------------
    // Boot
    // -------------------------------------------------------------------------

    public static function init() {
        self::maybe_upgrade_table();

        add_action( 'admin_menu',            [ __CLASS__, 'add_admin_page'   ] );
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_scripts'  ] );

        // AJAX — authenticated users only (manage_woocommerce capability checked inside)
        add_action( 'wp_ajax_thready_save_mockup',   [ __CLASS__, 'ajax_save_mockup'   ] );
        add_action( 'wp_ajax_thready_remove_mockup', [ __CLASS__, 'ajax_remove_mockup' ] );
    }

    // -------------------------------------------------------------------------
    // Database
    // -------------------------------------------------------------------------

    public static function create_table() {
        global $wpdb;

        $table   = $wpdb->prefix . self::TABLE_SUFFIX;
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id          bigint(20)    NOT NULL AUTO_INCREMENT,
            tip_slug    varchar(100)  NOT NULL,
            boja_slug   varchar(100)  NOT NULL,
            front_image bigint(20)    DEFAULT NULL,
            back_image  bigint(20)    DEFAULT NULL,
            created_at  datetime      NOT NULL,
            updated_at  datetime      NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY   tip_boja  (tip_slug, boja_slug),
            KEY          idx_tip   (tip_slug)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        update_option( self::DB_KEY, self::DB_VERSION );
    }

    public static function maybe_upgrade_table() {
        if ( get_option( self::DB_KEY ) !== self::DB_VERSION ) {
            self::create_table();
        }
    }

    // -------------------------------------------------------------------------
    // CRUD helpers
    // -------------------------------------------------------------------------

    private static function table() {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_SUFFIX;
    }

    /**
     * Fetch a single mockup row.
     */
    public static function get( $tip_slug, $boja_slug ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            'SELECT * FROM ' . self::table() . ' WHERE tip_slug = %s AND boja_slug = %s',
            $tip_slug,
            $boja_slug
        ) );
    }

    /**
     * Fetch all mockups for a pa_tip, indexed by boja_slug.
     *
     * @return array<string, object>
     */
    public static function get_for_tip( $tip_slug ) {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            'SELECT * FROM ' . self::table() . ' WHERE tip_slug = %s',
            $tip_slug
        ) );

        $map = [];
        foreach ( $rows as $row ) {
            $map[ $row->boja_slug ] = $row;
        }
        return $map;
    }

    /**
     * Full mockup map used by the canvas system — keyed "tip|boja".
     * Returns image URLs, not IDs.
     *
     * @return array<string, array{front:string|null, back:string|null}>
     */
    public static function get_canvas_map() {
        global $wpdb;
        $rows = $wpdb->get_results( 'SELECT * FROM ' . self::table() );

        $map = [];
        foreach ( $rows as $row ) {
            $key         = $row->tip_slug . '|' . $row->boja_slug;
            $map[ $key ] = [
                'front' => $row->front_image ? wp_get_attachment_url( (int) $row->front_image ) : null,
                'back'  => $row->back_image  ? wp_get_attachment_url( (int) $row->back_image  ) : null,
            ];
        }
        return $map;
    }

    /**
     * Image URLs for one tip/boja pair — used by canvas at runtime.
     *
     * @return array{front:string|null, back:string|null}|null  null if no record
     */
    public static function get_urls( $tip_slug, $boja_slug ) {
        $row = self::get( $tip_slug, $boja_slug );
        if ( ! $row ) return null;

        return [
            'front' => $row->front_image ? wp_get_attachment_url( (int) $row->front_image ) : null,
            'back'  => $row->back_image  ? wp_get_attachment_url( (int) $row->back_image  ) : null,
        ];
    }

    /**
     * Upsert a mockup record.
     *
     * Pass NULL to leave a field unchanged, 0 to clear it.
     */
    public static function save( $tip_slug, $boja_slug, $front_image = null, $back_image = null ) {
        global $wpdb;

        $now      = current_time( 'mysql' );
        $existing = self::get( $tip_slug, $boja_slug );

        if ( $existing ) {
            $data = [ 'updated_at' => $now ];

            if ( $front_image !== null ) {
                $data['front_image'] = $front_image > 0 ? $front_image : null;
            }
            if ( $back_image !== null ) {
                $data['back_image'] = $back_image > 0 ? $back_image : null;
            }

            $wpdb->update(
                self::table(),
                $data,
                [ 'tip_slug' => $tip_slug, 'boja_slug' => $boja_slug ]
            );

            return (int) $existing->id;
        }

        $wpdb->insert( self::table(), [
            'tip_slug'    => $tip_slug,
            'boja_slug'   => $boja_slug,
            'front_image' => $front_image > 0 ? $front_image : null,
            'back_image'  => $back_image  > 0 ? $back_image  : null,
            'created_at'  => $now,
            'updated_at'  => $now,
        ] );

        return (int) $wpdb->insert_id;
    }

    // -------------------------------------------------------------------------
    // Status helper
    // -------------------------------------------------------------------------

    /**
     * Returns 'complete' | 'front-only' | 'empty'
     */
    private static function card_status( $front_id, $back_id ) {
        if ( $front_id && $back_id ) return 'complete';
        if ( $front_id )             return 'front-only';
        return 'empty';
    }

    // -------------------------------------------------------------------------
    // Admin page
    // -------------------------------------------------------------------------

    public static function add_admin_page() {
        add_submenu_page(
            'woocommerce',
            __( 'Mockup Library', 'thready-product-customizer' ),
            __( 'Mockup Library', 'thready-product-customizer' ),
            'manage_woocommerce',
            'thready-mockup-library',
            [ __CLASS__, 'render_page' ]
        );
    }

    public static function enqueue_scripts( $hook ) {
        if ( 'woocommerce_page_thready-mockup-library' !== $hook ) return;

        wp_enqueue_media();

        wp_enqueue_style(
            'thready-mockup-library',
            THREADY_PC_URL . 'assets/css/mockup-library.css',
            [],
            THREADY_PC_VERSION
        );

        wp_enqueue_script(
            'thready-mockup-library',
            THREADY_PC_URL . 'assets/js/mockup-library.js',
            [ 'jquery' ],
            THREADY_PC_VERSION,
            true
        );

        wp_localize_script( 'thready-mockup-library', 'threadyMockup', [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'thready_mockup_nonce' ),
            'i18n'     => [
                'select_front'   => __( 'Select Front Base Image', 'thready-product-customizer' ),
                'select_back'    => __( 'Select Back Base Image',  'thready-product-customizer' ),
                'use_image'      => __( 'Use this image',          'thready-product-customizer' ),
                'saving'         => __( 'Saving…',                 'thready-product-customizer' ),
                'saved'          => __( 'Saved ✓',                 'thready-product-customizer' ),
                'save_error'     => __( 'Save failed',             'thready-product-customizer' ),
                'confirm_remove' => __( 'Remove this image?',      'thready-product-customizer' ),
            ],
        ] );
    }

    // -------------------------------------------------------------------------
    // Page render
    // -------------------------------------------------------------------------

    public static function render_page() {
        $tip_terms = get_terms( [ 'taxonomy' => 'pa_tip',  'hide_empty' => false, 'orderby' => 'name' ] );
        $boja_terms = get_terms( [ 'taxonomy' => 'pa_boja', 'hide_empty' => false, 'orderby' => 'name' ] );

        if ( is_wp_error( $tip_terms ) || empty( $tip_terms ) ) {
            self::notice( 'warning', __( 'No product types (pa_tip) found. Add product type attributes in WooCommerce → Attributes first.', 'thready-product-customizer' ) );
            return;
        }

        if ( is_wp_error( $boja_terms ) || empty( $boja_terms ) ) {
            self::notice( 'warning', __( 'No colors (pa_boja) found. Add color attributes in WooCommerce → Attributes first.', 'thready-product-customizer' ) );
            return;
        }

        // Resolve active tab
        $active_tip = isset( $_GET['tip'] ) ? sanitize_key( $_GET['tip'] ) : $tip_terms[0]->slug;

        // Validate — fall back to first if slug not in list
        $valid_slugs = wp_list_pluck( $tip_terms, 'slug' );
        if ( ! in_array( $active_tip, $valid_slugs, true ) ) {
            $active_tip = $tip_terms[0]->slug;
        }

        // Load mockups for active tip only (lazy per-tab)
        $mockups = self::get_for_tip( $active_tip );

        // Tab completion counts (one extra query per tab — small number of tabs expected)
        $total_colors = count( $boja_terms );
        ?>
        <div class="wrap thready-mockup-wrap">
            <div class="thready-page-header">
                <h1><?php esc_html_e( 'Mockup Library', 'thready-product-customizer' ); ?></h1>
                <p class="thready-page-subtitle">
                    <?php esc_html_e( 'Upload front & back base images for each product type × color combination. These serve as the canvas base layer — no regeneration needed when you change a design.', 'thready-product-customizer' ); ?>
                </p>
            </div>

            <?php /* ── Tab bar ─────────────────────────────────────────── */ ?>
            <nav class="thready-tab-bar" role="tablist">
                <?php foreach ( $tip_terms as $tip ) :
                    $tip_mockups = self::get_for_tip( $tip->slug );
                    $done = 0;
                    foreach ( $boja_terms as $boja ) {
                        $m = $tip_mockups[ $boja->slug ] ?? null;
                        if ( $m && $m->front_image ) $done++;
                    }
                    $pct      = $total_colors ? round( $done / $total_colors * 100 ) : 0;
                    $tab_cls  = ( $tip->slug === $active_tip ) ? 'thready-tab active' : 'thready-tab';
                    $pill_cls = $done === $total_colors ? 'pill-done' : ( $done > 0 ? 'pill-partial' : 'pill-empty' );
                    $tab_url  = add_query_arg( [ 'page' => 'thready-mockup-library', 'tip' => $tip->slug ], admin_url( 'admin.php' ) );
                ?>
                    <a href="<?php echo esc_url( $tab_url ); ?>"
                       class="<?php echo esc_attr( $tab_cls ); ?>"
                       role="tab"
                       aria-selected="<?php echo $tip->slug === $active_tip ? 'true' : 'false'; ?>">
                        <span class="tab-name"><?php echo esc_html( $tip->name ); ?></span>
                        <span class="tab-pill <?php echo esc_attr( $pill_cls ); ?>"
                              title="<?php echo esc_attr( sprintf( '%d / %d colors set', $done, $total_colors ) ); ?>">
                            <?php echo esc_html( $done . '/' . $total_colors ); ?>
                        </span>
                        <?php if ( $done > 0 && $done < $total_colors ) : ?>
                            <span class="tab-progress-bar">
                                <span class="tab-progress-fill" style="width:<?php echo esc_attr( $pct ); ?>%"></span>
                            </span>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <?php /* ── Color grid ──────────────────────────────────────── */ ?>
            <div class="thready-color-grid" data-tip="<?php echo esc_attr( $active_tip ); ?>">
                <?php foreach ( $boja_terms as $boja ) :
                    $hex      = get_term_meta( $boja->term_id, 'product_attribute_color', true );
                    $row      = $mockups[ $boja->slug ] ?? null;
                    $front_id = $row ? (int) $row->front_image : 0;
                    $back_id  = $row ? (int) $row->back_image  : 0;
                    $status   = self::card_status( $front_id, $back_id );

                    $front_thumb = $front_id ? wp_get_attachment_image_url( $front_id, 'thumbnail' ) : '';
                    $back_thumb  = $back_id  ? wp_get_attachment_image_url( $back_id,  'thumbnail' ) : '';
                ?>
                    <div class="thready-color-card status-<?php echo esc_attr( $status ); ?>"
                         data-tip="<?php echo esc_attr( $active_tip ); ?>"
                         data-boja="<?php echo esc_attr( $boja->slug ); ?>">

                        <?php /* Card header */ ?>
                        <div class="card-header">
                            <span class="color-swatch<?php echo $hex ? '' : ' swatch-empty'; ?>"
                                  style="<?php echo $hex ? 'background:' . esc_attr( $hex ) . ';' : ''; ?>"></span>
                            <span class="color-name"><?php echo esc_html( $boja->name ); ?></span>
                            <span class="status-badge status-<?php echo esc_attr( $status ); ?>"
                                  title="<?php
                                      echo esc_attr(
                                          $status === 'complete'   ? __( 'Front & back set',          'thready-product-customizer' ) :
                                        ( $status === 'front-only' ? __( 'Front set — back missing',  'thready-product-customizer' ) :
                                                                     __( 'No images',                 'thready-product-customizer' ) )
                                      );
                                  ?>">
                                <?php echo $status === 'complete' ? '✓' : ( $status === 'front-only' ? '½' : '–' ); ?>
                            </span>
                        </div>

                        <?php /* Image slots */ ?>
                        <div class="card-slots">

                            <?php foreach ( [ 'front' => $front_id, 'back' => $back_id ] as $slot => $img_id ) :
                                $thumb = $slot === 'front' ? $front_thumb : $back_thumb;
                            ?>
                                <div class="image-slot slot-<?php echo esc_attr( $slot ); ?>"
                                     data-slot="<?php echo esc_attr( $slot ); ?>">

                                    <div class="slot-label"><?php echo $slot === 'front' ? esc_html__( 'Front', 'thready-product-customizer' ) : esc_html__( 'Back', 'thready-product-customizer' ); ?></div>

                                    <div class="slot-preview <?php echo $img_id ? 'has-image' : 'empty'; ?>">
                                        <?php if ( $thumb ) : ?>
                                            <img src="<?php echo esc_url( $thumb ); ?>" alt="" loading="lazy">
                                        <?php else : ?>
                                            <span class="slot-icon dashicons dashicons-format-image"></span>
                                        <?php endif; ?>
                                        <?php if ( $img_id ) : ?>
                                            <button type="button"
                                                    class="slot-remove"
                                                    aria-label="<?php esc_attr_e( 'Remove image', 'thready-product-customizer' ); ?>">
                                                ✕
                                            </button>
                                        <?php endif; ?>
                                    </div>

                                    <input type="hidden" class="slot-image-id" value="<?php echo esc_attr( $img_id ); ?>">

                                    <button type="button" class="button slot-upload-btn">
                                        <?php echo $img_id
                                            ? esc_html__( 'Change', 'thready-product-customizer' )
                                            : esc_html__( 'Upload', 'thready-product-customizer' ); ?>
                                    </button>

                                    <span class="slot-saving-indicator" aria-live="polite"></span>
                                </div>
                            <?php endforeach; ?>

                        </div><!-- .card-slots -->
                    </div><!-- .thready-color-card -->
                <?php endforeach; ?>
            </div><!-- .thready-color-grid -->

        </div><!-- .thready-mockup-wrap -->
        <?php
    }

    // -------------------------------------------------------------------------
    // AJAX handlers
    // -------------------------------------------------------------------------

    public static function ajax_save_mockup() {
        check_ajax_referer( 'thready_mockup_nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => __( 'Unauthorized', 'thready-product-customizer' ) ], 403 );
        }

        $tip_slug  = sanitize_key( wp_unslash( $_POST['tip_slug']  ?? '' ) );
        $boja_slug = sanitize_key( wp_unslash( $_POST['boja_slug'] ?? '' ) );
        $slot      = sanitize_key( wp_unslash( $_POST['slot']      ?? '' ) );
        $image_id  = absint( $_POST['image_id'] ?? 0 );

        if ( ! $tip_slug || ! $boja_slug || ! in_array( $slot, [ 'front', 'back' ], true ) ) {
            wp_send_json_error( [ 'message' => __( 'Invalid parameters', 'thready-product-customizer' ) ] );
        }

        if ( $image_id && ! wp_attachment_is_image( $image_id ) ) {
            wp_send_json_error( [ 'message' => __( 'Invalid image', 'thready-product-customizer' ) ] );
        }

        if ( $slot === 'front' ) {
            self::save( $tip_slug, $boja_slug, $image_id, null );
        } else {
            self::save( $tip_slug, $boja_slug, null, $image_id );
        }

        $row      = self::get( $tip_slug, $boja_slug );
        $front_id = $row ? (int) $row->front_image : 0;
        $back_id  = $row ? (int) $row->back_image  : 0;

        wp_send_json_success( [
            'status'    => self::card_status( $front_id, $back_id ),
            'thumb_url' => $image_id ? wp_get_attachment_image_url( $image_id, 'thumbnail' ) : '',
            'image_id'  => $image_id,
        ] );
    }

    public static function ajax_remove_mockup() {
        check_ajax_referer( 'thready_mockup_nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => __( 'Unauthorized', 'thready-product-customizer' ) ], 403 );
        }

        $tip_slug  = sanitize_key( wp_unslash( $_POST['tip_slug']  ?? '' ) );
        $boja_slug = sanitize_key( wp_unslash( $_POST['boja_slug'] ?? '' ) );
        $slot      = sanitize_key( wp_unslash( $_POST['slot']      ?? '' ) );

        if ( ! $tip_slug || ! $boja_slug || ! in_array( $slot, [ 'front', 'back' ], true ) ) {
            wp_send_json_error( [ 'message' => __( 'Invalid parameters', 'thready-product-customizer' ) ] );
        }

        if ( $slot === 'front' ) {
            self::save( $tip_slug, $boja_slug, 0, null );
        } else {
            self::save( $tip_slug, $boja_slug, null, 0 );
        }

        $row      = self::get( $tip_slug, $boja_slug );
        $front_id = $row ? (int) $row->front_image : 0;
        $back_id  = $row ? (int) $row->back_image  : 0;

        wp_send_json_success( [
            'status' => self::card_status( $front_id, $back_id ),
        ] );
    }

    // -------------------------------------------------------------------------
    // Utility
    // -------------------------------------------------------------------------

    private static function notice( $type, $message ) {
        echo '<div class="wrap"><h1>' . esc_html__( 'Mockup Library', 'thready-product-customizer' ) . '</h1>';
        echo '<div class="notice notice-' . esc_attr( $type ) . '"><p>' . esc_html( $message ) . '</p></div></div>';
    }
}
