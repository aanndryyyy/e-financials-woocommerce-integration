<?php
/**
 * Isolated credential probe — mirrors e-financials/php-client
 * tests/Integration/DemoApi.php authentication.
 *
 * Usage: php scripts/probe-demo-credentials.php
 */

declare(strict_types=1);

require dirname( __DIR__ ) . '/vendor/autoload.php';

$id       = (string) ( getenv( 'E_FINANCIALS_API_KEY_ID' ) ?: '' );
$public   = (string) ( getenv( 'E_FINANCIALS_API_KEY_PUBLIC' ) ?: '' );
$password = (string) ( getenv( 'E_FINANCIALS_API_KEY_PASSWORD' ) ?: '' );

echo "e-financials/php-client DemoApi-style probe\n";
echo '  package:  ' . ( \Composer\InstalledVersions::getPrettyVersion( 'e-financials/php-client' ) ?? '?' ) . "\n";
echo '  id:       ' . ( $id !== '' ? 'SET len=' . strlen( $id ) : 'MISSING' ) . "\n";
echo '  public:   ' . ( $public !== '' ? 'SET len=' . strlen( $public ) : 'MISSING' ) . "\n";
echo '  password: ' . ( $password !== '' ? 'SET len=' . strlen( $password ) : 'MISSING' ) . "\n";

if ( $id === '' || $public === '' || $password === '' ) {
	fwrite( STDERR, "FAIL: missing E_FINANCIALS_API_KEY_{ID,PUBLIC,PASSWORD}\n" );
	exit( 2 );
}

// Same signing the package unit-tests assert:
// X-AUTH-KEY = public : BASE64(HMAC-SHA-384("{id}:{queryTime}:{path}", password))
$path       = '/v1/currencies';
$query_time = gmdate( "Y-m-d\TH:i:s" );
$signed     = $id . ':' . $query_time . ':' . $path;
$expected   = $public . ':' . base64_encode( hash_hmac( 'sha384', $signed, $password, true ) );
$via_vo     = EFinancialsClient\ValueObjects\ApiCredentials::from( $id, $public, $password )
	->authKey( $path, $query_time );
echo '  authKey self-check: ' . ( $expected === $via_vo ? 'match' : 'MISMATCH' ) . "\n";
echo '  queryTime: ' . $query_time . " UTC\n";

try {
	// Exact factory usage from tests/Integration/DemoApi.php (default base = demo).
	$client = EFinancials::factory()
		->withApiKeyId( $id )
		->withApiKeyPublic( $public )
		->withApiKeyPassword( $password )
		->make();

	$currencies = $client->currencies()->all();
	$count      = count( $currencies->data );

	echo "OK: currencies()->all() → {$count} row(s)\n";
	exit( 0 );
} catch ( Throwable $e ) {
	fwrite( STDERR, 'FAIL: ' . $e->getMessage() . "\n" );

	$ch = curl_init( 'https://demo-rmp-api.rik.ee' . $path );
	curl_setopt_array(
		$ch,
		[
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HTTPHEADER     => [
				'Content-Type: application/json',
				'X-AUTH-QUERYTIME: ' . $query_time,
				'X-AUTH-KEY: ' . $expected,
			],
		]
	);
	$body = (string) curl_exec( $ch );
	$code = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
	fwrite( STDERR, "Raw GET {$path} → HTTP {$code}: " . substr( $body, 0, 200 ) . "\n" );

	$egress_ch = curl_init( 'https://api.ipify.org' );
	curl_setopt_array( $egress_ch, [ CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5 ] );
	$egress     = (string) curl_exec( $egress_ch );
	fwrite( STDERR, 'Egress IP: ' . trim( $egress ) . "\n" );
	fwrite(
		STDERR,
		"Auth matches e-financials/php-client (ApiCredentials + DemoApi factory).\n" .
		"If credentials work from your machine, allowlist this egress IP (or 0.0.0.0/0)\n" .
		"on the demo ApiKey, and check RIK negative-event IP blocks after many 401s.\n"
	);
	exit( 1 );
}
