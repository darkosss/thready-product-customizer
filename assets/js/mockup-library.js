/**
 * Thready Mockup Library — Admin JS
 *
 * Handles:
 *  - WP media picker for front / back image slots
 *  - AJAX save on image select
 *  - AJAX remove on ✕ click
 *  - Card status + UI refresh after every save
 */

/* global threadyMockup, wp */
(function ( $ ) {
    'use strict';

    var cfg = window.threadyMockup || {};

    // One shared wp.media frame per slot type, lazily created
    var mediaFrames = {};

    // -------------------------------------------------------------------------
    // Media frame factory
    // -------------------------------------------------------------------------

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

        // Rebind select — we need a fresh closure over $card each time
        frame.off( 'select' );
        frame.on( 'select', function () {
            var attachment = frame.state().get( 'selection' ).first().toJSON();
            saveImage( $card, slot, attachment.id, attachment.sizes.thumbnail
                ? attachment.sizes.thumbnail.url
                : attachment.url );
        } );

        frame.open();
    }

    // -------------------------------------------------------------------------
    // Save / remove helpers
    // -------------------------------------------------------------------------

    function saveImage( $card, slot, imageId, thumbUrl ) {
        var $slot      = $card.find( '.slot-' + slot );
        var $indicator = $slot.find( '.slot-saving-indicator' );

        setSavingState( $slot, $indicator );

        $.post( cfg.ajax_url, {
            action    : 'thready_save_mockup',
            _ajax_nonce: cfg.nonce,
            tip_slug  : $card.data( 'tip' ),
            boja_slug : $card.data( 'boja' ),
            slot      : slot,
            image_id  : imageId,
        } )
        .done( function ( res ) {
            if ( res.success ) {
                updateSlotUI( $slot, imageId, thumbUrl );
                updateCardStatus( $card, res.data.status );
                showSaved( $indicator );
            } else {
                showError( $indicator, res.data && res.data.message );
            }
        } )
        .fail( function () {
            showError( $indicator );
        } );
    }

    function removeImage( $card, slot ) {
        if ( ! window.confirm( cfg.i18n.confirm_remove ) ) return;

        var $slot      = $card.find( '.slot-' + slot );
        var $indicator = $slot.find( '.slot-saving-indicator' );

        setSavingState( $slot, $indicator );

        $.post( cfg.ajax_url, {
            action    : 'thready_remove_mockup',
            _ajax_nonce: cfg.nonce,
            tip_slug  : $card.data( 'tip' ),
            boja_slug : $card.data( 'boja' ),
            slot      : slot,
        } )
        .done( function ( res ) {
            if ( res.success ) {
                clearSlotUI( $slot );
                updateCardStatus( $card, res.data.status );
                showSaved( $indicator );
            } else {
                showError( $indicator, res.data && res.data.message );
            }
        } )
        .fail( function () {
            showError( $indicator );
        } );
    }

    // -------------------------------------------------------------------------
    // UI update helpers
    // -------------------------------------------------------------------------

    function setSavingState( $slot, $indicator ) {
        $slot.addClass( 'is-saving' );
        $indicator.text( cfg.i18n.saving ).removeClass( 'is-error is-saved' );
    }

    function showSaved( $indicator ) {
        $indicator
            .text( cfg.i18n.saved )
            .addClass( 'is-saved' )
            .removeClass( 'is-error' );
        $indicator.closest( '.image-slot' ).removeClass( 'is-saving' );

        clearTimeout( $indicator.data( 'timer' ) );
        $indicator.data( 'timer', setTimeout( function () {
            $indicator.text( '' ).removeClass( 'is-saved' );
        }, 2500 ) );
    }

    function showError( $indicator, msg ) {
        $indicator
            .text( msg || cfg.i18n.save_error )
            .addClass( 'is-error' )
            .removeClass( 'is-saved' );
        $indicator.closest( '.image-slot' ).removeClass( 'is-saving' );
    }

    /**
     * Update a slot's preview area after a successful save.
     */
    function updateSlotUI( $slot, imageId, thumbUrl ) {
        var $preview = $slot.find( '.slot-preview' );

        $preview
            .removeClass( 'empty' )
            .addClass( 'has-image' )
            .html(
                '<img src="' + escAttr( thumbUrl ) + '" alt="" loading="lazy">' +
                '<button type="button" class="slot-remove" aria-label="Remove image">✕</button>'
            );

        $slot.find( '.slot-image-id' ).val( imageId );
        $slot.find( '.slot-upload-btn' ).text( 'Change' );
    }

    /**
     * Clear a slot after removal.
     */
    function clearSlotUI( $slot ) {
        var $preview = $slot.find( '.slot-preview' );

        $preview
            .removeClass( 'has-image' )
            .addClass( 'empty' )
            .html( '<span class="slot-icon dashicons dashicons-format-image"></span>' );

        $slot.find( '.slot-image-id' ).val( '' );
        $slot.find( '.slot-upload-btn' ).text( 'Upload' );
    }

    /**
     * Update card CSS class and status badge.
     */
    function updateCardStatus( $card, status ) {
        $card
            .removeClass( 'status-complete status-front-only status-empty' )
            .addClass( 'status-' + status );

        var $badge  = $card.find( '.status-badge' );
        var symbols = { complete: '✓', 'front-only': '½', empty: '–' };
        var labels  = {
            complete    : 'Front & back set',
            'front-only': 'Front set — back missing',
            empty       : 'No images',
        };

        $badge
            .attr( 'class', 'status-badge status-' + status )
            .attr( 'title', labels[ status ] || '' )
            .text( symbols[ status ] || '' );
    }

    function escAttr( str ) {
        return String( str )
            .replace( /&/g, '&amp;' )
            .replace( /"/g, '&quot;' )
            .replace( /'/g, '&#39;' )
            .replace( /</g, '&lt;' )
            .replace( />/g, '&gt;' );
    }

    // -------------------------------------------------------------------------
    // Event delegation — one listener per grid, covers dynamic DOM too
    // -------------------------------------------------------------------------

    $( document ).on( 'click', '.slot-upload-btn', function () {
        var $slot = $( this ).closest( '.image-slot' );
        var $card = $( this ).closest( '.thready-color-card' );
        var slot  = $slot.data( 'slot' );
        openMediaPicker( slot, $card );
    } );

    $( document ).on( 'click', '.slot-remove', function ( e ) {
        e.stopPropagation();
        var $slot = $( this ).closest( '.image-slot' );
        var $card = $( this ).closest( '.thready-color-card' );
        var slot  = $slot.data( 'slot' );
        removeImage( $card, slot );
    } );

}( jQuery ) );
