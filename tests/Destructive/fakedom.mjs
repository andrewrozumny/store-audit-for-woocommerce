// A very small DOM, just enough to run resources/admin.js in node and watch what it writes.
// Any write to innerHTML/outerHTML/insertAdjacentHTML throws, so a regression to unsafe rendering is caught.
import vm from 'node:vm';
import fs from 'node:fs';

class FakeNode {
	constructor( tag, doc ) {
		this.tagName = tag;
		this.doc = doc;
		this.children = [];
		this._text = '';
		this.className = '';
		this.hidden = false;
		this.disabled = false;
		this.attrs = {};
		this.style = {};
		this.listeners = {};
		this.parentNode = null;
		this.id = '';
		this.href = '';
		this.value = '';
	}

	get textContent() {
		return this._text + this.children.map( ( c ) => c.textContent ).join( '' );
	}

	set textContent( v ) {
		this._text = String( v );
		this.children = [];
	}

	set innerHTML( v ) {
		this.doc.violations.push( `innerHTML set to ${ String( v ).slice( 0, 60 ) }` );
		throw new Error( 'innerHTML is not allowed' );
	}

	set outerHTML( v ) {
		this.doc.violations.push( 'outerHTML set' );
		throw new Error( 'outerHTML is not allowed' );
	}

	insertAdjacentHTML() {
		this.doc.violations.push( 'insertAdjacentHTML' );
		throw new Error( 'insertAdjacentHTML is not allowed' );
	}

	appendChild( c ) {
		c.parentNode = this;
		this.children.push( c );
		return c;
	}

	setAttribute( k, v ) {
		this.attrs[ k ] = String( v );
	}

	getAttribute( k ) {
		return this.attrs[ k ] ?? null;
	}

	addEventListener( ev, fn ) {
		this.listeners[ ev ] = fn;
	}

	focus() {}
	select() {}
	scrollIntoView() {}
}

export function runAdminJs( file, config, { ids = [], fetchImpl = null } = {} ) {
	const doc = { violations: [], byId: {}, created: [] };
	doc.createElement = ( tag ) => {
		const n = new FakeNode( tag, doc );
		doc.created.push( n );
		return n;
	};
	const wanted = [ 'store-audit-run', 'store-audit-last', 'store-audit-progress', 'store-audit-progress-fill', 'store-audit-progress-text', 'store-audit-report', 'store-audit-actions', 'store-audit-footer', 'store-audit-copy', 'store-audit-notice', 'store-audit-fallback', 'store-audit-fallback-text', ...ids ];
	for ( const id of wanted ) {
		const n = new FakeNode( 'div', doc );
		n.id = id;
		doc.byId[ id ] = n;
	}
	doc.byId[ 'store-audit-progress-fill' ].parentNode = new FakeNode( 'div', doc );
	doc.getElementById = ( id ) => doc.byId[ id ] || null;
	const window = { storeAuditConfig: config, isSecureContext: false, matchMedia: () => ( { matches: false } ) };
	const ctx = vm.createContext( {
		window,
		document: doc,
		navigator: {},
		console,
		setTimeout,
		clearTimeout,
		fetch: fetchImpl || ( () => Promise.reject( new Error( 'no network in this harness' ) ) ),
		URLSearchParams,
		AbortController,
		Promise,
	} );
	let error = null;
	try {
		vm.runInContext( fs.readFileSync( file, 'utf8' ), ctx, { filename: 'admin.js' } );
	} catch ( e ) {
		error = e;
	}
	return { doc, error, report: doc.byId[ 'store-audit-report' ], text: () => doc.byId[ 'store-audit-report' ].textContent };
}
