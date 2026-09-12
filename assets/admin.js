/* global zwPangram, jQuery */
( function ( $ ) {
	'use strict';

	function testConnection() {
		const $result = $( '#zw-pangram-test-result' );
		$result.removeClass( 'is-ok is-error' ).text( zwPangram.i18n.testing );
		wp.ajax
			.post( 'zw_pangram_test_connection', {
				nonce: zwPangram.nonce,
				api_key: $( '#zw-pangram-api-key' ).val() || '',
			} )
			.done( function () {
				$result.addClass( 'is-ok' ).text( zwPangram.i18n.valid );
			} )
			.fail( function ( error ) {
				const message =
					error && error.message ? error.message : 'HTTP error';
				$result
					.addClass( 'is-error' )
					.text( zwPangram.i18n.invalid + ' ' + message );
			} );
	}

	function pollStatus() {
		if ( document.hidden ) {
			return;
		}
		wp.ajax
			.post( 'zw_pangram_queue_status', { nonce: zwPangram.nonce } )
			.done( function ( data ) {
				$.each( data.counts, function ( key, value ) {
					$( '#zw-pangram-status [data-count="' + key + '"]' ).text(
						value
					);
				} );
			} );
	}

	$( function () {
		$( '#zw-pangram-test-connection' ).on( 'click', testConnection );
		if ( zwPangram.tab === 'scan' && $( '#zw-pangram-status' ).length ) {
			window.setInterval( pollStatus, 15000 );
		}
	} );
} )( jQuery );
