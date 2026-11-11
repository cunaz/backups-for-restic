/**
 * Starts the repository server of the browser tests: a rest-server with TLS, a certificate
 * of its own and one account, as tests/Support/RestServer.php does for the PHP suites. What
 * the tests need to reach it is written to .tools/e2e-server.json.
 */
const { execFileSync, spawn } = require( 'node:child_process' );
const fs = require( 'node:fs' );
const net = require( 'node:net' );
const path = require( 'node:path' );

const USERNAME = 'plugin';
const PASSWORD = 'rest password';

function freePort() {
	return new Promise( function ( resolve, reject ) {
		const server = net.createServer();
		server.on( 'error', reject );
		server.listen( 0, '127.0.0.1', function () {
			const { port } = server.address();
			server.close( function () {
				resolve( port );
			} );
		} );
	} );
}

function listening( port ) {
	return new Promise( function ( resolve ) {
		const socket = net.connect( port, '127.0.0.1' );
		socket.on( 'connect', function () {
			socket.destroy();
			resolve( true );
		} );
		socket.on( 'error', function () {
			resolve( false );
		} );
	} );
}

module.exports = async function globalSetup() {
	const root = path.resolve( __dirname, '../..' );
	const binary = path.join( root, '.tools/bin/rest-server' );
	if ( ! fs.existsSync( binary ) ) {
		throw new Error( 'Run bin/install-test-tools.sh rest-server first.' );
	}
	const directory = fs.mkdtempSync( path.join( root, '.tools', 'e2e-server-' ) );
	const certificate = path.join( directory, 'cert.pem' );
	const key = path.join( directory, 'key.pem' );
	const data = path.join( directory, 'data' );
	fs.mkdirSync( data );

	execFileSync(
		'openssl',
		[ 'req', '-x509', '-newkey', 'ec', '-pkeyopt', 'ec_paramgen_curve:prime256v1', '-nodes', '-days', '2', '-subj', '/CN=127.0.0.1', '-addext', 'subjectAltName=IP:127.0.0.1', '-keyout', key, '-out', certificate ],
		{ stdio: 'ignore' },
	);
	const hash = execFileSync( 'php', [ '-r', 'echo password_hash( $argv[1], PASSWORD_BCRYPT );', PASSWORD ] ).toString();
	fs.writeFileSync( path.join( data, '.htpasswd' ), USERNAME + ':' + hash + '\n' );

	const port = await freePort();
	const log = fs.openSync( path.join( directory, 'server.log' ), 'a' );
	const server = spawn(
		binary,
		[ '--path', data, '--listen', '127.0.0.1:' + port, '--tls', '--tls-cert', certificate, '--tls-key', key ],
		{ detached: true, stdio: [ 'ignore', log, log ] },
	);
	server.unref();

	let up = false;
	for ( let attempt = 0; attempt < 100 && ! up; attempt++ ) {
		up = await listening( port );
		if ( ! up ) {
			await new Promise( function ( resolve ) {
				setTimeout( resolve, 50 );
			} );
		}
	}
	if ( ! up ) {
		throw new Error( 'rest-server did not start; see ' + path.join( directory, 'server.log' ) );
	}

	fs.writeFileSync(
		path.join( root, '.tools', 'e2e-server.json' ),
		JSON.stringify( {
			pid: server.pid,
			directory,
			url: 'https://127.0.0.1:' + port + '/site/',
			username: USERNAME,
			password: PASSWORD,
			certificate: fs.readFileSync( certificate, 'utf8' ),
		} ),
	);
};
