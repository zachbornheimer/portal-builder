<?php
/**
 * Every third-party name below is prefixed, quoted in a comment, or merely similar.
 *
 * @see \GuzzleHttp\Client is documented here but never referenced as code.
 */

use DragonGate\Vendor\GuzzleHttp\Client;
use DragonGate\Vendor\Google\Service\Drive;
use DragonGate\Vendor\Psr\Log\LoggerInterface;

// A plain comment may name GuzzleHttp\Client or Google_Client.
# So may a hash comment: Psr\Log\LoggerInterface.

class Portal_Google_Connect {
	public function build( LoggerInterface $logger ) {
		$client = new Client();
		$names  = array(
			'DragonGate\\Vendor\\Google\\Client',
			'DragonGate_Vendor_Google_Client',
			'Portal_Google_Probe',
		);
		if ( class_exists( 'DragonGate\\Vendor\\GuzzleHttp\\Client' ) && $client instanceof \DragonGate\Vendor\GuzzleHttp\Client ) {
			return \DragonGate\Vendor\Psr\Log\NullLogger::class;
		}
		// The polyfill is deliberately unprefixed (composer.json extra.strauss.exclude_from_prefix).
		return \Symfony\Polyfill\Php80\Php80::class;
	}
}
