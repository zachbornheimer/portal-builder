<?php
/**
 * Each code line below names a bundled dependency without the DragonGate\Vendor\ prefix.
 */

use GuzzleHttp\Client;
use \Google\Service\Drive;

class Portal_Offending {
	public function build( $value ) {
		$guzzle  = new Client();
		$fqcn    = new \GuzzleHttp\Psr7\Request( 'GET', '/' );
		$static  = Google\Service\Drive::class;
		$check   = $value instanceof Psr\Log\LoggerInterface;
		$string  = class_exists( 'GuzzleHttp\\Client' );
		$global  = class_exists( 'Google_Client' );
		$array   = array(
			'Psr\\Log\\NullLogger',
		);
		return $array;
	}
}
