// Helpers for the destructive scenarios. Runs INSIDE the clone container (node 20, php 8.4, mysql client).
// Nothing here touches the lab site: every path and database below belongs to the throwaway clone.
import { spawnSync } from 'node:child_process';
import fs from 'node:fs';

export const BASE = 'http://localhost:8080';
export const SITE = process.env.DESTR_SITE || '/cache/site';
export const DB = process.env.DESTR_DB || 'sa_destr_1';
export const PLUGIN_DIR = `${SITE}/wp-content/plugins/store-audit-for-woocommerce`;
export const LOG = `${SITE}/wp-content/debug.log`;
export const PHP_ERR_LOG = `${SITE}/wp-content/php-error.log`;
export const PASS = 'Destr-Pass-123!';
export const SEVERITIES = ['critical', 'warning', 'info', 'ok', 'error'];

// ------------------------------------------------------------------ shell, wp-cli, sql
export function sh( cmd, args, opts = {} ) {
	const r = spawnSync( cmd, args, { encoding: 'utf8', maxBuffer: 256 * 1024 * 1024, env: { ...process.env, ...( opts.env || {} ) }, input: opts.input, timeout: opts.timeout || 600000 } );
	return { code: r.status === null ? 124 : r.status, out: r.stdout || '', err: r.stderr || '' };
}

export function wp( args, opts = {} ) {
	const path = opts.path || SITE;
	return sh( 'php', [ '-d', 'memory_limit=1G', '-d', 'display_errors=stderr', '/wp-cli.phar', `--path=${path}`, ...args ], opts );
}

export function wpOut( args, opts = {} ) {
	const r = wp( args, opts );
	return r.out.trim();
}

export function evalPhp( code, env = {}, opts = {} ) {
	return wp( [ 'eval', code ], { ...opts, env } );
}

export function sql( query, db = DB ) {
	const r = sh( 'mysql', [ '-h', 'db', '-uroot', '-pwordpress', '-N', '-B', db, '-e', query ] );
	if ( r.code !== 0 ) {
		throw new Error( `mysql failed: ${ r.err }` );
	}
	return r.out.trim();
}

export function sqlRoot( query ) {
	const r = sh( 'mysql', [ '-h', 'db', '-uroot', '-pwordpress', '-N', '-B', '-e', query ] );
	if ( r.code !== 0 ) {
		throw new Error( `mysql failed: ${ r.err }` );
	}
	return r.out.trim();
}

// Names and values of every row that belongs to the plugin (options and transients), as one comparable string.
export function pluginRows( db = DB, prefix = 'wp_', withCache = false ) {
	const skip = withCache ? '' : " AND option_name NOT LIKE '%store\\_audit\\_wporg%'";
	return sql( `SELECT option_name, MD5(option_value) FROM ${ prefix }options WHERE option_name LIKE '%store\\_audit%'${ skip } ORDER BY option_name`, db );
}

// ------------------------------------------------------------------ debug.log accounting
export function logMark() {
	const size = ( file ) => ( fs.existsSync( file ) ? fs.statSync( file ).size : 0 );
	return { log: size( LOG ), php: size( PHP_ERR_LOG ) };
}

function slice( file, from ) {
	if ( ! fs.existsSync( file ) ) {
		return '';
	}
	const buf = fs.readFileSync( file );
	return buf.subarray( Math.min( from, buf.length ) ).toString( 'utf8' );
}

const ERR_RE = /PHP (Fatal error|Parse error|Warning|Notice|Deprecated)|WordPress database error|_doing_it_wrong|Uncaught/;
const OURS_RE = /store-audit-for-woocommerce|StoreAudit|store_audit/;

// New error lines since the mark, split into the plugin's own and everybody else's.
export function logSince( mark ) {
	const text = slice( LOG, mark.log ) + '\n' + slice( PHP_ERR_LOG, mark.php );
	const lines = text.split( '\n' ).filter( ( l ) => ERR_RE.test( l ) );
	return { ours: lines.filter( ( l ) => OURS_RE.test( l ) ), other: lines.filter( ( l ) => ! OURS_RE.test( l ) ), all: lines };
}

