/**
 * Thready Live Preview — live-preview.js
 *
 * Hooks into WooCommerce variation events and composites the print design
 * over the blank mockup image using HTML5 Canvas.
 *
 * Data contract (from threadyCanvas):
 * {
 *   tip_slug    : string,
 *   print_front : string  (URL),
 *   print_back  : string  (URL),
 *   pos_front   : {x, y, width},
 *   pos_back    : {x, y, width}|null,
 *   mockups     : { [boja_slug]: { front: url, back: url } },
 *   has_back    : bool,
 *   tax_boja    : 'pa_boja',
 * }
 *
 * How it works
 * ------------
 * 1. On found_variation, read boja_slug from variation data
 * 2. Look up mockup URLs from threadyCanvas.mockups[boja_slug]
 * 3. Draw base image + print PNG on a hidden canvas
 * 4. Convert canvas to a blob URL and swap into the WC gallery
 * 5. On reset_data / hide_variation, restore original gallery state
 *
 * Gallery compatibility
 * ---------------------
 * Works with the standard WooCommerce gallery and Greenshift's
 * gspb-product-image-gallery (which your existing frontend.js already handles).
 * Injects into whichever gallery element is found.
 */

/* global threadyCanvas, jQuery */
(function ( $ ) {
    'use strict';

    var d = window.threadyCanvas;
    if ( ! d ) return;  // Not a canvas product — bail immediately

    // ─────────────────────────────────────────────────────────────────────────
    // Image cache — avoid re-compositing the same combination twice per session
    // key: "boja|side"  value: blob URL or null
    // ─────────────────────────────────────────────────────────────────────────

    var compositeCache = {};

    // Track current boja so we can detect actual changes
    var currentBoja = null;
    var currentView = 'front';  // 'front' | 'back'

    // Original gallery state (captured once on init)
    var originalGalleryHTML = null;

    // Hidden canvas used for all compositing
    var canvas = document.createElement( 'canvas' );
    var ctx    = canvas.getContext( '2d' );

    // ─────────────────────────────────────────────────────────────────────────
    // Gallery helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Find the main product image element — handles multiple gallery types.
     */
    function getGalleryImage() {
        return (
            $( '.woocommerce-product-gallery__image img' ).first() ||
            $( '.gspb-product-image-gallery img' ).first()
        );
    }

    /**
     * Get the gallery wrapper for spinner/loading state.
     */
    function getGalleryWrap() {
        return $( '.woocommerce-product-gallery__image' ).first()
            .add( $( '.gspb-product-image-gallery' ).first() )
            .first();
    }

    function showLoading() {
        getGalleryWrap().addClass( 'thready-canvas-loading' );
    }

    function hideLoading() {
        getGalleryWrap().removeClass( 'thready-canvas-loading' );
    }

    /**
     * Swap the gallery's <img> src to a new URL.
     * Fades out → updates → fades in for a polished feel.
     */
    function swapGalleryImage( url ) {
        var $img = getGalleryImage();
        if ( ! $img || ! $img.length ) return;

        $img.stop( true ).animate( { opacity: 0 }, 120, function () {
            $img.attr( 'src', url ).attr( 'srcset', '' );

            $img[0].onload = function () {
                $img.animate( { opacity: 1 }, 150 );
                hideLoading();
            };

            // Also update the zoom / lightbox link if present
            $img.closest( 'a' ).attr( 'href', url );
        } );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Compositing
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Load an image with crossOrigin and return a Promise<HTMLImageElement>.
     */
    function loadImage( url ) {
        return new Promise( function ( resolve, reject ) {
            var img = new Image();
            img.crossOrigin = 'anonymous';
            img.onload  = function () { resolve( img ); };
            img.onerror = function () { reject( new Error( 'Failed to load: ' + url ) ); };
            img.src = url + ( url.indexOf( '?' ) !== -1 ? '&' : '?' ) + '_t=' + Date.now();
        } );
    }

    /**
     * Composite base image + print PNG and return a blob URL.
     * Mirrors the PHP GD positioning math exactly.
     *
     * @param  {string} baseUrl
     * @param  {string} printUrl
     * @param  {Object} pos     {x, y, width} — percentages
     * @return {Promise<string>}  Blob URL of composited image
     */
    function composite( baseUrl, printUrl, pos ) {
        var cacheKey = baseUrl + '||' + printUrl + '||' + JSON.stringify( pos );
        if ( compositeCache[ cacheKey ] ) {
            return Promise.resolve( compositeCache[ cacheKey ] );
        }

        var promises = [ loadImage( baseUrl ) ];
        if ( printUrl ) promises.push( loadImage( printUrl ) );

        return Promise.all( promises ).then( function ( imgs ) {
            var base  = imgs[0];
            var print = imgs[1] || null;

            canvas.width  = base.naturalWidth;
            canvas.height = base.naturalHeight;

            ctx.clearRect( 0, 0, canvas.width, canvas.height );
            ctx.drawImage( base, 0, 0 );

            if ( print && pos ) {
                var targetW = Math.round( ( pos.width / 100 ) * canvas.width );
                var targetH = Math.round( ( targetW * print.naturalHeight ) / print.naturalWidth );

                var posX, posY;

                // Mirror PHP GD: positive x = center-aligned, negative = adjusted
                if ( pos.x > 0 ) {
                    posX = Math.round( ( pos.x / 100 ) * canvas.width ) - Math.round( targetW / 2 );
                } else {
                    posX = Math.round( ( pos.x / 100 ) * canvas.width ) + Math.round( targetW / 2 );
                }

                posY = Math.round( ( pos.y / 100 ) * canvas.height );

                ctx.drawImage( print, posX, posY, targetW, targetH );
            }

            return new Promise( function ( resolve ) {
                canvas.toBlob( function ( blob ) {
                    var blobUrl = URL.createObjectURL( blob );
                    compositeCache[ cacheKey ] = blobUrl;
                    resolve( blobUrl );
                }, 'image/png' );
            } );
        } );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Front / back toggle
    // ─────────────────────────────────────────────────────────────────────────

    function updateView( bojaSlug, view ) {
        if ( ! bojaSlug ) return;

        var mockup = d.mockups[ bojaSlug ];
        if ( ! mockup ) {
            hideLoading();
            return;
        }

        var baseUrl  = view === 'back' && mockup.back ? mockup.back  : mockup.front;
        var printUrl = view === 'back' ? d.print_back : d.print_front;
        var pos      = view === 'back' ? d.pos_back   : d.pos_front;

        if ( ! baseUrl ) {
            hideLoading();
            return;
        }

        showLoading();

        composite( baseUrl, printUrl || null, pos || null )
            .then( function ( url ) { swapGalleryImage( url ); } )
            .catch( function () { hideLoading(); } );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Front/back toggle UI
    // ─────────────────────────────────────────────────────────────────────────

    function injectViewToggle() {
        if ( ! d.has_back ) return;
        if ( $( '.thready-view-toggle' ).length ) return;

        var $gallery = getGalleryWrap();
        if ( ! $gallery.length ) return;

        var html = '<div class="thready-view-toggle">'
                 + '<button type="button" class="tvt-btn tvt-active" data-view="front">Front</button>'
                 + '<button type="button" class="tvt-btn" data-view="back">Back</button>'
                 + '</div>';

        $gallery.css( 'position', 'relative' ).after( html );

        $( document ).on( 'click', '.tvt-btn', function () {
            var view = $( this ).data( 'view' );
            $( '.tvt-btn' ).removeClass( 'tvt-active' );
            $( this ).addClass( 'tvt-active' );
            currentView = view;
            updateView( currentBoja, view );
        } );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // WooCommerce variation events
    // ─────────────────────────────────────────────────────────────────────────

    $( document ).on( 'found_variation', function ( e, variation ) {
        var bojaSlug = variation.thready_boja_slug || variation.attributes[ 'attribute_pa_boja' ] || '';

        // Normalise — WC sometimes returns the term name, not the slug
        bojaSlug = bojaSlug.toLowerCase().replace( /\s+/g, '-' );

        if ( bojaSlug === currentBoja ) return; // no change

        currentBoja = bojaSlug;
        currentView = 'front'; // reset to front on color change

        // Reset toggle buttons
        $( '.tvt-btn' ).removeClass( 'tvt-active' );
        $( '.tvt-btn[data-view="front"]' ).addClass( 'tvt-active' );

        // Inject toggle if not yet present (first variation selection)
        injectViewToggle();

        updateView( bojaSlug, 'front' );
    } );

    $( document ).on( 'reset_data hide_variation', function () {
        currentBoja = null;
        currentView = 'front';

        // Restore original gallery image if we captured it
        if ( originalGalleryHTML ) {
            getGalleryWrap().html( originalGalleryHTML );
        }
    } );

    // ─────────────────────────────────────────────────────────────────────────
    // Init
    // ─────────────────────────────────────────────────────────────────────────

    $( function () {
        // Capture original gallery HTML for reset
        var $wrap = getGalleryWrap();
        if ( $wrap.length ) {
            originalGalleryHTML = $wrap.html();
        }

        // Prevent WooCommerce's default variation image swap for canvas products
        // WC binds to found_variation at DOMContentLoaded — we unbind image update
        // by overriding the image on the data object (already done in PHP filter,
        // but JS also reads data.image.src — we ensure it is blank)
        $( document ).on( 'found_variation.wc-variation-form', function ( e, variation ) {
            // If our canvas system is active, cancel WC's image swap
            if ( variation.thready_boja_slug !== undefined ) {
                variation.image = {
                    src: '', srcset: '', sizes: '', title: '', alt: '',
                    caption: '', full_src: '', gallery_thumbnail_src: '',
                };
            }
        } );
    } );

}( jQuery ) );