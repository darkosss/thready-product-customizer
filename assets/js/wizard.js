/**
 * Thready Product Wizard — wizard.js
 *
 * Manages 6-step product creation flow entirely client-side.
 * Communicates with the server only on final Create (AJAX).
 *
 * State shape
 * -----------
 * {
 *   step         : 1–6
 *   designName   : string
 *   selectedTips : [ {slug, name} ]
 *   tipConfigs   : {
 *     [tipSlug]: {
 *       bojas        : string[]   — selected boja slugs
 *       velicinas    : string[]   — selected velicina slugs
 *       regularPrice : number
 *       salePrice    : number|null
 *       posFront     : {x, y, width}
 *       posBack      : {x, y, width}|null
 *     }
 *   }
 *   printFrontId  : number
 *   printFrontUrl : string
 *   printBackId   : number
 *   printBackUrl  : string
 * }
 */

/* global threadyWizard, wp */
(function ( $ ) {
    'use strict';

    var cfg  = window.threadyWizard || {};
    var i18n = cfg.i18n || {};

    // ─────────────────────────────────────────────────────────────────────────
    // State
    // ─────────────────────────────────────────────────────────────────────────

    var state = {
        step         : 1,
        designName   : '',
        selectedTips : [],
        tipConfigs   : {},
        printFrontId : 0,
        printFrontUrl: '',
        printBackId  : 0,
        printBackUrl : '',
    };

    // Pre-fill state if in edit mode
    if ( cfg.edit_data ) {
        var ed = cfg.edit_data;
        state.designName    = ed.product_name || '';
        state.printFrontId  = ed.print_front_id || 0;
        state.printBackId   = ed.print_back_id  || 0;

        if ( ed.tip_slug ) {
            var tipTerm = ( cfg.tips || [] ).find( function (t) { return t.slug === ed.tip_slug; } );
            if ( tipTerm ) {
                state.selectedTips = [ tipTerm ];
                state.tipConfigs[ ed.tip_slug ] = {
                    bojas        : ed.colors || [],
                    velicinas    : ed.sizes  || [],
                    regularPrice : ed.regular_price || 0,
                    salePrice    : ed.sale_price,
                    posFront     : ed.pos_front || { x: 50, y: 25, width: 50 },
                    posBack      : ed.pos_back  || null,
                };
            }
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // DOM shortcuts
    // ─────────────────────────────────────────────────────────────────────────

    var $body    = $( '#wizard-body' );
    var $footer  = $( '#wizard-footer' );
    var $btnBack = $( '#wizard-btn-back' );
    var $btnNext = $( '#wizard-btn-next' );
    var $errMsg  = $( '#wizard-error-msg' );

    // ─────────────────────────────────────────────────────────────────────────
    // Navigation
    // ─────────────────────────────────────────────────────────────────────────

    function goTo( step ) {
        state.step = step;
        updateStepIndicators();
        renderStep( step );
        updateNavButtons();
        clearError();
        $( '.wizard-body' ).scrollTop( 0 );
        window.scrollTo( 0, 0 );
    }

    function updateNavButtons() {
        var s = state.step;
        $btnBack.toggle( s > 1 );

        if ( s === 6 ) {
            $btnNext.text( cfg.edit_data
                ? i18n.update
                : i18n.create
            );
        } else {
            $btnNext.text( i18n.next || 'Next →' );
        }
    }

    function updateStepIndicators() {
        $( '.wizard-step-indicator' ).each( function () {
            var n = parseInt( $( this ).data( 'step' ), 10 );
            $( this )
                .toggleClass( 'active',    n === state.step )
                .toggleClass( 'done',      n <  state.step );
        } );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Validation per step
    // ─────────────────────────────────────────────────────────────────────────

    function validate( step ) {
        clearError();

        switch ( step ) {

            case 1:
                collectStep1();
                if ( ! state.designName ) { showError( i18n.err_no_name );  return false; }
                if ( ! state.selectedTips.length ) { showError( i18n.err_no_tips ); return false; }
                return true;

            case 2:
                collectStep2();
                for ( var i = 0; i < state.selectedTips.length; i++ ) {
                    var slug = state.selectedTips[ i ].slug;
                    if ( ! state.tipConfigs[ slug ] || ! state.tipConfigs[ slug ].bojas.length ) {
                        showError( i18n.err_no_colors );
                        return false;
                    }
                }
                return true;

            case 3:
                collectStep3();
                for ( var i = 0; i < state.selectedTips.length; i++ ) {
                    var slug = state.selectedTips[ i ].slug;
                    var cfg2 = state.tipConfigs[ slug ];
                    if ( ! cfg2 || ! cfg2.regularPrice || cfg2.regularPrice <= 0 ) {
                        showError( i18n.err_no_price );
                        return false;
                    }
                }
                return true;

            case 4:
                collectStep4();
                for ( var i = 0; i < state.selectedTips.length; i++ ) {
                    var slug = state.selectedTips[ i ].slug;
                    if ( ! state.tipConfigs[ slug ] || ! state.tipConfigs[ slug ].velicinas.length ) {
                        showError( i18n.err_no_sizes );
                        return false;
                    }
                }
                return true;

            case 5:
                collectStep5();
                if ( ! state.printFrontId ) { showError( i18n.err_no_print ); return false; }
                return true;

            default:
                return true;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Collect state from DOM (called before validation)
    // ─────────────────────────────────────────────────────────────────────────

    function collectStep1() {
        state.designName   = $( '#wizard-design-name' ).val().trim();
        state.selectedTips = [];

        $( '.tip-checkbox:checked' ).each( function () {
            var slug = $( this ).val();
            var name = $( this ).data( 'name' );
            state.selectedTips.push( { slug: slug, name: name } );

            // Initialise tipConfig if missing
            if ( ! state.tipConfigs[ slug ] ) {
                state.tipConfigs[ slug ] = {
                    bojas: [], velicinas: [],
                    regularPrice: 0, salePrice: null,
                    posFront: { x: 50, y: 25, width: 50 },
                    posBack: null,
                };
            }
        } );

        // Remove tipConfigs for deselected tips
        Object.keys( state.tipConfigs ).forEach( function ( slug ) {
            if ( ! state.selectedTips.find( function (t) { return t.slug === slug; } ) ) {
                delete state.tipConfigs[ slug ];
            }
        } );
    }

    function collectStep2() {
        state.selectedTips.forEach( function ( tip ) {
            var bojas = [];
            $( '.boja-checkbox[data-tip="' + tip.slug + '"]:checked' ).each( function () {
                bojas.push( $( this ).val() );
            } );
            state.tipConfigs[ tip.slug ].bojas = bojas;
        } );
    }

    function collectStep3() {
        state.selectedTips.forEach( function ( tip ) {
            var reg  = parseFloat( $( '#price-regular-' + tip.slug ).val() ) || 0;
            var sale = $( '#price-sale-' + tip.slug ).val().trim();
            state.tipConfigs[ tip.slug ].regularPrice = reg;
            state.tipConfigs[ tip.slug ].salePrice    = sale !== '' ? parseFloat( sale ) : null;
        } );
    }

    function collectStep4() {
        state.selectedTips.forEach( function ( tip ) {
            var sizes = [];
            $( '.size-checkbox[data-tip="' + tip.slug + '"]:checked' ).each( function () {
                sizes.push( $( this ).val() );
            } );
            state.tipConfigs[ tip.slug ].velicinas = sizes;
        } );
    }

    function collectStep5() {
        // Print IDs are set directly on state via upload handlers
        state.selectedTips.forEach( function ( tip ) {
            state.tipConfigs[ tip.slug ].posFront = {
                x    : parseInt( $( '#pos-front-x-'     + tip.slug ).val(), 10 ) || 50,
                y    : parseInt( $( '#pos-front-y-'     + tip.slug ).val(), 10 ) || 25,
                width: parseInt( $( '#pos-front-width-' + tip.slug ).val(), 10 ) || 50,
            };

            if ( state.printBackId ) {
                state.tipConfigs[ tip.slug ].posBack = {
                    x    : parseInt( $( '#pos-back-x-'     + tip.slug ).val(), 10 ) || 50,
                    y    : parseInt( $( '#pos-back-y-'     + tip.slug ).val(), 10 ) || 25,
                    width: parseInt( $( '#pos-back-width-' + tip.slug ).val(), 10 ) || 50,
                };
            } else {
                state.tipConfigs[ tip.slug ].posBack = null;
            }
        } );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Step renderers
    // ─────────────────────────────────────────────────────────────────────────

    function renderStep( step ) {
        switch ( step ) {
            case 1: renderStep1(); break;
            case 2: renderStep2(); break;
            case 3: renderStep3(); break;
            case 4: renderStep4(); break;
            case 5: renderStep5(); break;
            case 6: renderStep6(); break;
        }
    }

    // ── Step 1 — Design name + product types ─────────────────────────────────

    function renderStep1() {
        var html = '<div class="wizard-step" data-step="1">';
        html += '<h2 class="step-title">' + esc( i18n.step_labels[0] ) + '</h2>';

        // Design name
        html += '<div class="wiz-field">';
        html += '<label class="wiz-label" for="wizard-design-name">Design Name <span class="req">*</span></label>';
        html += '<input type="text" id="wizard-design-name" class="regular-text" placeholder="e.g. Dragon, Rose Pattern…" value="' + esc( state.designName ) + '">';
        html += '<p class="wiz-hint">Used as the product title prefix. Each product type becomes a separate WooCommerce product.</p>';
        html += '</div>';

        // Tip checkboxes
        html += '<div class="wiz-field">';
        html += '<label class="wiz-label">Product Types <span class="req">*</span></label>';

        if ( ! cfg.tips || ! cfg.tips.length ) {
            html += '<p class="wiz-warn">⚠ No product types (pa_tip) found. Add them in WooCommerce → Attributes.</p>';
        } else {
            html += '<div class="wiz-checkbox-grid">';
            cfg.tips.forEach( function ( tip ) {
                var checked = state.selectedTips.find( function (t) { return t.slug === tip.slug; } ) ? 'checked' : '';
                html += '<label class="wiz-check-card ' + ( checked ? 'is-checked' : '' ) + '">';
                html += '<input type="checkbox" class="tip-checkbox" value="' + esc( tip.slug ) + '" data-name="' + esc( tip.name ) + '" ' + checked + '>';
                html += '<span class="check-card-name">' + esc( tip.name ) + '</span>';
                html += '</label>';
            } );
            html += '</div>';
        }
        html += '</div>';
        html += '</div>';

        $body.html( html );

        // Toggle card selected class
        $body.on( 'change', '.tip-checkbox', function () {
            $( this ).closest( '.wiz-check-card' ).toggleClass( 'is-checked', this.checked );
        } );

        // Focus name field
        $( '#wizard-design-name' ).trigger( 'focus' );
    }

    // ── Step 2 — Colors per tip ──────────────────────────────────────────────

    function renderStep2() {
        var html = '<div class="wizard-step" data-step="2">';
        html += '<h2 class="step-title">' + esc( i18n.step_labels[1] ) + '</h2>';

        state.selectedTips.forEach( function ( tip ) {
            var selectedBojas = state.tipConfigs[ tip.slug ].bojas;
            html += '<div class="wiz-tip-section">';
            html += '<h3 class="tip-section-title">' + esc( tip.name ) + '</h3>';
            html += '<div class="wiz-quick-actions">';
            html += '<button type="button" class="button button-small boja-select-all" data-tip="' + esc( tip.slug ) + '">' + esc( i18n.select_all ) + '</button>';
            html += '<button type="button" class="button button-small boja-select-none" data-tip="' + esc( tip.slug ) + '">' + esc( i18n.select_none ) + '</button>';
            html += '</div>';
            html += '<div class="wiz-color-grid">';

            if ( ! cfg.bojas || ! cfg.bojas.length ) {
                html += '<p class="wiz-warn">No colors found in pa_boja.</p>';
            } else {
                cfg.bojas.forEach( function ( boja ) {
                    var key        = tip.slug + '|' + boja.slug;
                    var mockup     = ( cfg.mockup_map || {} )[ key ] || {};
                    var hasFront   = mockup.has_front;
                    var checked    = selectedBojas.indexOf( boja.slug ) !== -1;
                    var missingCls = hasFront ? '' : 'no-mockup';

                    html += '<label class="wiz-color-card ' + missingCls + ' ' + ( checked ? 'is-checked' : '' ) + '">';
                    html += '<input type="checkbox" class="boja-checkbox" data-tip="' + esc( tip.slug ) + '" value="' + esc( boja.slug ) + '" ' + ( checked ? 'checked' : '' ) + '>';

                    // Color swatch
                    if ( boja.hex ) {
                        html += '<span class="wiz-swatch" style="background:' + esc( boja.hex ) + ';"></span>';
                    } else {
                        html += '<span class="wiz-swatch swatch-empty"></span>';
                    }

                    // Mockup thumbnail
                    if ( mockup.front_thumb ) {
                        html += '<span class="wiz-mockup-thumb"><img src="' + esc( mockup.front_thumb ) + '" alt=""></span>';
                    } else {
                        html += '<span class="wiz-mockup-thumb wiz-mockup-missing">?</span>';
                    }

                    html += '<span class="wiz-color-name">' + esc( boja.name ) + '</span>';
                    if ( ! hasFront ) {
                        html += '<span class="wiz-no-mockup-badge" title="' + esc( i18n.no_mockup_warning ) + '">No mockup</span>';
                    }
                    html += '</label>';
                } );
            }

            html += '</div>';// .wiz-color-grid

            // Mockup library link if any missing
            var missingCount = ( cfg.bojas || [] ).filter( function ( b ) {
                return ! ( ( cfg.mockup_map || {} )[ tip.slug + '|' + b.slug ] || {} ).has_front;
            } ).length;

            if ( missingCount > 0 ) {
                html += '<p class="wiz-warn-inline">⚠ ' + missingCount + ' color(s) have no base image. '
                      + '<a href="' + esc( cfg.mockup_lib_url ) + '" target="_blank">' + esc( i18n.add_to_library ) + '</a></p>';
            }

            html += '</div>';// .wiz-tip-section
        } );

        html += '</div>';
        $body.html( html );

        // Toggle card class
        $body.on( 'change', '.boja-checkbox', function () {
            $( this ).closest( '.wiz-color-card' ).toggleClass( 'is-checked', this.checked );
        } );

        // Select all/none
        $body.on( 'click', '.boja-select-all', function () {
            var tipSlug = $( this ).data( 'tip' );
            $( '.boja-checkbox[data-tip="' + tipSlug + '"]' ).prop( 'checked', true )
                .closest( '.wiz-color-card' ).addClass( 'is-checked' );
        } );
        $body.on( 'click', '.boja-select-none', function () {
            var tipSlug = $( this ).data( 'tip' );
            $( '.boja-checkbox[data-tip="' + tipSlug + '"]' ).prop( 'checked', false )
                .closest( '.wiz-color-card' ).removeClass( 'is-checked' );
        } );
    }

    // ── Step 3 — Prices per tip ──────────────────────────────────────────────

    function renderStep3() {
        var html = '<div class="wizard-step" data-step="3">';
        html += '<h2 class="step-title">' + esc( i18n.step_labels[2] ) + '</h2>';
        html += '<p class="step-subtitle">Set prices for each product type. All colors and sizes within a type share the same price.</p>';

        state.selectedTips.forEach( function ( tip ) {
            var tipCfg = state.tipConfigs[ tip.slug ];

            html += '<div class="wiz-tip-section wiz-price-section">';
            html += '<h3 class="tip-section-title">' + esc( tip.name ) + '</h3>';
            html += '<div class="wiz-price-row">';

            html += '<div class="wiz-field wiz-field-inline">';
            html += '<label class="wiz-label" for="price-regular-' + esc( tip.slug ) + '">' + esc( i18n.regular_price_label ) + ' <span class="req">*</span></label>';
            html += '<div class="wiz-price-input">';
            html += '<span class="wiz-currency">' + ( get_woocommerce_currency_symbol() ) + '</span>';
            html += '<input type="number" id="price-regular-' + esc( tip.slug ) + '" class="price-regular" data-tip="' + esc( tip.slug ) + '" min="0" step="0.01" value="' + esc( tipCfg.regularPrice || '' ) + '">';
            html += '</div></div>';

            html += '<div class="wiz-field wiz-field-inline">';
            html += '<label class="wiz-label" for="price-sale-' + esc( tip.slug ) + '">' + esc( i18n.sale_price_label ) + '</label>';
            html += '<div class="wiz-price-input">';
            html += '<span class="wiz-currency">' + ( get_woocommerce_currency_symbol() ) + '</span>';
            html += '<input type="number" id="price-sale-' + esc( tip.slug ) + '" class="price-sale" data-tip="' + esc( tip.slug ) + '" min="0" step="0.01" value="' + esc( tipCfg.salePrice !== null ? tipCfg.salePrice : '' ) + '">';
            html += '</div></div>';

            html += '</div>';// .wiz-price-row
            html += '</div>';
        } );

        html += '</div>';
        $body.html( html );
    }

    // ── Step 4 — Sizes per tip ───────────────────────────────────────────────

    function renderStep4() {
        var html = '<div class="wizard-step" data-step="4">';
        html += '<h2 class="step-title">' + esc( i18n.step_labels[3] ) + '</h2>';

        state.selectedTips.forEach( function ( tip ) {
            var selectedSizes = state.tipConfigs[ tip.slug ].velicinas;
            html += '<div class="wiz-tip-section">';
            html += '<h3 class="tip-section-title">' + esc( tip.name ) + '</h3>';
            html += '<div class="wiz-quick-actions">';
            html += '<button type="button" class="button button-small size-select-all" data-tip="' + esc( tip.slug ) + '">' + esc( i18n.select_all ) + '</button>';
            html += '<button type="button" class="button button-small size-select-none" data-tip="' + esc( tip.slug ) + '">' + esc( i18n.select_none ) + '</button>';
            html += '</div>';
            html += '<div class="wiz-checkbox-grid wiz-size-grid">';

            if ( ! cfg.velicinas || ! cfg.velicinas.length ) {
                html += '<p class="wiz-warn">No sizes found in pa_velicina.</p>';
            } else {
                cfg.velicinas.forEach( function ( v ) {
                    var checked = selectedSizes.indexOf( v.slug ) !== -1;
                    html += '<label class="wiz-check-card wiz-size-card ' + ( checked ? 'is-checked' : '' ) + '">';
                    html += '<input type="checkbox" class="size-checkbox" data-tip="' + esc( tip.slug ) + '" value="' + esc( v.slug ) + '" ' + ( checked ? 'checked' : '' ) + '>';
                    html += '<span class="check-card-name">' + esc( v.name ) + '</span>';
                    html += '</label>';
                } );
            }

            html += '</div>';// .wiz-size-grid

            // Show variation count preview
            var colorCount = state.tipConfigs[ tip.slug ].bojas.length;
            var sizeCount  = selectedSizes.length;
            html += '<p class="wiz-variation-count" id="var-count-' + esc( tip.slug ) + '">'
                  + colorCount + ' × ' + sizeCount + ' = <strong>' + ( colorCount * sizeCount ) + ' ' + esc( i18n.variations_count ) + '</strong></p>';

            html += '</div>';
        } );

        html += '</div>';
        $body.html( html );

        // Update count on change
        $body.on( 'change', '.size-checkbox', function () {
            var tipSlug = $( this ).data( 'tip' );
            $( this ).closest( '.wiz-check-card' ).toggleClass( 'is-checked', this.checked );
            updateVariationCount( tipSlug );
        } );

        $body.on( 'click', '.size-select-all', function () {
            var tipSlug = $( this ).data( 'tip' );
            $( '.size-checkbox[data-tip="' + tipSlug + '"]' ).prop( 'checked', true )
                .closest( '.wiz-check-card' ).addClass( 'is-checked' );
            updateVariationCount( tipSlug );
        } );
        $body.on( 'click', '.size-select-none', function () {
            var tipSlug = $( this ).data( 'tip' );
            $( '.size-checkbox[data-tip="' + tipSlug + '"]' ).prop( 'checked', false )
                .closest( '.wiz-check-card' ).removeClass( 'is-checked' );
            updateVariationCount( tipSlug );
        } );
    }

    function updateVariationCount( tipSlug ) {
        var colorCount = state.tipConfigs[ tipSlug ] ? state.tipConfigs[ tipSlug ].bojas.length : 0;
        var sizeCount  = $( '.size-checkbox[data-tip="' + tipSlug + '"]:checked' ).length;
        $( '#var-count-' + tipSlug ).html(
            colorCount + ' × ' + sizeCount + ' = <strong>' + ( colorCount * sizeCount ) + ' ' + esc( i18n.variations_count ) + '</strong>'
        );
    }

    // ── Step 5 — Print design upload + positioning ───────────────────────────

    function renderStep5() {
        var html = '<div class="wizard-step" data-step="5">';
        html += '<h2 class="step-title">' + esc( i18n.step_labels[4] ) + '</h2>';

        // ── Upload section (shared) ──
        html += '<div class="wiz-upload-section">';
        html += '<div class="wiz-upload-pair">';

        // Front print
        html += '<div class="wiz-upload-slot" id="upload-slot-front">';
        html += '<div class="wiz-upload-label">' + esc( i18n.upload_front ) + ' <span class="req">*</span></div>';
        html += buildUploadSlotHTML( 'front', state.printFrontId, state.printFrontUrl );
        html += '</div>';

        // Back print
        html += '<div class="wiz-upload-slot" id="upload-slot-back">';
        html += '<div class="wiz-upload-label">' + esc( i18n.upload_back ) + '</div>';
        html += buildUploadSlotHTML( 'back', state.printBackId, state.printBackUrl );
        html += '</div>';

        html += '</div>';// .wiz-upload-pair
        html += '</div>';// .wiz-upload-section

        // ── Positioning per tip ──
        html += '<div class="wiz-position-section">';
        html += '<h3 class="wiz-position-heading">Print Positioning</h3>';
        html += '<p class="wiz-position-note">Set position for each product type. Preview updates in real time.</p>';

        state.selectedTips.forEach( function ( tip ) {
            var tipCfg = state.tipConfigs[ tip.slug ];
            var pf     = tipCfg.posFront || { x: 50, y: 25, width: 50 };
            var pb     = tipCfg.posBack  || { x: 50, y: 25, width: 50 };

            // Find first available mockup image for preview
            var previewFrontUrl = '';
            var previewBackUrl  = '';
            if ( tipCfg.bojas.length ) {
                var firstBoja = tipCfg.bojas[0];
                var mk = ( cfg.mockup_map || {} )[ tip.slug + '|' + firstBoja ] || {};
                previewFrontUrl = mk.front_url || '';
                previewBackUrl  = mk.back_url  || '';
            }

            html += '<div class="wiz-tip-position" data-tip="' + esc( tip.slug ) + '">';
            html += '<h4 class="tip-pos-title">' + esc( tip.name ) + '</h4>';
            html += '<p class="tip-pos-color-note">' + esc( i18n.preview_note ) + '</p>';

            // Front position
            html += '<div class="wiz-pos-panel" id="pos-panel-front-' + esc( tip.slug ) + '">';
            html += '<div class="pos-panel-label">' + esc( i18n.front_label ) + '</div>';
            html += '<div class="pos-panel-inner">';
            html += buildCanvasHTML( tip.slug, 'front', pf );
            html += buildPositionInputsHTML( tip.slug, 'front', pf );
            html += '</div></div>';

            // Back position (shown only if back print uploaded)
            html += '<div class="wiz-pos-panel wiz-pos-back" id="pos-panel-back-' + esc( tip.slug ) + '" '
                  + ( state.printBackId ? '' : 'style="display:none;"' ) + '>';
            html += '<div class="pos-panel-label">' + esc( i18n.back_label ) + '</div>';
            html += '<div class="pos-panel-inner">';
            html += buildCanvasHTML( tip.slug, 'back', pb );
            html += buildPositionInputsHTML( tip.slug, 'back', pb );
            html += '</div></div>';

            html += '</div>';// .wiz-tip-position
        } );

        html += '</div>';// .wiz-position-section
        html += '</div>';

        $body.html( html );

        // Draw initial canvases
        state.selectedTips.forEach( function ( tip ) {
            var tipCfg = state.tipConfigs[ tip.slug ];
            var firstBoja = tipCfg.bojas[0] || '';
            var mk = ( cfg.mockup_map || {} )[ tip.slug + '|' + firstBoja ] || {};

            drawCanvas( tip.slug, 'front', mk.front_url || '', state.printFrontUrl,
                tipCfg.posFront || { x:50, y:25, width:50 } );

            if ( state.printBackId ) {
                drawCanvas( tip.slug, 'back', mk.back_url || '', state.printBackUrl,
                    tipCfg.posBack || { x:50, y:25, width:50 } );
            }
        } );

        bindStep5Events();
    }

    function buildUploadSlotHTML( side, imgId, imgUrl ) {
        var html = '<div class="wiz-print-preview ' + ( imgId ? 'has-image' : '' ) + '" id="print-preview-' + side + '">';
        if ( imgUrl ) {
            html += '<img src="' + esc( imgUrl ) + '" alt="">';
            html += '<button type="button" class="print-remove" data-side="' + side + '">✕</button>';
        } else {
            html += '<span class="dashicons dashicons-format-image"></span>';
        }
        html += '</div>';
        html += '<input type="file" id="print-file-' + side + '" accept="image/png" style="display:none;">';
        html += '<button type="button" class="button print-upload-btn" data-side="' + side + '">'
              + ( imgId ? esc( i18n.change ) : esc( 'Upload PNG' ) ) + '</button>';
        html += '<span class="print-upload-status" id="print-status-' + side + '"></span>';
        return html;
    }

    function buildCanvasHTML( tipSlug, side, pos ) {
        return '<div class="wiz-canvas-wrap">'
             + '<canvas id="canvas-' + side + '-' + esc( tipSlug ) + '" class="wiz-canvas"></canvas>'
             + '<div class="canvas-no-base" id="no-base-' + side + '-' + esc( tipSlug ) + '" style="display:none;">'
             + esc( i18n.no_preview ) + '</div>'
             + '</div>';
    }

    function buildPositionInputsHTML( tipSlug, side, pos ) {
        var html = '<div class="wiz-pos-inputs">';
        [ ['x','X (%)', -100, 100], ['y','Y (%)', -100, 100], ['width','Width (%)', 1, 100] ].forEach( function ( f ) {
            html += '<div class="wiz-pos-field">';
            html += '<label>' + f[1] + '</label>';
            html += '<input type="number" id="pos-' + side + '-' + f[0] + '-' + esc( tipSlug ) + '" '
                  + 'class="pos-input" data-tip="' + esc( tipSlug ) + '" data-side="' + side + '" data-axis="' + f[0] + '" '
                  + 'value="' + esc( pos[ f[0] ] ) + '" min="' + f[2] + '" max="' + f[3] + '" step="1">';
            html += '</div>';
        } );
        html += '</div>';
        return html;
    }

    function bindStep5Events() {
        // File input trigger
        $body.on( 'click', '.print-upload-btn', function () {
            var side = $( this ).data( 'side' );
            $( '#print-file-' + side ).trigger( 'click' );
        } );

        // File chosen — upload via AJAX
        $body.on( 'change', '#print-file-front, #print-file-back', function () {
            var side = this.id === 'print-file-front' ? 'front' : 'back';
            if ( this.files && this.files[0] ) {
                uploadPrintFile( side, this.files[0] );
            }
        } );

        // Remove print
        $body.on( 'click', '.print-remove', function () {
            var side = $( this ).data( 'side' );
            removePrint( side );
        } );

        // Position input → redraw canvas
        $body.on( 'input change', '.pos-input', function () {
            var tipSlug = $( this ).data( 'tip' );
            var side    = $( this ).data( 'side' );
            var pos     = readPosInputs( tipSlug, side );
            var firstBoja = state.tipConfigs[ tipSlug ].bojas[0] || '';
            var mk      = ( cfg.mockup_map || {} )[ tipSlug + '|' + firstBoja ] || {};
            var baseUrl = side === 'front' ? mk.front_url || '' : mk.back_url || '';
            var printUrl = side === 'front' ? state.printFrontUrl : state.printBackUrl;
            drawCanvas( tipSlug, side, baseUrl, printUrl, pos );
        } );
    }

    function readPosInputs( tipSlug, side ) {
        return {
            x    : parseInt( $( '#pos-' + side + '-x-'     + tipSlug ).val(), 10 ) || 50,
            y    : parseInt( $( '#pos-' + side + '-y-'     + tipSlug ).val(), 10 ) || 25,
            width: parseInt( $( '#pos-' + side + '-width-' + tipSlug ).val(), 10 ) || 50,
        };
    }

    function uploadPrintFile( side, file ) {
        var $status = $( '#print-status-' + side );
        $status.text( i18n.uploading );

        var formData = new FormData();
        formData.append( 'action',      'thready_wizard_upload_print' );
        formData.append( '_ajax_nonce', cfg.nonce );
        formData.append( 'file',        file );

        $.ajax( {
            url        : cfg.ajax_url,
            type       : 'POST',
            data       : formData,
            processData: false,
            contentType: false,
        } )
        .done( function ( res ) {
            if ( res.success ) {
                if ( side === 'front' ) {
                    state.printFrontId  = res.data.attachment_id;
                    state.printFrontUrl = res.data.url;
                } else {
                    state.printBackId  = res.data.attachment_id;
                    state.printBackUrl = res.data.url;
                }

                updatePrintPreview( side, res.data.thumb_url, res.data.url );
                $status.text( '' );

                // Redraw all canvases for this side
                state.selectedTips.forEach( function ( tip ) {
                    var firstBoja = state.tipConfigs[ tip.slug ].bojas[0] || '';
                    var mk = ( cfg.mockup_map || {} )[ tip.slug + '|' + firstBoja ] || {};
                    var baseUrl = side === 'front' ? mk.front_url || '' : mk.back_url || '';
                    var pos = readPosInputs( tip.slug, side );
                    drawCanvas( tip.slug, side, baseUrl, res.data.url, pos );
                } );

                // Show/hide back positioning panels
                if ( side === 'back' ) {
                    $( '.wiz-pos-back' ).show();
                }
            } else {
                $status.text( res.data && res.data.message ? res.data.message : 'Upload failed' );
            }
        } )
        .fail( function () { $status.text( 'Upload failed' ); } );
    }

    function updatePrintPreview( side, thumbUrl, fullUrl ) {
        var $preview = $( '#print-preview-' + side );
        $preview.addClass( 'has-image' ).html(
            '<img src="' + esc( thumbUrl ) + '" alt="">'
            + '<button type="button" class="print-remove" data-side="' + side + '">✕</button>'
        );
        $( '.print-upload-btn[data-side="' + side + '"]' ).text( i18n.change );
    }

    function removePrint( side ) {
        if ( side === 'front' ) {
            state.printFrontId  = 0;
            state.printFrontUrl = '';
        } else {
            state.printBackId  = 0;
            state.printBackUrl = '';
        }
        var $preview = $( '#print-preview-' + side );
        $preview.removeClass( 'has-image' ).html( '<span class="dashicons dashicons-format-image"></span>' );
        $( '.print-upload-btn[data-side="' + side + '"]' ).text( 'Upload PNG' );

        // Re-draw canvases without print
        if ( side === 'front' || side === 'back' ) {
            state.selectedTips.forEach( function ( tip ) {
                var firstBoja = state.tipConfigs[ tip.slug ].bojas[0] || '';
                var mk = ( cfg.mockup_map || {} )[ tip.slug + '|' + firstBoja ] || {};
                var baseUrl = side === 'front' ? mk.front_url || '' : mk.back_url || '';
                var pos = readPosInputs( tip.slug, side );
                drawCanvas( tip.slug, side, baseUrl, '', pos );
            } );

            if ( side === 'back' ) {
                $( '.wiz-pos-back' ).hide();
            }
        }
    }

    // ── Canvas drawing ───────────────────────────────────────────────────────

    function drawCanvas( tipSlug, side, baseUrl, printUrl, pos ) {
        var canvasId = 'canvas-' + side + '-' + tipSlug;
        var canvas   = document.getElementById( canvasId );
        if ( ! canvas ) return;

        var ctx      = canvas.getContext( '2d' );
        var $noBase  = $( '#no-base-' + side + '-' + tipSlug );

        if ( ! baseUrl ) {
            $( canvas ).hide();
            $noBase.show();
            return;
        }

        $( canvas ).show();
        $noBase.hide();

        var baseImg = new Image();
        baseImg.crossOrigin = 'anonymous';

        baseImg.onload = function () {
            // Scale canvas to fit container (max 300px wide)
            var maxW   = Math.min( 300, baseImg.width );
            var scale  = maxW / baseImg.width;
            canvas.width  = Math.round( baseImg.width  * scale );
            canvas.height = Math.round( baseImg.height * scale );

            ctx.clearRect( 0, 0, canvas.width, canvas.height );
            ctx.drawImage( baseImg, 0, 0, canvas.width, canvas.height );

            if ( ! printUrl ) return;

            var printImg = new Image();
            printImg.crossOrigin = 'anonymous';

            printImg.onload = function () {
                // Mirror the PHP GD positioning logic exactly
                var targetW = Math.round( ( pos.width / 100 ) * canvas.width );
                var targetH = Math.round( ( targetW * printImg.height ) / printImg.width );

                var posX, posY;
                if ( pos.x > 0 ) {
                    posX = Math.round( ( pos.x / 100 ) * canvas.width ) - Math.round( targetW / 2 );
                } else {
                    posX = Math.round( ( pos.x / 100 ) * canvas.width ) + Math.round( targetW / 2 );
                }
                posY = Math.round( ( pos.y / 100 ) * canvas.height );

                ctx.drawImage( printImg, posX, posY, targetW, targetH );
            };

            printImg.onerror = function () { /* silently skip */ };
            printImg.src = printUrl + '?nocache=' + Date.now();
        };

        baseImg.onerror = function () {
            $( canvas ).hide();
            $noBase.show();
        };

        baseImg.src = baseUrl + '?nocache=' + Date.now();
    }

    // ── Step 6 — Review & Create ─────────────────────────────────────────────

    function renderStep6() {
        var totalVariations = 0;
        state.selectedTips.forEach( function ( tip ) {
            var tc = state.tipConfigs[ tip.slug ];
            totalVariations += tc.bojas.length * tc.velicinas.length;
        } );

        var html = '<div class="wizard-step" data-step="6">';
        html += '<h2 class="step-title">' + esc( i18n.step_labels[5] ) + '</h2>';
        html += '<p class="step-subtitle">Review your configuration below. Click <strong>'
              + esc( cfg.edit_data ? i18n.update : i18n.create ) + '</strong> to create the products.</p>';

        // Summary card
        html += '<div class="wiz-review-card">';
        html += '<div class="review-row"><span class="review-label">Design</span><span class="review-val">' + esc( state.designName ) + '</span></div>';
        html += '<div class="review-row"><span class="review-label">Products</span><span class="review-val">' + state.selectedTips.length + ' (' + state.selectedTips.map( function (t) { return t.name; } ).join( ', ' ) + ')</span></div>';
        html += '<div class="review-row"><span class="review-label">Total Variations</span><span class="review-val"><strong>' + totalVariations + '</strong></span></div>';
        html += '<div class="review-row"><span class="review-label">Front Print</span><span class="review-val">'
              + ( state.printFrontId ? '✓ uploaded' : '<span class="wiz-missing">missing</span>' ) + '</span></div>';
        html += '<div class="review-row"><span class="review-label">Back Print</span><span class="review-val">'
              + ( state.printBackId ? '✓ uploaded' : 'none (front only)' ) + '</span></div>';
        html += '</div>';

        // Per-tip breakdown
        state.selectedTips.forEach( function ( tip ) {
            var tc = state.tipConfigs[ tip.slug ];
            html += '<div class="wiz-review-tip">';
            html += '<div class="review-tip-header">' + esc( tip.name ) + '</div>';
            html += '<div class="review-tip-body">';
            html += '<div class="review-row"><span class="review-label">Colors</span><span class="review-val">' + tc.bojas.length + ' selected</span></div>';
            html += '<div class="review-row"><span class="review-label">Sizes</span><span class="review-val">' + tc.velicinas.map( function (v) { var term = (cfg.velicinas||[]).find( function(t) { return t.slug === v; }); return term ? term.name : v; } ).join( ', ' ) + '</span></div>';
            html += '<div class="review-row"><span class="review-label">Regular Price</span><span class="review-val">' + get_woocommerce_currency_symbol() + tc.regularPrice + '</span></div>';
            if ( tc.salePrice !== null ) {
                html += '<div class="review-row"><span class="review-label">Sale Price</span><span class="review-val">' + get_woocommerce_currency_symbol() + tc.salePrice + '</span></div>';
            }
            html += '<div class="review-row"><span class="review-label">Variations</span><span class="review-val"><strong>' + ( tc.bojas.length * tc.velicinas.length ) + '</strong></span></div>';
            html += '</div></div>';
        } );

        // Result area (populated after create)
        html += '<div id="wizard-result" class="wizard-result" style="display:none;"></div>';

        html += '</div>';
        $body.html( html );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Create products (AJAX)
    // ─────────────────────────────────────────────────────────────────────────

    function createProducts() {
        $btnNext.prop( 'disabled', true ).text( i18n.creating );
        clearError();

        var tipsList = state.selectedTips.map( function ( tip ) {
            var tc = state.tipConfigs[ tip.slug ];
            return {
                tip_slug      : tip.slug,
                boja_slugs    : tc.bojas,
                velicina_slugs: tc.velicinas,
                regular_price : tc.regularPrice,
                sale_price    : tc.salePrice,
                pos_front     : tc.posFront,
                pos_back      : tc.posBack,
            };
        } );

        var payload = {
            design_name   : state.designName,
            print_front_id: state.printFrontId,
            print_back_id : state.printBackId || 0,
            tips          : tipsList,
        };

        $.post( cfg.ajax_url, {
            action      : 'thready_wizard_create',
            _ajax_nonce : cfg.nonce,
            payload     : JSON.stringify( payload ),
        } )
        .done( function ( res ) {
            $btnNext.prop( 'disabled', false );

            if ( res.success ) {
                showCreateResults( res.data.results );
            } else {
                $btnNext.text( i18n.create );
                showError( res.data && res.data.message ? res.data.message : 'Create failed.' );
                if ( res.data && res.data.results ) {
                    showCreateResults( res.data.results );
                }
            }
        } )
        .fail( function () {
            $btnNext.prop( 'disabled', false ).text( i18n.create );
            showError( 'Server error. Please try again.' );
        } );
    }

    function showCreateResults( results ) {
        var $result = $( '#wizard-result' ).show();
        var html = '<h3 class="result-title">Results</h3>';

        results.forEach( function ( r ) {
            if ( r.success ) {
                html += '<div class="result-item result-ok">';
                html += '✓ <strong>' + esc( r.tip_name ) + '</strong> — '
                      + r.variation_count + ' variations created. ';
                html += '<a href="' + esc( r.edit_url ) + '" class="button button-small">Edit</a> ';
                html += '<a href="' + esc( r.view_url ) + '" class="button button-small" target="_blank">View</a>';
                html += '</div>';
            } else {
                html += '<div class="result-item result-err">';
                html += '✗ <strong>' + esc( r.tip_name ) + '</strong> — ' + esc( r.message );
                html += '</div>';
            }
        } );

        var allOk = results.every( function (r) { return r.success; } );
        if ( allOk ) {
            html += '<a href="' + esc( cfg.products_url ) + '" class="button button-primary result-back-btn">← Back to Products</a>';
            $btnNext.hide();
            $btnBack.hide();
        }

        $result.html( html );
        $result[0].scrollIntoView( { behavior: 'smooth' } );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Utility
    // ─────────────────────────────────────────────────────────────────────────

    function showError( msg ) {
        $errMsg.text( msg ).show();
    }

    function clearError() {
        $errMsg.text( '' ).hide();
    }

    function esc( str ) {
        return $( '<span>' ).text( str ).html();
    }

    function get_woocommerce_currency_symbol() {
        // WooCommerce doesn't expose this to JS by default.
        // We output it from PHP as a data attribute on the wizard container.
        return $( '#thready-wizard' ).data( 'currency' ) || '€';
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Button handlers
    // ─────────────────────────────────────────────────────────────────────────

    $btnNext.on( 'click', function () {
        if ( state.step === 6 ) {
            createProducts();
            return;
        }

        if ( validate( state.step ) ) {
            goTo( state.step + 1 );
        }
    } );

    $btnBack.on( 'click', function () {
        if ( state.step > 1 ) {
            goTo( state.step - 1 );
        }
    } );

    // Allow clicking done steps to jump back
    $( document ).on( 'click', '.wizard-step-indicator.done', function () {
        var n = parseInt( $( this ).data( 'step' ), 10 );
        if ( n < state.step ) goTo( n );
    } );

    // ─────────────────────────────────────────────────────────────────────────
    // Init
    // ─────────────────────────────────────────────────────────────────────────

    // Pass currency symbol from PHP via data attribute
    ( function injectCurrencySymbol() {
        // We need to add this to the wizard HTML via PHP — see render_page()
        // For now, detect from WooCommerce localized data if available
        if ( window.woocommerce_params && window.woocommerce_params.currency_symbol ) {
            $( '#thready-wizard' ).data( 'currency', window.woocommerce_params.currency_symbol );
        }
    }() );

    goTo( 1 );

}( jQuery ) );
