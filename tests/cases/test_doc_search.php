<?php
/**
 * Account-scoped document search + status filtering.
 *
 * @package ComSign\Tests
 */

namespace ComSign\Tests;

use ComSign\Database\DocumentRepository;
use ComSign\Services\AccountService;
use ComSign\Services\DocumentService;

Test::add( 'documents: search + status filter', static function (): void {
	reset_tables();
	$svc      = new DocumentService();
	$docs     = new DocumentRepository();
	$account  = ( new AccountService() )->default_account_id();
	$scope    = array( $account );

	// Three documents: two drafts, one sent; distinct titles.
	$svc->create_from_text( 'Lease Agreement', '<p>x</p>', array() );
	$svc->create_from_text( 'Lease Renewal', '<p>x</p>', array() );
	$sent = $svc->create_from_text( 'Invoice', '<p>x</p>', array() );
	$s    = $svc->add_signer( $sent, 'A', 'a@example.com' );
	$svc->save_fields( $sent, array(
		array( 'signer_id' => $s, 'type' => 'signature', 'page' => 1, 'pos_x' => .1, 'pos_y' => .1, 'width' => .3, 'height' => .08 ),
	) );
	$svc->send( $sent );

	// No filter: all three.
	Test::equals( 3, $docs->count_for_accounts( $scope ), 'counts all documents' );

	// Title search.
	Test::equals( 2, $docs->count_for_accounts( $scope, 'Lease' ), 'search "Lease" matches two' );
	Test::equals( 1, $docs->count_for_accounts( $scope, 'Invoice' ), 'search "Invoice" matches one' );
	Test::equals( 0, $docs->count_for_accounts( $scope, 'nonexistent' ), 'search with no match returns zero' );

	// Status filter.
	Test::equals( 2, $docs->count_for_accounts( $scope, '', DocumentRepository::STATUS_DRAFT ), 'two drafts' );
	Test::equals( 1, $docs->count_for_accounts( $scope, '', DocumentRepository::STATUS_SENT ), 'one sent' );

	// Combined search + status.
	Test::equals( 0, $docs->count_for_accounts( $scope, 'Lease', DocumentRepository::STATUS_SENT ), 'no sent "Lease"' );
	$rows = $docs->paginate_for_accounts( $scope, 20, 0, 'Lease', DocumentRepository::STATUS_DRAFT );
	Test::equals( 2, count( $rows ), 'paginate returns the two draft "Lease" docs' );

	// Another account sees nothing.
	Test::equals( 0, $docs->count_for_accounts( array( 999999 ), 'Lease' ), 'foreign account scope is empty' );
} );
