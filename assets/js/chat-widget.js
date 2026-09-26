/**
 * NetArz AI — live chat widget.
 *
 * Plain JavaScript, no dependencies. Talks only to this site's REST API
 * (/wp-json/netarz-ai/v1/chat/…); the site talks to NetArz.
 */
( function () {
	'use strict';

	var cfg = window.NetarzAIChat;
	if ( ! cfg || ! document.getElementById ) {
		return;
	}
	var t = cfg.i18n || {};
	var STORE = 'nzai_chat_v1';

	/* ------------------------------------------------------------ utils */

	function store( key, value ) {
		try {
			var all = JSON.parse( window.localStorage.getItem( STORE ) || '{}' ) || {};
			if ( arguments.length === 1 ) {
				return all[ key ];
			}
			if ( value === null ) {
				delete all[ key ];
			} else {
				all[ key ] = value;
			}
			window.localStorage.setItem( STORE, JSON.stringify( all ) );
		} catch ( e ) {
			return undefined;
		}
		return value;
	}

	function session( key, value ) {
		try {
			if ( arguments.length === 1 ) {
				return window.sessionStorage.getItem( 'nzai_' + key );
			}
			window.sessionStorage.setItem( 'nzai_' + key, value );
		} catch ( e ) {
			return null;
		}
		return value;
	}

	function el( tag, attrs, children ) {
		var node = document.createElement( tag );
		if ( attrs ) {
			Object.keys( attrs ).forEach( function ( k ) {
				if ( k === 'class' ) {
					node.className = attrs[ k ];
				} else if ( k === 'text' ) {
					node.textContent = attrs[ k ];
				} else if ( k.indexOf( 'on' ) === 0 && typeof attrs[ k ] === 'function' ) {
					node.addEventListener( k.slice( 2 ), attrs[ k ] );
				} else if ( attrs[ k ] !== null && attrs[ k ] !== undefined && attrs[ k ] !== false ) {
					node.setAttribute( k, attrs[ k ] === true ? '' : attrs[ k ] );
				}
			} );
		}
		( children || [] ).forEach( function ( c ) {
			if ( c ) {
				node.appendChild( typeof c === 'string' ? document.createTextNode( c ) : c );
			}
		} );
		return node;
	}

	var ICONS = {
		chat: '<path d="M7.9 20A9 9 0 1 0 4 16.1L2 22Z"/>',
		x: '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>',
		send: '<path d="M14.536 21.686a.5.5 0 0 0 .937-.024l6.5-19a.496.496 0 0 0-.635-.635l-19 6.5a.5.5 0 0 0-.024.937l7.93 3.18a2 2 0 0 1 1.112 1.11z"/><path d="m21.854 2.147-10.94 10.939"/>',
		vol: '<path d="M11 4.702a.705.705 0 0 0-1.203-.498L6.413 7.587A1.4 1.4 0 0 1 5.416 8H3a1 1 0 0 0-1 1v6a1 1 0 0 0 1 1h2.416a1.4 1.4 0 0 1 .997.413l3.383 3.384A.705.705 0 0 0 11 19.298z"/><path d="M16 9a5 5 0 0 1 0 6"/><path d="M19.364 18.364a9 9 0 0 0 0-12.728"/>',
		mute: '<path d="M11 4.702a.705.705 0 0 0-1.203-.498L6.413 7.587A1.4 1.4 0 0 1 5.416 8H3a1 1 0 0 0-1 1v6a1 1 0 0 0 1 1h2.416a1.4 1.4 0 0 1 .997.413l3.383 3.384A.705.705 0 0 0 11 19.298z"/><line x1="22" x2="16" y1="9" y2="15"/><line x1="16" x2="22" y1="9" y2="15"/>',
		more: '<circle cx="12" cy="12" r="1"/><circle cx="12" cy="5" r="1"/><circle cx="12" cy="19" r="1"/>',
		user: '<circle cx="12" cy="8" r="5"/><path d="M20 21a8 8 0 0 0-16 0"/>',
		ticket: '<path d="M2 9a3 3 0 0 1 0 6v2a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-2a3 3 0 0 1 0-6V7a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2Z"/><path d="M13 5v2"/><path d="M13 17v2"/><path d="M13 11v2"/>',
		end: '<path d="M18.36 6.64A9 9 0 1 1 5.64 6.64"/><path d="M12 2v10"/>',
		check: '<path d="M20 6 9 17l-5-5"/>',
		checks: '<path d="M18 6 7 17l-5-5"/><path d="m22 10-7.5 7.5L13 16"/>',
		star: '<path d="M11.525 2.295a.53.53 0 0 1 .95 0l2.31 4.679a2.123 2.123 0 0 0 1.595 1.16l5.166.756a.53.53 0 0 1 .294.904l-3.736 3.638a2.123 2.123 0 0 0-.611 1.878l.882 5.14a.53.53 0 0 1-.771.56l-4.618-2.428a2.122 2.122 0 0 0-1.973 0L6.396 21.01a.53.53 0 0 1-.77-.56l.881-5.139a2.122 2.122 0 0 0-.611-1.879L2.16 9.795a.53.53 0 0 1 .294-.906l5.165-.755a2.122 2.122 0 0 0 1.597-1.16z"/>'
	};

	function icon( name, cls ) {
		var span = document.createElement( 'span' );
		span.className = 'nzai-icon' + ( cls ? ' ' + cls : '' );
		span.setAttribute( 'aria-hidden', 'true' );
		span.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' + ICONS[ name ] + '</svg>';
		return span;
	}

	/** Text → nodes, with http(s) links made clickable. Never uses innerHTML for user text. */
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
			var a = el( 'a', { href: m[ 0 ], target: '_blank', rel: 'noopener nofollow', dir: 'ltr', text: m[ 0 ] } );
			frag.appendChild( a );
			last = m.index + m[ 0 ].length;
		}
		if ( last < text.length ) {
			frag.appendChild( document.createTextNode( text.slice( last ) ) );
		}
		return frag;
	}

	/* -------------------------------------------------------------- api */

	var nonce = cfg.nonce || '';

	function api( method, path, body, retried ) {
		var headers = { Accept: 'application/json' };
		if ( body ) {
			headers[ 'Content-Type' ] = 'application/json';
		}
		if ( nonce ) {
			headers[ 'X-WP-Nonce' ] = nonce;
		}
		if ( state.token ) {
			headers[ 'X-Netarz-Token' ] = state.token;
		}
		return window.fetch( cfg.rest + path, {
			method: method,
			headers: headers,
			credentials: 'same-origin',
			body: body ? JSON.stringify( body ) : undefined
		} ).then( function ( res ) {
			return res.json().catch( function () {
				return {};
			} ).then( function ( data ) {
				// A cached page can carry a stale nonce; retry as a guest.
				if ( res.status === 403 && data && data.code === 'rest_cookie_invalid_nonce' && ! retried ) {
					nonce = '';
					return api( method, path, body, true );
				}
				if ( ! res.ok ) {
					var err = new Error( ( data && data.message ) || t.errorGeneric );
					err.status = res.status;
					err.code = data && data.code;
					throw err;
				}
				return data;
			} );
		} );
	}

	/* ------------------------------------------------------------ state */

	var state = {
		uuid: store( 'uuid' ) || '',
		token: store( 'token' ) || '',
		messages: [],
		last: 0,
		info: null,
		open: false,
		muted: store( 'muted' ) === true,
		unread: 0,
		busy: false,
		thinking: false,
		pollTimer: null,
		replyTimer: null,
		replyTries: 0,
		online: true,
		offlineText: ''
	};

	/* --------------------------------------------------------------- UI */

	var root = document.getElementById( 'netarz-ai-chat-root' );
	if ( ! root ) {
		root = el( 'div', { id: 'netarz-ai-chat-root', class: 'nzai-root' } );
		document.body.appendChild( root );
	}
	root.hidden = false;
	root.classList.add( cfg.position === 'left' ? 'nzai-left' : 'nzai-right' );
	if ( cfg.hideMobile ) {
		root.classList.add( 'nzai-hide-mobile' );
	}
	root.style.setProperty( '--nzai-accent', cfg.color || '#f5b301' );
	root.style.setProperty( '--nzai-on-accent', cfg.textColor || '#1a1a2e' );
	var offset = parseInt( cfg.offset, 10 );
	root.style.setProperty( '--nzai-bottom', ( isNaN( offset ) ? 20 : offset ) + 'px' );
	root.setAttribute( 'dir', 'rtl' );

	var ui = {};

	ui.launcher = el( 'button', { type: 'button', class: 'nzai-launcher', 'aria-label': t.open, onclick: toggle }, [
		icon( 'chat', 'nzai-launcher-open' ),
		icon( 'x', 'nzai-launcher-close' )
	] );
	ui.badge = el( 'span', { class: 'nzai-badge', hidden: true } );
	ui.launcher.appendChild( ui.badge );

	ui.teaser = el( 'div', { class: 'nzai-teaser', hidden: true, role: 'status' }, [
		el( 'button', { type: 'button', class: 'nzai-teaser-text', onclick: function () {
			hideTeaser();
			openPanel();
		} }, [ cfg.teaser || '' ] ),
		el( 'button', { type: 'button', class: 'nzai-teaser-x', 'aria-label': t.close, onclick: hideTeaser }, [ icon( 'x' ) ] )
	] );

	// Header.
	ui.status = el( 'span', { class: 'nzai-status-text' } );
	ui.dot = el( 'span', { class: 'nzai-dot' } );
	ui.muteBtn = el( 'button', { type: 'button', class: 'nzai-hbtn', onclick: toggleMute } );
	ui.menuBtn = el( 'button', { type: 'button', class: 'nzai-hbtn', 'aria-label': '…', 'aria-haspopup': 'true', onclick: toggleMenu }, [ icon( 'more' ) ] );
	ui.header = el( 'header', { class: 'nzai-header' }, [
		el( 'span', { class: 'nzai-avatar' }, [ icon( 'chat' ) ] ),
		el( 'div', { class: 'nzai-htext' }, [
			el( 'strong', { text: cfg.title || '' } ),
			el( 'span', { class: 'nzai-status' }, [ ui.dot, ui.status ] )
		] ),
		ui.muteBtn,
		ui.menuBtn,
		el( 'button', { type: 'button', class: 'nzai-hbtn', 'aria-label': t.close, onclick: closePanel }, [ icon( 'x' ) ] )
	] );

	// Menu.
	ui.menuHuman = el( 'button', { type: 'button', onclick: requestHuman }, [ icon( 'user' ), t.human ] );
	ui.menuTicket = el( 'button', { type: 'button', onclick: showTicketForm }, [ icon( 'ticket' ), t.ticket ] );
	ui.menuEnd = el( 'button', { type: 'button', onclick: endChat }, [ icon( 'end' ), t.end ] );
	ui.menu = el( 'div', { class: 'nzai-menu', hidden: true, role: 'menu' }, [ ui.menuHuman, ui.menuTicket, ui.menuEnd ] );

	// Intro form.
	ui.form = buildIntro();

	// Stream.
	ui.stream = el( 'div', { class: 'nzai-stream' } );
	// Screen readers hear each new reply once, not the whole thread again.
	ui.live = el( 'div', { class: 'nzai-sr', 'aria-live': 'polite', 'aria-atomic': 'true' } );
	ui.typing = el( 'div', { class: 'nzai-typing', hidden: true }, [
		el( 'span', { class: 'nzai-dots' }, [ el( 'i' ), el( 'i' ), el( 'i' ) ] ),
		el( 'span', { text: t.typing } )
	] );
	ui.notice = el( 'div', { class: 'nzai-notice', hidden: true } );
	ui.after = el( 'div', { class: 'nzai-after', hidden: true } );
	ui.body = el( 'div', { class: 'nzai-body', hidden: true }, [ ui.stream, ui.typing, ui.notice, ui.after, ui.live ] );

	// Composer.
	ui.input = el( 'textarea', { class: 'nzai-input', rows: '1', placeholder: t.typeHere, 'aria-label': t.message, maxlength: String( cfg.maxLength || 1500 ) } );
	ui.input.addEventListener( 'keydown', function ( e ) {
		// keyCode 229: Safari reports an IME composition this way instead of isComposing.
		if ( e.key === 'Enter' && ! e.shiftKey && ! e.isComposing && e.keyCode !== 229 ) {
			e.preventDefault();
			send();
		}
	} );
	ui.input.addEventListener( 'input', autosize );
	ui.sendBtn = el( 'button', { type: 'button', class: 'nzai-send', 'aria-label': t.send, onclick: send }, [ icon( 'send' ) ] );
	ui.error = el( 'p', { class: 'nzai-error', hidden: true, role: 'alert' } );
	ui.composer = el( 'div', { class: 'nzai-composer', hidden: true }, [ ui.error, el( 'div', { class: 'nzai-crow' }, [ ui.input, ui.sendBtn ] ) ] );

	ui.panel = el( 'div', { class: 'nzai-panel', role: 'dialog', 'aria-modal': 'false', 'aria-label': cfg.title || t.open, hidden: true }, [
		ui.header, ui.menu, ui.form.node, ui.body, ui.composer
	] );

	root.appendChild( ui.panel );
	root.appendChild( ui.teaser );
	root.appendChild( ui.launcher );

	function buildIntro() {
		var f = {};
		var logged = !! cfg.user;
		f.name = el( 'input', { type: 'text', class: 'nzai-field', autocomplete: 'name', id: 'nzai-name' } );
		f.contact = el( 'input', { type: cfg.askContact === 'email' ? 'email' : 'text', class: 'nzai-field nzai-ltr', dir: 'ltr', autocomplete: cfg.askContact === 'email' ? 'email' : 'tel', inputmode: cfg.askContact === 'mobile' ? 'tel' : null, id: 'nzai-contact' } );
		f.message = el( 'textarea', { class: 'nzai-field', rows: '3', placeholder: t.placeholder, id: 'nzai-message', maxlength: String( cfg.maxLength || 1500 ) } );
		f.hp = el( 'input', { type: 'text', name: 'website', tabindex: '-1', autocomplete: 'off', class: 'nzai-hp', 'aria-hidden': 'true' } );
		f.error = el( 'p', { class: 'nzai-error', hidden: true, role: 'alert' } );
		f.button = el( 'button', { type: 'submit', class: 'nzai-primary' }, [ icon( 'send' ), t.start ] );

		var contactLabel = {
			optional: t.mobileOrEmail + ' ' + t.optional,
			mobile_or_email: t.mobileOrEmail,
			mobile: t.mobile,
			email: t.email
		}[ cfg.askContact ];

		var rows = [];
		rows.push( el( 'p', { class: 'nzai-welcome', text: cfg.welcome || '' } ) );
		f.offline = el( 'p', { class: 'nzai-offline', hidden: true } );
		rows.push( f.offline );
		if ( ! logged && cfg.askName !== 'off' ) {
			rows.push( el( 'label', { class: 'nzai-label', for: 'nzai-name', text: t.name + ( cfg.askName === 'optional' ? ' ' + t.optional : '' ) } ) );
			rows.push( f.name );
		}
		if ( ! logged && cfg.askContact !== 'off' ) {
			rows.push( el( 'label', { class: 'nzai-label', for: 'nzai-contact', text: contactLabel } ) );
			rows.push( f.contact );
			rows.push( el( 'p', { class: 'nzai-hint', text: t.contactHint } ) );
		}
		rows.push( el( 'label', { class: 'nzai-label', for: 'nzai-message', text: t.message } ) );
		rows.push( f.message );
		rows.push( f.hp );
		rows.push( f.error );
		rows.push( f.button );

		f.node = el( 'form', { class: 'nzai-intro', novalidate: true }, rows );
		f.node.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			start();
		} );
		f.message.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'Enter' && ( e.ctrlKey || e.metaKey ) ) {
				e.preventDefault();
				start();
			}
		} );
		return f;
	}

	/* ---------------------------------------------------------- render */

	function renderHeader() {
		var online = state.online;
		ui.dot.className = 'nzai-dot ' + ( online ? 'is-on' : 'is-off' );
		ui.status.textContent = online ? t.online : t.offline;
		ui.muteBtn.innerHTML = '';
		ui.muteBtn.appendChild( icon( state.muted ? 'mute' : 'vol' ) );
		ui.muteBtn.setAttribute( 'aria-label', state.muted ? t.unmute : t.mute );
		ui.muteBtn.hidden = ! cfg.sound;
		ui.menuBtn.hidden = ! state.uuid;
	}

	function renderMode() {
		var started = !! state.uuid;
		ui.form.node.hidden = started;
		ui.body.hidden = ! started;
		var closed = state.info && state.info.status === 'closed';
		ui.composer.hidden = ! started || closed;
		ui.menuHuman.hidden = ! state.info || closed || state.info.handoff;
		ui.menuTicket.hidden = ! cfg.tickets || ! state.info || state.info.ticket;
		ui.menuEnd.hidden = ! state.info || closed;
		renderHeader();
		renderAfter();
		renderAssist();
	}

	function renderAssist() {
		var info = state.info || {};
		var closed = info.status === 'closed';
		ui.typing.hidden = closed || ! ( info.typing || state.thinking );
		if ( info.handoff && ! closed ) {
			ui.notice.hidden = false;
			ui.notice.textContent = t.handoff;
		} else {
			ui.notice.hidden = true;
		}
		scrollDown();
	}

	function renderAfter() {
		var info = state.info;
		ui.after.innerHTML = '';
		if ( ! info || info.status !== 'closed' ) {
			ui.after.hidden = true;
			return;
		}
		ui.after.hidden = false;
		ui.after.appendChild( el( 'p', { class: 'nzai-ended', text: t.ended } ) );

		if ( info.rating ) {
			ui.after.appendChild( el( 'p', { class: 'nzai-thanks', text: t.thanks } ) );
		} else {
			var stars = el( 'div', { class: 'nzai-stars', role: 'group', 'aria-label': t.rate } );
			// Highest first: with row-reverse, "hover ~ button" lights the lower stars.
			for ( var i = 5; i >= 1; i-- ) {
				stars.appendChild( el( 'button', { type: 'button', 'aria-label': String( i ), 'data-v': String( i ), onclick: rate }, [ icon( 'star' ) ] ) );
			}
			ui.after.appendChild( el( 'p', { class: 'nzai-rate', text: t.rate } ) );
			ui.after.appendChild( stars );
		}
		ui.after.appendChild( el( 'button', { type: 'button', class: 'nzai-primary', onclick: reset }, [ t.newChat ] ) );
		scrollDown();
	}

	function bubble( m ) {
		if ( m.sender === 'system' ) {
			return el( 'div', { class: 'nzai-sys', 'data-id': String( m.id ) }, [ linkify( m.body ) ] );
		}
		var mine = m.sender === 'visitor';
		var meta = el( 'span', { class: 'nzai-meta' }, [ ( mine ? '' : ( m.author ? m.author + ' · ' : '' ) ) + ( m.time || '' ) ] );
		if ( mine && m.id > 0 ) {
			var seen = state.info && state.info.seen >= m.id;
			var tick = icon( seen ? 'checks' : 'check', seen ? 'nzai-seen' : 'nzai-sent' );
			tick.setAttribute( 'title', seen ? t.seen : t.sent );
			meta.appendChild( tick );
		}
		var text = el( 'div', { class: 'nzai-text' } );
		text.appendChild( linkify( m.body ) );
		return el( 'div', { class: 'nzai-msg ' + ( mine ? 'is-mine' : 'is-them' ) + ( m.pending ? ' is-pending' : '' ), 'data-id': String( m.id ) }, [ text, meta ] );
	}

	function renderMessages() {
		ui.stream.innerHTML = '';
		state.messages.forEach( function ( m ) {
			ui.stream.appendChild( bubble( m ) );
		} );
		scrollDown();
	}

	/** Follow new content, unless the visitor has scrolled up to read. */
	function scrollDown( force ) {
		var near = ui.body.scrollHeight - ui.body.scrollTop - ui.body.clientHeight < 140;
		if ( ! force && ! near ) {
			return;
		}
		window.requestAnimationFrame( function () {
			ui.body.scrollTop = ui.body.scrollHeight;
		} );
	}

	function autosize() {
		ui.input.style.height = 'auto';
		ui.input.style.height = Math.min( 120, ui.input.scrollHeight ) + 'px';
	}

	function showError( node, text ) {
		node.textContent = text || '';
		node.hidden = ! text;
	}

	/* ---------------------------------------------------------- merge */

	/** Add server messages, replacing optimistic copies and skipping ones we already hold. */
	function merge( list, silent ) {
		var added = false;
		var incoming = false;
		var lastIncoming = '';
		( list || [] ).forEach( function ( m ) {
			var exists = state.messages.some( function ( x ) {
				return x.id === m.id;
			} );
			if ( exists ) {
				return;
			}
			if ( m.sender === 'visitor' ) {
				// Drop the optimistic bubble this message confirms.
				for ( var i = 0; i < state.messages.length; i++ ) {
					if ( state.messages[ i ].pending && state.messages[ i ].body === m.body ) {
						state.messages.splice( i, 1 );
						break;
					}
				}
			} else {
				incoming = true;
				lastIncoming = m.body;
			}
			state.messages.push( m );
			state.last = Math.max( state.last, m.id );
			added = true;
		} );
		state.messages.sort( function ( a, b ) {
			// Pending (negative id) bubbles stay at the end.
			var ai = a.id < 0 ? Infinity : a.id;
			var bi = b.id < 0 ? Infinity : b.id;
			return ai - bi;
		} );
		if ( added ) {
			renderMessages();
			scrollDown();
		}
		if ( incoming && ! silent ) {
			ui.live.textContent = lastIncoming;
			if ( ! state.open || document.hidden ) {
				state.unread++;
				renderBadge();
			}
			ding();
		}
	}

	function setInfo( info ) {
		if ( ! info ) {
			return;
		}
		var seenBefore = state.info ? state.info.seen : -1;
		state.info = info;
		state.online = !! info.online;
		renderMode();
		// Re-draw only when the read ticks changed.
		if ( state.messages.length && seenBefore !== info.seen ) {
			renderMessages();
		}
	}

	function renderBadge() {
		ui.badge.hidden = state.unread < 1;
		ui.badge.textContent = state.unread > 9 ? '9+' : String( state.unread );
	}

	/* ---------------------------------------------------------- sound */

	var audioCtx = null;

	/** iOS keeps an AudioContext silent unless it is created or resumed inside a tap. */
	function unlockAudio() {
		if ( ! cfg.sound || state.muted ) {
			return;
		}
		try {
			var Ctx = window.AudioContext || window.webkitAudioContext;
			if ( Ctx ) {
				audioCtx = audioCtx || new Ctx();
				if ( audioCtx.state === 'suspended' && audioCtx.resume ) {
					audioCtx.resume();
				}
			}
		} catch ( e ) {}
	}

	function ding() {
		if ( ! cfg.sound || state.muted ) {
			return;
		}
		try {
			var Ctx = window.AudioContext || window.webkitAudioContext;
			if ( ! Ctx ) {
				return;
			}
			if ( ! audioCtx || audioCtx.state === 'suspended' ) {
				return;
			}
			var o = audioCtx.createOscillator();
			var g = audioCtx.createGain();
			o.type = 'sine';
			o.frequency.setValueAtTime( 880, audioCtx.currentTime );
			o.frequency.exponentialRampToValueAtTime( 1320, audioCtx.currentTime + 0.12 );
			g.gain.setValueAtTime( 0.0001, audioCtx.currentTime );
			g.gain.exponentialRampToValueAtTime( 0.12, audioCtx.currentTime + 0.02 );
			g.gain.exponentialRampToValueAtTime( 0.0001, audioCtx.currentTime + 0.35 );
			o.connect( g );
			g.connect( audioCtx.destination );
			o.start();
			o.stop( audioCtx.currentTime + 0.4 );
		} catch ( e ) {}
	}

	function toggleMute() {
		state.muted = ! state.muted;
		store( 'muted', state.muted );
		renderHeader();
	}

	/* --------------------------------------------------------- actions */

	function start() {
		if ( state.busy ) {
			return;
		}
		var f = ui.form;
		var name = ( f.name.value || '' ).trim();
		var contact = ( f.contact.value || '' ).trim();
		var message = ( f.message.value || '' ).trim();
		var logged = !! cfg.user;

		if ( ! logged && cfg.askName === 'required' && ! name ) {
			return showError( f.error, t.errorName );
		}
		if ( ! logged && [ 'mobile_or_email', 'mobile', 'email' ].indexOf( cfg.askContact ) > -1 && ! contact ) {
			return showError( f.error, t.errorContact );
		}
		if ( ! message ) {
			return showError( f.error, t.errorMessage );
		}
		if ( message.length > ( cfg.maxLength || 1500 ) ) {
			return showError( f.error, t.tooLong );
		}
		showError( f.error, '' );

		state.busy = true;
		f.button.disabled = true;
		unlockAudio();
		api( 'POST', '/start', {
			// Logged in: send the account's own details, so a stale-nonce retry (as a guest) still validates.
			name: logged ? ( name || cfg.user.name || '' ) : name,
			contact: logged ? ( cfg.user.email || '' ) : contact,
			message: message,
			page: window.location.href,
			website: f.hp.value
		} ).then( function ( data ) {
			state.uuid = data.uuid;
			state.token = data.token;
			store( 'uuid', data.uuid );
			store( 'token', data.token );
			state.messages = [];
			state.last = 0;
			merge( data.messages, true );
			setInfo( data.state );
			f.message.value = '';
			scrollDown( true );
			scheduleReply( 50 );
			schedulePoll();
			ui.input.focus();
		} ).catch( function ( err ) {
			showError( f.error, err.message );
		} ).then( function () {
			state.busy = false;
			f.button.disabled = false;
		} );
	}

	var tempId = -1;
	function send() {
		var body = ( ui.input.value || '' ).trim();
		if ( ! body || ! state.uuid ) {
			return;
		}
		if ( body.length > ( cfg.maxLength || 1500 ) ) {
			return showError( ui.error, t.tooLong );
		}
		showError( ui.error, '' );
		ui.input.value = '';
		autosize();

		unlockAudio();
		var temp = { id: tempId--, sender: 'visitor', body: body, time: '', pending: true };
		state.messages.push( temp );
		renderMessages();
		scrollDown( true );

		var uuid = state.uuid;
		api( 'POST', '/' + uuid + '/send', { body: body, after: state.last } ).then( function ( data ) {
			if ( uuid !== state.uuid ) {
				return;
			}
			merge( data.messages, true );
			// A double tap returns an existing message; the optimistic copy must still go.
			state.messages = state.messages.filter( function ( m ) {
				return m !== temp;
			} );
			renderMessages();
			setInfo( data.state );
			scheduleReply( 1400 );
		} ).catch( function ( err ) {
			if ( uuid !== state.uuid ) {
				return;
			}
			state.messages = state.messages.filter( function ( m ) {
				return m !== temp;
			} );
			renderMessages();
			ui.input.value = body;
			autosize();
			showError( ui.error, err.message );
			if ( err.status === 404 || err.code === 'chat_closed' ) {
				poll();
			}
		} );
	}

	/**
	 * Ask the assistant to answer. Called a moment after the visitor stops
	 * sending, so a message typed in three lines gets one answer.
	 */
	function scheduleReply( delay ) {
		window.clearTimeout( state.replyTimer );
		if ( ! state.info || ! state.info.aiActive || ! state.info.awaiting ) {
			return;
		}
		state.thinking = true;
		renderAssist();
		state.replyTimer = window.setTimeout( requestReply, delay );
	}

	var replyInFlight = false;
	var replyAgain = false;
	function requestReply() {
		if ( ! state.uuid ) {
			return;
		}
		if ( replyInFlight ) {
			// Ask again once the running request is back, so nothing is left unanswered.
			replyAgain = true;
			return;
		}
		replyInFlight = true;
		replyAgain = false;
		var uuid = state.uuid;
		api( 'POST', '/' + uuid + '/reply', { after: state.last } ).then( function ( data ) {
			replyInFlight = false;
			if ( uuid !== state.uuid ) {
				// The visitor started a new conversation meanwhile; it may be waiting too.
				if ( replyAgain ) {
					replyAgain = false;
					scheduleReply( 0 );
				}
				return;
			}
			merge( data.messages );
			setInfo( data.state );
			var waiting = data.state && data.state.awaiting;
			// "busy": another request (maybe from a page the visitor left) holds the lock;
			// back off for longer than one model call can take, then ask again.
			if ( waiting && ( data.result === 'busy' || data.result === 'superseded' || replyAgain ) && state.replyTries < 14 ) {
				state.replyTries++;
				state.replyTimer = window.setTimeout( requestReply, data.result === 'busy' ? Math.min( 15000, 1500 * state.replyTries ) : 400 );
				return;
			}
			state.replyTries = 0;
			state.thinking = false;
			renderAssist();
		} ).catch( function () {
			replyInFlight = false;
			state.replyTries = 0;
			state.thinking = false;
			renderAssist();
		} );
	}

	function requestHuman() {
		closeMenu();
		if ( ! state.uuid ) {
			return;
		}
		window.clearTimeout( state.replyTimer );
		state.thinking = false;
		var uuid = state.uuid;
		api( 'POST', '/' + uuid + '/human', { after: state.last } ).then( function ( data ) {
			if ( uuid !== state.uuid ) {
				return;
			}
			merge( data.messages );
			setInfo( data.state );
		} ).catch( function ( err ) {
			showError( ui.error, err.message );
		} );
	}

	function endChat() {
		closeMenu();
		if ( ! state.uuid || ! window.confirm( t.endConfirm ) ) {
			return;
		}
		api( 'POST', '/' + state.uuid + '/end', {} ).then( function ( data ) {
			setInfo( data.state );
			poll();
		} ).catch( function ( err ) {
			showError( ui.error, err.message );
		} );
	}

	function showTicketForm() {
		closeMenu();
		if ( ! state.uuid ) {
			return;
		}
		var email = el( 'input', { type: 'email', class: 'nzai-field nzai-ltr', dir: 'ltr', placeholder: 'you@example.com', value: ( cfg.user && cfg.user.email ) || '' } );
		var err = el( 'p', { class: 'nzai-error', hidden: true } );
		var btn = el( 'button', { type: 'submit', class: 'nzai-primary' }, [ icon( 'ticket' ), t.ticket ] );
		var box = el( 'form', { class: 'nzai-ticket', novalidate: true }, [
			el( 'label', { class: 'nzai-label', text: t.ticketEmail } ), email, err, btn
		] );
		box.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			btn.disabled = true;
			api( 'POST', '/' + state.uuid + '/ticket', { email: email.value.trim() } ).then( function () {
				box.innerHTML = '';
				box.appendChild( el( 'p', { class: 'nzai-thanks', text: t.ticketDone } ) );
				if ( state.info ) {
					state.info.ticket = true;
				}
				renderMode();
			} ).catch( function ( e2 ) {
				showError( err, e2.message );
				btn.disabled = false;
			} );
		} );
		var old = ui.body.querySelector( '.nzai-ticket' );
		if ( old ) {
			old.parentNode.removeChild( old );
		}
		ui.body.appendChild( box );
		scrollDown();
		email.focus();
	}

	function rate( e ) {
		var v = parseInt( e.currentTarget.getAttribute( 'data-v' ), 10 );
		api( 'POST', '/' + state.uuid + '/rate', { rating: v } ).then( function () {
			if ( state.info ) {
				state.info.rating = v;
			}
			renderAfter();
		} ).catch( function () {} );
	}

	function reset() {
		window.clearTimeout( state.pollTimer );
		window.clearTimeout( state.replyTimer );
		state.uuid = '';
		state.token = '';
		state.messages = [];
		state.last = 0;
		state.info = null;
		state.thinking = false;
		state.replyTries = 0;
		replyAgain = false;
		// A request still in flight belongs to the old conversation; its answer is dropped by the uuid check.
		store( 'uuid', null );
		store( 'token', null );
		ui.stream.innerHTML = '';
		var ticket = ui.body.querySelector( '.nzai-ticket' );
		if ( ticket ) {
			ticket.parentNode.removeChild( ticket );
		}
		renderMode();
		checkOnline();
		ui.form.message.focus();
	}

	/* ------------------------------------------------------------ poll */

	function schedulePoll() {
		window.clearTimeout( state.pollTimer );
		if ( ! state.uuid || ( state.info && state.info.status === 'closed' && ! state.open ) ) {
			return;
		}
		var delay = state.open && ! document.hidden ? 3000 : ( document.hidden ? 30000 : 12000 );
		if ( state.info && state.info.status === 'closed' ) {
			delay = 20000;
		}
		state.pollTimer = window.setTimeout( poll, delay );
	}

	function poll() {
		if ( ! state.uuid ) {
			return;
		}
		var first = state.last === 0;
		var uuid = state.uuid;
		var seen = state.open && ! document.hidden ? '&seen=1' : '';
		api( 'GET', '/' + uuid + '/poll?after=' + encodeURIComponent( state.last ) + seen ).then( function ( data ) {
			if ( uuid !== state.uuid ) {
				return;
			}
			// The first load restores history; it is not news.
			merge( data.messages, first );
			setInfo( data.state );
			// A question left unanswered (page changed mid-reply, request dropped): ask now.
			if ( data.state && data.state.awaiting && ! replyInFlight && ! state.thinking ) {
				scheduleReply( 600 );
			}
		} ).catch( function ( err ) {
			if ( uuid !== state.uuid ) {
				return;
			}
			if ( err.status === 404 ) {
				// The conversation was deleted on the server.
				reset();
			}
		} ).then( schedulePoll );
	}

	function checkOnline() {
		api( 'GET', '/status' ).then( function ( data ) {
			state.online = !! data.online;
			state.offlineText = data.offline || '';
			ui.form.offline.hidden = state.online || ! state.offlineText;
			ui.form.offline.textContent = state.offlineText;
			renderHeader();
		} ).catch( function () {} );
	}

	/* ----------------------------------------------------------- panel */

	function openPanel() {
		state.open = true;
		hideTeaser( true );
		ui.panel.hidden = false;
		root.classList.add( 'is-open' );
		document.documentElement.classList.add( 'nzai-open' );
		ui.launcher.setAttribute( 'aria-expanded', 'true' );
		state.unread = 0;
		renderBadge();
		renderMode();
		if ( state.uuid ) {
			poll();
			window.setTimeout( function () {
				ui.input.focus();
			}, 60 );
		} else {
			checkOnline();
			window.setTimeout( function () {
				( ui.form.node.querySelector( 'input:not(.nzai-hp), textarea' ) || ui.form.message ).focus();
			}, 60 );
		}
	}

	function closePanel() {
		state.open = false;
		closeMenu();
		ui.panel.hidden = true;
		root.classList.remove( 'is-open' );
		document.documentElement.classList.remove( 'nzai-open' );
		ui.launcher.setAttribute( 'aria-expanded', 'false' );
		ui.launcher.focus();
		schedulePoll();
	}

	function toggle() {
		unlockAudio();
		if ( state.open ) {
			closePanel();
		} else {
			openPanel();
		}
	}

	function toggleMenu( e ) {
		e.stopPropagation();
		ui.menu.hidden = ! ui.menu.hidden;
	}

	function closeMenu() {
		ui.menu.hidden = true;
	}

	document.addEventListener( 'click', function ( e ) {
		if ( ! ui.menu.hidden && ! ui.menu.contains( e.target ) && e.target !== ui.menuBtn ) {
			closeMenu();
		}
	} );
	document.addEventListener( 'keydown', function ( e ) {
		if ( e.key === 'Escape' && state.open ) {
			if ( ! ui.menu.hidden ) {
				closeMenu();
			} else {
				closePanel();
			}
		}
	} );
	document.addEventListener( 'visibilitychange', function () {
		if ( ! document.hidden && state.uuid ) {
			poll();
		}
	} );

	// Any element with data-netarz-chat (or #netarz-chat links) opens the panel.
	document.addEventListener( 'click', function ( e ) {
		var trigger = e.target.closest && e.target.closest( '[data-netarz-chat], a[href="#netarz-chat"]' );
		if ( trigger ) {
			e.preventDefault();
			openPanel();
		}
	} );
	window.NetarzAIChatOpen = openPanel;

	/* ---------------------------------------------------------- teaser */

	function hideTeaser( remember ) {
		ui.teaser.hidden = true;
		if ( remember !== false ) {
			session( 'teased', '1' );
		}
	}

	function maybeTease() {
		if ( ! cfg.teaser || state.uuid || state.open || session( 'teased' ) ) {
			return;
		}
		window.setTimeout( function () {
			var active = document.activeElement;
			var typing = active && ( active.tagName === 'INPUT' || active.tagName === 'TEXTAREA' || active.isContentEditable );
			if ( state.open || state.uuid || typing || session( 'teased' ) ) {
				return;
			}
			ui.teaser.hidden = false;
			session( 'teased', '1' );
		}, Math.max( 3, cfg.teaserDelay || 25 ) * 1000 );
	}

	/* ------------------------------------------------------------ boot */

	renderMode();
	renderBadge();
	if ( state.uuid && state.token ) {
		poll();
	} else {
		maybeTease();
	}
}() );
