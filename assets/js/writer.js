/**
 * NetArz AI — content tools in wp-admin.
 *
 * Works in both the block editor and the classic editor: one meta box,
 * plus the Writer page, the Images page, alt text in the media modal and
 * AI reply suggestions on the comments screen.
 */
( function ( $ ) {
	'use strict';

	var cfg = window.NetarzAIWriter;
	if ( ! cfg || ! window.wp || ! window.wp.apiFetch ) {
		return;
	}
	var t = cfg.i18n;
	var api = window.wp.apiFetch;
	var ns = '/' + cfg.ns;

	function errorText( err ) {
		return t.error + ': ' + ( ( err && err.message ) || '' );
	}

	/* ------------------------------------------------------ editor glue */

	function isBlockEditor() {
		return !! ( window.wp.data && window.wp.data.select && window.wp.data.select( 'core/editor' ) && document.body.classList.contains( 'block-editor-page' ) );
	}

	function classicEditor() {
		return window.tinymce && window.tinymce.get( 'content' ) && ! window.tinymce.get( 'content' ).isHidden() ? window.tinymce.get( 'content' ) : null;
	}

	var editor = {
		title: function () {
			if ( isBlockEditor() ) {
				return window.wp.data.select( 'core/editor' ).getEditedPostAttribute( 'title' ) || '';
			}
			return $( '#title' ).val() || '';
		},
		content: function () {
			if ( isBlockEditor() ) {
				return window.wp.data.select( 'core/editor' ).getEditedPostContent() || '';
			}
			var mce = classicEditor();
			return mce ? mce.getContent() : ( $( '#content' ).val() || '' );
		},
		setTitle: function ( value ) {
			if ( isBlockEditor() ) {
				window.wp.data.dispatch( 'core/editor' ).editPost( { title: value } );
				return;
			}
			$( '#title' ).val( value ).trigger( 'input' ).trigger( 'change' );
			$( '#title-prompt-text' ).addClass( 'screen-reader-text' );
		},
		setExcerpt: function ( value ) {
			if ( isBlockEditor() ) {
				window.wp.data.dispatch( 'core/editor' ).editPost( { excerpt: value } );
				return;
			}
			var mce = window.tinymce && window.tinymce.get( 'excerpt' );
			if ( mce && ! mce.isHidden() ) {
				mce.setContent( value );
			}
			$( '#excerpt' ).val( value ).trigger( 'change' );
		},
		insert: function ( html, replace ) {
			if ( isBlockEditor() && window.wp.blocks ) {
				var blocks = window.wp.blocks.rawHandler( { HTML: html } );
				var be = window.wp.data.dispatch( 'core/block-editor' );
				if ( replace ) {
					be.resetBlocks( blocks );
				} else {
					be.insertBlocks( blocks );
				}
				return;
			}
			var mce = classicEditor();
			if ( mce ) {
				if ( replace ) {
					mce.setContent( html );
				} else {
					mce.setContent( mce.getContent() + html );
				}
				mce.fire( 'change' );
				return;
			}
			var area = $( '#content' );
			area.val( replace ? html : ( area.val() || '' ) + '\n\n' + html ).trigger( 'change' );
		},
		setFeatured: function ( id ) {
			if ( isBlockEditor() ) {
				window.wp.data.dispatch( 'core/editor' ).editPost( { featured_media: id } );
				return;
			}
			if ( window.wp.media && window.wp.media.featuredImage ) {
				window.wp.media.featuredImage.set( id );
			} else {
				$( '#_thumbnail_id' ).val( id );
			}
		}
	};

	function copy( text, button ) {
		var done = function () {
			if ( button ) {
				var old = button.text();
				button.text( t.copied );
				window.setTimeout( function () {
					button.text( old );
				}, 1500 );
			}
		};
		if ( window.navigator.clipboard && window.isSecureContext ) {
			window.navigator.clipboard.writeText( text ).then( done ).catch( function () {
				fallbackCopy( text );
				done();
			} );
		} else {
			fallbackCopy( text );
			done();
		}
	}

	function fallbackCopy( text ) {
		var area = $( '<textarea readonly>' ).css( { position: 'fixed', top: '-1000px' } ).val( text ).appendTo( document.body );
		area[ 0 ].select();
		try {
			document.execCommand( 'copy' );
		} catch ( e ) {}
		area.remove();
	}

	/* ------------------------------------------------------- meta box */

	var box = $( '.nzai-wbox' );
	if ( box.length ) {
		var postId = parseInt( box.data( 'post' ), 10 ) || 0;
		var result = box.find( '.nzai-w-result' );

		var syncFields = function () {
			var task = box.find( '.nzai-w-task' ).val();
			box.find( '.nzai-w-target' ).prop( 'hidden', task !== 'translate' );
			box.find( '.nzai-w-length' ).prop( 'hidden', task !== 'article' );
		};
		box.on( 'change', '.nzai-w-task', syncFields );
		syncFields();

		box.on( 'click', '.nzai-w-run', function () {
			var btn = $( this );
			var spinner = btn.siblings( '.spinner' );
			var task = box.find( '.nzai-w-task' ).val();
			btn.prop( 'disabled', true );
			spinner.addClass( 'is-active' );
			result.prop( 'hidden', false ).empty().append( $( '<p class="nzai-muted">' ).text( t.working ) );

			api( {
				path: ns + '/writer/run',
				method: 'POST',
				data: {
					task: task,
					post_id: postId,
					input: box.find( '.nzai-w-input' ).val(),
					title: editor.title(),
					content: editor.content(),
					tone: box.find( '.nzai-w-tone' ).val(),
					length: box.find( '.nzai-w-length' ).val(),
					keywords: box.find( '.nzai-w-keywords' ).val(),
					target: box.find( '.nzai-w-target' ).val()
				}
			} ).then( function ( res ) {
				renderResult( task, res );
			} ).catch( function ( err ) {
				result.empty().append( $( '<p class="nzai-error-text">' ).text( errorText( err ) ) );
			} ).then( function () {
				btn.prop( 'disabled', false );
				spinner.removeClass( 'is-active' );
			} );
		} );

		var renderResult = function ( task, res ) {
			result.empty();
			var bar = $( '<p class="nzai-w-bar">' );

			if ( res.format === 'html' ) {
				result.append( $( '<div class="nzai-preview nzai-preview-sm">' ).html( res.html ) );
				bar.append( $( '<button type="button" class="button button-primary">' ).text( t.insert ).on( 'click', function () {
					editor.insert( res.html, false );
				} ) );
				bar.append( $( '<button type="button" class="button">' ).text( t.replace ).on( 'click', function () {
					if ( window.confirm( t.confirmReplace ) ) {
						editor.insert( res.html, true );
					}
				} ) );
				bar.append( $( '<button type="button" class="button-link">' ).text( t.copy ).on( 'click', function () {
					copy( res.html, $( this ) );
				} ) );
				result.append( bar );
				return;
			}

			if ( res.format === 'list' ) {
				var list = $( '<ul class="nzai-w-list">' );
				( res.items || [] ).forEach( function ( item ) {
					var li = $( '<li>' ).append( $( '<span>' ).text( item ) );
					if ( task === 'titles' ) {
						li.append( $( '<button type="button" class="button-link">' ).text( t.useTitle ).on( 'click', function () {
							editor.setTitle( item );
						} ) );
					}
					list.append( li );
				} );
				result.append( list );
				bar.append( $( '<button type="button" class="button">' ).text( t.copy ).on( 'click', function () {
					copy( ( res.items || [] ).join( task === 'tags' ? '، ' : '\n' ), $( this ) );
				} ) );
				result.append( bar );
				return;
			}

			if ( res.format === 'json' && task === 'seo' ) {
				var d = res.data || {};
				var titleInput = $( '<input type="text" class="widefat">' ).val( d.title || '' );
				var descInput = $( '<textarea class="widefat" rows="3">' ).val( d.description || '' );
				var counter = function ( input, max ) {
					var c = $( '<small class="nzai-count-chars">' );
					var paint = function () {
						var n = String( input.val() ).length;
						c.text( n + ' ' + t.chars ).toggleClass( 'is-over', n > max );
					};
					input.on( 'input', paint );
					paint();
					return c;
				};
				result.append( $( '<label>' ).text( t.seoTitle ), titleInput, counter( titleInput, 60 ) );
				result.append( $( '<label>' ).text( t.seoDesc ), descInput, counter( descInput, 160 ) );
				bar.append( $( '<button type="button" class="button button-primary">' ).text( t.saveSeo ).on( 'click', function () {
					var b = $( this );
					b.prop( 'disabled', true );
					api( { path: ns + '/writer/seo', method: 'POST', data: { post_id: postId, title: titleInput.val(), description: descInput.val(), focus_keyword: d.focus_keyword || '' } } ).then( function () {
						b.text( t.saved );
					} ).catch( function ( err ) {
						window.alert( errorText( err ) );
						b.prop( 'disabled', false );
					} );
				} ) );
				result.append( bar );
				return;
			}

			if ( res.format === 'json' && task === 'product' ) {
				var p = res.data || {};
				result.append( $( '<div class="nzai-preview nzai-preview-sm">' ).html( p.description || '' ) );
				bar.append( $( '<button type="button" class="button button-primary">' ).text( t.useDesc ).on( 'click', function () {
					if ( ! $.trim( $( '<div>' ).html( editor.content() ).text() ) || window.confirm( t.confirmReplace ) ) {
						editor.insert( p.description || '', true );
					}
				} ) );
				if ( p.short_description ) {
					result.append( $( '<div class="nzai-preview nzai-preview-sm is-short">' ).html( p.short_description ) );
					bar.append( $( '<button type="button" class="button">' ).text( t.useShort ).on( 'click', function () {
						editor.setExcerpt( p.short_description );
					} ) );
				}
				result.append( bar );
				return;
			}

			// Plain text.
			var text = res.text || '';
			result.append( $( '<p class="nzai-w-text">' ).text( text ) );
			if ( task === 'summary' ) {
				bar.append( $( '<button type="button" class="button button-primary">' ).text( t.useExcerpt ).on( 'click', function () {
					editor.setExcerpt( text );
				} ) );
			}
			if ( task === 'image_prompt' ) {
				box.find( '.nzai-w-image' ).prop( 'open', true );
				box.find( '.nzai-w-prompt' ).val( text );
			}
			bar.append( $( '<button type="button" class="button">' ).text( t.copy ).on( 'click', function () {
				copy( text, $( this ) );
			} ) );
			result.append( bar );
		};

		box.on( 'click', '.nzai-w-imagine', function () {
			var btn = $( this );
			var spinner = btn.siblings( '.spinner' );
			var out = box.find( '.nzai-w-image-result' );
			var prompt = $.trim( box.find( '.nzai-w-prompt' ).val() );

			var go = function ( finalPrompt ) {
				out.empty().append( $( '<p class="nzai-muted">' ).text( t.imaging ) );
				return api( {
					path: ns + '/writer/image',
					method: 'POST',
					data: {
						prompt: finalPrompt,
						post_id: postId,
						featured: true,
						size: box.find( '.nzai-w-size' ).val(),
						quality: box.find( '.nzai-w-quality' ).val()
					}
				} ).then( function ( res ) {
					editor.setFeatured( res.id );
					out.empty().append(
						$( '<img alt="">' ).attr( 'src', res.thumb || res.url ),
						$( '<p>' ).text( t.setFeatured )
					);
				} );
			};

			btn.prop( 'disabled', true );
			spinner.addClass( 'is-active' );

			var chain;
			if ( prompt ) {
				chain = go( prompt );
			} else {
				// No description: let the writer propose one from the post first.
				out.empty().append( $( '<p class="nzai-muted">' ).text( t.working ) );
				chain = api( { path: ns + '/writer/run', method: 'POST', data: { task: 'image_prompt', post_id: postId, title: editor.title(), content: editor.content() } } ).then( function ( res ) {
					box.find( '.nzai-w-prompt' ).val( res.text || '' );
					return go( res.text || editor.title() );
				} );
			}
			chain.catch( function ( err ) {
				out.empty().append( $( '<p class="nzai-error-text">' ).text( errorText( err ) ) );
			} ).then( function () {
				btn.prop( 'disabled', false );
				spinner.removeClass( 'is-active' );
			} );
		} );
	}

	/* ---------------------------------------------------- writer page */

	var page = $( '#nzai-writer' );
	if ( page.length ) {
		var lastHtml = '';
		var run = function ( task ) {
			var topic = $.trim( $( '#nzai-wp-topic' ).val() );
			var notes = $.trim( $( '#nzai-wp-notes' ).val() );
			var err = $( '#nzai-wp-error' ).prop( 'hidden', true );
			var spinner = page.find( '.nzai-writer-form .spinner' ).addClass( 'is-active' );
			page.find( '#nzai-wp-go, #nzai-wp-outline' ).prop( 'disabled', true );
			$( '#nzai-wp-saved' ).empty();

			api( {
				path: ns + '/writer/run',
				method: 'POST',
				data: {
					task: task,
					input: notes ? topic + '\n\n' + notes : topic,
					title: topic,
					keywords: $( '#nzai-wp-keywords' ).val(),
					tone: $( '#nzai-wp-tone' ).val(),
					length: $( '#nzai-wp-length' ).val(),
					language: $( '#nzai-wp-language' ).val()
				}
			} ).then( function ( res ) {
				lastHtml = res.html || '';
				$( '#nzai-wp-preview' ).html( lastHtml );
				$( '#nzai-wp-out' ).prop( 'hidden', false );
				$( '#nzai-wp-out' )[ 0 ].scrollIntoView( { behavior: 'smooth', block: 'start' } );
			} ).catch( function ( e ) {
				err.text( errorText( e ) ).prop( 'hidden', false );
			} ).then( function () {
				spinner.removeClass( 'is-active' );
				page.find( '#nzai-wp-go, #nzai-wp-outline' ).prop( 'disabled', false );
			} );
		};

		$( '#nzai-wp-go' ).on( 'click', function () {
			run( 'article' );
		} );
		$( '#nzai-wp-outline' ).on( 'click', function () {
			run( 'outline' );
		} );
		$( '#nzai-wp-copy' ).on( 'click', function () {
			copy( lastHtml, $( this ) );
		} );
		$( '#nzai-wp-save' ).on( 'click', function () {
			var btn = $( this ).prop( 'disabled', true );
			api( {
				path: ns + '/writer/draft',
				method: 'POST',
				data: { title: $( '#nzai-wp-topic' ).val(), html: lastHtml, post_type: $( '#nzai-wp-type' ).val() }
			} ).then( function ( res ) {
				$( '#nzai-wp-saved' ).empty().append( $( '<a>' ).attr( 'href', res.edit ).text( t.openEditor ) );
			} ).catch( function ( e ) {
				$( '#nzai-wp-saved' ).text( errorText( e ) );
			} ).then( function () {
				btn.prop( 'disabled', false );
			} );
		} );
	}

	/* ---------------------------------------------------- images page */

	var images = $( '#nzai-images' );
	if ( images.length ) {
		$( '#nzai-img-go' ).on( 'click', function () {
			var btn = $( this ).prop( 'disabled', true );
			var spinner = btn.siblings( '.spinner' ).addClass( 'is-active' );
			var err = $( '#nzai-img-error' ).prop( 'hidden', true );
			var holder = $( '<figure class="nzai-img is-loading">' ).append( $( '<figcaption>' ).text( t.imaging ) );
			$( '#nzai-img-grid' ).prepend( holder );

			api( {
				path: ns + '/writer/image',
				method: 'POST',
				data: { prompt: $( '#nzai-img-prompt' ).val(), size: $( '#nzai-img-size' ).val(), quality: $( '#nzai-img-quality' ).val() }
			} ).then( function ( res ) {
				holder.removeClass( 'is-loading' ).empty().append(
					$( '<a target="_blank" rel="noopener">' ).attr( 'href', res.url ).append( $( '<img alt="">' ).attr( 'src', res.thumb || res.url ) ),
					$( '<figcaption>' ).append( $( '<a>' ).attr( 'href', res.edit ).text( t.openMedia ) )
				);
			} ).catch( function ( e ) {
				holder.remove();
				err.text( errorText( e ) ).prop( 'hidden', false );
			} ).then( function () {
				btn.prop( 'disabled', false );
				spinner.removeClass( 'is-active' );
			} );
		} );
	}

	/* ------------------------------------------------------- alt text */

	$( document ).on( 'click', '.nzai-alt-btn', function () {
		var btn = $( this );
		var id = parseInt( btn.data( 'id' ), 10 );
		var status = btn.siblings( '.nzai-alt-status' );
		btn.prop( 'disabled', true );
		status.text( t.altWorking );
		api( { path: ns + '/writer/alt', method: 'POST', data: { attachment_id: id } } ).then( function ( res ) {
			status.text( t.altDone );
			// Media modal: update the model so the field and the next save agree.
			if ( window.wp.media && window.wp.media.attachment ) {
				var model = window.wp.media.attachment( id );
				if ( model ) {
					model.set( 'alt', res.alt );
				}
			}
			$( '[data-setting="alt"] textarea, [data-setting="alt"] input, #attachment_alt, #attachment-details-two-column-alt-text, #attachment-details-alt-text' ).val( res.alt );
		} ).catch( function ( err ) {
			status.text( errorText( err ) );
		} ).then( function () {
			btn.prop( 'disabled', false );
		} );
	} );

	/* ------------------------------------------------- comment replies */

	$( document ).on( 'click', '.nzai-comment-reply', function ( e ) {
		e.preventDefault();
		var link = $( this );
		var id = parseInt( link.data( 'id' ), 10 );
		var post = parseInt( link.data( 'post' ), 10 );
		var old = link.text();
		link.text( t.working );

		api( { path: ns + '/writer/comment', method: 'POST', data: { comment_id: id } } ).then( function ( res ) {
			if ( window.commentReply && typeof window.commentReply.open === 'function' ) {
				window.commentReply.open( id, post );
				window.setTimeout( function () {
					$( '#replycontent' ).val( res.reply || '' ).trigger( 'focus' );
				}, 60 );
			} else {
				window.prompt( t.reply, res.reply || '' );
			}
		} ).catch( function ( err ) {
			window.alert( errorText( err ) );
		} ).then( function () {
			link.text( old );
		} );
	} );
}( jQuery ) );
