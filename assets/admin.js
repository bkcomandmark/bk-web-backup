/* BK WEB Backup — tableau de bord. */
( function () {
	'use strict';

	var $ = function ( sel, root ) {
		return ( root || document ).querySelector( sel );
	};
	var polling = false;
	var wasRunning = false;

	function post( action, data ) {
		var body = new URLSearchParams( Object.assign( { action: 'bkwb_' + action, nonce: BKWB.nonce }, data || {} ) );
		return fetch( BKWB.ajax, { method: 'POST', credentials: 'same-origin', body: body } ).then( function ( r ) {
			return r.json().catch( function () {
				return { success: false, data: { message: 'Réponse inattendue du serveur (' + r.status + ').' } };
			} );
		} );
	}

	function message( res ) {
		return ( res && res.data && res.data.message ) || 'Erreur inconnue.';
	}

	function setBusy( busy ) {
		document.querySelectorAll( '.bkwb-start, .bkwb-restore' ).forEach( function ( b ) {
			b.disabled = busy;
		} );
	}

	function showJob( d ) {
		var box = $( '#bkwb-job' );
		if ( ! box ) {
			return;
		}
		if ( ! d || ! d.running ) {
			box.hidden = true;
			return;
		}
		box.hidden = false;
		$( '#bkwb-job-title' ).textContent = 'restore' === d.type ? 'Restauration en cours' : 'Sauvegarde en cours';
		$( '#bkwb-job-label' ).textContent = d.label + ( d.waiting ? ' — ' + d.waiting : '' );
		$( '#bkwb-job-pct' ).textContent = d.progress;
		$( '#bkwb-job-bar' ).style.width = d.progress + '%';
		var log = $( '#bkwb-job-log' );
		log.innerHTML = '';
		( d.log || [] ).slice().reverse().forEach( function ( line ) {
			var li = document.createElement( 'li' );
			li.textContent = line;
			log.appendChild( li );
		} );
		$( '#bkwb-cancel' ).hidden = ! d.can_cancel;
		setBusy( true );
	}

	function notice( type, text ) {
		var r = $( '#bkwb-result' );
		if ( ! r ) {
			window.alert( text );
			return;
		}
		r.className = 'notice notice-' + type;
		r.innerHTML = '<p></p>';
		r.firstChild.textContent = text;
		r.hidden = false;
		r.scrollIntoView( { behavior: 'smooth', block: 'center' } );
	}

	function showResult( d ) {
		if ( ! d || ! d.result ) {
			return;
		}
		var types = { success: 'success', partial: 'warning', cancelled: 'info' };
		var text = ( 'restore' === d.type ? 'Restauration' : 'Sauvegarde' ) + ' : ' + d.result.label + ' (' + d.result.finished + ')';
		if ( d.result.size ) {
			text += ' — ' + d.result.size;
		}
		if ( d.result.error ) {
			text += ' — ' + d.result.error;
		}
		if ( d.result.warnings ) {
			text += ' — ' + d.result.warnings + ' avertissement(s), voir l’historique.';
		}
		notice( types[ d.result.status ] || 'error', text );
	}

	function poll() {
		if ( polling ) {
			return;
		}
		polling = true;
		post( 'status' )
			.then( function ( res ) {
				polling = false;
				if ( ! res.success ) {
					notice( 'warning', wasRunning ? 'La tâche est peut-être terminée, mais votre session a expiré (normal après une restauration de la base) : rechargez la page et reconnectez-vous.' : message( res ) );
					return;
				}
				var d = res.data;
				showJob( d );
				if ( d.running ) {
					wasRunning = true;
					setTimeout( poll, 2500 );
					return;
				}
				setBusy( false );
				if ( wasRunning ) {
					wasRunning = false;
					showResult( d );
					setTimeout( function () {
						window.location.reload();
					}, 5000 );
				}
			} )
			.catch( function () {
				polling = false;
				setTimeout( poll, 5000 );
			} );
	}

	// Sauvegarde manuelle.
	document.querySelectorAll( '.bkwb-start' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			setBusy( true );
			post( 'start_backup', { content: btn.dataset.content } ).then( function ( res ) {
				if ( ! res.success ) {
					setBusy( false );
					notice( 'error', message( res ) );
					return;
				}
				wasRunning = true;
				showJob( res.data );
				setTimeout( poll, 1500 );
			} );
		} );
	} );

	// Annulation.
	var cancel = $( '#bkwb-cancel' );
	if ( cancel ) {
		cancel.addEventListener( 'click', function () {
			if ( ! window.confirm( 'Annuler la tâche en cours ?' ) ) {
				return;
			}
			cancel.disabled = true;
			post( 'cancel' ).then( function ( res ) {
				cancel.disabled = false;
				if ( ! res.success ) {
					notice( 'error', message( res ) );
				}
				poll();
			} );
		} );
	}

	// Restauration.
	var box = $( '#bkwb-restore-box' );
	var target = null;
	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest( '.bkwb-restore' );
		if ( ! btn || ! box ) {
			return;
		}
		target = { source: btn.dataset.source, name: btn.dataset.name };
		$( '#bkwb-restore-name' ).textContent = btn.dataset.name + ( 'remote' === btn.dataset.source ? ' (depuis OVH)' : '' );
		var dbOnly = 'db' === btn.dataset.content;
		box.querySelectorAll( 'input[name="bkwb-what"]' ).forEach( function ( r ) {
			r.disabled = dbOnly && 'db' !== r.value;
			r.checked = dbOnly ? 'db' === r.value : 'full' === r.value;
		} );
		$( '#bkwb-restore-confirm' ).value = '';
		box.hidden = false;
		box.scrollIntoView( { behavior: 'smooth', block: 'center' } );
	} );
	if ( box ) {
		$( '#bkwb-restore-close' ).addEventListener( 'click', function () {
			box.hidden = true;
		} );
		$( '#bkwb-restore-go' ).addEventListener( 'click', function () {
			var what = box.querySelector( 'input[name="bkwb-what"]:checked' );
			var go = $( '#bkwb-restore-go' );
			go.disabled = true;
			post( 'start_restore', {
				source: target.source,
				name: target.name,
				what: what ? what.value : 'full',
				confirm: $( '#bkwb-restore-confirm' ).value
			} ).then( function ( res ) {
				go.disabled = false;
				if ( ! res.success ) {
					notice( 'error', message( res ) );
					return;
				}
				box.hidden = true;
				wasRunning = true;
				showJob( res.data );
				$( '#bkwb-job' ).scrollIntoView( { behavior: 'smooth', block: 'center' } );
				setTimeout( poll, 1500 );
			} );
		} );
	}

	// Liste des sauvegardes sur OVH.
	var load = $( '#bkwb-remote-load' );
	if ( load ) {
		load.addEventListener( 'click', function () {
			var wrap = $( '#bkwb-remote' );
			load.disabled = true;
			wrap.textContent = 'Chargement…';
			post( 'remote_list', { refresh: 1 } ).then( function ( res ) {
				load.disabled = false;
				load.textContent = 'Actualiser';
				if ( ! res.success ) {
					wrap.innerHTML = '<p class="bkwb-bad"></p>';
					wrap.firstChild.textContent = message( res );
					return;
				}
				if ( ! res.data.items.length ) {
					wrap.textContent = 'Aucune sauvegarde sur le stockage OVH pour ce site.';
					return;
				}
				var table = document.createElement( 'table' );
				table.className = 'widefat striped';
				table.innerHTML = '<thead><tr><th>Date</th><th>Contenu</th><th>Taille</th><th>Aussi en local</th><th>Actions</th></tr></thead><tbody></tbody>';
				res.data.items.forEach( function ( it ) {
					var tr = document.createElement( 'tr' );
					[ it.date, 'db' === it.content ? 'Base seule' : 'Fichiers + base', it.size, it.local ? '✓' : '' ].forEach( function ( v ) {
						var td = document.createElement( 'td' );
						td.textContent = v;
						tr.appendChild( td );
					} );
					var td = document.createElement( 'td' );
					var b = document.createElement( 'button' );
					b.type = 'button';
					b.className = 'button bkwb-restore';
					b.dataset.source = 'remote';
					b.dataset.name = it.name;
					b.dataset.content = it.content;
					b.textContent = 'Restaurer…';
					td.appendChild( b );
					tr.appendChild( td );
					table.tBodies[ 0 ].appendChild( tr );
				} );
				wrap.innerHTML = '';
				wrap.appendChild( table );
			} );
		} );
	}

	// Test de connexion S3.
	var test = $( '#bkwb-test-s3' );
	if ( test ) {
		test.addEventListener( 'click', function () {
			var out = $( '#bkwb-test-result' );
			test.disabled = true;
			out.className = '';
			out.textContent = 'Test en cours…';
			post( 'test_s3' ).then( function ( res ) {
				test.disabled = false;
				out.className = res.success ? 'bkwb-ok' : 'bkwb-bad';
				out.textContent = message( res );
			} );
		} );
	}

	// Jour de la semaine visible seulement en planification hebdomadaire.
	var schedule = $( '#bkwb-schedule' );
	if ( schedule ) {
		var toggle = function () {
			var row = $( '.bkwb-weekday' );
			if ( row ) {
				row.style.display = 'weekly' === schedule.value ? '' : 'none';
			}
		};
		schedule.addEventListener( 'change', toggle );
		toggle();
	}

	if ( $( '#bkwb-job' ) ) {
		poll();
	}
}() );
