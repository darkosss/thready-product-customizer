<?php
/**
 * Thready Mockup Library
 *
 * Manages blank product base images organised by pa_tip-proizvoda × pa_boja.
 * Stored in a custom DB table. Supports auto-import from named files.
 *
 * Naming convention for auto-import:
 *   /wp-content/uploads/thready-mockups/{tip-slug}-{boja-slug}-front.webp
 *   /wp-content/uploads/thready-mockups/{tip-slug}-{boja-slug}-back.webp
 *
 * WebP is the preferred format. JPG/PNG also accepted.
 */

defined( 'ABSPATH' ) || exit;

class Thready_Mockup_Library {

    const TABLE_SUFFIX = 'thready_mockups';
    const DB_VERSION   = '1.0';
    const DB_KEY       = 'thready_mockup_db_version';
    const SCAN_DIR     = 'thready-mockups';

    public static function init() {
        self::maybe_upgrade_table();
        add_action( 'admin_menu',            [ __CLASS__, 'add_admin_page'   ] );
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_scripts'  ] );
        add_action( 'wp_ajax_thready_save_mockup',   [ __CLASS__, 'ajax_save_mockup'   ] );
        add_action( 'wp_ajax_thready_remove_mockup', [ __CLASS__, 'ajax_remove_mockup' ] );
        add_action( 'wp_ajax_thready_scan_mockups',  [ __CLASS__, 'ajax_scan_mockups'  ] );
    }

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

    private static function table() {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_SUFFIX;
    }

