/**
 * wpadm — ACF Flexible Content preview.
 *
 * Collects unsaved values from the post edit form, sends them to the server
 * and shows the rendered layout in an iframe modal.
 *
 * All user-facing strings arrive translated from PHP via wp_localize_script,
 * so nothing here needs a translation call.
 */
( function ( $ ) {
	'use strict';

	if ( typeof window.acf === 'undefined' || typeof window.WPAdmFlexPreview === 'undefined' ) {
		return;
	}

	var cfg     = window.WPAdmFlexPreview;
	var i18n    = cfg.i18n;
	var $modal  = null;
	var lastReq = null;

	/**
	 * Finds the container holding the ACF fields.
	 *
	 * The classic editor has <form id="post">. The block editor does not — its
	 * meta box forms are .metabox-base-form / .metabox-location-*, so body is
	 * used as the safest common ancestor there.
	 */
	function getScope() {
		var candidates = [ '#post', 'form.acf-form', 'form.metabox-base-form' ];

		for ( var i = 0; i < candidates.length; i++ ) {
			var $el = $( candidates[ i ] ).first();

			if ( $el.length && $el.find( '[name^="acf["]' ).length ) {
				return $el;
			}
		}

		return $( document.body );
	}

	/**
	 * Collects every ACF input, skipping clone rows and disabled fields.
	 */
	function collectValues() {
		if ( window.tinymce ) {
			window.tinymce.triggerSave();
		}

		return getScope()
			.find( 'input[name^="acf["], select[name^="acf["], textarea[name^="acf["]' )
			.not( ':disabled' )
			.not( '.acf-clone input, .acf-clone select, .acf-clone textarea' )
			.serializeArray();
	}

	/**
	 * Escapes a translated string for use inside an HTML attribute.
	 *
	 * These strings reach the page through attributes now rather than as text,
	 * and a translator writing an apostrophe or a quote in them should not be
	 * able to break the markup.
	 */
	function attr( value ) {
		return String( value )
			.replace( /&/g, '&amp;' )
			.replace( /"/g, '&quot;' )
			.replace( /'/g, '&#39;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' );
	}

	/**
	 * A button that shows a dashicon and says its name to a screen reader.
	 *
	 * The label is never visible, so it goes in twice: `title` for the pointer
	 * and `aria-label` for assistive technology. The icon itself is hidden from
	 * the accessibility tree — an icon font's glyph is a private-use character
	 * and announcing it says nothing useful.
	 *
	 * @param {string} cls   Class for the button.
	 * @param {string} icon  Dashicon name, without the `dashicons-` prefix.
	 * @param {string} label Translated name of the action.
	 * @param {string} extra Any further attributes, already escaped.
	 */
	function iconButton( cls, icon, label, extra ) {
		return '<button type="button" class="' + cls + '"' +
			' title="' + attr( label ) + '"' +
			' aria-label="' + attr( label ) + '"' +
			( extra || '' ) + '>' +
			'<span class="dashicons dashicons-' + icon + '" aria-hidden="true"></span>' +
			'</button>';
	}

	/**
	 * Builds the modal once and returns it on every later call.
	 */
	function getModal() {
		if ( $modal ) {
			return $modal;
		}

		$modal = $(
			'<div class="wpadm-fp-modal" role="dialog" aria-modal="true" aria-label="' + attr( i18n.title ) + '">' +
				'<div class="wpadm-fp-backdrop"></div>' +
				'<div class="wpadm-fp-panel">' +
					'<div class="wpadm-fp-bar">' +
						'<span class="wpadm-fp-title">' + i18n.title + '</span>' +
						'<div class="wpadm-fp-sizes">' +
							iconButton( 'wpadm-fp-size is-active', 'desktop', i18n.desktop, ' data-width="100%" aria-pressed="true"' ) +
							iconButton( 'wpadm-fp-size', 'tablet', i18n.tablet, ' data-width="820px" aria-pressed="false"' ) +
							iconButton( 'wpadm-fp-size', 'smartphone', i18n.mobile, ' data-width="390px" aria-pressed="false"' ) +
						'</div>' +
						iconButton( 'wpadm-fp-reload', 'update', i18n.reload ) +
						iconButton( 'wpadm-fp-close', 'no-alt', i18n.close ) +
					'</div>' +
					'<div class="wpadm-fp-stage">' +
						'<div class="wpadm-fp-status"></div>' +
						'<iframe class="wpadm-fp-frame" title="' + i18n.title + '"></iframe>' +
					'</div>' +
				'</div>' +
			'</div>'
		).appendTo( 'body' );

		$modal.on( 'click', '.wpadm-fp-close, .wpadm-fp-backdrop', closeModal );

		$modal.on( 'click', '.wpadm-fp-size', function () {
			var $btn = $( this );

			$btn.addClass( 'is-active' ).attr( 'aria-pressed', 'true' );
			$btn.siblings().removeClass( 'is-active' ).attr( 'aria-pressed', 'false' );

			$modal.find( '.wpadm-fp-frame' ).css( 'width', $btn.data( 'width' ) );
		} );

		$modal.on( 'click', '.wpadm-fp-reload', function () {
			if ( lastReq ) {
				openPreview( lastReq.row, lastReq.field );
			}
		} );

		$( document ).on( 'keydown.wpadmfp', function ( e ) {
			if ( 27 === e.keyCode && $modal.hasClass( 'is-open' ) ) {
				closeModal();
			}
		} );

		return $modal;
	}

	function closeModal() {
		if ( ! $modal ) {
			return;
		}

		$modal.removeClass( 'is-open' );
		$modal.find( '.wpadm-fp-frame' ).attr( 'src', 'about:blank' );
		$( 'body' ).removeClass( 'wpadm-fp-locked' );
	}

	function setStatus( text, isError ) {
		var $m = getModal();

		$m.find( '.wpadm-fp-status' )
			.text( text || '' )
			.toggleClass( 'is-error', !! isError )
			.toggle( !! text );

		$m.find( '.wpadm-fp-frame' ).toggle( ! text );
	}

	/**
	 * Sends the current values to the server and loads the returned URL.
	 *
	 * @param {number} row   Layout index, -1 for all layouts.
	 * @param {string} field Flexible content field name.
	 */
	function openPreview( row, field ) {
		lastReq = { row: row, field: field };

		var $m = getModal();

		$m.addClass( 'is-open' );
		$( 'body' ).addClass( 'wpadm-fp-locked' );
		setStatus( i18n.loading, false );

		var values = collectValues();

		if ( ! values.length ) {
			// Better to stop here than send an empty request — the message
			// points at where the problem actually is.
			window.console && window.console.warn(
				'[wpadm] No ACF inputs found. Check in the console: ' +
				'jQuery("[name^=\'acf[\']").length'
			);

			setStatus( i18n.noFields, true );
			return;
		}

		var payload = $.param( values ) + '&' + $.param( {
			action:  'wpadm_flex_preview',
			nonce:   cfg.nonce,
			post_id: cfg.postId,
			row:     row,
			field:   field
		} );

		$.post( cfg.ajaxUrl, payload )
			.done( function ( res ) {
				if ( ! res || ! res.success || ! res.data || ! res.data.url ) {
					setStatus( ( res && res.data && res.data.message ) || i18n.genError, true );
					return;
				}

				setStatus( '', false );
				$m.find( '.wpadm-fp-frame' ).attr( 'src', res.data.url );
			} )
			.fail( function ( xhr ) {
				var msg = i18n.genError;

				if ( xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message ) {
					msg = xhr.responseJSON.data.message;
				}

				setStatus( msg, true );
			} );
	}

	$( document ).on( 'click', '.wpadm-fp-btn', function ( e ) {
		e.preventDefault();
		e.stopPropagation();

		var $btn    = $( this );
		var $layout = $btn.closest( '.layout' );
		var $fc     = $layout.closest( '.acf-flexible-content' );
		var row     = $fc.find( '> .values > .layout' ).not( '.acf-clone' ).index( $layout );

		openPreview( row, $btn.data( 'field' ) );
	} );
} )( jQuery );