// ------------------------------------------------------------------ http
export async function http( method, path, { cookie = '', form = null, headers = {}, timeout = 120000 } = {} ) {
	const h = { ...headers };
	if ( cookie ) {
		h.Cookie = cookie;
	}
	let body;
	if ( form !== null && method !== 'GET' ) {
		h[ 'Content-Type' ] = h[ 'Content-Type' ] || 'application/x-www-form-urlencoded';
		body = typeof form === 'string' ? form : new URLSearchParams( form ).toString();
	}
	const t0 = Date.now();
	try {
		const res = await fetch( BASE + path, { method, headers: h, body, redirect: 'manual', signal: AbortSignal.timeout( timeout ) } );
		const text = await res.text();
		return { status: res.status, headers: res.headers, text, ms: Date.now() - t0, location: res.headers.get( 'location' ) || '' };
	} catch ( e ) {
		return { status: 0, headers: new Headers(), text: String( e ), ms: Date.now() - t0, location: '', error: true };
	}
}

export function enc( s ) {
	return encodeURIComponent( s );
}

export function formString( pairs ) {
	return pairs.map( ( [ k, v ] ) => `${ enc( k ) }=${ enc( v ) }` ).join( '&' );
}

export function jsonBody( res ) {
	try {
		return JSON.parse( res.text );
	} catch {
		return null;
	}
}

// ------------------------------------------------------------------ sessions
export async function login( user, pass = PASS ) {
	const res = await http( 'POST', '/wp-login.php', {
		cookie: 'wordpress_test_cookie=WP%20Cookie%20check',
		form: { log: user, pwd: pass, 'wp-submit': 'Log In', redirect_to: `${ BASE }/wp-admin/`, testcookie: '1' },
	} );
	const jar = [];
	for ( const c of res.headers.getSetCookie() ) {
		const pair = c.split( ';' )[ 0 ];
		if ( ! pair.endsWith( '=' ) && ! /=deleted$/.test( pair ) ) {
			jar.push( pair );
		}
	}
	const loggedIn = jar.find( ( c ) => c.startsWith( 'wordpress_logged_in_' ) );
	if ( ! loggedIn ) {
		throw new Error( `login failed for ${ user }: HTTP ${ res.status }` );
	}
	return { user, cookie: jar.join( '; ' ), loggedIn, nonces: {} };
}

// Creates a nonce for the user's session through WordPress itself. offsetTicks > 0 gives an expired one.
export function nonceFor( session, action, offsetTicks = 0 ) {
	const [ name, ...rest ] = session.loggedIn.split( '=' );
	const code = `
		$u = get_user_by( 'login', getenv( 'SA_USER' ) );
		wp_set_current_user( $u->ID );
		$_COOKIE[ LOGGED_IN_COOKIE ] = getenv( 'SA_COOKIE' );
		$off = (int) getenv( 'SA_OFF' );
		if ( 0 === $off ) { echo wp_create_nonce( getenv( 'SA_ACTION' ) ); return; }
		$i = wp_nonce_tick() - $off;
		echo substr( wp_hash( $i . '|' . getenv( 'SA_ACTION' ) . '|' . $u->ID . '|' . wp_get_session_token(), 'nonce' ), -12, 10 );
	`;
	const r = evalPhp( code, { SA_USER: session.user, SA_COOKIE: decodeURIComponent( rest.join( '=' ) ), SA_ACTION: action, SA_OFF: String( offsetTicks ), SA_NAME: name } );
	return r.out.trim();
}

export function sessionNonce( session, action = 'store_audit' ) {
	const key = action;
	if ( ! session.nonces[ key ] ) {
		session.nonces[ key ] = nonceFor( session, action );
	}
	return session.nonces[ key ];
}

