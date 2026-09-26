/**
 * Keeps the "AI" menu badge current on every admin screen.
 */
( function () {
	'use strict';
	var cfg = window.NetarzAIBadge;
	if ( ! cfg || ! window.wp || ! window.wp.apiFetch || cfg.inbox ) {
		// The inbox screen updates the badge itself.
		return;
	}
	var last = null;

	function paint( count ) {
		document.querySelectorAll( '#toplevel_page_netarz-ai .nzai-chat-bubble' ).forEach( function ( node ) {
			node.style.display = count > 0 ? '' : 'none';
			var inner = node.querySelector( '.nzai-count' );
			if ( inner ) {
				inner.textContent = String( count );
			}
		} );
		if ( last !== null && count > last && document.hidden === false ) {
			document.title = '(' + count + ') ' + document.title.replace( /^\(\d+\)\s/, '' );
		}
		last = count;
	}

	function tick() {
		if ( document.hidden ) {
			return;
		}
		window.wp.apiFetch( { path: cfg.path } ).then( function ( r ) {
			paint( parseInt( r.count, 10 ) || 0 );
		} ).catch( function () {} );
	}

	window.setInterval( tick, 45000 );
}() );
