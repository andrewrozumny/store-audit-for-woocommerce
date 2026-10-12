/* Store Audit for WooCommerce - admin runner. Vanilla JS, no build step. */
( function () {
	'use strict';

	var cfg = window.storeAuditConfig || {};
	var S = cfg.strings || {};
	var CHECK_TIMEOUT_MS = 45000;
	var SEVERITY_ORDER = [ 'critical', 'warning', 'error', 'info', 'ok' ];

	var runBtn = document.getElementById( 'store-audit-run' );
	var lastEl = document.getElementById( 'store-audit-last' );
	var progressEl = document.getElementById( 'store-audit-progress' );
	var progressFill = document.getElementById( 'store-audit-progress-fill' );
	var progressText = document.getElementById( 'store-audit-progress-text' );
	var reportEl = document.getElementById( 'store-audit-report' );

	if ( ! runBtn || ! reportEl ) {
		return;
	}

	function el( tag, className, text ) {
		var node = document.createElement( tag );
		if ( className ) {
			node.className = className;
		}
		if ( undefined !== text && null !== text ) {
			node.textContent = String( text );
		}
		return node;
	}

	function fmt( template, args ) {
		return String( template ).replace( /%(\d)\$s/g, function ( m, n ) {
			return String( args[ parseInt( n, 10 ) - 1 ] );
		} );
	}

	function formatDate( iso ) {
		var d = new Date( iso );
		return isNaN( d.getTime() ) ? String( iso ) : d.toLocaleString();
	}

	function post( action, data, timeoutMs ) {
		var body = new URLSearchParams();
		body.append( 'action', action );
		body.append( 'nonce', cfg.nonce || '' );
		Object.keys( data || {} ).forEach( function ( key ) {
			body.append( key, data[ key ] );
		} );

		var controller = new AbortController();
		var timer = timeoutMs ? setTimeout( function () {
			controller.abort();
		}, timeoutMs ) : null;

		return fetch( cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body,
			signal: controller.signal
		} ).then( function ( response ) {
			if ( ! response.ok ) {
				throw new Error( 'HTTP ' + response.status );
			}
			return response.json();
		} ).then( function ( json ) {
			if ( ! json || ! json.success ) {
				var message = json && json.data && json.data.message ? json.data.message : 'Request failed';
				throw new Error( message );
			}
			return json.data;
		} ).finally( function () {
			if ( timer ) {
				clearTimeout( timer );
			}
		} );
	}

	function scoreClass( score ) {
		if ( score >= 80 ) {
			return 'is-good';
		}
		if ( score >= 50 ) {
			return 'is-fair';
		}
		return 'is-poor';
	}

	function severityLabel( severity ) {
		return S[ severity ] || severity;
	}

	function renderRow( row ) {
		var item = el( 'div', 'store-audit-row severity-' + row.severity );
		var head = el( 'div', 'store-audit-row-head' );
		head.appendChild( el( 'span', 'store-audit-badge severity-' + row.severity, severityLabel( row.severity ) ) );
		head.appendChild( el( 'strong', 'store-audit-row-title', row.title ) );
		item.appendChild( head );
		item.appendChild( el( 'p', 'store-audit-row-summary', row.summary ) );

		if ( row.details && row.details.length ) {
			var list = el( 'ul', 'store-audit-row-details' );
			row.details.forEach( function ( line ) {
				list.appendChild( el( 'li', '', line ) );
			} );
			item.appendChild( list );
		}

		if ( row.explanation ) {
			var d1 = el( 'details', 'store-audit-more' );
			d1.appendChild( el( 'summary', '', S.whatIsThis ) );
			d1.appendChild( el( 'p', '', row.explanation ) );
			item.appendChild( d1 );
		}
		if ( row.fix_hint && 'ok' !== row.severity ) {
			var d2 = el( 'details', 'store-audit-more' );
			d2.appendChild( el( 'summary', '', S.whatToDo ) );
			d2.appendChild( el( 'p', '', row.fix_hint ) );
			item.appendChild( d2 );
		}
		return item;
	}

	function render( report ) {
		reportEl.textContent = '';
		var results = ( report && report.results ) || [];
		if ( ! results.length ) {
			reportEl.appendChild( el( 'p', '', S.noResults ) );
			return;
		}

		var score = report.score || { overall: 0, sections: {} };
		var summary = el( 'div', 'store-audit-summary' );
		var big = el( 'div', 'store-audit-score ' + scoreClass( score.overall ) );
		big.appendChild( el( 'span', 'store-audit-score-number', score.overall ) );
		big.appendChild( el( 'span', 'store-audit-score-label', S.outOf ) );
		summary.appendChild( big );
		summary.appendChild( el( 'div', 'store-audit-score-title', S.score ) );

		var chips = el( 'div', 'store-audit-chips' );
		var sectionIds = Object.keys( score.sections || {} );
		sectionIds.forEach( function ( id ) {
			var s = score.sections[ id ];
			var chip = el( 'a', 'store-audit-chip ' + scoreClass( s.score ) );
			chip.href = '#store-audit-section-' + id;
			chip.appendChild( el( 'span', 'store-audit-chip-label', s.label ) );
			chip.appendChild( el( 'span', 'store-audit-chip-score', s.score ) );
			chips.appendChild( chip );
		} );
		summary.appendChild( chips );
		reportEl.appendChild( summary );

		// Group rows by section, keeping the order of score sections first.
		var groups = {};
		var order = sectionIds.slice();
		results.forEach( function ( row ) {
			if ( ! groups[ row.section ] ) {
				groups[ row.section ] = [];
			}
			groups[ row.section ].push( row );
			if ( order.indexOf( row.section ) === -1 ) {
				order.push( row.section );
			}
		} );

		order.forEach( function ( id ) {
			var rows = groups[ id ];
			if ( ! rows ) {
				return;
			}
			rows.sort( function ( a, b ) {
				return SEVERITY_ORDER.indexOf( a.severity ) - SEVERITY_ORDER.indexOf( b.severity );
			} );
			var label = score.sections && score.sections[ id ] ? score.sections[ id ].label : ( ( cfg.sections && cfg.sections[ id ] ) || id );
			var section = el( 'section', 'store-audit-section' );
			section.id = 'store-audit-section-' + id;
			section.appendChild( el( 'h2', '', label ) );
			rows.forEach( function ( row ) {
				section.appendChild( renderRow( row ) );
			} );
			reportEl.appendChild( section );
		} );
	}

	function showLast( report ) {
		lastEl.textContent = '';
		if ( report && report.generated_at ) {
			lastEl.textContent = S.lastRun + ' ' + formatDate( report.generated_at );
			runBtn.textContent = S.runAgain;
		} else {
			runBtn.textContent = S.run;
		}
	}

	function setProgress( done, total, title ) {
		var pct = total ? Math.round( ( done / total ) * 100 ) : 0;
		progressFill.style.width = pct + '%';
		progressFill.parentNode.setAttribute( 'aria-valuenow', String( pct ) );
		progressText.textContent = title ? fmt( S.checking, [ title, done + 1, total ] ) : S.finishing;
	}

	function failedRow( check ) {
		return {
			id: check.id,
			section: check.section,
			title: check.title,
			severity: 'error',
			summary: S.couldNot,
			details: [],
			explanation: '',
			fix_hint: '',
			duration_ms: 0
		};
	}

	function runAudit() {
		runBtn.disabled = true;
		reportEl.textContent = '';
		progressEl.hidden = false;
		setProgress( 0, 1, '' );
		var failed = [];

		post( 'store_audit_start', {}, CHECK_TIMEOUT_MS ).then( function ( run ) {
			var checks = run.checks || [];
			var chain = Promise.resolve();
			checks.forEach( function ( check, index ) {
				chain = chain.then( function () {
					setProgress( index, checks.length, check.title );
					return post( 'store_audit_run_check', { run_id: run.run_id, check_id: check.id }, CHECK_TIMEOUT_MS ).catch( function () {
						failed.push( failedRow( check ) );
					} );
				} );
			} );
			return chain.then( function () {
				setProgress( checks.length, checks.length, '' );
				return post( 'store_audit_finish', { run_id: run.run_id }, CHECK_TIMEOUT_MS );
			} );
		} ).then( function ( report ) {
			report.results = ( report.results || [] ).concat( failed );
			render( report );
			showLast( report );
		} ).catch( function ( err ) {
			reportEl.appendChild( el( 'div', 'notice notice-error inline', ( err && err.message ) ? S.failedStart + ' (' + err.message + ')' : S.failedStart ) );
		} ).finally( function () {
			progressEl.hidden = true;
			runBtn.disabled = false;
		} );
	}

	runBtn.addEventListener( 'click', runAudit );
	showLast( cfg.lastReport );
	if ( cfg.lastReport ) {
		render( cfg.lastReport );
	}
}() );
