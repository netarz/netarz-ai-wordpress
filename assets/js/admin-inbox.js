/**
 * NetArz AI — operator inbox (wp-admin → AI → Conversations).
 *
 * Two panes: the conversation list and the open thread. Both poll the
 * plugin's REST routes; nothing here talks to NetArz directly.
 */
( function () {
	'use strict';

	var cfg = window.NetarzAIInbox;
	var root = document.getElementById( 'nzai-inbox' );
	if ( ! cfg || ! root || ! window.wp || ! window.wp.apiFetch ) {
		return;
	}
	var t = cfg.i18n;
	var api = window.wp.apiFetch;
	// wp_localize_script turns numbers into strings.
	var openId = parseInt( cfg.open, 10 ) || 0;

	var state = {
		filter: 'open',
		search: '',
		items: [],
		current: 0,
		chat: null,
		messages: [],
		last: 0,
		unread: 0,
		listTimer: null,
		threadTimer: null,
		typingAt: 0,
		sending: false
	};

	/* ------------------------------------------------------------ dom */

	function el( tag, attrs, children ) {
		var node = document.createElement( tag );
		Object.keys( attrs || {} ).forEach( function ( k ) {
			var v = attrs[ k ];
			if ( k === 'class' ) {
				node.className = v;
			} else if ( k === 'text' ) {
				node.textContent = v;
			} else if ( k.indexOf( 'on' ) === 0 && typeof v === 'function' ) {
				node.addEventListener( k.slice( 2 ), v );
			} else if ( v !== null && v !== undefined && v !== false ) {
				node.setAttribute( k, v === true ? '' : v );
			}
		} );
		( children || [] ).forEach( function ( c ) {
			if ( c !== null && c !== undefined && c !== false ) {
				node.appendChild( typeof c === 'string' ? document.createTextNode( c ) : c );
			}
		} );
		return node;
	}

	function linkify( text ) {
		var frag = document.createDocumentFragment();
		var re = /(https?:\/\/[^\s<>"'«»]+[^\s<>"'«».,!?؟،:;)\]])/g;
		var last = 0;
		var m;
		text = String( text || '' );
		while ( ( m = re.exec( text ) ) !== null ) {
			if ( m.index > last ) {
				frag.appendChild( document.createTextNode( text.slice( last, m.index ) ) );
			}
			frag.appendChild( el( 'a', { href: m[ 0 ], target: '_blank', rel: 'noopener', dir: 'ltr', text: m[ 0 ] } ) );
			last = m.index + m[ 0 ].length;
		}
		if ( last < text.length ) {
			frag.appendChild( document.createTextNode( text.slice( last ) ) );
		}
		return frag;
	}

	root.innerHTML = '';

	var ui = {};
	ui.tabs = el( 'div', { class: 'nzai-ib-tabs', role: 'tablist' } );
	[ [ 'waiting', t.waiting ], [ 'open', t.open ], [ 'closed', t.closed ], [ 'all', t.all ] ].forEach( function ( pair ) {
		ui.tabs.appendChild( el( 'button', { type: 'button', 'data-f': pair[ 0 ], role: 'tab', onclick: function () {
			state.filter = pair[ 0 ];
			loadList();
		} }, [ pair[ 1 ] ] ) );
	} );
	var searchTimer = null;
	ui.search = el( 'input', { type: 'search', class: 'nzai-ib-search', placeholder: t.search } );
	ui.search.addEventListener( 'input', function () {
		window.clearTimeout( searchTimer );
		searchTimer = window.setTimeout( function () {
			state.search = ui.search.value.trim();
			loadList();
		}, 350 );
	} );
	ui.list = el( 'ul', { class: 'nzai-ib-list' } );
	ui.side = el( 'section', { class: 'nzai-ib-side' }, [ ui.tabs, ui.search, ui.list ] );

	ui.head = el( 'header', { class: 'nzai-ib-head' } );
	ui.stream = el( 'div', { class: 'nzai-ib-stream' } );
	ui.input = el( 'textarea', { class: 'nzai-ib-input', rows: '3', placeholder: t.reply } );
	ui.input.addEventListener( 'keydown', function ( e ) {
		if ( e.key === 'Enter' && ! e.shiftKey && ! e.isComposing && e.keyCode !== 229 ) {
			e.preventDefault();
			send();
			return;
		}
		pingTyping();
	} );
	ui.draftBtn = el( 'button', { type: 'button', class: 'button', onclick: draft }, [ t.draft ] );
	ui.sendBtn = el( 'button', { type: 'button', class: 'button button-primary', onclick: send }, [ t.send ] );
	ui.error = el( 'p', { class: 'nzai-ib-error', hidden: true } );
	ui.composer = el( 'div', { class: 'nzai-ib-composer' }, [ ui.error, ui.input, el( 'div', { class: 'nzai-ib-cbar' }, [ ui.draftBtn, ui.sendBtn ] ) ] );
	ui.thread = el( 'section', { class: 'nzai-ib-thread' }, [ ui.head, ui.stream, ui.composer ] );
	ui.empty = el( 'div', { class: 'nzai-ib-pick' }, [ t.pick ] );

	root.appendChild( ui.side );
	root.appendChild( ui.empty );
	root.appendChild( ui.thread );
	ui.thread.hidden = true;

	/* ----------------------------------------------------------- list */

	function renderTabs() {
		Array.prototype.forEach.call( ui.tabs.children, function ( b ) {
			b.classList.toggle( 'is-active', b.getAttribute( 'data-f' ) === state.filter );
			b.setAttribute( 'aria-selected', b.getAttribute( 'data-f' ) === state.filter ? 'true' : 'false' );
		} );
	}

	function renderList() {
		renderTabs();
		ui.list.innerHTML = '';
		if ( ! state.items.length ) {
			ui.list.appendChild( el( 'li', { class: 'nzai-ib-none', text: t.empty } ) );
			return;
		}
		state.items.forEach( function ( item ) {
			var who = item.last_sender === 'visitor' ? '' : ( item.last_sender === 'ai' ? t.ai + ': ' : ( item.last_sender === 'agent' ? t.agent + ': ' : '' ) );
			var li = el( 'li', {
				class: 'nzai-ib-item' + ( item.id === state.current ? ' is-current' : '' ) + ( item.waiting && item.status === 'open' ? ' is-waiting' : '' ) + ( item.unread ? ' is-unread' : '' ),
				tabindex: '0',
				onclick: function () {
					openChat( item.id );
				},
				onkeydown: function ( e ) {
					if ( e.key === 'Enter' ) {
						openChat( item.id );
					}
				}
			}, [
				el( 'div', { class: 'nzai-ib-row' }, [
					el( 'strong', { text: item.name } ),
					el( 'span', { class: 'nzai-ib-ago', text: item.ago } )
				] ),
				el( 'div', { class: 'nzai-ib-row' }, [
					el( 'span', { class: 'nzai-ib-last', text: who + item.last } ),
					item.unread ? el( 'span', { class: 'nzai-ib-unread', text: String( item.unread ) } ) : null
				] ),
				el( 'div', { class: 'nzai-ib-flags' }, [
					item.waiting && item.status === 'open' ? el( 'span', { class: 'nzai-pill is-warn', text: t.waiting } ) : null,
					item.status === 'closed' ? el( 'span', { class: 'nzai-pill is-off', text: t.closed } ) : null,
					item.status === 'open' ? el( 'span', { class: 'nzai-pill ' + ( item.ai ? 'is-on' : 'is-off' ), text: item.ai ? t.aiOn : t.aiOff } ) : null,
					item.contact ? el( 'span', { class: 'nzai-ib-contact', dir: 'ltr', text: item.contact } ) : null
				] )
			] );
			ui.list.appendChild( li );
		} );
	}

	var listSeq = 0;
	var listLoaded = false;
	function loadList() {
		window.clearTimeout( state.listTimer );
		var seq = ++listSeq;
		var q = '?filter=' + encodeURIComponent( state.filter ) + ( state.search ? '&search=' + encodeURIComponent( state.search ) : '' );
		return api( { path: cfg.ns + '/inbox' + q } ).then( function ( data ) {
			if ( seq !== listSeq ) {
				return;
			}
			var before = state.unread;
			var items = data.items || [];
			var changed = JSON.stringify( items ) !== JSON.stringify( state.items );
			state.items = items;
			state.unread = data.unread || 0;
			if ( changed ) {
				renderList(); // Redrawing an unchanged list would drop keyboard focus.
			} else {
				renderTabs();
			}
			paintBadge( state.unread );
			if ( listLoaded && state.unread > before ) {
				ding();
			}
			listLoaded = true;
		} ).catch( function () {} ).then( function () {
			// Only the newest request keeps the loop going, so loops never pile up.
			if ( seq === listSeq ) {
				state.listTimer = window.setTimeout( loadList, document.hidden ? 30000 : 8000 );
			}
		} );
	}

	function paintBadge( count ) {
		document.querySelectorAll( '#toplevel_page_netarz-ai .nzai-chat-bubble' ).forEach( function ( node ) {
			node.style.display = count > 0 ? '' : 'none';
			var inner = node.querySelector( '.nzai-count' );
			if ( inner ) {
				inner.textContent = String( count );
			}
		} );
	}

	/* --------------------------------------------------------- thread */

	function openChat( id ) {
		state.current = id;
		state.messages = [];
		state.last = 0;
		state.chat = null;
		ui.stream.innerHTML = '';
		ui.head.innerHTML = '';
		ui.error.hidden = true;
		ui.input.value = '';
		ui.empty.hidden = true;
		ui.thread.hidden = false;
		root.classList.add( 'has-thread' );
		renderList();
		loadThread( true );
		try {
			var url = new window.URL( window.location.href );
			url.searchParams.set( 'chat', String( id ) );
			window.history.replaceState( null, '', url.toString() );
		} catch ( e ) {}
	}

	var threadSeq = 0;
	function loadThread( first ) {
		window.clearTimeout( state.threadTimer );
		var seq = ++threadSeq;
		var id = state.current;
		if ( ! id ) {
			return;
		}
		api( { path: cfg.ns + '/inbox/' + id + '?after=' + state.last } ).then( function ( data ) {
			if ( id !== state.current || seq !== threadSeq ) {
				return;
			}
			applyThread( data, first );
		} ).catch( function ( err ) {
			if ( err && err.code === 'chat_not_found' ) {
				closeThread();
			}
		} ).then( function () {
			if ( id === state.current && seq === threadSeq ) {
				state.threadTimer = window.setTimeout( loadThread, document.hidden ? 20000 : 3000 );
			}
		} );
	}

	function applyThread( data, first ) {
		var incoming = false;
		( data.messages || [] ).forEach( function ( m ) {
			if ( m.id > state.last ) {
				state.messages.push( m );
				state.last = m.id;
				if ( m.sender === 'visitor' ) {
					incoming = true;
				}
			}
		} );
		var headChanged = JSON.stringify( data.chat ) !== JSON.stringify( state.chat );
		var added = ( data.messages || [] ).some( function ( m ) {
			return m.id >= state.last;
		} );
		state.chat = data.chat;
		// Redraw only what changed, so the operator can select and copy text between polls.
		if ( headChanged || first ) {
			renderHead();
		}
		if ( first || added || headChanged ) {
			renderStream( first || incoming );
		}
		if ( incoming && ! first ) {
			ding();
		}
	}

	function renderHead() {
		var c = state.chat;
		if ( ! c ) {
			return;
		}
		ui.head.innerHTML = '';
		var info = el( 'div', { class: 'nzai-ib-who' }, [
			el( 'button', { type: 'button', class: 'button-link nzai-ib-back', onclick: closeThread }, [ '→ ' + t.back ] ),
			el( 'h2', { text: c.name } ),
			el( 'p', { class: 'nzai-ib-meta' }, [
				el( 'span', { class: 'nzai-dot ' + ( c.visitor_online ? 'is-on' : 'is-off' ) } ),
				c.visitor_online ? t.online : t.offline,
				c.mobile ? el( 'span', { dir: 'ltr', text: ' · ' + c.mobile } ) : null,
				c.email ? el( 'span', { dir: 'ltr', text: ' · ' + c.email } ) : null,
				c.user_link ? el( 'a', { href: c.user_link, text: ' · ' + t.user } ) : null,
				c.rating ? el( 'span', { text: ' · ' + t.rating + ': ' + c.rating + '/5' } ) : null
			] ),
			c.page ? el( 'p', { class: 'nzai-ib-meta' }, [ t.page + ': ', el( 'a', { href: c.page, target: '_blank', rel: 'noopener', dir: 'ltr', text: c.page.length > 70 ? c.page.slice( 0, 70 ) + '…' : c.page } ) ] ) : null,
			c.waiting && c.reason ? el( 'p', { class: 'nzai-ib-reason' }, [ el( 'strong', { text: t.reason + ' ' } ), c.reason ] ) : null
		] );

		var aiLabel = ! c.ai_global ? t.aiGlobalOff : ( c.ai ? t.aiOn : t.aiOff );
		var actions = el( 'div', { class: 'nzai-ib-actions' }, [
			el( 'button', { type: 'button', class: 'button nzai-ib-ai ' + ( c.ai && c.ai_global ? 'is-on' : '' ), disabled: ! c.ai_global || ! cfg.aiReady, title: aiLabel, onclick: function () {
				act( 'ai', { enabled: ! c.ai } );
			} }, [ aiLabel ] ),
			c.ticket_link
				? el( 'a', { class: 'button', href: c.ticket_link }, [ t.openTicket ] )
				: el( 'button', { type: 'button', class: 'button', onclick: function () {
					act( 'ticket', {} );
				} }, [ t.ticket ] ),
			el( 'button', { type: 'button', class: 'button', onclick: function () {
				act( 'status', { status: c.status === 'open' ? 'closed' : 'open' } );
			} }, [ c.status === 'open' ? t.close : t.reopen ] ),
			cfg.isAdmin ? el( 'button', { type: 'button', class: 'button button-link-delete', onclick: remove }, [ t.delete ] ) : null
		] );
		ui.head.appendChild( info );
		ui.head.appendChild( actions );
		ui.draftBtn.disabled = ! cfg.aiReady;
	}

	function renderStream( scroll ) {
		var nearBottom = ui.stream.scrollHeight - ui.stream.scrollTop - ui.stream.clientHeight < 160;
		ui.stream.innerHTML = '';
		var seen = state.chat ? state.chat.visitor_seen : 0;
		state.messages.forEach( function ( m ) {
			if ( m.sender === 'system' ) {
				ui.stream.appendChild( el( 'p', { class: 'nzai-ib-sys' }, [ linkify( m.body ), el( 'small', { text: ' — ' + m.time } ) ] ) );
				return;
			}
			var label = m.sender === 'visitor' ? ( state.chat ? state.chat.name : t.visitor ) : ( m.sender === 'ai' ? t.ai : ( m.author || t.agent ) );
			var text = el( 'div', { class: 'nzai-ib-text' } );
			text.appendChild( linkify( m.body ) );
			ui.stream.appendChild( el( 'div', { class: 'nzai-ib-msg is-' + m.sender }, [
				el( 'div', { class: 'nzai-ib-by' }, [
					el( 'strong', { text: label } ),
					m.sender === 'ai' ? el( 'span', { class: 'nzai-pill is-ai', text: 'AI' } ) : null,
					el( 'span', { text: m.time } ),
					m.sender !== 'visitor' && m.id <= seen ? el( 'span', { class: 'nzai-ib-seen', text: '✓✓ ' + t.seen } ) : null
				] ),
				text
			] ) );
		} );
		if ( scroll || nearBottom ) {
			ui.stream.scrollTop = ui.stream.scrollHeight;
		}
		var closed = state.chat && state.chat.status === 'closed';
		ui.composer.classList.toggle( 'is-closed', !! closed );
	}

	/** threadSeq was bumped to drop a stale poll; keep the loop alive with a fresh one. */
	function restartThreadPoll() {
		window.clearTimeout( state.threadTimer );
		state.threadTimer = window.setTimeout( loadThread, 3000 );
	}

	function closeThread() {
		state.current = 0;
		window.clearTimeout( state.threadTimer );
		ui.thread.hidden = true;
		ui.empty.hidden = false;
		root.classList.remove( 'has-thread' );
		renderList();
	}

	function showError( err ) {
		ui.error.textContent = ( err && err.message ) || t.error;
		ui.error.hidden = false;
	}

	function send() {
		var body = ui.input.value.trim();
		if ( ! body || ! state.current || state.sending ) {
			return;
		}
		state.sending = true;
		ui.sendBtn.disabled = true;
		ui.error.hidden = true;
		var id = state.current;
		api( { path: cfg.ns + '/inbox/' + id + '/reply', method: 'POST', data: { body: body } } ).then( function ( data ) {
			if ( id !== state.current ) {
				return; // The operator moved to another conversation meanwhile.
			}
			ui.input.value = '';
			threadSeq++;
			state.messages = [];
			state.last = 0;
			applyThread( data, true );
			restartThreadPoll();
			loadList();
		} ).catch( function ( err ) {
			if ( id === state.current ) {
				showError( err );
			}
		} ).then( function () {
			state.sending = false;
			ui.sendBtn.disabled = false;
			ui.input.focus();
		} );
	}

	function pingTyping() {
		var now = Date.now();
		if ( ! state.current || now - state.typingAt < 4000 ) {
			return;
		}
		state.typingAt = now;
		api( { path: cfg.ns + '/inbox/' + state.current + '/typing', method: 'POST', data: {} } ).catch( function () {} );
	}

	function act( action, data ) {
		ui.error.hidden = true;
		var id = state.current;
		api( { path: cfg.ns + '/inbox/' + id + '/' + action, method: 'POST', data: data } ).then( function ( res ) {
			if ( id !== state.current ) {
				return;
			}
			threadSeq++;
			state.messages = [];
			state.last = 0;
			applyThread( res, true );
			restartThreadPoll();
			loadList();
		} ).catch( function ( err ) {
			if ( id === state.current ) {
				showError( err );
			}
		} );
	}

	function draft() {
		if ( ! state.current ) {
			return;
		}
		var label = ui.draftBtn.textContent;
		ui.draftBtn.disabled = true;
		ui.draftBtn.textContent = t.drafting;
		ui.error.hidden = true;
		var id = state.current;
		api( { path: cfg.ns + '/inbox/' + id + '/draft', method: 'POST', data: {} } ).then( function ( res ) {
			if ( id !== state.current ) {
				return; // Never drop one customer's draft into another's composer.
			}
			ui.input.value = res.draft || '';
			ui.input.focus();
		} ).catch( function ( err ) {
			if ( id === state.current ) {
				showError( err );
			}
		} ).then( function () {
			ui.draftBtn.disabled = false;
			ui.draftBtn.textContent = label;
		} );
	}

	function remove() {
		if ( ! window.confirm( t.confirmDel ) ) {
			return;
		}
		api( { path: cfg.ns + '/inbox/' + state.current, method: 'DELETE' } ).then( function () {
			closeThread();
			loadList();
		} ).catch( showError );
	}

	/* ---------------------------------------------------------- sound */

	var ctx = null;
	function ding() {
		try {
			var C = window.AudioContext || window.webkitAudioContext;
			if ( ! C ) {
				return;
			}
			ctx = ctx || new C();
			var o = ctx.createOscillator();
			var g = ctx.createGain();
			o.frequency.setValueAtTime( 660, ctx.currentTime );
			o.frequency.setValueAtTime( 990, ctx.currentTime + 0.1 );
			g.gain.setValueAtTime( 0.0001, ctx.currentTime );
			g.gain.exponentialRampToValueAtTime( 0.15, ctx.currentTime + 0.02 );
			g.gain.exponentialRampToValueAtTime( 0.0001, ctx.currentTime + 0.4 );
			o.connect( g );
			g.connect( ctx.destination );
			o.start();
			o.stop( ctx.currentTime + 0.45 );
		} catch ( e ) {}
	}

	document.addEventListener( 'visibilitychange', function () {
		if ( ! document.hidden ) {
			loadList();
			if ( state.current ) {
				loadThread();
			}
		}
	} );

	/* ----------------------------------------------------------- boot */

	if ( openId ) {
		state.filter = 'all';
	}
	loadList().then( function () {
		if ( openId ) {
			openChat( openId );
		} else if ( ! state.items.length && state.filter === 'open' ) {
			state.filter = 'all';
			loadList();
		}
	} );
}() );
