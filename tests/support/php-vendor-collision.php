<?php
/**
 * CLI harness: load ANOTHER plugin's Composer autoloader first, then ours, then
 * exercise the Google client path and Monolog the way a submit does.
 *
 *   php tests/support/php-vendor-collision.php \
 *       --foreign=/path/to/other-plugin/vendor/autoload.php \
 *       --ours=vendor-prefixed/autoload.php \
 *       --prefix='DragonGate\Vendor\'
 *
 * Production fatal this guards (ZYS-2607): op-dashboard (OptimizePress) loads
 * guzzlehttp/guzzle 6.3.3 and psr/log 1.x first, so the plugin's unprefixed
 * Google client died with
 *   Undefined constant GuzzleHttp\ClientInterface::MAJOR_VERSION
 *   in google/apiclient/src/AuthHandler/AuthHandlerFactory.php
 *
 * --prefix='' runs the same path against an unprefixed vendor/autoload.php
 * (the pre-fix layout) and is expected to fail.
 *
 * Prints one JSON object. Exit 0 only when every probe passes.
 */

$options = getopt( '', array( 'foreign:', 'ours:', 'prefix::' ) );
foreach ( array( 'foreign', 'ours' ) as $required ) {
	if ( empty( $options[ $required ] ) || ! is_string( $options[ $required ] ) ) {
		fwrite( STDERR, "php-vendor-collision: --{$required}=<autoload.php> is required\n" );
		exit( 2 );
	}
}
$prefix = isset( $options['prefix'] ) && is_string( $options['prefix'] ) ? $options['prefix'] : '';

$result = array(
	'ok'     => false,
	'prefix' => $prefix,
);

/**
 * Report a failed probe the way the exception handler would, then stop.
 *
 * @param array<string,mixed> $result Result so far.
 * @param string              $probe  Probe name.
 * @param Throwable           $e      Failure.
 */
function vendor_collision_fail( array $result, $probe, Throwable $e ) {
	$result['ok']    = false;
	$result['probe'] = $probe;
	$result['error'] = get_class( $e ) . ': ' . $e->getMessage();
	$result['where'] = basename( $e->getFile() ) . ':' . $e->getLine();
	echo json_encode( $result, JSON_UNESCAPED_SLASHES ) . "\n";
	exit( 1 );
}

foreach ( array( 'foreign', 'ours' ) as $which ) {
	$autoload = $options[ $which ];
	if ( ! is_readable( $autoload ) || filesize( $autoload ) < 200 ) {
		fwrite( STDERR, "php-vendor-collision: --{$which} autoload missing or a stub: {$autoload}\n" );
		exit( 2 );
	}
}

// The other plugin wins the race: it loads first.
require_once $options['foreign'];
$result['foreignGuzzleMajorDefined'] = defined( 'GuzzleHttp\\ClientInterface::MAJOR_VERSION' );

try {
	require_once $options['ours'];
} catch ( Throwable $e ) {
	vendor_collision_fail( $result, 'load-ours', $e );
}

$guzzle_interface = $prefix . 'GuzzleHttp\\ClientInterface';
$auth_factory     = $prefix . 'Google\\AuthHandler\\AuthHandlerFactory';
$google_client    = $prefix . 'Google\\Client';
$logger_class     = $prefix . 'Monolog\\Logger';
$stream_handler   = $prefix . 'Monolog\\Handler\\StreamHandler';
$psr_logger       = $prefix . 'Psr\\Log\\LoggerInterface';

// Probe 1: Google auth handler selection reads ClientInterface::MAJOR_VERSION.
try {
	$handler                   = $auth_factory::build();
	$result['authHandler']     = get_class( $handler );
	$result['guzzleInterface'] = ( new ReflectionClass( $guzzle_interface ) )->getFileName();
} catch ( Throwable $e ) {
	vendor_collision_fail( $result, 'AuthHandlerFactory::build', $e );
}

// Probe 2: full client path a submit takes up to the network call.
try {
	$client = new $google_client();
	$client->setApplicationName( 'collision-probe' );
	$client->setAccessToken(
		array(
			'access_token' => 'probe-token',
			'expires_in'   => 3600,
			'created'      => time(),
		)
	);
	$authorized                 = $client->authorize();
	$result['authorizedClient'] = get_class( $authorized );
} catch ( Throwable $e ) {
	vendor_collision_fail( $result, 'Client::authorize', $e );
}

// Probe 3: Monolog against psr/log, the earlier ZYS-2607 fatal.
try {
	$logger = new $logger_class( 'collision-probe' );
	$logger->pushHandler( new $stream_handler( 'php://memory' ) );
	$logger->warning( 'collision probe', array( 'ctx' => 1 ) );
	$client->setLogger( $logger );
	$result['loggerImplementsPsr'] = $logger instanceof $psr_logger;
	$result['psrLoggerFile']       = ( new ReflectionClass( $psr_logger ) )->getFileName();
	if ( ! $result['loggerImplementsPsr'] ) {
		throw new RuntimeException( 'Monolog Logger does not implement ' . $psr_logger );
	}
} catch ( Throwable $e ) {
	vendor_collision_fail( $result, 'Monolog Logger', $e );
}

// Probe 4: the plugin's own imports resolve to the copies under the plugin dir.
$plugin_root = dirname( __DIR__, 2 );
foreach ( array( $guzzle_interface, $psr_logger, $logger_class ) as $class ) {
	$file = ( new ReflectionClass( $class ) )->getFileName();
	if ( false === strpos( (string) $file, $plugin_root ) ) {
		vendor_collision_fail( $result, 'resolved-from-plugin', new RuntimeException( $class . ' resolved outside the plugin: ' . $file ) );
	}
}

$result['ok'] = true;
echo json_encode( $result, JSON_UNESCAPED_SLASHES ) . "\n";
exit( 0 );
