<?php
/**
 * Thready Quick Add Variations
 *
 * For canvas-mode products, replaces WC's native variation buttons in the
 * Variations tab with a "Quick Add Variation" button that opens a modal.
 *
 * Modal uses tabs per product type. Users select colors (and for new types,
 * prices and sizes) to bulk-create missing variation combinations.
 */

defined( 'ABSPATH' ) || exit;

class Thready_Quick_Add_Variation {

    public static function init() {
        add_action( 'wp_ajax_thready_quick_add_variation', [ __CLASS__, 'ajax_add_variations' ] );
        add_action( 'admin_footer-post.php',               [ __CLASS__, 'render_modal'         ] );
        add_action( 'admin_footer-post-new.php',           [ __CLASS__, 'render_modal'         ] );
    }

    // =========================================================================
    // Modal
    // =========================================================================

    public static function render_modal() {
        $screen = get_current_screen();
        if ( ! $screen || $screen->id !== 'product' ) return;

        global $post;
        if ( ! $post ) return;

        $product_id = $post->ID;
        if ( get_post_meta( $product_id, '_thready_render_mode', true ) !== 'canvas' ) return;

        // ── Gather data ──────────────────────────────────────────────────────

        $tips_with_mockups = Thready_Mockup_Library::get_tips_with_mockups();
        $tip_data = [];
        $tip_mockup_bojas = []; // { tip_slug: [boja_slug, ...] }
        foreach ( $tips_with_mockups as $slug ) {
            $term = get_term_by( 'slug', $slug, THREADY_TAX_TIP );
            if ( ! $term ) continue;
            $tip_data[] = [ 'slug' => $slug, 'name' => $term->name ];

            // Collect which boja slugs have mockups for this tip
            $mockups = Thready_Mockup_Library::get_for_tip( $slug );
            $tip_mockup_bojas[ $slug ] = array_keys( $mockups );
        }
        if ( empty( $tip_data ) ) return;

        $existing_map  = self::get_existing_map( $product_id );
        $existing_tips = array_values( array_unique( array_map( function( $k ) {
            return explode( '|', $k )[0];
        }, array_keys( $existing_map ) ) ) );

        $tip_prices = Thready_Variation_Factory::get_tip_prices( $product_id );
        $tip_sizes  = [];
        foreach ( $existing_tips as $t ) {
            $tip_sizes[ $t ] = Thready_Variation_Factory::get_sizes_csv_for_tip( $product_id, $t );
        }

        $boja_terms = Thready_Variation_Factory::get_ordered_terms( THREADY_TAX_BOJA );
        $boja_data  = [];
        foreach ( $boja_terms as $b ) {
            $hex = get_term_meta( $b->term_id, 'product_attribute_color', true );
            $boja_data[] = [ 'slug' => $b->slug, 'name' => $b->name, 'hex' => $hex ?: '' ];
        }

        $size_terms = Thready_Variation_Factory::get_ordered_terms( 'pa_velicina' );
        $size_data  = [];
        foreach ( $size_terms as $s ) {
            $size_data[] = [ 'slug' => $s->slug, 'name' => $s->name ];
        }

        $has_light  = (bool) get_post_meta( $product_id, '_thready_light_print_image', true );
        $currency   = get_woocommerce_currency_symbol();
        $wizard_url = admin_url( 'admin.php?page=thready-wizard&product_id=' . $product_id );
        $nonce      = wp_create_nonce( 'thready_quick_add_variation' );

        $js_data = wp_json_encode( [
            'productId'      => $product_id,
            'nonce'          => $nonce,
            'tips'           => $tip_data,
            'existingTips'   => $existing_tips,
            'existingCombos' => array_keys( $existing_map ),
            'tipPrices'      => (object) $tip_prices,
            'tipSizes'       => (object) $tip_sizes,
            'tipMockupBojas' => (object) $tip_mockup_bojas,
            'bojas'          => $boja_data,
            'sizes'          => $size_data,
            'hasLight'       => $has_light,
            'currency'       => $currency,
            'wizardUrl'      => $wizard_url,
        ] );
        ?>

        <!-- Quick Add Variations Modal -->
        <div id="qav-overlay" style="display:none;">
        <div id="qav-modal">
            <div class="qav-header">
                <h2>Quick Add Variations</h2>
                <button type="button" id="qav-close" class="qav-x">&times;</button>
            </div>
            <div class="qav-body" id="qav-body"></div>
            <div class="qav-footer">
                <a href="<?php echo esc_url( $wizard_url ); ?>" class="qav-wiz-link">Edit in Wizard →</a>
                <button type="button" class="button button-primary button-large" id="qav-submit">Add Variations</button>
            </div>
        </div>
        </div>

        <style>
        /* ── overlay / modal shell ──────────────────────────────── */
        #qav-overlay{position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:100100;display:flex;align-items:center;justify-content:center}
        #qav-modal{background:#fff;border-radius:8px;width:640px;max-width:92vw;max-height:85vh;display:flex;flex-direction:column;box-shadow:0 10px 40px rgba(0,0,0,.3)}
        .qav-header{display:flex;align-items:center;justify-content:space-between;padding:16px 20px;border-bottom:1px solid #dcdcda}
        .qav-header h2{margin:0;font-size:16px}
        .qav-x{background:none;border:none;font-size:24px;color:#646970;cursor:pointer;padding:0;line-height:1}
        .qav-x:hover{color:#1e1e1e}
        .qav-body{padding:20px;overflow-y:auto;flex:1}
        .qav-footer{display:flex;align-items:center;justify-content:space-between;padding:14px 20px;border-top:1px solid #dcdcda;background:#f6f7f7;border-radius:0 0 8px 8px}
        .qav-wiz-link{font-size:12px;color:#646970;text-decoration:none}
        .qav-wiz-link:hover{color:#2271b1}

        /* ── sections ───────────────────────────────────────────── */
        .qav-section{margin-bottom:18px}
        .qav-stitle{font-size:13px;font-weight:600;margin:0 0 8px}
        .qav-list{max-height:200px;overflow-y:auto;border:1px solid #dcdcda;border-radius:4px;padding:6px}
        .qav-list-short{max-height:120px}
        .qav-row{display:flex;align-items:center;gap:8px;padding:4px 6px;cursor:pointer;border-radius:3px;font-size:13px}
        .qav-row:hover{background:#f6f7f7}
        .qav-row.is-off{opacity:.4;cursor:default}
        .qav-row.is-off:hover{background:none}
        .qav-swatch{display:inline-block;width:18px;height:18px;border-radius:50%;border:1px solid rgba(0,0,0,.15);flex-shrink:0}
        .qav-tag{font-size:10px;background:#e8e8e6;padding:1px 6px;border-radius:3px;color:#646970;margin-left:auto}
        .qav-tag-warn{background:#fcf0e3;color:#9a6700}

        /* ── tabs ────────────────────────────────────────────────── */
        .qav-tabs{display:flex;gap:0;margin:14px 0 0;border-bottom:2px solid #dcdcda}
        .qav-tab{padding:8px 16px;font-size:13px;font-weight:600;cursor:pointer;border:none;background:none;color:#646970;border-bottom:2px solid transparent;margin-bottom:-2px;transition:color .12s,border-color .12s}
        .qav-tab:hover{color:#1e1e1e}
        .qav-tab.is-active{color:#fff;background:#2271b1;border-color:#2271b1;border-radius:4px 4px 0 0}
        .qav-tab-panel{display:none;padding-top:14px}
        .qav-tab-panel.is-visible{display:block}

        /* ── actions row ─────────────────────────────────────────── */
        .qav-acts{display:flex;gap:6px;margin-bottom:6px}

        /* ── new type config ─────────────────────────────────────── */
        .qav-cfg{background:#f9f9f8;border:1px solid #e8e8e6;border-radius:6px;padding:14px;margin-bottom:14px}
        .qav-cfg h4{margin:0 0 10px;font-size:13px}
        .qav-prow{display:flex;gap:12px;margin-bottom:10px}
        .qav-pf{flex:1}
        .qav-pf label{display:block;font-size:12px;margin-bottom:3px;color:#1e1e1e}
        .qav-pf input{width:100%;padding:5px 8px;border:1px solid #8c8f94;border-radius:4px;font-size:13px;box-sizing:border-box}
        .qav-pf input.qav-err{border-color:#cf2929}
        .qav-szlabel{font-size:12px;margin-bottom:4px;display:block}
        .qav-szwrap{display:flex;flex-wrap:wrap;gap:6px}
        .qav-szwrap label{font-size:12px;display:flex;align-items:center;gap:3px}
        .qav-light{display:flex;align-items:center;gap:6px;font-size:12px;margin-top:6px}

        /* ── result ──────────────────────────────────────────────── */
        #qav-result{font-size:12px;margin-top:10px}
        .qav-ok{color:#1a7340}
        .qav-er{color:#cf2929}

        /* ── toolbar button ──────────────────────────────────────── */
        .thready-qav-toolbar-btn{background:#2271b1!important;color:#fff!important;border-color:#2271b1!important}
        .thready-qav-toolbar-btn:hover{background:#135e96!important;border-color:#135e96!important}
        </style>

        <script>
        jQuery(function($) {
            var D = <?php echo $js_data; ?>;
            var activeTab = '';

            // ══════════════════════════════════════════════════════
            // Build modal body
            // ══════════════════════════════════════════════════════
            function buildBody() {
                var h = '';

                // ── Product Types checkboxes ─────────────────────
                h += '<div class="qav-section">';
                h += '<p class="qav-stitle">Product Types</p>';
                h += '<div class="qav-list qav-list-short">';
                D.tips.forEach(function(tip) {
                    var onProd = D.existingTips.indexOf(tip.slug) !== -1;
                    h += '<label class="qav-row">';
                    h += '<input type="checkbox" class="qav-tip-cb" value="' + tip.slug + '">';
                    h += '<span>' + esc(tip.name) + '</span>';
                    if (onProd) h += '<span class="qav-tag">on product</span>';
                    h += '</label>';
                });
                h += '</div>';

                // Tab bar + panels placeholder
                h += '<div id="qav-tabs-bar" class="qav-tabs" style="display:none;"></div>';
                h += '</div>';
                h += '<div id="qav-panels"></div>';

                // ── Light print ──────────────────────────────────
                if (D.hasLight) {
                    h += '<div class="qav-section">';
                    h += '<label class="qav-light"><input type="checkbox" id="qav-light"> Use light print for new variations</label>';
                    h += '</div>';
                }

                h += '<div id="qav-result"></div>';
                $('#qav-body').html(h);
                activeTab = '';
            }

            // ══════════════════════════════════════════════════════
            // Rebuild tabs when type checkboxes change
            // ══════════════════════════════════════════════════════
            function rebuildTabs() {
                var checked = [];
                $('.qav-tip-cb:checked').each(function() { checked.push($(this).val()); });

                var $bar    = $('#qav-tabs-bar');
                var $panels = $('#qav-panels');

                if (!checked.length) {
                    $bar.hide().empty();
                    $panels.empty();
                    activeTab = '';
                    return;
                }

                // Build tab buttons
                var tabs = '';
                checked.forEach(function(slug) {
                    var name = '';
                    D.tips.forEach(function(t) { if (t.slug === slug) name = t.name; });
                    tabs += '<button type="button" class="qav-tab" data-tip="' + slug + '">' + esc(name) + ' Variations</button>';
                });
                $bar.html(tabs).show();

                // Build panels
                var panels = '';
                checked.forEach(function(slug) {
                    var name = '';
                    D.tips.forEach(function(t) { if (t.slug === slug) name = t.name; });
                    var isNew = D.existingTips.indexOf(slug) === -1;

                    panels += '<div class="qav-tab-panel" data-tip="' + slug + '">';

                    // New type config
                    if (isNew) {
                        panels += '<div class="qav-cfg">';
                        panels += '<h4>Settings for ' + esc(name) + ' <span style="font-weight:400;color:#646970">(new type)</span></h4>';
                        panels += '<div class="qav-prow">';
                        panels += '<div class="qav-pf"><label>Regular Price (' + esc(D.currency) + ') *</label>';
                        panels += '<input type="number" class="qav-reg" data-tip="' + slug + '" min="0" step="0.01" placeholder="0.00"></div>';
                        panels += '<div class="qav-pf"><label>Sale Price (' + esc(D.currency) + ')</label>';
                        panels += '<input type="number" class="qav-sale" data-tip="' + slug + '" min="0" step="0.01" placeholder="Optional"></div>';
                        panels += '</div>';
                        panels += '<span class="qav-szlabel">Sizes</span>';
                        panels += '<div class="qav-szwrap">';
                        D.sizes.forEach(function(s) {
                            panels += '<label><input type="checkbox" class="qav-sz-cb" data-tip="' + slug + '" value="' + s.slug + '"> ' + esc(s.name) + '</label>';
                        });
                        panels += '</div>';
                        panels += '</div>';
                    }

                    // Colors
                    panels += '<p class="qav-stitle">' + esc(name) + ' Colors</p>';
                    panels += '<div class="qav-acts">';
                    panels += '<button type="button" class="button button-small qav-all" data-tip="' + slug + '">All</button>';
                    panels += '<button type="button" class="button button-small qav-none" data-tip="' + slug + '">None</button>';
                    panels += '</div>';
                    panels += '<div class="qav-list">';
                    var mockupBojas = (D.tipMockupBojas && D.tipMockupBojas[slug]) || [];
                    D.bojas.forEach(function(b) {
                        var exists    = D.existingCombos.indexOf(slug + '|' + b.slug) !== -1;
                        var hasMockup = mockupBojas.indexOf(b.slug) !== -1;
                        var isOff     = exists || !hasMockup;
                        panels += '<label class="qav-row qav-color-row' + (isOff ? ' is-off' : '') + '" data-tip="' + slug + '" data-boja="' + b.slug + '">';
                        panels += '<input type="checkbox" class="qav-boja-cb" data-tip="' + slug + '" value="' + b.slug + '"' + (isOff ? ' disabled' : '') + '>';
                        if (b.hex) panels += '<span class="qav-swatch" style="background:' + b.hex + '"></span>';
                        panels += '<span>' + esc(b.name) + '</span>';
                        if (exists) panels += '<span class="qav-tag">exists</span>';
                        else if (!hasMockup) panels += '<span class="qav-tag qav-tag-warn">no mockup</span>';
                        panels += '</label>';
                    });
                    panels += '</div>';

                    panels += '</div>';
                });
                $panels.html(panels);

                // Activate first tab or keep current
                if (checked.indexOf(activeTab) === -1) activeTab = checked[0];
                activateTab(activeTab);
            }

            function activateTab(slug) {
                activeTab = slug;
                $('.qav-tab').removeClass('is-active');
                $('.qav-tab[data-tip="' + slug + '"]').addClass('is-active');
                $('.qav-tab-panel').removeClass('is-visible');
                $('.qav-tab-panel[data-tip="' + slug + '"]').addClass('is-visible');
            }

            // ── Events ───────────────────────────────────────────
            $(document).on('change', '.qav-tip-cb', rebuildTabs);
            $(document).on('click', '.qav-tab', function() { activateTab($(this).data('tip')); });
            $(document).on('click', '.qav-all', function() {
                var tip = $(this).data('tip');
                $('.qav-boja-cb[data-tip="' + tip + '"]:not(:disabled)').prop('checked', true);
            });
            $(document).on('click', '.qav-none', function() {
                var tip = $(this).data('tip');
                $('.qav-boja-cb[data-tip="' + tip + '"]').prop('checked', false);
            });

            // ══════════════════════════════════════════════════════
            // Submit
            // ══════════════════════════════════════════════════════
            $('#qav-submit').on('click', function() {
                var $btn = $(this);
                var tips = [], light = $('#qav-light').is(':checked');
                var tipColors = {};
                var newTypeConfigs = {};
                var errors = [];
                var totalToCreate = 0;

                $('.qav-tip-cb:checked').each(function() { tips.push($(this).val()); });
                if (!tips.length) { msg('Select at least one product type.', true); return; }

                // Collect colors per tip + validate new type configs
                tips.forEach(function(slug) {
                    var colors = [];
                    $('.qav-boja-cb[data-tip="' + slug + '"]:checked').each(function() {
                        colors.push($(this).val());
                    });
                    tipColors[slug] = colors;
                    totalToCreate += colors.length;

                    var isNew = D.existingTips.indexOf(slug) === -1;
                    if (isNew) {
                        var $panel = $('.qav-tab-panel[data-tip="' + slug + '"]');
                        var reg  = parseFloat($panel.find('.qav-reg').val()) || 0;
                        var sale = $panel.find('.qav-sale').val().trim();
                        sale = sale !== '' ? parseFloat(sale) : null;

                        $panel.find('.qav-err').removeClass('qav-err');

                        if (reg <= 0) {
                            errors.push('Regular price required for new type.');
                            $panel.find('.qav-reg').addClass('qav-err');
                        }
                        if (sale !== null && sale >= reg) {
                            errors.push('Sale price must be lower than regular price.');
                            $panel.find('.qav-sale').addClass('qav-err');
                        }

                        var sizes = [];
                        $panel.find('.qav-sz-cb:checked').each(function() { sizes.push($(this).val()); });

                        newTypeConfigs[slug] = { regular: reg, sale: sale, sizes: sizes };
                    }
                });

                if (errors.length) { msg(errors[0], true); return; }
                if (totalToCreate === 0) { msg('Select at least one color per type.', true); return; }

                $btn.prop('disabled', true).text('Adding ' + totalToCreate + ' variation(s)…');
                $('#qav-result').html('');

                $.post(ajaxurl, {
                    action:           'thready_quick_add_variation',
                    _ajax_nonce:      D.nonce,
                    product_id:       D.productId,
                    tip_colors:       JSON.stringify(tipColors),
                    light_print:      light ? 1 : 0,
                    new_type_configs: JSON.stringify(newTypeConfigs),
                }, function(res) {
                    $btn.prop('disabled', false).text('Add Variations');
                    if (res.success) {
                        var d = res.data;
                        var m = '✓ ' + d.created + ' variation(s) created.';
                        if (d.skipped > 0) m += ' ' + d.skipped + ' already existed.';
                        msg(m, false);

                        // Reload page after short delay so user sees the message
                        if (d.created > 0) {
                            setTimeout(function() { window.location.reload(); }, 1200);
                        }
                    } else {
                        msg(res.data && res.data.message || 'Error', true);
                    }
                }).fail(function() {
                    $btn.prop('disabled', false).text('Add Variations');
                    msg('Request failed.', true);
                });
            });

            function msg(text, isErr) {
                $('#qav-result').html('<span class="' + (isErr ? 'qav-er' : 'qav-ok') + '">' + text + '</span>');
            }

            // ══════════════════════════════════════════════════════
            // Modal open / close
            // ══════════════════════════════════════════════════════
            function openModal()  { buildBody(); $('#qav-overlay').show(); }
            function closeModal() { $('#qav-overlay').hide(); }

            $('#qav-close').on('click', closeModal);
            $('#qav-overlay').on('click', function(e) { if (e.target === this) closeModal(); });
            $(document).on('keydown', function(e) { if (e.key === 'Escape' && $('#qav-overlay').is(':visible')) closeModal(); });

            // ══════════════════════════════════════════════════════
            // Replace WC variation buttons
            // ══════════════════════════════════════════════════════
            function replaceWcButtons() {
                // Target the toolbars in the variations panel
                var $tops = $('.woocommerce_variations_add, .toolbar.toolbar-variations-defaults, .toolbar-top');
                $tops.find('.add_variation_manually, .link_all_variations, .do_variation_action').hide();
                $tops.find('select.variation_actions').hide();

                // Also hide by text content for themes that use different markup
                $tops.find('.button, button').each(function() {
                    var t = ($(this).text() || '').trim().toLowerCase();
                    if (t === 'add manually' || t === 'generate variations' || t === 'go') {
                        $(this).hide();
                    }
                });

                // Place our button in the top toolbar (with Generate/Add manually)
                var $topBar = $('.toolbar.toolbar-top');
                if ($topBar.length && !$topBar.find('.thready-qav-toolbar-btn').length) {
                    var $btn = $('<button type="button" class="button thready-qav-toolbar-btn">Quick Add Variation</button>');
                    $btn.on('click', function(e) { e.preventDefault(); openModal(); });
                    $topBar.prepend($btn);
                }
            }

            // Run immediately, after WC loads variations, and periodically
            replaceWcButtons();
            $(document).on('woocommerce_variations_loaded', function() {
                setTimeout(replaceWcButtons, 100);
            });
            // Catch late-rendering toolbars
            var replaceAttempts = 0;
            var replaceInterval = setInterval(function() {
                replaceWcButtons();
                if (++replaceAttempts > 10) clearInterval(replaceInterval);
            }, 600);

            function esc(s) {
                if (!s) return '';
                return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
            }
        });
        </script>
        <?php
    }

    // =========================================================================
    // AJAX handler
    // =========================================================================

    public static function ajax_add_variations() {
        check_ajax_referer( 'thready_quick_add_variation' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
        }

        $product_id       = absint( $_POST['product_id'] ?? 0 );
        $tip_colors       = json_decode( wp_unslash( $_POST['tip_colors'] ?? '{}' ), true ) ?: [];
        $light_print      = ! empty( $_POST['light_print'] );
        $new_type_configs = json_decode( wp_unslash( $_POST['new_type_configs'] ?? '{}' ), true ) ?: [];

        if ( ! $product_id || empty( $tip_colors ) ) {
            wp_send_json_error( [ 'message' => 'Missing required fields.' ] );
        }

        if ( get_post_meta( $product_id, '_thready_render_mode', true ) !== 'canvas' ) {
            wp_send_json_error( [ 'message' => 'Not a canvas product.' ] );
        }

        $existing = self::get_existing_map( $product_id );
        $created  = 0;
        $skipped  = 0;

        foreach ( $tip_colors as $tip_slug => $colors ) {
            $tip_slug = sanitize_title( $tip_slug );
            $colors   = array_map( 'sanitize_title', (array) $colors );
            if ( empty( $colors ) ) continue;

            // Build overrides for new types
            $overrides = [];
            if ( isset( $new_type_configs[ $tip_slug ] ) ) {
                $cfg     = $new_type_configs[ $tip_slug ];
                $regular = (float) ( $cfg['regular'] ?? 0 );
                $sale    = isset( $cfg['sale'] ) && $cfg['sale'] !== '' && $cfg['sale'] !== null
                         ? (float) $cfg['sale'] : null;

                if ( $sale !== null && $sale >= $regular ) {
                    wp_send_json_error( [ 'message' => "Sale price must be less than regular price." ] );
                }

                $sizes_csv = ! empty( $cfg['sizes'] )
                    ? implode( ',', array_map( 'sanitize_title', (array) $cfg['sizes'] ) )
                    : '';

                $overrides = [ 'regular' => $regular, 'sale' => $sale, 'sizes_csv' => $sizes_csv ];
            }

            foreach ( $colors as $boja_slug ) {
                $key = $tip_slug . '|' . $boja_slug;
                if ( isset( $existing[ $key ] ) ) { $skipped++; continue; }

                $result = Thready_Variation_Factory::add_color(
                    $product_id, $tip_slug, $boja_slug, $light_print, $overrides
                );
                if ( is_wp_error( $result ) ) continue;

                $var_id = $result['variation_id'] ?? 0;
                if ( $var_id ) {
                    Thready_Variation_Factory::generate_single_variation_thumbnail( $product_id, $var_id );
                }
                $created++;
            }
        }

        wp_send_json_success( [ 'created' => $created, 'skipped' => $skipped ] );
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private static function get_existing_map( $product_id ) {
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
}