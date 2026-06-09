<?php
/**
 * Portal hosting: the [comsign_portal] shortcode lets any page act as the
 * customer personal area, and portal links follow that page when present.
 *
 * @package ComSign\Tests
 */

namespace ComSign\Tests;

use ComSign\Frontend\PortalController;

Test::add( 'portal: [comsign_portal] page is detected and links route to it', static function (): void {
	// No hosting page yet → links fall back to the built-in /comsign/app route.
	PortalController::flush_portal_page_cache();
	Test::equals( 0, PortalController::portal_page_id(), 'no portal page initially' );
	Test::ok( false !== strpos( PortalController::url(), 'comsign_app' ), 'falls back to comsign_app route' );

	// Publish a page that hosts the shortcode.
	$page_id = wp_insert_post( array(
		'post_title'   => 'My account',
		'post_content' => 'Welcome. [comsign_portal]',
		'post_status'  => 'publish',
		'post_type'    => 'page',
	) );
	Test::ok( $page_id > 0, 'hosting page created' );

	// save_post should have flushed the cache; detection finds the page.
	Test::equals( (int) $page_id, PortalController::portal_page_id(), 'shortcode page detected' );

	// Portal links now point at that page, carrying view args but not comsign_app.
	$url = PortalController::url( array( 'view' => 'billing' ) );
	Test::ok( false !== strpos( $url, 'view=billing' ), 'view arg carried through' );
	Test::ok( false === strpos( $url, 'comsign_app' ), 'no comsign_app when a host page exists' );

	// Unpublishing the page removes it from detection (back to the route).
	wp_update_post( array( 'ID' => $page_id, 'post_status' => 'draft' ) );
	PortalController::flush_portal_page_cache();
	Test::equals( 0, PortalController::portal_page_id(), 'draft page is not a portal host' );

	wp_delete_post( $page_id, true );
	PortalController::flush_portal_page_cache();
} );
