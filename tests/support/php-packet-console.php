<?php
/**
 * Drive shipped packet policy + store (staff replace, applicant list/recall).
 *
 * Usage:
 *   php tests/support/php-packet-console.php <scenario.json>
 */
// phpcs:disable
function wp_json_encode( $data ) {
	return json_encode( $data );
}

$repo = dirname( __DIR__, 2 );
require_once $repo . '/includes/Submission/class-portal-files.php';
require_once $repo . '/includes/Submission/class-portal-submit-log.php';
require_once $repo . '/includes/Submission/class-portal-packet-policy.php';
require_once $repo . '/includes/Submission/class-portal-packet-store.php';

$in = json_decode( file_get_contents( $argv[1] ), true );
$in = is_array( $in ) ? $in : array();
$dir = isset( $in['dir'] ) ? $in['dir'] : sys_get_temp_dir() . '/dg-packets-test';

class Fake_Packet_Drive {
	public $writes = array();
	public function store_file( $portal_id, $field_id, $buffer, $filename ) {
		$this->writes[] = array(
			'portal'   => (string) $portal_id,
			'field'    => (string) $field_id,
			'bytes'    => (string) $buffer,
			'filename' => (string) $filename,
		);
		return $this->writes[ count( $this->writes ) - 1 ];
	}
}

$drive = new Fake_Packet_Drive();
$store = new Portal_Packet_Store( $dir, new Portal_Files(), $drive );

$email = isset( $in['email'] ) ? $in['email'] : 'alex@example.com';
$hash  = Portal_Submit_Log::hash_email( $email );
$packet = $store->record(
	'portal-1',
	array(
		'applicationId' => 'app-1',
		'portalId'      => 'portal-1',
		'emailHash'     => $hash,
		'status'        => Portal_Packet_Policy::STATUS_CURRENT,
	)
);

$other = $store->record(
	'portal-1',
	array(
		'applicationId' => 'app-other',
		'portalId'      => 'portal-1',
		'emailHash'     => Portal_Submit_Log::hash_email( 'other@example.com' ),
		'status'        => Portal_Packet_Policy::STATUS_CURRENT,
	)
);
unset( $other );

$staff_ok  = Portal_Packet_Policy::staff_can_edit( array( 'manage_options' => true ) );
$guest_no  = Portal_Packet_Policy::staff_can_edit( array() );
$owner_ok  = Portal_Packet_Policy::applicant_can_touch( $hash, $packet );
$stranger  = Portal_Packet_Policy::applicant_can_touch( Portal_Submit_Log::hash_email( 'nope@example.com' ), $packet );

$mine = $store->list_for_email( 'portal-1', $email );

$replaced = null;
if ( $staff_ok ) {
	$replaced = $store->replace_file( 'portal-1', 'app-1', 'score', 'NEW-BYTES', 'score.pdf' );
}

$denied_replace = $store->replace_file( 'portal-1', 'app-missing', 'score', 'X', 'x.pdf' );

$recalled = $store->recall( 'portal-1', 'app-1' );
$after    = $store->get( 'portal-1', 'app-1' );
$touch_after = Portal_Packet_Policy::applicant_can_touch( $hash, is_array( $after ) ? $after : array() );

echo json_encode(
	array(
		'staffOk'        => $staff_ok,
		'guestDenied'    => ! $guest_no,
		'ownerCanTouch'  => $owner_ok,
		'strangerDenied' => ! $stranger,
		'listCount'      => count( $mine ),
		'listOnlyMine'   => 1 === count( $mine ) && 'app-1' === $mine[0]['applicationId'],
		'replaced'       => is_array( $replaced ),
		'driveWrites'    => $drive->writes,
		'deniedReplace'  => null === $denied_replace,
		'recalled'       => is_array( $recalled ) && Portal_Packet_Policy::STATUS_RECALLED === $recalled['status'],
		'notCurrent'     => is_array( $after ) && ! Portal_Packet_Policy::is_current( $after ),
		'noTouchAfter'   => ! $touch_after,
	)
) . "\n";
