/**
 * Thready Live Preview — live-preview.js
 *
 * Canvas compositor for products in canvas render mode.
 * Works with multi-tip products: variations = pa_tip-proizvoda × pa_boja.
 *
 * threadyCanvas data shape:
 * {
 *   print_front   : string  (URL)
 *   print_light   : string  (URL, optional)
 *   print_back    : string  (URL, optional)
 *   tip_positions : { [tipSlug]: { front:{x,y,width}, back:{x,y,width}|null } }
 *   mockups       : { "tip|boja": { front:url, back:url } }
 *   has_back      : bool
 *   has_light     : bool
 *   tax_tip       : string  e.g. "pa_tip-proizvoda"
 *   tax_boja      : string  e.g. "pa_boja"
 * }
 */
/* global threadyCanvas, jQuery */
(function ( $ ) {
    'use strict';

    var d = window.threadyCanvas;
    if ( ! d ) return;

    // ── Per-session canvas composite cache ───────────────────────────────────
    var cache = {};

    // Current state
    var currentTip  = null;
    var currentBoja = null;
    var currentView = 'front';

    // Capture original gallery state once for reset
    var originalGalleryHTML = null;

    // Off-screen canvas
    var canvas = document.createElement( 'canvas' );
    var ctx    = canvas.getContext( '2d' );

    // ── Gallery helpers ───────────────────────────────────────────────────────

    function getGalleryImg() {
        return $( '.woocommerce-product-gallery__image img, .gspb-product-image-gallery img' ).first();
    }

    function getGalleryWrap() {
        var $a = $( '.woocommerce-product-gallery__image' ).first();
        var $b = $( '.gspb-product-image-gallery' ).first();
        return $a.length ? $a : $b;
    }

    function swapGalleryImage( url ) {
        var $img = getGalleryImg();
        if ( ! $img.length ) return;

        $img.stop( true ).animate( { opacity: 0 }, 100, function () {
            $img.attr( { src: url, srcset: '' } );
            $img.closest( 'a' ).attr( 'href', url );
            $img[0].onload = function () {
                $img.animate( { opacity: 1 }, 150 );
                getGalleryWrap().removeClass( 'thready-canvas-loading' );
            };
        } );
    }

    // ── Image loading ─────────────────────────────────────────────────────────

    function loadImg( url ) {
        return new Promise( function ( resolve, reject ) {
            var img        = new Image();
            img.crossOrigin = 'anonymous';
            img.onload  = function () { resolve( img ); };
            img.onerror = function () { reject( new Error( 'Load failed: ' + url ) ); };
            img.src     = url + ( url.indexOf( '?' ) !== -1 ? '&' : '?' ) + '_tc=' + Date.now();
        } );
    }

    // ── Composite ─────────────────────────────────────────────────────────────

    function composite( baseUrl, printUrl, pos ) {
        var key = baseUrl + '||' + printUrl + '||' + JSON.stringify( pos );
        if ( cache[ key ] ) return Promise.resolve( cache[ key ] );

        var loads = [ loadImg( baseUrl ) ];
        if ( printUrl ) loads.push( loadImg( printUrl ) );

        return Promise.all( loads ).then( function ( imgs ) {
            var base  = imgs[0];
            var print = imgs[1] || null;

            canvas.width  = base.naturalWidth;
            canvas.height = base.naturalHeight;
            ctx.clearRect( 0, 0, canvas.width, canvas.height );
            ctx.drawImage( base, 0, 0 );

            if ( print && pos ) {
                var tw = Math.round( ( pos.width / 100 ) * canvas.width );
                var th = Math.round( tw * print.naturalHeight / print.naturalWidth );
                var px = pos.x > 0
                    ? Math.round( ( pos.x / 100 ) * canvas.width ) - Math.round( tw / 2 )
                    : Math.round( ( pos.x / 100 ) * canvas.width ) + Math.round( tw / 2 );
                var py = Math.round( ( pos.y / 100 ) * canvas.height );
                ctx.drawImage( print, px, py, tw, th );
            }

            return new Promise( function ( resolve ) {
                canvas.toBlob( function ( blob ) {
                    var url = URL.createObjectURL( blob );
                    cache[ key ] = url;
                    resolve( url );
                }, 'image/png' );
            } );
        } );
    }

    // ── Update view ───────────────────────────────────────────────────────────

    function updateView( tipSlug, bojaSlug, view, lightPrint ) {
        if ( ! tipSlug || ! bojaSlug ) return;

        var mkKey  = tipSlug + '|' + bojaSlug;
        var mockup = ( d.mockups || {} )[ mkKey ];

        if ( ! mockup ) {
            // No mockup image — show nothing, don't break the page
            getGalleryWrap().removeClass( 'thready-canvas-loading' );
            return;
        }

        var baseUrl = view === 'back' && mockup.back ? mockup.back : mockup.front;
        if ( ! baseUrl ) {
            getGalleryWrap().removeClass( 'thready-canvas-loading' );
            return;
        }

        // Choose print image
        var printUrl;
        if ( view === 'back' ) {
            printUrl = d.print_back || '';
        } else if ( lightPrint && d.has_light ) {
            printUrl = d.print_light || '';
        } else {
            printUrl = d.print_front || '';
        }

        // Get position for this tip
        var tipPos   = ( d.tip_positions || {} )[ tipSlug ] || {};
        var pos      = view === 'back' ? ( tipPos.back || null ) : ( tipPos.front || { x:50, y:25, width:50 } );

        getGalleryWrap().addClass( 'thready-canvas-loading' );

        composite( baseUrl, printUrl || null, pos )
            .then( function ( url ) { swapGalleryImage( url ); } )
            .catch( function () {
                getGalleryWrap().removeClass( 'thready-canvas-loading' );
            } );
    }

    // ── Front / Back toggle UI ────────────────────────────────────────────────

    function injectViewToggle() {
        if ( ! d.has_back ) return;
        if ( $( '.thready-view-toggle' ).length ) return;

        var $wrap = getGalleryWrap();
        if ( ! $wrap.length ) return;

        var html = '<div class="thready-view-toggle">'
                 + '<button type="button" class="tvt-btn tvt-active" data-view="front">Front</button>'
                 + '<button type="button" class="tvt-btn" data-view="back">Back</button>'
                 + '</div>';

        $wrap.after( html );

        $( document ).on( 'click', '.tvt-btn', function () {
            currentView = $( this ).data( 'view' );
            $( '.tvt-btn' ).removeClass( 'tvt-active' );
            $( this ).addClass( 'tvt-active' );
            updateView( currentTip, currentBoja, currentView, false );
        } );
    }

    // ── WooCommerce variation events ──────────────────────────────────────────

    $( document ).on( 'found_variation', function ( e, variation ) {
        var tipSlug    = variation.thready_tip_slug  || '';
        var bojaSlug   = variation.thready_boja_slug || '';
        var lightPrint = !! variation.thready_light_print;

        // Normalise slugs
        tipSlug  = tipSlug.toLowerCase().replace( /\s+/g, '-' );
        bojaSlug = bojaSlug.toLowerCase().replace( /\s+/g, '-' );

        currentTip  = tipSlug;
        currentBoja = bojaSlug;
        currentView = 'front';

        // Reset toggle
        $( '.tvt-btn' ).removeClass( 'tvt-active' );
        $( '.tvt-btn[data-view="front"]' ).addClass( 'tvt-active' );

        injectViewToggle();
        updateView( tipSlug, bojaSlug, 'front', lightPrint );
    } );

    $( document ).on( 'reset_data hide_variation', function () {
        currentTip  = null;
        currentBoja = null;
        currentView = 'front';

        if ( originalGalleryHTML ) {
            getGalleryWrap().html( originalGalleryHTML );
        }
    } );

    // ── Prevent WC default image swap for canvas products ────────────────────

    $( document ).on( 'found_variation.wc-variation-form', function ( e, variation ) {
        if ( variation.thready_boja_slug !== undefined ) {
            // Replace image with blank so WC doesn't swap to variation image
            variation.image = {
                src: '', srcset: '', sizes: '', title: '', alt: '',
                caption: '', full_src: '', gallery_thumbnail_src: '',
            };
        }
    } );

    // ── Init ─────────────────────────────────────────────────────────────────

    $( function () {
        var $wrap = getGalleryWrap();
        if ( $wrap.length ) {
            originalGalleryHTML = $wrap.html();
        }
    } );

}( jQuery ) );