    public static function get( $tip_slug, $boja_slug ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            'SELECT * FROM ' . self::table() . ' WHERE tip_slug = %s AND boja_slug = %s',
            $tip_slug, $boja_slug
        ) );
    }

    public static function get_for_tip( $tip_slug ) {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            'SELECT * FROM ' . self::table() . ' WHERE tip_slug = %s', $tip_slug
        ) );
        $map = [];
        foreach ( $rows as $row ) $map[ $row->boja_slug ] = $row;
        return $map;
    }

    public static function get_canvas_map() {
        global $wpdb;
        $rows = $wpdb->get_results( 'SELECT * FROM ' . self::table() );
        $map  = [];
        foreach ( $rows as $row ) {
            $map[ $row->tip_slug . '|' . $row->boja_slug ] = [
                'front' => $row->front_image ? wp_get_attachment_url( (int) $row->front_image ) : null,
                'back'  => $row->back_image  ? wp_get_attachment_url( (int) $row->back_image  ) : null,
            ];
        }
        return $map;
    }

    public static function get_urls( $tip_slug, $boja_slug ) {
        $row = self::get( $tip_slug, $boja_slug );
        if ( ! $row ) return null;
        return [
            'front' => $row->front_image ? wp_get_attachment_url( (int) $row->front_image ) : null,
            'back'  => $row->back_image  ? wp_get_attachment_url( (int) $row->back_image  ) : null,
        ];
    }

    public static function save( $tip_slug, $boja_slug, $front_image = null, $back_image = null ) {
        global $wpdb;
        $now      = current_time( 'mysql' );
        $existing = self::get( $tip_slug, $boja_slug );

        if ( $existing ) {
            $data = [ 'updated_at' => $now ];
            if ( $front_image !== null ) $data['front_image'] = $front_image > 0 ? $front_image : null;
            if ( $back_image  !== null ) $data['back_image']  = $back_image  > 0 ? $back_image  : null;
            $wpdb->update( self::table(), $data, [ 'tip_slug' => $tip_slug, 'boja_slug' => $boja_slug ] );
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
    // Auto-scan & import
    // -------------------------------------------------------------------------

    public static function scan_and_import() {
        $upload_dir = wp_upload_dir();
        $scan_path  = $upload_dir['basedir'] . '/' . self::SCAN_DIR;

        if ( ! file_exists( $scan_path ) ) {
            wp_mkdir_p( $scan_path );
        }

        $tip_terms  = get_terms( [ 'taxonomy' => THREADY_TAX_TIP,  'hide_empty' => false ] );
        $boja_terms = get_terms( [ 'taxonomy' => THREADY_TAX_BOJA, 'hide_empty' => false ] );

        $valid_tips  = [];
        $valid_bojas = [];
        if ( ! is_wp_error( $tip_terms ) )  foreach ( $tip_terms  as $t ) $valid_tips[ $t->slug ]  = $t;
        if ( ! is_wp_error( $boja_terms ) ) foreach ( $boja_terms as $t ) $valid_bojas[ $t->slug ] = $t;

        // WebP preferred — also accept jpg/png as fallback
        $allowed_ext = [ 'webp', 'jpg', 'jpeg', 'png' ];

        $files    = glob( $scan_path . '/*.*' ) ?: [];
        $imported = 0;
        $skipped  = 0;
        $errors   = [];

        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        foreach ( $files as $filepath ) {
            $filename = basename( $filepath );
            $ext      = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );

            if ( ! in_array( $ext, $allowed_ext, true ) ) continue;

            $parsed = self::parse_filename(
                $filename,
                array_keys( $valid_tips ),
                array_keys( $valid_bojas )
            );

            if ( ! $parsed ) {
                $errors[] = "Could not match: $filename";
                continue;
            }

            [ $tip_slug, $boja_slug, $side ] = $parsed;

            $attachment_id = self::find_attachment_by_file( $filepath );

            if ( ! $attachment_id ) {
                $attachment_id = self::create_attachment( $filepath );
                if ( is_wp_error( $attachment_id ) ) {
                    $errors[] = "Failed to import $filename: " . $attachment_id->get_error_message();
                    continue;
                }
            } else {
                $skipped++;
            }

            if ( $side === 'front' ) {
                self::save( $tip_slug, $boja_slug, $attachment_id, null );
            } else {
                self::save( $tip_slug, $boja_slug, null, $attachment_id );
            }

            $imported++;
        }

        return [ 'imported' => $imported, 'skipped' => $skipped, 'errors' => $errors ];
    }

    /**
     * Parse "{tip-slug}-{boja-slug}-{front|back}.{ext}" into [tip, boja, side].
     * Sorts tip slugs by length descending so "oversized-duks" matches before "duks".
     */
    private static function parse_filename( $filename, array $tip_slugs, array $boja_slugs ) {
        $name = pathinfo( $filename, PATHINFO_FILENAME );

        foreach ( [ 'front', 'back' ] as $side ) {
            $suffix = '-' . $side;
            if ( substr( $name, -strlen( $suffix ) ) !== $suffix ) continue;

            $base = substr( $name, 0, -strlen( $suffix ) );

            // Longer slugs first to avoid partial matches
            usort( $tip_slugs, fn( $a, $b ) => strlen( $b ) - strlen( $a ) );

            foreach ( $tip_slugs as $tip ) {
                $prefix = $tip . '-';
                if ( strpos( $base, $prefix ) === 0 ) {
                    $boja = substr( $base, strlen( $prefix ) );
                    if ( in_array( $boja, $boja_slugs, true ) ) {
                        return [ $tip, $boja, $side ];
                    }
                }
            }
        }

        return null;
    }

    private static function find_attachment_by_file( $filepath ) {
        global $wpdb;
        $upload_dir = wp_upload_dir();
        $relative   = str_replace( trailingslashit( $upload_dir['basedir'] ), '', $filepath );

        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta}
             WHERE meta_key = '_wp_attached_file' AND meta_value = %s LIMIT 1",
            $relative
        ) );
    }

    private static function create_attachment( $filepath ) {
        $filetype = wp_check_filetype( basename( $filepath ), null );

        if ( empty( $filetype['type'] ) ) {
            return new WP_Error( 'invalid_type', 'Unsupported file type: ' . basename( $filepath ) );
        }

        $attachment = [
            'post_mime_type' => $filetype['type'],
            'post_title'     => sanitize_file_name( pathinfo( $filepath, PATHINFO_FILENAME ) ),
            'post_content'   => '',
            'post_status'    => 'inherit',
        ];

        $attach_id = wp_insert_attachment( $attachment, $filepath );
        if ( is_wp_error( $attach_id ) ) return $attach_id;

        $metadata = wp_generate_attachment_metadata( $attach_id, $filepath );
        wp_update_attachment_metadata( $attach_id, $metadata );

        return $attach_id;
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
        wp_enqueue_style( 'thready-mockup-library', THREADY_PC_URL . 'assets/css/mockup-library.css', [], THREADY_PC_VERSION );
        wp_enqueue_script( 'thready-mockup-library', THREADY_PC_URL . 'assets/js/mockup-library.js', [ 'jquery' ], THREADY_PC_VERSION, true );

        $upload_dir = wp_upload_dir();
        wp_localize_script( 'thready-mockup-library', 'threadyMockup', [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'thready_mockup_nonce' ),
            'scan_dir' => $upload_dir['baseurl'] . '/' . self::SCAN_DIR . '/',
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

    public static function render_page() {
        $tip_terms  = get_terms( [ 'taxonomy' => THREADY_TAX_TIP,  'hide_empty' => false, 'orderby' => 'name' ] );
        $boja_terms = get_terms( [ 'taxonomy' => THREADY_TAX_BOJA, 'hide_empty' => false, 'orderby' => 'name' ] );

        if ( is_wp_error( $tip_terms ) || empty( $tip_terms ) ) {
            self::notice( 'warning', 'No product types found. Add pa_tip-proizvoda attributes first.' );
            return;
        }
        if ( is_wp_error( $boja_terms ) || empty( $boja_terms ) ) {
            self::notice( 'warning', 'No colors found. Add pa_boja attributes first.' );
            return;
        }

        $active_tip  = isset( $_GET['tip'] ) ? sanitize_key( $_GET['tip'] ) : $tip_terms[0]->slug;
        $valid_slugs = wp_list_pluck( $tip_terms, 'slug' );
        if ( ! in_array( $active_tip, $valid_slugs, true ) ) $active_tip = $tip_terms[0]->slug;

        $mockups      = self::get_for_tip( $active_tip );
        $total_colors = count( $boja_terms );
        $upload_dir   = wp_upload_dir();
        $scan_path    = $upload_dir['basedir'] . '/' . self::SCAN_DIR;
        ?>
        <div class="wrap thready-mockup-wrap">

            <div class="thready-page-header">
                <h1><?php esc_html_e( 'Mockup Library', 'thready-product-customizer' ); ?></h1>
                <p class="thready-page-subtitle">
                    <?php esc_html_e( 'Upload front & back base images for each product type × color. For bulk import, drop WebP files into:', 'thready-product-customizer' ); ?>
                    <code><?php echo esc_html( $scan_path ); ?></code>
                </p>
                <p class="thready-page-subtitle">
                    <?php esc_html_e( 'Naming format:', 'thready-product-customizer' ); ?>
                    <code>{tip-slug}-{boja-slug}-front.webp</code> /
                    <code>{tip-slug}-{boja-slug}-back.webp</code>
                    — <?php esc_html_e( 'e.g.', 'thready-product-customizer' ); ?>
                    <code>duks-crna-front.webp</code>,
                    <code>oversized-majica-srebrno-siva-back.webp</code>
                </p>
                <div class="thready-scan-bar">
                    <button type="button" class="button button-primary" id="thready-scan-btn">
                        <?php esc_html_e( 'Scan & Import', 'thready-product-customizer' ); ?>
                    </button>
                    <span id="thready-scan-result"></span>
                </div>
            </div>

            <nav class="thready-tab-bar" role="tablist">
                <?php foreach ( $tip_terms as $tip ) :
                    $tip_mockups = self::get_for_tip( $tip->slug );
                    $done = 0;
                    foreach ( $boja_terms as $boja ) {
                        $m = $tip_mockups[ $boja->slug ] ?? null;
                        if ( $m && $m->front_image ) $done++;
                    }
                    $pct     = $total_colors ? round( $done / $total_colors * 100 ) : 0;
                    $active  = $tip->slug === $active_tip;
                    $pill    = $done === $total_colors ? 'pill-done' : ( $done > 0 ? 'pill-partial' : 'pill-empty' );
                    $tab_url = add_query_arg( [ 'page' => 'thready-mockup-library', 'tip' => $tip->slug ], admin_url( 'admin.php' ) );
                ?>
                    <a href="<?php echo esc_url( $tab_url ); ?>"
                       class="thready-tab <?php echo $active ? 'active' : ''; ?>"
                       role="tab"
                       aria-selected="<?php echo $active ? 'true' : 'false'; ?>">
                        <span class="tab-name"><?php echo esc_html( $tip->name ); ?></span>
                        <span class="tab-pill <?php echo esc_attr( $pill ); ?>">
                            <?php echo esc_html( "$done/$total_colors" ); ?>
                        </span>
                        <?php if ( $done > 0 && $done < $total_colors ) : ?>
                            <span class="tab-progress-bar">
                                <span class="tab-progress-fill" style="width:<?php echo esc_attr( $pct ); ?>%"></span>
                            </span>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <div class="thready-color-grid" data-tip="<?php echo esc_attr( $active_tip ); ?>">
                <?php foreach ( $boja_terms as $boja ) :
                    $hex         = get_term_meta( $boja->term_id, 'product_attribute_color', true );
                    $row         = $mockups[ $boja->slug ] ?? null;
                    $front_id    = $row ? (int) $row->front_image : 0;
                    $back_id     = $row ? (int) $row->back_image  : 0;
                    $status      = $front_id && $back_id ? 'complete' : ( $front_id ? 'front-only' : 'empty' );
                    $front_thumb = $front_id ? wp_get_attachment_image_url( $front_id, 'thumbnail' ) : '';
                    $back_thumb  = $back_id  ? wp_get_attachment_image_url( $back_id,  'thumbnail' ) : '';
                ?>
                    <div class="thready-color-card status-<?php echo esc_attr( $status ); ?>"
                         data-tip="<?php echo esc_attr( $active_tip ); ?>"
                         data-boja="<?php echo esc_attr( $boja->slug ); ?>">

                        <div class="card-header">
                            <span class="color-swatch<?php echo $hex ? '' : ' swatch-empty'; ?>"
                                  style="<?php echo $hex ? 'background:' . esc_attr( $hex ) . ';' : ''; ?>"></span>
                            <span class="color-name"><?php echo esc_html( $boja->name ); ?></span>
                            <span class="status-badge status-<?php echo esc_attr( $status ); ?>"
                                  title="<?php echo esc_attr(
                                      $status === 'complete'   ? 'Front & back set' :
                                    ( $status === 'front-only' ? 'Front set — back missing' : 'No images' )
                                  ); ?>">
                                <?php echo $status === 'complete' ? '✓' : ( $status === 'front-only' ? '½' : '–' ); ?>
                            </span>
                        </div>

                        <div class="card-slots">
                            <?php foreach ( [ 'front' => $front_id, 'back' => $back_id ] as $slot => $img_id ) :
                                $thumb = $slot === 'front' ? $front_thumb : $back_thumb;
                            ?>
                                <div class="image-slot slot-<?php echo esc_attr( $slot ); ?>" data-slot="<?php echo esc_attr( $slot ); ?>">
                                    <div class="slot-label"><?php echo $slot === 'front' ? 'Front' : 'Back'; ?></div>
                                    <div class="slot-preview <?php echo $img_id ? 'has-image' : 'empty'; ?>">
                                        <?php if ( $thumb ) : ?>
                                            <img src="<?php echo esc_url( $thumb ); ?>" alt="" loading="lazy">
                                            <button type="button" class="slot-remove" aria-label="Remove">✕</button>
                                        <?php else : ?>
                                            <span class="slot-icon dashicons dashicons-format-image"></span>
                                        <?php endif; ?>
                                    </div>
                                    <input type="hidden" class="slot-image-id" value="<?php echo esc_attr( $img_id ); ?>">
                                    <button type="button" class="button slot-upload-btn">
                                        <?php echo $img_id ? 'Change' : 'Upload'; ?>
                                    </button>
                                    <span class="slot-saving-indicator" aria-live="polite"></span>
                                </div>
                            <?php endforeach; ?>
                        </div>

                    </div>
                <?php endforeach; ?>
            </div>

        </div>
        <?php
    }

    // -------------------------------------------------------------------------
    // AJAX
    // -------------------------------------------------------------------------

    public static function ajax_save_mockup() {
        check_ajax_referer( 'thready_mockup_nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_send_json_error( [], 403 );

        $tip_slug  = sanitize_key( wp_unslash( $_POST['tip_slug']  ?? '' ) );
        $boja_slug = sanitize_key( wp_unslash( $_POST['boja_slug'] ?? '' ) );
        $slot      = sanitize_key( wp_unslash( $_POST['slot']      ?? '' ) );
        $image_id  = absint( $_POST['image_id'] ?? 0 );

        if ( ! $tip_slug || ! $boja_slug || ! in_array( $slot, [ 'front', 'back' ], true ) )
            wp_send_json_error( [ 'message' => 'Invalid parameters' ] );

        if ( $image_id && ! wp_attachment_is_image( $image_id ) )
            wp_send_json_error( [ 'message' => 'Invalid image' ] );

        // Auto-rename the attachment to the correct mockup convention
        if ( $image_id ) {
            self::auto_rename_attachment( $image_id, $tip_slug, $boja_slug, $slot );
        }

        if ( $slot === 'front' ) self::save( $tip_slug, $boja_slug, $image_id, null );
        else                     self::save( $tip_slug, $boja_slug, null, $image_id );

        $row      = self::get( $tip_slug, $boja_slug );
        $front_id = $row ? (int) $row->front_image : 0;
        $back_id  = $row ? (int) $row->back_image  : 0;
        $status   = $front_id && $back_id ? 'complete' : ( $front_id ? 'front-only' : 'empty' );

        wp_send_json_success( [
            'status'    => $status,
            'thumb_url' => $image_id ? wp_get_attachment_image_url( $image_id, 'thumbnail' ) : '',
            'image_id'  => $image_id,
        ] );
    }

    public static function ajax_remove_mockup() {
        check_ajax_referer( 'thready_mockup_nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_send_json_error( [], 403 );

        $tip_slug  = sanitize_key( wp_unslash( $_POST['tip_slug']  ?? '' ) );
        $boja_slug = sanitize_key( wp_unslash( $_POST['boja_slug'] ?? '' ) );
        $slot      = sanitize_key( wp_unslash( $_POST['slot']      ?? '' ) );

        if ( ! $tip_slug || ! $boja_slug || ! in_array( $slot, [ 'front', 'back' ], true ) )
            wp_send_json_error( [ 'message' => 'Invalid parameters' ] );

        if ( $slot === 'front' ) self::save( $tip_slug, $boja_slug, 0, null );
        else                     self::save( $tip_slug, $boja_slug, null, 0 );

        $row      = self::get( $tip_slug, $boja_slug );
        $front_id = $row ? (int) $row->front_image : 0;
        $back_id  = $row ? (int) $row->back_image  : 0;
        $status   = $front_id && $back_id ? 'complete' : ( $front_id ? 'front-only' : 'empty' );

        wp_send_json_success( [ 'status' => $status ] );
    }

    public static function ajax_scan_mockups() {
        check_ajax_referer( 'thready_mockup_nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_send_json_error( [], 403 );

        $result = self::scan_and_import();

        wp_send_json_success( [
            'message'  => sprintf(
                '%d imported, %d already existed, %d errors',
                $result['imported'],
                $result['skipped'],
                count( $result['errors'] )
            ),
            'imported' => $result['imported'],
            'skipped'  => $result['skipped'],
            'errors'   => $result['errors'],
        ] );
    }

    // -------------------------------------------------------------------------
    // Auto-rename
    // -------------------------------------------------------------------------

    /**
     * Rename an attachment file to the mockup naming convention:
     *   {tip-slug}-{boja-slug}-{front|back}.{ext}
     *
     * Updates the physical file, postmeta, and attachment title.
     */
    private static function auto_rename_attachment( $attachment_id, $tip_slug, $boja_slug, $slot ) {
        $filepath = get_attached_file( $attachment_id );
        if ( ! $filepath || ! file_exists( $filepath ) ) return;

        $upload_dir   = wp_upload_dir();
        $ext          = strtolower( pathinfo( $filepath, PATHINFO_EXTENSION ) );
        $new_filename = $tip_slug . '-' . $boja_slug . '-' . $slot . '.' . $ext;

        // Ensure thready-mockups directory exists
        $mockup_dir = trailingslashit( $upload_dir['basedir'] ) . self::SCAN_DIR;
        if ( ! file_exists( $mockup_dir ) ) {
            wp_mkdir_p( $mockup_dir );
        }

        $new_filepath = trailingslashit( $mockup_dir ) . $new_filename;
        $new_relative = self::SCAN_DIR . '/' . $new_filename;

        // Already in the right place with the right name — skip
        if ( $filepath === $new_filepath ) return;

        // Copy to thready-mockups (keep original in place for WP media library integrity,
        // but point the attachment to the new location)
        if ( ! copy( $filepath, $new_filepath ) ) {
            // Fallback: try rename if copy fails (same filesystem)
            if ( ! rename( $filepath, $new_filepath ) ) {
                error_log( "Thready Mockup: could not copy/rename $filepath to $new_filepath" );
                return;
            }
        }

        // Update attachment to point to new file
        update_post_meta( $attachment_id, '_wp_attached_file', $new_relative );

        // Update attachment title
        wp_update_post( [
            'ID'         => $attachment_id,
            'post_title' => sanitize_file_name( $tip_slug . '-' . $boja_slug . '-' . $slot ),
            'post_name'  => sanitize_title( $tip_slug . '-' . $boja_slug . '-' . $slot ),
        ] );

        // Regenerate metadata pointing to new file
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $metadata = wp_generate_attachment_metadata( $attachment_id, $new_filepath );
        wp_update_attachment_metadata( $attachment_id, $metadata );
    }

    private static function notice( $type, $message ) {
        echo '<div class="wrap"><h1>' . esc_html__( 'Mockup Library', 'thready-product-customizer' ) . '</h1>';
        echo '<div class="notice notice-' . esc_attr( $type ) . '"><p>' . esc_html( $message ) . '</p></div></div>';
    }
}