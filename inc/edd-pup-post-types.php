<?php
/**
 * EDD Product Updates Post TYpes
 *
 * Install certain post types
 *
 *
 * @package    EDD_PUP
 * @author     Evan Luzi
 * @copyright  Copyright 2014 Evan Luzi, The Black and Blue, LLC
 * @since      0.9.2
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) exit;

function edd_pup_post_types() {

	$edd_pup_email_labels = array(
		'name' 				=> _x('Payments', 'post type general name', 'edd' ),
		'singular_name' 	=> _x('Payment', 'post type singular name', 'edd' ),
		'add_new' 			=> __( 'Add New', 'edd' ),
		'add_new_item' 		=> __( 'Add New Payment', 'edd' ),
		'edit_item' 		=> __( 'Edit Payment', 'edd' ),
		'new_item' 			=> __( 'New Payment', 'edd' ),
		'all_items' 		=> __( 'All Payments', 'edd' ),
		'view_item' 		=> __( 'View Payment', 'edd' ),
		'search_items' 		=> __( 'Search Payments', 'edd' ),
		'not_found' 		=>  __( 'No Payments found', 'edd' ),
		'not_found_in_trash'=> __( 'No Payments found in Trash', 'edd' ),
		'parent_item_colon' => '',
		'menu_name' 		=> __( 'Payment History', 'edd' )
	);

	$edd_pup_email_args = array(
		//'labels' 			=> apply_filters( 'edd_payment_labels', $payment_labels ),
		'public' 			=> false,
		'query_var' 		=> false,
		'rewrite' 			=> false,
		'show_ui'			=> false,
		'capability_type' 	=> 'manage_shop_settings',
		'map_meta_cap'      => true,
		'supports' 			=> array( 'title' ),
		'can_export'		=> true
	);
	
	register_post_type( 'edd_pup_email', $edd_pup_email_args );
}

add_action( 'init', 'edd_pup_post_types' );

/* Create the email post when initial send button is pressed and store the post_id into a transient for 24 hours.
 * Update the post if the button is pressed again.
 * If confirm send button is pressed, move the email from draft to publish and clear the transient afterward.
*/