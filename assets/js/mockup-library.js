/**
 * Thready Mockup Library — Admin JS
 *
 * Handles:
 *  - WP media picker for front / back image slots
 *  - AJAX save on image select
 *  - AJAX remove on ✕ click
 *  - Card status + UI refresh after every save
 *  - Scan & Import button
 */
/* global threadyMockup, wp */
(function ( $ ) {
    'use strict';

    var cfg = window.threadyMockup || {};

    // One shared wp.media frame per slot type, lazily created
    var mediaFrames = {};

    // ── Media frame factory ───────────────────────────────────────────────────

    function openMediaPicker( slot, $card ) {
        var title = slot === 'front' ? cfg.i18n.select_front : cfg.i18n.select_back;

        if ( ! mediaFrames[ slot ] ) {
            mediaFrames[ slot ] = wp.media( {
                title    : title,
                button   : { text: cfg.i18n.use_image },
                multiple : false,
                library  : { type: 'image' },
            } );
        }

        var frame = mediaFrames[ slot ];

        frame.off( 'select' );
        frame.on( 'select', function () {
            var attachment = frame.state().get( 'selection' ).first().toJSON();
            var thumbUrl   = attachment.sizes && attachment.sizes.thumbnail
                             ? attachment.sizes.thumbnail.url
                             : attachment.url;
            saveImage( $card, slot, attachment.id, thumbUrl );
        } );

        frame.open();
    }

    // ── Save / remove ─────────────────────────────────────────────────────────

    function saveImage( $card, slot, imageId, thumbUrl ) {
        var $slot      = $card.find( '.slot-' + slot );
        var $indicator = $slot.find( '.slot-saving-indicator' );

        setSaving( $slot, $indicator );

        $.post( cfg.ajax_url, {
            action      : 'thready_save_mockup',
            _ajax_nonce : cfg.nonce,
            tip_slug    : $card.data( 'tip' ),
            boja_slug   : $card.data( 'boja' ),
            slot        : slot,
            image_id    : imageId,
        } )
        .done( function ( res ) {
            if ( res.success ) {
                updateSlot( $slot, imageId, thumbUrl );
                updateCardStatus( $card, res.data.status );
                showSaved( $indicator );
            } else {
                showError( $indicator, res.data && res.data.message );
            }
        } )
        .fail( function () { showError( $indicator ); } );
    }

    function removeImage( $card, slot ) {
        if ( ! window.confirm( cfg.i18n.confirm_remove ) ) return;

        var $slot      = $card.find( '.slot-' + slot );
        var $indicator = $slot.find( '.slot-saving-indicator' );

        setSaving( $slot, $indicator );

        $.post( cfg.ajax_url, {
            action      : 'thready_remove_mockup',
            _ajax_nonce : cfg.nonce,
            tip_slug    : $card.data( 'tip' ),
            boja_slug   : $card.data( 'boja' ),
            slot        : slot,
        } )
        .done( function ( res ) {
            if ( res.success ) {
                clearSlot( $slot );
                updateCardStatus( $card, res.data.status );
                showSaved( $indicator );
            } else {
                showError( $indicator, res.data && res.data.message );
            }
        } )
        .fail( function () { showError( $indicator ); } );
    }

    // ── UI helpers ────────────────────────────────────────────────────────────

    function setSaving( $slot, $indicator ) {
        $slot.addClass( 'is-saving' );
        $indicator.text( cfg.i18n.saving ).removeClass( 'is-error is-saved' );
    }

    function showSaved( $indicator ) {
        $indicator.text( cfg.i18n.saved ).addClass( 'is-saved' ).removeClass( 'is-error' );
        $indicator.closest( '.image-slot' ).removeClass( 'is-saving' );
        clearTimeout( $indicator.data( 'timer' ) );
        $indicator.data( 'timer', setTimeout( function () {
            $indicator.text( '' ).removeClass( 'is-saved' );
        }, 2500 ) );
    }

    function showError( $indicator, msg ) {
        $indicator
            .text( msg || ( cfg.i18n.save_error || 'Save failed' ) )
            .addClass( 'is-error' ).removeClass( 'is-saved' );
        $indicator.closest( '.image-slot' ).removeClass( 'is-saving' );
    }

    function updateSlot( $slot, imageId, thumbUrl ) {
        $slot.find( '.slot-preview' )
            .removeClass( 'empty' )
            .addClass( 'has-image' )
            .html(
                '<img src="' + escAttr( thumbUrl ) + '" alt="" loading="lazy">'
                + '<button type="button" class="slot-remove" aria-label="Remove">✕</button>'
            );
        $slot.find( '.slot-image-id' ).val( imageId );
        $slot.find( '.slot-upload-btn' ).text( 'Change' );
    }

    function clearSlot( $slot ) {
        $slot.find( '.slot-preview' )
            .removeClass( 'has-image' )
            .addClass( 'empty' )
            .html( '<span class="slot-icon dashicons dashicons-format-image"></span>' );
        $slot.find( '.slot-image-id' ).val( '' );
        $slot.find( '.slot-upload-btn' ).text( 'Upload' );
    }

    function updateCardStatus( $card, status ) {
        $card
            .removeClass( 'status-complete status-front-only status-empty' )
            .addClass( 'status-' + status );

        var symbols = { complete: '✓', 'front-only': '½', empty: '–' };
        var titles  = {
            complete    : 'Front & back set',
            'front-only': 'Front set — back missing',
            empty       : 'No images',
        };

        $card.find( '.status-badge' )
            .attr( 'class', 'status-badge status-' + status )
            .attr( 'title', titles[ status ] || '' )
            .text( symbols[ status ] || '' );
    }

    function escAttr( str ) {
        return String( str )
            .replace( /&/g, '&amp;' ).replace( /"/g, '&quot;' )
            .replace( /'/g, '&#39;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' );
    }

    // ── Event delegation ──────────────────────────────────────────────────────

    $( document ).on( 'click', '.slot-upload-btn', function () {
        var $slot = $( this ).closest( '.image-slot' );
        var $card = $( this ).closest( '.thready-color-card' );
        openMediaPicker( $slot.data( 'slot' ), $card );
    } );

    $( document ).on( 'click', '.slot-remove', function ( e ) {
        e.stopPropagation();
        var $slot = $( this ).closest( '.image-slot' );
        var $card = $( this ).closest( '.thready-color-card' );
        removeImage( $card, $slot.data( 'slot' ) );
    } );

    // ── Scan & Import button ──────────────────────────────────────────────────

    $( '#thready-scan-btn' ).on( 'click', function () {
        var $btn    = $( this );
        var $result = $( '#thready-scan-result' );

        $btn.prop( 'disabled', true ).text( "Scanning…" );
        $result.text( '' ).css( 'color', '' );

        $.post( cfg.ajax_url, {
            action      : 'thready_scan_mockups',
            _ajax_nonce : cfg.nonce,
        } )
        .done( function ( res ) {
            $btn.prop( 'disabled', false ).text( 'Scan & Import' );

            if ( res.success ) {
                $result.css( 'color', '#1a7340' ).text( '✓ ' + res.data.message );

                // Show any errors below
                if ( res.data.errors && res.data.errors.length ) {
                    var errHtml = '<ul style="color:#cf2929;margin:6px 0 0 0;font-size:12px;">';
                    res.data.errors.forEach( function ( e ) {
                        errHtml += '<li>' + e + '</li>';
                    } );
                    errHtml += '</ul>';
                    $result.after( errHtml );
                }

                // Reload page if anything was imported
                if ( res.data.imported > 0 ) {
                    setTimeout( function () { location.reload(); }, 1400 );
                }
            } else {
                $result.css( 'color', '#cf2929' ).text(
                    ( res.data && res.data.message ) ? res.data.message : 'Scan failed'
                );
            }
        } )
        .fail( function () {
            $btn.prop( 'disabled', false ).text( 'Scan & Import' );
            $result.css( 'color', '#cf2929' ).text( 'Request failed' );
        } );
    } );

}( jQuery ) );