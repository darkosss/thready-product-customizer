/**
 * Thready Product Wizard — wizard.js
 *
 * Dynamic multi-step wizard. Steps are built after Step 1 based on
 * how many product types are selected.
 *
 * Flow:
 *   Step 1   : Setup — name + types + print uploads (front, light, back)
 *   Steps 2…N: Colors per type  (one step per type)
 *   Steps …  : Sizes  per type  (one step per type)
 *   Step     : Pricing          (all types, one step)
 *   Step     : Positioning      (all types, front + back if back uploaded)
 *   Last     : Review & Create
 */
/* global threadyWizard, wp, jQuery */
(function ( $ ) {
    'use strict';

    var d    = window.threadyWizard || {};
    var i18n = d.i18n || {};

    // ── State ─────────────────────────────────────────────────────────────────
    var S = {
        name         : '',
        tipSlugs     : [],
        printFrontId : 0, printFrontUrl : '', printFrontThumb : '',
        printLightId : 0, printLightUrl : '', printLightThumb : '',
        printBackId  : 0, printBackUrl  : '', printBackThumb  : '',
        tipColors    : {},
        tipSizes     : {},
        tipPrices    : {},
        tipPositions : {},
        featuredTipSlug  : '',
        featuredBojaSlug : '',
        featuredSide     : 'front',
        dynamicSteps : [],
        stepIndex    : 0,
    };

    // Pre-fill from edit mode
    if ( d.edit_data ) {
        var ed       = d.edit_data;
        S.name        = ed.product_name   || '';
        S.tipSlugs    = ed.tips           || [];
        S.tipPrices   = ed.tip_prices     || {};
        S.tipPositions = ed.tip_positions || {};
        S.printFrontId = ed.print_front_id || 0;
        S.printLightId = ed.print_light_id || 0;
        S.printBackId  = ed.print_back_id  || 0;
        if ( ed.tip_colors ) S.tipColors = ed.tip_colors;
        if ( ed.tip_sizes  ) S.tipSizes  = ed.tip_sizes;
    }

    // ── DOM ───────────────────────────────────────────────────────────────────
    var $body    = $( '#wizard-body' );
    var $btnBack = $( '#wizard-btn-back' );
    var $btnNext = $( '#wizard-btn-next' );
    var $errMsg  = $( '#wizard-error-msg' );

    // wp.media frames, keyed by side
    var mediaFrames = {};

    // ── Dynamic step definitions ──────────────────────────────────────────────

    function buildDynamicSteps() {
        var steps = [ { id: 'setup', type: 'setup', label: 'Setup', sublabel: '' } ];

        // One step per type — colors + sizes merged
        S.tipSlugs.forEach( function ( t ) {
            var tip = getTip( t );
            steps.push( { id: 'type-' + t, type: 'type_config', tipSlug: t, tipName: tip.name, label: tip.name, sublabel: '' } );
        } );

        steps.push( { id: 'pricing',       type: 'pricing',       label: 'Pricing', sublabel: '' } );
        steps.push( { id: 'positioning',   type: 'positioning',   label: 'Design',  sublabel: '' } );
        steps.push( { id: 'product_image', type: 'product_image', label: 'Image',   sublabel: '' } );
        steps.push( { id: 'review',        type: 'review',        label: 'Review',  sublabel: '' } );

        S.dynamicSteps = steps;
    }

    function rebuildStepIndicator() {
        var $ol = $( '#wizard-steps' );
        $ol.empty();

        S.dynamicSteps.forEach( function ( step, i ) {
            var $li = $( '<li class="wizard-step-indicator"></li>' )
                .addClass( i === S.stepIndex ? 'active' : ( i < S.stepIndex ? 'done' : '' ) )
                .attr( 'data-step-index', i );

            $li.append( '<span class="step-num">' + ( i + 1 ) + '</span>' );
            var labelHtml = '<span class="step-label">' + esc( step.label );
            if ( step.sublabel ) labelHtml += '<br><small>' + esc( step.sublabel ) + '</small>';
            labelHtml += '</span>';
            $li.append( labelHtml );

            $ol.append( $li );
        } );
    }

    // ── Navigation ────────────────────────────────────────────────────────────

    function goTo( index ) {
        if ( index < 0 || index >= S.dynamicSteps.length ) return;

        S.stepIndex = index;
        var step   = S.dynamicSteps[ index ];
        var isLast = index === S.dynamicSteps.length - 1;

        // Update step indicators
        $( '.wizard-step-indicator' ).each( function () {
            var i = parseInt( $( this ).data( 'step-index' ), 10 );
            $( this )
                .toggleClass( 'active', i === index )
                .toggleClass( 'done',   i <  index  );
        } );

        // Clear body events before re-rendering to prevent accumulation
        $body.off( '.wiz' );

        // Render step content
        switch ( step.type ) {
            case 'setup':       renderSetup();                                  break;
            case 'type_config': renderTypeConfig( step.tipSlug, step.tipName ); break;
            case 'pricing':     renderPricing();                                break;
            case 'positioning':   renderPositioning();   break;
            case 'product_image': renderProductImage();  break;
            case 'review':        renderReview();        break;
        }

        $btnBack.toggle( index > 0 );
        $btnNext.text( step.type === 'review'
            ? ( d.edit_data ? 'Save Changes' : 'Create Product' )
            : 'Next →'
        ).prop( 'disabled', false );

        $errMsg.text( '' );
        window.scrollTo( 0, 0 );
    }

    // ── Proceed / validate ────────────────────────────────────────────────────

    function proceed() {
        var step = S.dynamicSteps[ S.stepIndex ];

        // Only create when explicitly on the review step
        if ( step.type === 'review' ) { createProduct(); return; }

        var ok = validate( step );
        if ( ok ) goTo( S.stepIndex + 1 );
    }

    function validate( step ) {
        $errMsg.text( '' );

        switch ( step.type ) {

            case 'setup':
                S.name     = $( '#wiz-name' ).val().trim();
                S.tipSlugs = [];
                $( '.tip-cb:checked' ).each( function () { S.tipSlugs.push( $( this ).val() ); } );

                if ( ! S.name )            return err( 'Enter a product name.' );
                if ( ! S.tipSlugs.length )  return err( 'Select at least one product type.' );
                if ( ! S.printFrontId )     return err( 'Upload or select a front print image.' );

                // Init per-type state for any newly selected types
                S.tipSlugs.forEach( function ( t ) {
                    if ( ! S.tipColors[ t ] )    S.tipColors[ t ]    = {};
                    if ( ! S.tipSizes[ t ] )      S.tipSizes[ t ]      = [];
                    if ( ! S.tipPrices[ t ] )     S.tipPrices[ t ]     = { regular: '', sale: null };
                    if ( ! S.tipPositions[ t ] )  S.tipPositions[ t ]  = { front: { x:50, y:25, width:50 }, back: null };
                } );

                buildDynamicSteps();
                rebuildStepIndicator();
                return true;

            case 'type_config': {
                collectColors( step.tipSlug );
                collectSizes( step.tipSlug );
                var sel = Object.keys( S.tipColors[ step.tipSlug ] || {} )
                    .filter( function ( b ) { return S.tipColors[ step.tipSlug ][ b ].selected; } );
                if ( ! sel.length ) return err( 'Select at least one color for ' + step.tipName + '.' );
                if ( ! S.tipSizes[ step.tipSlug ] || ! S.tipSizes[ step.tipSlug ].length ) {
                    return err( 'Select at least one size for ' + step.tipName + '.' );
                }
                return true;
            }

            case 'pricing':
                collectPricing();
                for ( var i = 0; i < S.tipSlugs.length; i++ ) {
                    var t = S.tipSlugs[ i ];
                    if ( ! S.tipPrices[ t ] || ! S.tipPrices[ t ].regular || S.tipPrices[ t ].regular <= 0 ) {
                        return err( 'Enter a regular price for each product type.' );
                    }
                }
                return true;

            case 'positioning':
                collectPositioning();
                return true;

            case 'product_image':
                if ( ! S.featuredTipSlug || ! S.featuredBojaSlug ) {
                    return err( 'Select a product image.' );
                }
                return true;

            default:
                return true;
        }
    }

    function err( msg ) { $errMsg.text( msg ); return false; }

    // ── Collect helpers ───────────────────────────────────────────────────────

    function collectColors( tipSlug ) {
        if ( ! S.tipColors[ tipSlug ] ) S.tipColors[ tipSlug ] = {};

        $body.find( '.boja-cb' ).each( function () {
            var slug       = $( this ).val();
            var selected   = this.checked;
            var lightPrint = false;

            if ( selected && S.printLightId ) {
                lightPrint = $body.find( '#lp-' + tipSlug + '-' + slug ).is( ':checked' );
            }

            S.tipColors[ tipSlug ][ slug ] = { selected: selected, lightPrint: lightPrint };
        } );
    }

    function collectSizes( tipSlug ) {
        S.tipSizes[ tipSlug ] = [];
        $body.find( '.size-cb:checked' ).each( function () {
            S.tipSizes[ tipSlug ].push( $( this ).val() );
        } );
    }

    function collectPricing() {
        S.tipSlugs.forEach( function ( t ) {
            var reg  = parseFloat( $( '#reg-'  + t ).val() ) || 0;
            var sale = $( '#sale-' + t ).val().trim();
            S.tipPrices[ t ] = { regular: reg, sale: sale !== '' ? parseFloat( sale ) : null };
        } );
    }

    function collectPositioning() {
        S.tipSlugs.forEach( function ( t ) {
            S.tipPositions[ t ] = {
                front: readPos( t, 'front' ),
                back : S.printBackId ? readPos( t, 'back' ) : null,
            };
        } );
    }

    // ── SETUP (Step 1) ────────────────────────────────────────────────────────

    function renderSetup() {
        var h = '<div class="wizard-step">';
        h += '<h2 class="step-title">Setup</h2>';

        // Product name
        h += '<div class="wiz-field">';
        h += '<label class="wiz-label" for="wiz-name">Product Name <span class="req">*</span></label>';
        h += '<input type="text" id="wiz-name" class="regular-text" value="' + esc( S.name ) + '" placeholder="e.g. Zmaj, Akira, Rose Pattern…">';
        h += '</div>';

        // Product types
        h += '<div class="wiz-field">';
        h += '<label class="wiz-label">Product Types <span class="req">*</span></label>';
        h += '<p class="wiz-hint">Each type can have its own colors, sizes and price. Colors and sizes are configured per type in the next steps.</p>';
        h += '<div class="wiz-checkbox-grid">';
        ( d.tips || [] ).forEach( function ( t ) {
            var chk = S.tipSlugs.indexOf( t.slug ) !== -1;
            h += '<label class="wiz-check-card ' + ( chk ? 'is-checked' : '' ) + '">';
            h += '<input type="checkbox" class="tip-cb" value="' + esc( t.slug ) + '" ' + ( chk ? 'checked' : '' ) + '>';
            h += '<span class="check-card-name">' + esc( t.name ) + '</span>';
            h += '</label>';
        } );
        h += '</div></div>';

        // Print images
        h += '<div class="wiz-field">';
        h += '<label class="wiz-label">Print Images</label>';
        h += '<div class="wiz-print-uploads">';
        h += buildPrintUploadRow( 'front', S.printFrontId, S.printFrontThumb,
            'Print Design Image <span class="req">*</span>',
            'Upload a transparent PNG of the print design.' );
        h += buildPrintUploadRow( 'light', S.printLightId, S.printLightThumb,
            'Light Print Design Image',
            'Upload a transparent PNG of the light print design (optional). Used for light-coloured garments.' );
        h += buildPrintUploadRow( 'back', S.printBackId, S.printBackThumb,
            'Back Print Design Image',
            'Upload a transparent PNG of the back print design (optional).' );
        h += '</div></div>';

        h += '</div>';
        $body.html( h );

        // Bind events — using .wiz namespace so $body.off('.wiz') cleans them up
        $body.on( 'change.wiz', '.tip-cb', function () {
            $( this ).closest( '.wiz-check-card' ).toggleClass( 'is-checked', this.checked );
        } );

        // Single button → open WP media library (handles both select + upload)
        $body.on( 'click.wiz', '.print-media-btn', function () {
            openMediaPicker( $( this ).data( 'side' ) );
        } );

        // Remove
        $body.on( 'click.wiz', '.print-remove-btn', function () {
            removePrint( $( this ).data( 'side' ) );
        } );

        // Focus name field
        setTimeout( function () { $( '#wiz-name' ).focus(); }, 50 );
    }

    function buildPrintUploadRow( side, imgId, thumbUrl, label, description ) {
        var h = '<div class="wiz-print-upload-row">';
        h += '<div class="wiz-print-upload-info">';
        h += '<div class="wiz-print-upload-label">' + label + '</div>';
        h += '<p class="wiz-hint">' + esc( description ) + '</p>';
        h += '</div>';
        h += '<div class="wiz-print-upload-controls">';

        // Preview thumbnail
        h += '<div class="wiz-print-preview ' + ( imgId ? 'has-image' : '' ) + '" id="prev-' + side + '">';
        if ( imgId && thumbUrl ) {
            h += '<img src="' + esc( thumbUrl ) + '" alt="">';
            h += '<button type="button" class="print-remove-btn" data-side="' + side + '" title="Remove">✕</button>';
        } else {
            h += '<span class="dashicons dashicons-format-image"></span>';
        }
        h += '</div>';

        // Single button — opens WP media library (select or upload from within)
        h += '<button type="button" class="button print-media-btn" data-side="' + side + '">';
        h += imgId ? 'Change Image' : 'Select / Upload';
        h += '</button>';

        h += '</div></div>';
        return h;
    }

    // ── WP Media picker (select or upload — single entry point) ───────────────

    function openMediaPicker( side ) {
        var titles = { front: 'Front Print Image', light: 'Light Print Image', back: 'Back Print Image' };

        if ( ! mediaFrames[ side ] ) {
            mediaFrames[ side ] = wp.media( {
                title    : titles[ side ] || 'Select Print Image',
                button   : { text: 'Use this image' },
                multiple : false,
                library  : { type: 'image' },
            } );
        }

        var frame = mediaFrames[ side ];
        frame.off( 'select' );
        frame.on( 'select', function () {
            var att      = frame.state().get( 'selection' ).first().toJSON();
            var thumbUrl = att.sizes && att.sizes.thumbnail ? att.sizes.thumbnail.url : att.url;
            setPrintImage( side, att.id, att.url, thumbUrl );
        } );

        frame.open();
    }

    function setPrintImage( side, id, url, thumbUrl ) {
        if ( side === 'front' ) { S.printFrontId = id; S.printFrontUrl = url; S.printFrontThumb = thumbUrl; }
        if ( side === 'light' ) { S.printLightId = id; S.printLightUrl = url; S.printLightThumb = thumbUrl; }
        if ( side === 'back'  ) { S.printBackId  = id; S.printBackUrl  = url; S.printBackThumb  = thumbUrl; }

        $( '#prev-' + side )
            .addClass( 'has-image' )
            .html( '<img src="' + esc( thumbUrl ) + '" alt="">'
                 + '<button type="button" class="print-remove-btn" data-side="' + side + '" title="Remove">✕</button>' );
        $( '.print-media-btn[data-side="' + side + '"]' ).text( 'Change Image' );
    }

    function removePrint( side ) {
        if ( side === 'front' ) { S.printFrontId = 0; S.printFrontUrl = ''; S.printFrontThumb = ''; }
        if ( side === 'light' ) { S.printLightId = 0; S.printLightUrl = ''; S.printLightThumb = ''; }
        if ( side === 'back'  ) { S.printBackId  = 0; S.printBackUrl  = ''; S.printBackThumb  = ''; }

        $( '#prev-' + side ).removeClass( 'has-image' )
            .html( '<span class="dashicons dashicons-format-image"></span>' );
        $( '.print-media-btn[data-side="' + side + '"]' ).text( 'Select / Upload' );
    }

    // ── COLORS (one step per type) ────────────────────────────────────────────

    function renderColors( tipSlug, tipName ) {
        var tipColorState = S.tipColors[ tipSlug ] || {};
        var hasLight      = S.printLightId > 0;
        var hasBack       = S.printBackId  > 0;

        var h = '<div class="wizard-step">';
        h += '<h2 class="step-title">' + esc( tipName ) + ' — Colors</h2>';
        h += '<p class="step-subtitle">Select which colors are available for ' + esc( tipName ) + '.</p>';

        if ( hasLight ) {
            h += '<p class="wiz-info-note">💡 Light print uploaded — check <strong>Light print</strong> on colors where the lighter design should be used.</p>';
        }

        h += '<div class="wiz-quick-actions">';
        h += '<button type="button" class="button button-small boja-all-btn">All</button>';
        h += '<button type="button" class="button button-small boja-none-btn">None</button>';
        h += '</div>';

        h += '<div class="wiz-color-grid-full">';

        ( d.bojas || [] ).forEach( function ( b ) {
            var colorState = tipColorState[ b.slug ] || { selected: false, lightPrint: false };
            var chk        = colorState.selected;
            var lp         = colorState.lightPrint;
            var mk         = ( d.mockup_map || {} )[ tipSlug + '|' + b.slug ] || {};

            h += '<div class="wiz-color-card-wrap ' + ( chk ? 'is-selected' : '' ) + '">';

            h += '<label class="wiz-color-card ' + ( chk ? 'is-checked' : '' ) + ( ! mk.has_front ? ' no-mockup' : '' ) + '">';
            h += '<input type="checkbox" class="boja-cb" data-tip="' + esc( tipSlug ) + '" value="' + esc( b.slug ) + '" ' + ( chk ? 'checked' : '' ) + '>';

            if ( b.hex ) h += '<span class="wiz-swatch" style="background:' + esc( b.hex ) + ';"></span>';
            else         h += '<span class="wiz-swatch swatch-empty"></span>';

            if ( mk.front_thumb ) {
                h += '<span class="wiz-mockup-thumb"><img src="' + esc( mk.front_thumb ) + '" alt="" title="Front base image"></span>';
            } else {
                h += '<span class="wiz-mockup-thumb wiz-mockup-missing" title="No front base image">?</span>';
            }

            if ( hasBack ) {
                if ( mk.has_back ) {
                    h += '<span class="wiz-mockup-thumb wiz-mockup-back-thumb" title="Back base image available">✓B</span>';
                } else {
                    h += '<span class="wiz-mockup-thumb wiz-mockup-missing" title="No back base image">?B</span>';
                }
            }

            h += '<span class="wiz-color-name">' + esc( b.name ) + '</span>';
            if ( ! mk.has_front ) h += '<span class="wiz-no-mockup-badge">!</span>';
            h += '</label>';

            if ( hasLight ) {
                h += '<label class="wiz-light-print-label" id="lp-wrap-' + esc( tipSlug ) + '-' + esc( b.slug ) + '" '
                   + 'style="' + ( chk ? '' : 'display:none;' ) + '">';
                h += '<input type="checkbox" id="lp-' + esc( tipSlug ) + '-' + esc( b.slug ) + '" ' + ( lp ? 'checked' : '' ) + '>';
                h += ' Light print';
                h += '</label>';
            }

            h += '</div>'; // .wiz-color-card-wrap
        } );

        h += '</div>'; // .wiz-color-grid-full

        var missingCount = ( d.bojas || [] ).filter( function ( b ) {
            return ! ( ( d.mockup_map || {} )[ tipSlug + '|' + b.slug ] || {} ).has_front;
        } ).length;

        if ( missingCount ) {
            h += '<p class="wiz-warn-inline" style="margin-top:12px;">⚠ ' + missingCount
               + ' color(s) have no base image. <a href="' + esc( d.mockup_lib_url ) + '" target="_blank">Add in Mockup Library →</a></p>';
        }

        h += '</div>';
        $body.html( h );

        $body.on( 'change.wiz', '.boja-cb', function () {
            var slug    = $( this ).val();
            var checked = this.checked;
            $( this ).closest( '.wiz-color-card' ).toggleClass( 'is-checked', checked );
            $( this ).closest( '.wiz-color-card-wrap' ).toggleClass( 'is-selected', checked );
            if ( hasLight ) {
                $( '#lp-wrap-' + tipSlug + '-' + slug ).toggle( checked );
            }
        } );

        $body.on( 'click.wiz', '.boja-all-btn', function () {
            $body.find( '.boja-cb' ).prop( 'checked', true )
                .closest( '.wiz-color-card' ).addClass( 'is-checked' );
            $body.find( '.wiz-color-card-wrap' ).addClass( 'is-selected' );
            if ( hasLight ) $body.find( '[id^="lp-wrap-"]' ).show();
        } );

        $body.on( 'click.wiz', '.boja-none-btn', function () {
            $body.find( '.boja-cb' ).prop( 'checked', false )
                .closest( '.wiz-color-card' ).removeClass( 'is-checked' );
            $body.find( '.wiz-color-card-wrap' ).removeClass( 'is-selected' );
            if ( hasLight ) $body.find( '[id^="lp-wrap-"]' ).hide();
        } );
    }

    // ── SIZES (one step per type) ─────────────────────────────────────────────

    function renderSizes( tipSlug, tipName ) {
        var selected   = S.tipSizes[ tipSlug ] || [];
        var colorCount = Object.keys( S.tipColors[ tipSlug ] || {} )
            .filter( function ( b ) { return ( S.tipColors[ tipSlug ] || {} )[ b ].selected; } ).length;

        var h = '<div class="wizard-step">';
        h += '<h2 class="step-title">' + esc( tipName ) + ' — Sizes</h2>';
        h += '<p class="step-subtitle">Select available sizes for ' + esc( tipName )
           + '. Sizes are stored as options on each variation, not as separate variations.</p>';

        h += '<div class="wiz-quick-actions">';
        h += '<button type="button" class="button button-small size-all-btn">All</button>';
        h += '<button type="button" class="button button-small size-none-btn">None</button>';
        h += '</div>';

        h += '<div class="wiz-checkbox-grid wiz-size-grid">';
        ( d.velicinas || [] ).forEach( function ( v ) {
            var chk = selected.indexOf( v.slug ) !== -1;
            h += '<label class="wiz-check-card wiz-size-card ' + ( chk ? 'is-checked' : '' ) + '">';
            h += '<input type="checkbox" class="size-cb" value="' + esc( v.slug ) + '" ' + ( chk ? 'checked' : '' ) + '>';
            h += '<span class="check-card-name">' + esc( v.name ) + '</span>';
            h += '</label>';
        } );
        h += '</div>';

        h += '<p class="wiz-variation-count" id="var-count">';
        h += varCountText( colorCount, selected.length, tipName );
        h += '</p>';

        h += '</div>';
        $body.html( h );

        $body.on( 'change.wiz', '.size-cb', function () {
            $( this ).closest( '.wiz-check-card' ).toggleClass( 'is-checked', this.checked );
            $( '#var-count' ).html( varCountText( colorCount, $body.find( '.size-cb:checked' ).length, tipName ) );
        } );

        $body.on( 'click.wiz', '.size-all-btn', function () {
            $body.find( '.size-cb' ).prop( 'checked', true ).closest( '.wiz-check-card' ).addClass( 'is-checked' );
            $( '#var-count' ).html( varCountText( colorCount, $body.find( '.size-cb' ).length, tipName ) );
        } );

        $body.on( 'click.wiz', '.size-none-btn', function () {
            $body.find( '.size-cb' ).prop( 'checked', false ).closest( '.wiz-check-card' ).removeClass( 'is-checked' );
            $( '#var-count' ).html( varCountText( colorCount, 0, tipName ) );
        } );
    }

    function varCountText( colorCount, sizeCount, tipName ) {
        return '<strong>' + colorCount + '</strong> ' + esc( tipName )
             + ' variations × <strong>' + sizeCount + '</strong> available sizes each';
    }

    // ── TYPE CONFIG (colors + sizes merged) ──────────────────────────────────

    function renderTypeConfig( tipSlug, tipName ) {
        var tipColorState = S.tipColors[ tipSlug ] || {};
        var selectedSizes = S.tipSizes[ tipSlug ]  || [];
        var hasLight      = S.printLightId > 0;

        var h = '<div class="wizard-step">';
        h += '<h2 class="step-title">' + esc( tipName ) + '</h2>';

        // ── Colors ──
        h += '<div class="wiz-section">';
        h += '<h3 class="wiz-section-title">Colors</h3>';

        if ( hasLight ) {
            h += '<p class="wiz-info-note">💡 Check <strong>Light print</strong> on colors where the lighter design should be used instead.</p>';
        }

        h += '<div class="wiz-quick-actions">';
        h += '<button type="button" class="button button-small boja-all-btn">All</button>';
        h += '<button type="button" class="button button-small boja-none-btn">None</button>';
        h += '</div>';

        h += '<div class="wiz-color-grid-full">';
        ( d.bojas || [] ).forEach( function ( b ) {
            var colorState = tipColorState[ b.slug ] || { selected: false, lightPrint: false };
            var chk        = colorState.selected;
            var lp         = colorState.lightPrint;
            var mk         = ( d.mockup_map || {} )[ tipSlug + '|' + b.slug ] || {};

            h += '<div class="wiz-color-card-wrap ' + ( chk ? 'is-selected' : '' ) + '">';
            h += '<label class="wiz-color-card ' + ( chk ? 'is-checked' : '' ) + ( ! mk.has_front ? ' no-mockup' : '' ) + '">';
            h += '<input type="checkbox" class="boja-cb" data-tip="' + esc( tipSlug ) + '" value="' + esc( b.slug ) + '" ' + ( chk ? 'checked' : '' ) + '>';

            if ( b.hex ) h += '<span class="wiz-swatch" style="background:' + esc( b.hex ) + ';"></span>';
            else         h += '<span class="wiz-swatch swatch-empty"></span>';

            if ( mk.front_thumb ) {
                h += '<span class="wiz-mockup-thumb"><img src="' + esc( mk.front_thumb ) + '" alt=""></span>';
            } else {
                h += '<span class="wiz-mockup-thumb wiz-mockup-missing" title="No base image in Mockup Library">?</span>';
            }

            h += '<span class="wiz-color-name">' + esc( b.name ) + '</span>';
            if ( ! mk.has_front ) h += '<span class="wiz-no-mockup-badge">!</span>';
            h += '</label>';

            if ( hasLight ) {
                h += '<label class="wiz-light-print-label" id="lp-wrap-' + esc( tipSlug ) + '-' + esc( b.slug ) + '" '
                   + 'style="' + ( chk ? '' : 'display:none;' ) + '">';
                h += '<input type="checkbox" id="lp-' + esc( tipSlug ) + '-' + esc( b.slug ) + '" ' + ( lp ? 'checked' : '' ) + '>';
                h += ' Light print';
                h += '</label>';
            }

            h += '</div>';
        } );
        h += '</div>';

        var missingCount = ( d.bojas || [] ).filter( function ( b ) {
            return ! ( ( d.mockup_map || {} )[ tipSlug + '|' + b.slug ] || {} ).has_front;
        } ).length;
        if ( missingCount ) {
            h += '<p class="wiz-warn-inline" style="margin-top:8px;">⚠ ' + missingCount
               + ' color(s) missing base images. <a href="' + esc( d.mockup_lib_url ) + '" target="_blank">Add in Mockup Library →</a></p>';
        }

        h += '</div>'; // .wiz-section

        // ── Sizes ──
        h += '<div class="wiz-section" style="margin-top:24px;">';
        h += '<h3 class="wiz-section-title">Available Sizes</h3>';
        h += '<p class="wiz-hint" style="margin-bottom:10px;">Sizes are stored as options per variation — not as separate variations.</p>';

        h += '<div class="wiz-quick-actions">';
        h += '<button type="button" class="button button-small size-all-btn">All</button>';
        h += '<button type="button" class="button button-small size-none-btn">None</button>';
        h += '</div>';

        h += '<div class="wiz-checkbox-grid wiz-size-grid">';
        ( d.velicinas || [] ).forEach( function ( v ) {
            var chk = selectedSizes.indexOf( v.slug ) !== -1;
            h += '<label class="wiz-check-card wiz-size-card ' + ( chk ? 'is-checked' : '' ) + '">';
            h += '<input type="checkbox" class="size-cb" value="' + esc( v.slug ) + '" ' + ( chk ? 'checked' : '' ) + '>';
            h += '<span class="check-card-name">' + esc( v.name ) + '</span>';
            h += '</label>';
        } );
        h += '</div>';

        h += '<p class="wiz-variation-count" id="var-count">' + getVarCountText( tipSlug ) + '</p>';
        h += '</div>';

        h += '</div>';
        $body.html( h );

        // Color events
        $body.on( 'change.wiz', '.boja-cb', function () {
            var slug    = $( this ).val();
            var checked = this.checked;
            $( this ).closest( '.wiz-color-card' ).toggleClass( 'is-checked', checked );
            $( this ).closest( '.wiz-color-card-wrap' ).toggleClass( 'is-selected', checked );
            if ( hasLight ) $( '#lp-wrap-' + tipSlug + '-' + slug ).toggle( checked );
            updateVarCount( tipSlug );
        } );

        $body.on( 'click.wiz', '.boja-all-btn', function () {
            $body.find( '.boja-cb' ).prop( 'checked', true )
                .closest( '.wiz-color-card' ).addClass( 'is-checked' );
            $body.find( '.wiz-color-card-wrap' ).addClass( 'is-selected' );
            if ( hasLight ) $body.find( '[id^="lp-wrap-"]' ).show();
            updateVarCount( tipSlug );
        } );

        $body.on( 'click.wiz', '.boja-none-btn', function () {
            $body.find( '.boja-cb' ).prop( 'checked', false )
                .closest( '.wiz-color-card' ).removeClass( 'is-checked' );
            $body.find( '.wiz-color-card-wrap' ).removeClass( 'is-selected' );
            if ( hasLight ) $body.find( '[id^="lp-wrap-"]' ).hide();
            updateVarCount( tipSlug );
        } );

        // Size events
        $body.on( 'change.wiz', '.size-cb', function () {
            $( this ).closest( '.wiz-check-card' ).toggleClass( 'is-checked', this.checked );
            updateVarCount( tipSlug );
        } );

        $body.on( 'click.wiz', '.size-all-btn', function () {
            $body.find( '.size-cb' ).prop( 'checked', true ).closest( '.wiz-check-card' ).addClass( 'is-checked' );
            updateVarCount( tipSlug );
        } );

        $body.on( 'click.wiz', '.size-none-btn', function () {
            $body.find( '.size-cb' ).prop( 'checked', false ).closest( '.wiz-check-card' ).removeClass( 'is-checked' );
            updateVarCount( tipSlug );
        } );
    }

    function getVarCountText( tipSlug ) {
        var colorCount = $body.find( '.boja-cb:checked' ).length;
        var sizeCount  = $body.find( '.size-cb:checked' ).length;
        return '<strong>' + colorCount + '</strong> variations × <strong>' + sizeCount + '</strong> available sizes each';
    }

    function updateVarCount( tipSlug ) {
        $( '#var-count' ).html( getVarCountText( tipSlug ) );
    }

    // ── PRICING ───────────────────────────────────────────────────────────────

    function renderPricing() {
        var sym = $( '#thready-wizard' ).data( 'currency' ) || '';
        var h = '<div class="wizard-step">';
        h += '<h2 class="step-title">Pricing</h2>';
        h += '<p class="step-subtitle">Set the price for each product type. All colors within a type share the same price.</p>';

        S.tipSlugs.forEach( function ( t ) {
            var tip = getTip( t );
            var p   = S.tipPrices[ t ] || { regular: '', sale: null };

            h += '<div class="wiz-tip-section wiz-price-section">';
            h += '<h3 class="tip-section-title">' + esc( tip.name ) + '</h3>';
            h += '<div class="wiz-price-row">';

            h += '<div class="wiz-field wiz-field-inline">';
            h += '<label class="wiz-label">Regular Price <span class="req">*</span></label>';
            h += '<div class="wiz-price-input"><span class="wiz-currency">' + esc( sym ) + '</span>';
            h += '<input type="number" id="reg-' + esc( t ) + '" min="0" step="0.01" value="' + esc( p.regular || '' ) + '"></div></div>';

            h += '<div class="wiz-field wiz-field-inline">';
            h += '<label class="wiz-label">Sale Price <span style="font-weight:400;color:#646970">(optional)</span></label>';
            h += '<div class="wiz-price-input"><span class="wiz-currency">' + esc( sym ) + '</span>';
            h += '<input type="number" id="sale-' + esc( t ) + '" min="0" step="0.01" value="' + esc( p.sale !== null ? p.sale : '' ) + '"></div></div>';

            h += '</div></div>';
        } );

        h += '</div>';
        $body.html( h );
    }

    // ── POSITIONING ───────────────────────────────────────────────────────────

    function renderPositioning() {
        var h = '<div class="wizard-step">';
        h += '<h2 class="step-title">Print Positioning</h2>';
        h += '<p class="step-subtitle">Set print position and size per product type. Preview shows the first selected color.</p>';

        S.tipSlugs.forEach( function ( t ) {
            var tip  = getTip( t );
            var pos  = S.tipPositions[ t ] || { front: { x:50, y:25, width:50 }, back: null };
            var pf   = pos.front || { x:50, y:25, width:50 };
            var pb   = pos.back  || { x:50, y:25, width:50 };

            var firstBoja = getFirstSelectedBoja( t );
            var mk = ( d.mockup_map || {} )[ t + '|' + firstBoja ] || {};

            h += '<div class="wiz-tip-position" data-tip="' + esc( t ) + '">';
            h += '<h4 class="tip-pos-title">' + esc( tip.name ) + '</h4>';
            if ( firstBoja ) {
                h += '<p class="tip-pos-color-note">Preview: ' + esc( getBoja( firstBoja ).name ) + '</p>';
            }

            // Front panel
            h += '<div class="wiz-pos-panel">';
            h += '<div class="pos-panel-label">Front Print</div>';
            h += '<div class="pos-panel-inner">';
            h += '<div class="wiz-canvas-wrap">';
            h += '<canvas id="cv-front-' + esc( t ) + '" class="wiz-canvas"></canvas>';
            h += '<div class="canvas-no-base" id="nb-front-' + esc( t ) + '" style="display:none;">No base image — add in Mockup Library</div>';
            h += '</div>';
            h += buildPosInputs( t, 'front', pf );
            h += '</div></div>';

            // Back panel
            if ( S.printBackId ) {
                h += '<div class="wiz-pos-panel" id="pp-back-' + esc( t ) + '">';
                h += '<div class="pos-panel-label">Back Print</div>';
                h += '<div class="pos-panel-inner">';
                h += '<div class="wiz-canvas-wrap">';
                h += '<canvas id="cv-back-' + esc( t ) + '" class="wiz-canvas"></canvas>';
                h += '<div class="canvas-no-base" id="nb-back-' + esc( t ) + '" style="display:none;">No back base image</div>';
                h += '</div>';
                h += buildPosInputs( t, 'back', pb );
                h += '</div></div>';
            }

            h += '</div>'; // .wiz-tip-position
        } );

        h += '</div>';
        $body.html( h );

        // Draw canvases
        S.tipSlugs.forEach( function ( t ) {
            var firstBoja = getFirstSelectedBoja( t );
            var mk   = ( d.mockup_map || {} )[ t + '|' + firstBoja ] || {};
            var pos  = S.tipPositions[ t ] || { front: { x:50, y:25, width:50 }, back: null };
            drawCanvas( t, 'front', mk.front_url || '', S.printFrontUrl, pos.front || { x:50, y:25, width:50 } );
            if ( S.printBackId ) {
                drawCanvas( t, 'back', mk.back_url || '', S.printBackUrl, pos.back || { x:50, y:25, width:50 } );
            }
        } );

        $body.on( 'input.wiz change.wiz', '.pos-input', function () {
            var t    = $( this ).data( 'tip' );
            var side = $( this ).data( 'side' );
            var pos  = readPos( t, side );
            var firstBoja = getFirstSelectedBoja( t );
            var mk   = ( d.mockup_map || {} )[ t + '|' + firstBoja ] || {};
            var base  = side === 'front' ? ( mk.front_url || '' ) : ( mk.back_url || '' );
            var print = side === 'front' ? S.printFrontUrl : S.printBackUrl;
            drawCanvas( t, side, base, print, pos );
        } );
    }

    function buildPosInputs( tip, side, pos ) {
        var h = '<div class="wiz-pos-inputs">';
        [ [ 'x','X (%)',-100,100 ], [ 'y','Y (%)',-100,100 ], [ 'width','Width (%)',1,100 ] ]
        .forEach( function ( f ) {
            h += '<div class="wiz-pos-field"><label>' + f[1] + '</label>';
            h += '<input type="number"'
               + ' id="pos-' + side + '-' + f[0] + '-' + esc( tip ) + '"'
               + ' class="pos-input" data-tip="' + esc( tip ) + '" data-side="' + side + '" data-axis="' + f[0] + '"'
               + ' value="' + esc( pos[ f[0] ] ) + '" min="' + f[2] + '" max="' + f[3] + '" step="1">';
            h += '</div>';
        } );
        return h + '</div>';
    }

    function readPos( tip, side ) {
        return {
            x    : parseInt( $( '#pos-' + side + '-x-'     + tip ).val(), 10 ) || 50,
            y    : parseInt( $( '#pos-' + side + '-y-'     + tip ).val(), 10 ) || 25,
            width: parseInt( $( '#pos-' + side + '-width-' + tip ).val(), 10 ) || 50,
        };
    }

    // ── PRODUCT IMAGE ─────────────────────────────────────────────────────────

    function renderProductImage() {
        var h = '<div class="wizard-step">';
        h += '<h2 class="step-title">Product Image</h2>';
        h += '<p class="step-subtitle">Choose which combination to use as the featured image. '
           + 'This appears in the shop, archives and social sharing.</p>';

        h += '<div class="wiz-pi-grid">';

        S.tipSlugs.forEach( function ( t ) {
            var tip     = getTip( t );
            var colors  = Object.keys( S.tipColors[ t ] || {} )
                .filter( function ( b ) { return ( S.tipColors[ t ] || {} )[ b ].selected; } );
            var isSelected = S.featuredTipSlug === t;
            var selBoja    = isSelected ? S.featuredBojaSlug : ( colors[0] || '' );
            var selSide    = isSelected ? S.featuredSide : 'front';

            h += '<div class="wiz-pi-card ' + ( isSelected ? 'is-selected' : '' ) + '" data-tip="' + esc( t ) + '">';
            h += '<div class="wiz-pi-card-name">' + esc( tip.name ) + '</div>';

            // Full-size canvas preview
            h += '<div class="wiz-pi-canvas-wrap">';
            h += '<canvas id="pi-cv-' + esc( t ) + '" class="wiz-pi-canvas"></canvas>';
            h += '<div class="wiz-pi-no-base" id="pi-nb-' + esc( t ) + '" style="display:none;">No base image — add in Mockup Library</div>';
            h += '</div>';

            // Controls row
            h += '<div class="wiz-pi-controls">';

            // Color selector
            h += '<select class="wiz-pi-color" data-tip="' + esc( t ) + '">';
            colors.forEach( function ( b ) {
                var boja = getBoja( b );
                h += '<option value="' + esc( b ) + '" ' + ( selBoja === b ? 'selected' : '' ) + '>'
                   + esc( boja.name ) + '</option>';
            } );
            h += '</select>';

            // Front/Back toggle (only if back print uploaded)
            if ( S.printBackId ) {
                h += '<div class="wiz-pi-sides">';
                h += '<button type="button" class="wiz-pi-side ' + ( selSide === 'front' ? 'active' : '' )
                   + '" data-tip="' + esc( t ) + '" data-side="front">Front</button>';
                h += '<button type="button" class="wiz-pi-side ' + ( selSide === 'back' ? 'active' : '' )
                   + '" data-tip="' + esc( t ) + '" data-side="back">Back</button>';
                h += '</div>';
            }

            h += '</div>'; // .wiz-pi-controls

            h += '<button type="button" class="button ' + ( isSelected ? 'button-primary' : '' )
               + ' wiz-pi-pick" data-tip="' + esc( t ) + '">';
            h += isSelected ? '✓ Selected' : 'Use This';
            h += '</button>';

            h += '</div>'; // .wiz-pi-card
        } );

        h += '</div></div>'; // .wiz-pi-grid + .wizard-step
        $body.html( h );

        // Auto-select first tip if nothing chosen yet
        if ( ! S.featuredTipSlug && S.tipSlugs.length ) {
            var ft = S.tipSlugs[0];
            var fc = Object.keys( S.tipColors[ ft ] || {} )
                .filter( function ( b ) { return ( S.tipColors[ ft ] || {} )[ b ].selected; } )[0] || '';
            selectFeatured( ft, fc, 'front', false );
        }

        // Draw all canvases
        S.tipSlugs.forEach( function ( t ) {
            drawPiCanvas( t );
        } );

        bindPiEvents();
    }

    function selectFeatured( tipSlug, bojaSlug, side, redraw ) {
        S.featuredTipSlug  = tipSlug;
        S.featuredBojaSlug = bojaSlug;
        S.featuredSide     = side || 'front';

        $( '.wiz-pi-card' ).removeClass( 'is-selected' );
        $( '.wiz-pi-pick' ).removeClass( 'button-primary' ).text( 'Use This' );

        var $card = $( '.wiz-pi-card[data-tip="' + tipSlug + '"]' );
        $card.addClass( 'is-selected' );
        $card.find( '.wiz-pi-pick' ).addClass( 'button-primary' ).text( '✓ Selected' );

        if ( redraw !== false ) drawPiCanvas( tipSlug );
    }

    function drawPiCanvas( tipSlug ) {
        var cv = document.getElementById( 'pi-cv-' + tipSlug );
        var nb = document.getElementById( 'pi-nb-' + tipSlug );
        if ( ! cv ) return;

        var isSelected = S.featuredTipSlug === tipSlug;
        var bojaSlug   = isSelected ? S.featuredBojaSlug
            : ( $( '.wiz-pi-color[data-tip="' + tipSlug + '"]' ).val()
                || Object.keys( S.tipColors[ tipSlug ] || {} )
                   .filter( function ( b ) { return ( S.tipColors[ tipSlug ] || {} )[ b ].selected; } )[0]
                || '' );
        var side       = isSelected ? S.featuredSide : 'front';

        var mk       = ( d.mockup_map || {} )[ tipSlug + '|' + bojaSlug ] || {};
        var baseUrl  = side === 'back' ? ( mk.back_url || '' ) : ( mk.front_url || '' );
        var printUrl = side === 'back' ? S.printBackUrl : S.printFrontUrl;
        var pos      = ( S.tipPositions[ tipSlug ] || {} )[ side ] || { x:50, y:25, width:50 };

        if ( ! baseUrl ) {
            $( cv ).hide(); if ( nb ) $( nb ).show(); return;
        }
        $( cv ).show(); if ( nb ) $( nb ).hide();

        var base = new Image();
        base.crossOrigin = 'anonymous';

        base.onload = function () {
            // Fit canvas to container width (max 380px), keep aspect ratio
            var maxW  = Math.min( 380, base.naturalWidth );
            var scale = maxW / base.naturalWidth;
            cv.width  = Math.round( base.naturalWidth  * scale );
            cv.height = Math.round( base.naturalHeight * scale );

            var ctx = cv.getContext( '2d' );
            ctx.clearRect( 0, 0, cv.width, cv.height );
            ctx.drawImage( base, 0, 0, cv.width, cv.height );

            if ( ! printUrl ) return;

            var print = new Image();
            print.crossOrigin = 'anonymous';
            print.onload = function () {
                var tw = Math.round( ( pos.width / 100 ) * cv.width );
                var th = Math.round( tw * print.naturalHeight / print.naturalWidth );
                var px = pos.x > 0
                    ? Math.round( ( pos.x / 100 ) * cv.width ) - Math.round( tw / 2 )
                    : Math.round( ( pos.x / 100 ) * cv.width ) + Math.round( tw / 2 );
                var py = Math.round( ( pos.y / 100 ) * cv.height );
                ctx.drawImage( print, px, py, tw, th );
            };
            print.onerror = function () {};
            print.src = printUrl + '?t=' + Date.now();
        };

        base.onerror = function () { $( cv ).hide(); if ( nb ) $( nb ).show(); };
        base.src = baseUrl + '?t=' + Date.now();
    }

    function bindPiEvents() {
        // Select button
        $body.on( 'click.wiz', '.wiz-pi-pick', function () {
            var t    = $( this ).data( 'tip' );
            var boja = $( '.wiz-pi-color[data-tip="' + t + '"]' ).val() || '';
            var side = $( '.wiz-pi-side.active[data-tip="' + t + '"]' ).data( 'side' ) || 'front';
            selectFeatured( t, boja, side, true );
        } );

        // Color change → redraw
        $body.on( 'change.wiz', '.wiz-pi-color', function () {
            var t    = $( this ).data( 'tip' );
            var boja = $( this ).val();
            if ( S.featuredTipSlug === t ) S.featuredBojaSlug = boja;
            drawPiCanvas( t );
        } );

        // Side toggle → redraw
        $body.on( 'click.wiz', '.wiz-pi-side', function () {
            var t    = $( this ).data( 'tip' );
            var side = $( this ).data( 'side' );
            $( '.wiz-pi-side[data-tip="' + t + '"]' ).removeClass( 'active' );
            $( this ).addClass( 'active' );
            if ( S.featuredTipSlug === t ) S.featuredSide = side;
            drawPiCanvas( t );
        } );
    }

    // ── REVIEW ────────────────────────────────────────────────────────────────

    function renderReview() {
        var totalVars = S.tipSlugs.reduce( function ( sum, t ) {
            return sum + Object.keys( S.tipColors[ t ] || {} )
                .filter( function ( b ) { return ( S.tipColors[ t ] || {} )[ b ].selected; } ).length;
        }, 0 );

        var sym = $( '#thready-wizard' ).data( 'currency' ) || '';
        var h = '<div class="wizard-step">';
        h += '<h2 class="step-title">Review</h2>';
        h += '<p class="step-subtitle">Check your configuration, then create the product.</p>';

        h += '<div class="wiz-review-card">';
        h += rRow( 'Product Name',   S.name );
        h += rRow( 'Types',          S.tipSlugs.map( function(t){ return getTip(t).name; } ).join( ', ' ) );
        h += rRow( 'Total Variations', '<strong>' + totalVars + '</strong>' );
        h += rRow( 'Front Print',    S.printFrontId ? '✓ set' : '<span class="wiz-missing">⚠ missing</span>' );
        h += rRow( 'Light Print',    S.printLightId ? '✓ set' : 'none' );
        h += rRow( 'Back Print',     S.printBackId  ? '✓ set' : 'none' );
        var featTip  = S.featuredTipSlug  ? getTip( S.featuredTipSlug ).name  : '—';
        var featBoja = S.featuredBojaSlug ? getBoja( S.featuredBojaSlug ).name : '—';
        h += rRow( 'Featured Image', featTip + ' — ' + featBoja + ' — ' + S.featuredSide );
        h += '</div>';

        S.tipSlugs.forEach( function ( t ) {
            var tip     = getTip( t );
            var colors  = Object.keys( S.tipColors[ t ] || {} )
                .filter( function ( b ) { return ( S.tipColors[ t ] || {} )[ b ].selected; } );
            var lpCount = colors.filter( function ( b ) { return ( S.tipColors[ t ] || {} )[ b ].lightPrint; } ).length;
            var sizes   = S.tipSizes[ t ] || [];
            var price   = S.tipPrices[ t ] || {};
            var pos     = S.tipPositions[ t ] || {};

            h += '<div class="wiz-review-tip">';
            h += '<div class="review-tip-header">' + esc( tip.name ) + '</div>';
            h += '<div class="review-tip-body">';
            h += rRow( 'Colors',    colors.length + ' selected' + ( lpCount ? ' (' + lpCount + ' light print)' : '' ) );
            h += rRow( 'Sizes',     sizes.length + ' available sizes' );
            h += rRow( 'Regular Price', price.regular ? sym + price.regular : '—' );
            if ( price.sale ) h += rRow( 'Sale Price', sym + price.sale );
            if ( pos.front ) h += rRow( 'Front Pos', 'X:' + pos.front.x + '% Y:' + pos.front.y + '% W:' + pos.front.width + '%' );
            if ( pos.back  ) h += rRow( 'Back Pos',  'X:' + pos.back.x  + '% Y:' + pos.back.y  + '% W:' + pos.back.width  + '%' );
            h += '</div></div>';
        } );

        h += '<div id="wiz-result" style="display:none;margin-top:20px;"></div>';
        h += '</div>';
        $body.html( h );
    }

    function rRow( label, val ) {
        return '<div class="review-row"><span class="review-label">' + esc( label ) + '</span>'
             + '<span class="review-val">' + val + '</span></div>';
    }

    // ── Canvas ────────────────────────────────────────────────────────────────

    function drawCanvas( tip, side, baseUrl, printUrl, pos ) {
        var cv     = document.getElementById( 'cv-' + side + '-' + tip );
        var noBase = document.getElementById( 'nb-' + side + '-' + tip );
        if ( ! cv ) return;

        if ( ! baseUrl ) { $( cv ).hide(); if ( noBase ) $( noBase ).show(); return; }
        $( cv ).show(); if ( noBase ) $( noBase ).hide();

        var base = new Image();
        base.crossOrigin = 'anonymous';
        base.onload = function () {
            var maxW  = Math.min( 800, base.naturalWidth );
            var scale = maxW / base.naturalWidth;
            cv.width  = Math.round( base.naturalWidth  * scale );
            cv.height = Math.round( base.naturalHeight * scale );
            var ctx   = cv.getContext( '2d' );
            ctx.clearRect( 0, 0, cv.width, cv.height );
            ctx.drawImage( base, 0, 0, cv.width, cv.height );

            if ( ! printUrl ) return;
            var print = new Image();
            print.crossOrigin = 'anonymous';
            print.onload = function () {
                var tw = Math.round( ( pos.width / 100 ) * cv.width );
                var th = Math.round( tw * print.naturalHeight / print.naturalWidth );
                var px = pos.x > 0
                    ? Math.round( ( pos.x / 100 ) * cv.width ) - Math.round( tw / 2 )
                    : Math.round( ( pos.x / 100 ) * cv.width ) + Math.round( tw / 2 );
                var py = Math.round( ( pos.y / 100 ) * cv.height );
                ctx.drawImage( print, px, py, tw, th );
            };
            print.onerror = function () {};
            print.src = printUrl + '?t=' + Date.now();
        };
        base.onerror = function () { $( cv ).hide(); if ( noBase ) $( noBase ).show(); };
        base.src = baseUrl + '?t=' + Date.now();
    }

    // ── Create product ────────────────────────────────────────────────────────

    function createProduct() {
        $btnNext.prop( 'disabled', true ).text( 'Creating…' );
        $errMsg.text( '' );

        var tipColorsPayload = {};
        S.tipSlugs.forEach( function ( t ) {
            tipColorsPayload[ t ] = Object.keys( S.tipColors[ t ] || {} )
                .filter( function ( b ) { return ( S.tipColors[ t ] || {} )[ b ].selected; } )
                .map( function ( b ) { return { slug: b, light_print: !! ( S.tipColors[ t ] || {} )[ b ].lightPrint }; } );
        } );

        var payload = {
            name               : S.name,
            tip_slugs          : S.tipSlugs,
            tip_colors         : tipColorsPayload,
            tip_sizes          : S.tipSizes,
            tip_prices         : S.tipPrices,
            tip_positions      : S.tipPositions,
            print_front_id     : S.printFrontId,
            print_light_id     : S.printLightId || 0,
            print_back_id      : S.printBackId  || 0,
            featured_tip_slug  : S.featuredTipSlug,
            featured_boja_slug : S.featuredBojaSlug,
            featured_side      : S.featuredSide,
        };

        $.post( d.ajax_url, {
            action      : 'thready_wizard_create',
            _ajax_nonce : d.nonce,
            payload     : JSON.stringify( payload ),
        } )
        .done( function ( res ) {
            $btnNext.prop( 'disabled', false );
            if ( res.success ) {
                showResult( res.data );
            } else {
                $btnNext.text( 'Create Product' );
                $errMsg.text( ( res.data && res.data.message ) || 'Create failed.' );
            }
        } )
        .fail( function () {
            $btnNext.prop( 'disabled', false ).text( 'Create Product' );
            $errMsg.text( 'Server error. Please try again.' );
        } );
    }

    function showResult( data ) {
        $btnNext.hide(); $btnBack.hide();
        var h = '<div class="result-item result-ok">';
        h += '✓ <strong>' + esc( S.name ) + '</strong> created — ' + data.variation_count + ' variations. ';
        h += '<a href="' + esc( data.edit_url ) + '" class="button button-small">Edit</a> ';
        h += '<a href="' + esc( data.view_url ) + '" class="button button-small" target="_blank">View</a>';
        h += '</div>';
        h += '<a href="' + esc( d.products_url ) + '" class="button button-primary" style="margin-top:12px">← Back to Products</a>';
        $( '#wiz-result' ).html( h ).show();
        $( '#wiz-result' )[0].scrollIntoView( { behavior: 'smooth' } );
    }

    // ── Utility ───────────────────────────────────────────────────────────────

    function getTip( slug ) {
        return ( d.tips || [] ).find( function ( t ) { return t.slug === slug; } ) || { slug: slug, name: slug };
    }

    function getBoja( slug ) {
        return ( d.bojas || [] ).find( function ( b ) { return b.slug === slug; } ) || { slug: slug, name: slug };
    }

    function getFirstSelectedBoja( tipSlug ) {
        var colors = S.tipColors[ tipSlug ] || {};
        return Object.keys( colors ).find( function ( b ) { return colors[ b ].selected; } ) || '';
    }

    function esc( s ) { return $( '<span>' ).text( String( s ) ).html(); }

    // ── Global button handlers ────────────────────────────────────────────────

    $btnNext.on( 'click', proceed );

    $btnBack.on( 'click', function () {
        if ( S.stepIndex > 0 ) goTo( S.stepIndex - 1 );
    } );

    $( document ).on( 'click', '.wizard-step-indicator.done', function () {
        goTo( parseInt( $( this ).data( 'step-index' ), 10 ) );
    } );

    // ── Init ──────────────────────────────────────────────────────────────────

    S.dynamicSteps = [ { id: 'setup', type: 'setup', label: 'Setup', sublabel: '' } ];
    S.stepIndex    = 0;
    rebuildStepIndicator();
    goTo( 0 );

}( jQuery ) );