export function ajax( session, action, fields = {}, { nonce, raw = null } = {} ) {
	const cookie = session ? session.cookie : '';
	let body;
	if ( raw !== null ) {
		body = raw;
	} else {
		const pairs = [ [ 'action', action ] ];
		const n = nonce === undefined ? ( session ? sessionNonce( session ) : 'x' ) : nonce;
		if ( n !== null ) {
			pairs.push( [ 'nonce', n ] );
		}
		for ( const [ k, v ] of Object.entries( fields ) ) {
			pairs.push( [ k, v ] );
		}
		body = formString( pairs );
	}
	return http( 'POST', '/wp-admin/admin-ajax.php', { cookie, form: body } );
}

// A normal, complete audit through the AJAX endpoints. Returns the report data and the per-request timings.
export async function fullAudit( session, { only = null } = {} ) {
	const start = jsonBody( await ajax( session, 'store_audit_start' ) );
	if ( ! start || ! start.success ) {
		return { ok: false, step: 'start' };
	}
	const timings = [];
	const results = [];
	let checks = start.data.checks;
	if ( only ) {
		checks = checks.slice( 0, only );
	}
	for ( const c of checks ) {
		const r = await ajax( session, 'store_audit_run_check', { run_id: start.data.run_id, check_id: c.id } );
		const j = jsonBody( r );
		timings.push( { id: c.id, ms: r.ms, status: r.status } );
		if ( j && j.success ) {
			results.push( j.data );
		}
	}
	const fin = await ajax( session, 'store_audit_finish', { run_id: start.data.run_id } );
	return { ok: true, start: start.data, results, timings, finish: jsonBody( fin ), finishStatus: fin.status };
}

// ------------------------------------------------------------------ html safety
// Splits a page the way a browser does: returns the text outside scripts and the script bodies.
export function splitHtml( html ) {
	const scripts = [];
	let outside = '';
	let i = 0;
	const re = /<script\b[^>]*>/gi;
	let m;
	while ( ( m = re.exec( html ) ) !== null ) {
		outside += html.slice( i, m.index );
		const end = html.slice( re.lastIndex ).search( /<\/script/i );
		if ( end === -1 ) {
			scripts.push( html.slice( re.lastIndex ) );
			i = html.length;
			break;
		}
		scripts.push( html.slice( re.lastIndex, re.lastIndex + end ) );
		i = re.lastIndex + end;
		re.lastIndex = i;
	}
	outside += html.slice( i );
	return { outside, scripts };
}

// Markers used by every hostile data set below.
export const MARKERS = [ 'alert(1)', 'alert("xss', 'onerror=', 'onmouseover=' ];

