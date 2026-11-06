<?php
/**
 * Stand-in for another WordPress plugin's bundled Composer tree.
 *
 * Models op-dashboard (OptimizePress) on isjac.org, which ships
 * guzzlehttp/guzzle 6.3.3 and psr/log 1.x as global, unprefixed classes and
 * loads before DragonGate Portals. Only the shape that broke production is
 * reproduced:
 *
 * - GuzzleHttp\ClientInterface has VERSION (6.x) and no MAJOR_VERSION
 *   (introduced in 7.0), so google/apiclient's AuthHandlerFactory died with
 *   "Undefined constant GuzzleHttp\ClientInterface::MAJOR_VERSION".
 * - Psr\Log\LoggerInterface is the untyped 1.x signature, which a
 *   return-typed Monolog 3 cannot implement.
 *
 * Each symbol is declared eagerly, as a real autoloader would after the other
 * plugin used it, so the first-loaded copy always wins.
 */

namespace GuzzleHttp {
	interface ClientInterface {
		const VERSION = '6.3.3';

		public function send( $request, array $options = array() );
	}

	class Client implements ClientInterface {
		public function send( $request, array $options = array() ) {
			return null;
		}
	}
}

namespace Psr\Log {
	interface LoggerInterface {
		public function emergency( $message, array $context = array() );
		public function alert( $message, array $context = array() );
		public function critical( $message, array $context = array() );
		public function error( $message, array $context = array() );
		public function warning( $message, array $context = array() );
		public function notice( $message, array $context = array() );
		public function info( $message, array $context = array() );
		public function debug( $message, array $context = array() );
		public function log( $level, $message, array $context = array() );
	}

	class LogLevel {
		const EMERGENCY = 'emergency';
		const ALERT     = 'alert';
		const CRITICAL  = 'critical';
		const ERROR     = 'error';
		const WARNING   = 'warning';
		const NOTICE    = 'notice';
		const INFO      = 'info';
		const DEBUG     = 'debug';
	}
}
