/**
 * NetArz AI — ticket screen helpers: AI draft, summary, canned replies.
 */
( function ( $ ) {
	'use strict';
	var cfg = window.NetarzAITickets;
	var box = $( '.nzai-ticket' );
	if ( ! cfg || ! box.length ) {
		return;
	}
	var id = box.data( 'id' );
	var api = window.wp.apiFetch;
	var text = box.find( '.nzai-reply-text' );

	function fill( value ) {
		if ( $.trim( text.val() ) && ! window.confirm( cfg.i18n.overwrite ) ) {
			return;
		}
		text.val( value ).trigger( 'focus' );
		text[ 0 ].scrollIntoView( { behavior: 'smooth', block: 'center' } );
	}

	box.on( 'click', '.nzai-draft', function () {
		var btn = $( this );
		var spinner = btn.siblings( '.spinner' );
		btn.prop( 'disabled', true );
		spinner.addClass( 'is-active' );
		api( { path: cfg.ns + '/tickets/' + id + '/draft', method: 'POST', data: {} } ).then( function ( res ) {
			fill( res.draft || '' );
		} ).catch( function ( err ) {
			window.alert( cfg.i18n.error + ': ' + ( ( err && err.message ) || '' ) );
		} ).then( function () {
			btn.prop( 'disabled', false );
			spinner.removeClass( 'is-active' );
		} );
	} );

	box.on( 'click', '.nzai-summarize', function () {
		var btn = $( this );
		var out = box.find( '.nzai-summary-text' );
		btn.prop( 'disabled', true );
		out.text( cfg.i18n.working );
		api( { path: cfg.ns + '/tickets/' + id + '/summary', method: 'POST', data: {} } ).then( function ( res ) {
			out.text( res.summary || '' );
		} ).catch( function ( err ) {
			out.text( cfg.i18n.error + ': ' + ( ( err && err.message ) || '' ) );
		} ).then( function () {
			btn.prop( 'disabled', false );
		} );
	} );

	box.on( 'click', '.nzai-use-draft', function () {
		fill( String( $( this ).attr( 'data-text' ) || '' ) );
	} );

	box.on( 'change', '.nzai-canned', function () {
		var i = parseInt( $( this ).val(), 10 );
		if ( ! isNaN( i ) && cfg.canned[ i ] ) {
			var current = $.trim( text.val() );
			text.val( current ? current + '\n\n' + cfg.canned[ i ].text : cfg.canned[ i ].text ).trigger( 'focus' );
		}
		$( this ).val( '' );
	} );

	box.on( 'click', '.nzai-delete-ticket', function ( e ) {
		if ( ! window.confirm( cfg.i18n.confirm ) ) {
			e.preventDefault();
		}
	} );
}( jQuery ) );