// Returns a list of problems found in an HTML page (empty list = safe).
export function htmlProblems( html, { allowScripts = true } = {} ) {
	const problems = [];
	const { outside, scripts } = splitHtml( html );
	for ( const s of scripts ) {
		if ( /alert\s*\(/.test( s ) && ! /^\s*window\.storeAuditConfig\s*=/.test( s.trim() ) && ! /\bwp\./.test( s ) && ! /\{/.test( s.slice( 0, 4 ) ) ) {
			problems.push( 'unexpected script containing alert(' );
		}
		if ( /<!--/.test( s ) ) {
			problems.push( 'inline script contains "<!--"' );
		}
	}
	if ( /<script[^>]*>[^<]*alert/i.test( outside ) ) {
		problems.push( 'live <script> with alert in markup' );
	}
	if ( /<img[^>]*\sonerror\s*=/i.test( outside ) ) {
		problems.push( 'live <img onerror>' );
	}
	if ( /<[a-z][a-z0-9]*\s[^>]*\bon[a-z]+\s*=\s*["']?\s*alert/i.test( outside ) ) {
		problems.push( 'event handler attribute with alert' );
	}
	if ( /"\s*onmouseover\s*=/i.test( outside ) ) {
		problems.push( 'quote breakout before onmouseover' );
	}
	if ( /href\s*=\s*["']?\s*javascript:/i.test( outside ) ) {
		problems.push( 'javascript: URL in href' );
	}
	if ( /Fatal error|Warning:|Notice:|Deprecated:|Stack trace/.test( outside ) ) {
		problems.push( 'PHP error text in the page' );
	}
	if ( ! allowScripts && scripts.length > 0 ) {
		problems.push( 'unexpected inline script' );
	}
	return problems;
}

// Pulls window.storeAuditConfig out of the admin page and parses it; null when the script is broken.
export function pageConfig( html ) {
	const { scripts } = splitHtml( html );
	for ( const s of scripts ) {
		const m = s.match( /window\.storeAuditConfig\s*=\s*([\s\S]*?);\s*(?:\/\/# sourceURL=[^\n]*)?\s*$/ );
		if ( m ) {
			try {
				return JSON.parse( m[ 1 ] );
			} catch {
				return null;
			}
		}
	}
	return undefined;
}

// A report row that admin.js can render without throwing.
export function wellFormedReport( r ) {
	if ( r === null || r === undefined ) {
		return true;
	}
	if ( typeof r !== 'object' || ! Array.isArray( r.results ) ) {
		return false;
	}
	for ( const row of r.results ) {
		if ( ! row || typeof row !== 'object' || ! SEVERITIES.includes( row.severity ) || typeof row.title !== 'string' || typeof row.summary !== 'string' || typeof row.section !== 'string' ) {
			return false;
		}
		if ( row.details !== undefined && ! Array.isArray( row.details ) ) {
			return false;
		}
	}
	if ( ! r.score || typeof r.score !== 'object' || typeof r.score.overall !== 'number' || ( r.score.sections && typeof r.score.sections !== 'object' ) ) {
		return false;
	}
	for ( const s of Object.values( r.score.sections || {} ) ) {
		if ( ! s || typeof s !== 'object' || typeof s.score !== 'number' || typeof s.label !== 'string' ) {
			return false;
		}
	}
	return true;
}

// ------------------------------------------------------------------ test framework
export const table = [];
let current = null;

export async function scenario( id, name, expected, test, tests = '' ) {
	if ( process.env.ONLY && ! process.env.ONLY.split( ',' ).includes( id ) ) {
		return;
	}
	current = { id, name, expected, failures: [], notes: [], tests, checks: 0 };
	const t0 = Date.now();
	process.stdout.write( `\n== ${ id } ${ name }\n` );
	try {
		await test( current );
	} catch ( e ) {
		current.failures.push( `scenario crashed: ${ e && e.stack ? e.stack : e }` );
	}
	current.seconds = Math.round( ( Date.now() - t0 ) / 1000 );
	process.stdout.write( `   ${ current.failures.length ? 'FAIL' : 'ok' } (${ current.checks } checks, ${ current.seconds }s)\n` );
	for ( const f of current.failures.slice( 0, 25 ) ) {
		process.stdout.write( `   - ${ f }\n` );
	}
	if ( current.failures.length > 25 ) {
		process.stdout.write( `   ... ${ current.failures.length - 25 } more\n` );
	}
	for ( const n of current.notes ) {
		process.stdout.write( `   note: ${ n }\n` );
	}
	table.push( current );
	current = null;
}

export function ok( cond, message ) {
	if ( ! current ) {
		if ( ! cond ) {
			console.log( `outside a scenario: ${ message }` );
		}
		return !! cond;
	}
	current.checks++;
	if ( ! cond ) {
		current.failures.push( message );
	}
	return !! cond;
}

export function note( message ) {
	if ( ! current ) {
		console.log( `note: ${ message }` );
		return;
	}
	current.notes.push( message );
}

export function eq( actual, expected, message ) {
	return ok( actual === expected, `${ message } (expected ${ JSON.stringify( expected ) }, got ${ JSON.stringify( actual ) })` );
}

export function oneOf( actual, list, message ) {
	return ok( list.includes( actual ), `${ message } (expected one of ${ JSON.stringify( list ) }, got ${ JSON.stringify( actual ) })` );
}

export function logClean( mark, message ) {
	const l = logSince( mark );
	ok( l.ours.length === 0, `${ message }: PHP errors from the plugin in debug.log: ${ l.ours.slice( 0, 3 ).join( ' | ' ) }` );
	if ( l.other.length ) {
		note( `${ message }: ${ l.other.length } line(s) from other code (e.g. ${ l.other[ 0 ].slice( 0, 160 ) })` );
	}
	return l;
}
