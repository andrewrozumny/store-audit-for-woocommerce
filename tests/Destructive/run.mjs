// Destructive test runner. Usage (inside the clone container): node run.mjs [1] [2] [3] [4] [5]
import fs from 'node:fs';
import { table } from './lib.mjs';
import { s1, sessions } from './s1.mjs';
import { s1x } from './s1x.mjs';

const wanted = process.argv.slice( 2 );
const want = ( n ) => wanted.length === 0 || wanted.includes( String( n ) );

const S = await sessions();
if ( want( 1 ) ) {
	if ( ! process.env.ONLY_X ) {
		await s1( S );
	}
	await s1x( S );
}
if ( want( 2 ) ) {
	const m = await import( './s2.mjs' );
	await m.s2( S );
}
if ( want( 3 ) ) {
	const m = await import( './s3.mjs' );
	await m.s3( S );
}
if ( want( 4 ) ) {
	const m = await import( './s4.mjs' );
	await m.s4( S );
}
if ( want( 5 ) ) {
	const m = await import( './s5.mjs' );
	await m.s5( S );
}

const failed = table.filter( ( t ) => t.failures.length );
fs.mkdirSync( '/cache/tmp', { recursive: true } );
fs.writeFileSync( '/cache/tmp/destructive-results.json', JSON.stringify( table, null, 2 ) );
console.log( `\n${ table.length } scenarios, ${ failed.length } failed` );
for ( const t of table ) {
	console.log( `${ t.failures.length ? 'FAIL' : 'PASS' }  ${ t.id }  ${ t.name }  (${ t.checks } checks)` );
}
process.exit( failed.length ? 1 : 0 );
