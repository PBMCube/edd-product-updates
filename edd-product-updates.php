<?php
/**
 * Plugin Name: Easy Digital Downloads - Product Update Emails
 * Description: Batch send product update emails to EDD customers
 * Author: Evan Luzi
 * Author URI: http://evanluzi.com
 * Version: 0.9
 * Text Domain: edd-pup
 *
 * @package EDD_PUP
 * @author Evan Luzi
 * @version 0.9.2
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) exit;

// Includes
require( 'inc/edd-pup-payment.php');
require( 'inc/edd-pup-tags.php');
require( 'inc/edd-pup-post-types.php');
require( 'inc/edd-pup-submenu.php');
require( 'inc/edd-pup-ajax.php');

/**
 * Register custom database table name into $wpdb global
 * 
 * @access public
 * @return void
 * @since 0.9.2
 */
function edd_pup_register_table() {
    global $wpdb;
    $wpdb->edd_pup_queue = "{$wpdb->prefix}edd_pup_queue";
}
add_action( 'init', 'edd_pup_register_table', 1 );
add_action( 'switch_blog', 'edd_pup_register_table' );


/**
 * Create custom database table for email send queue
 * 
 * @access public
 * @return void
 * @since 0.9.2
 */
function edd_pup_create_tables() {
    require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
    
	global $wpdb;
	global $charset_collate;
	
	edd_pup_register_table();
	
	$sql_create_table = "CREATE TABLE {$wpdb->edd_pup_queue} (
          eddpup_id bigint(20) unsigned NOT NULL auto_increment,
          customer_id bigint(20) unsigned NOT NULL default '0',
          email_id bigint(20) unsigned NOT NULL default '0',
          sent bool NOT NULL default '0',
          sent_date timestamp NOT NULL default CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP,
          PRIMARY KEY  (eddpup_id),
          KEY customer_id (customer_id)
     ) $charset_collate; ";
 
	 dbDelta( $sql_create_table );
}
register_activation_hook( __FILE__, 'edd_pup_create_tables' );


/**
 * Removes ALL data on plugin uninstall including custom db table,
 * all transients, and all saved email sends (custom post type)
 * 
 * @access public
 * @return void
 * @since 0.9.2
 */
function edd_pup_uninstall(){
    global $wpdb;
    
    //Remove our table (if it exists)
    $wpdb->query("DROP TABLE IF EXISTS $wpdb->edd_pup_queue");
    
    //Remove all email posts
    $wpdb->query("DELETE FROM $wpdb->posts WHERE post_type = 'edd_pup_email'");
    
    //Remove all custom metadata from postmeta table
    $wpdb->query("DELETE FROM $wpdb->postmeta WHERE meta_key IN ( '_edd_pup_from_name' , '_edd_pup_from_email' , '_edd_pup_subject' , '_edd_pup_message' , '_edd_pup_headers' , '_edd_pup_updated_products' )");
         
    //Remove the database version
    delete_option('wptuts_activity_log_version');
 
    //Remove any leftover transients
	$customers = edd_pup_get_all_customers();	
	
	foreach ($customers as $customer){
		delete_transient( 'edd_pup_eligible_updates_'. $customer->ID );
	}
	delete_transient( 'edd_pup_email_id' );
	delete_transient( 'edd_pup_all_customers' );
	delete_transient( 'edd_pup_subject' );	
	delete_transient( 'edd_pup_email_body_header' );
	delete_transient( 'edd_pup_email_body_footer' );
}
register_uninstall_hook(__FILE__,'edd_pup_uninstall');

/**
 * Register and enqueue necessary JS and CSS files
 * 
 * @access public
 * @return void
 * @since 0.9
 */
function edd_pup_scripts() {
        wp_register_script( 'edd_prod_updates_js', plugins_url(). '/edd-product-updates/assets/edd-pup.js', false, '1.0.0' );
        wp_enqueue_script( 'edd_prod_updates_js' );

        wp_register_style( 'edd_prod_updates_css', plugins_url(). '/edd-product-updates/assets/edd-pup.css', false, '1.0.0' );
        wp_enqueue_style( 'edd_prod_updates_css' );
}
add_action( 'admin_enqueue_scripts', 'edd_pup_scripts' );

/**
 * Add Product Update Settings to EDD Settings -> Emails
 * 
 * @access public
 * @param mixed $edd_settings
 * @return array EDD Settings
 * @since 0.9
 */
function edd_pup_settings ( $edd_settings ) {
        $products = array();

        $downloads = get_posts( array( 'post_type' => 'download', 'posts_per_page' => -1 ) );

	    if ( !empty( $downloads ) ) {
	        foreach ( $downloads as $download ) {
	        	
	            $products[ $download->ID ] = get_the_title( $download->ID );

	        }
	    }

        $settings[] =
            array(
                'id' => 'prod_updates',
                'name' => '<strong>' . __( 'Product Updates Email Settings', 'edd-pup' ) . '</strong>',
                'desc' => __( 'Configure the Product Updates Email settings', 'edd-pup' ),
                'type' => 'header'
            );
	            
       if ( is_plugin_active('edd-software-licensing/edd-software-licenses.php' ) ) {
       
        $settings[] =
            array(
                'id' => 'prod_updates_license',
                'name' => __( 'Easy Digital Downloads Software Licensing Integration', 'edd-pup' ),
                'desc' => __( 'If enabled, only customers with active software licenses will receive update emails', 'edd-pup' ),
                'type' => 'checkbox'
            );
	            
        }
        
        $settings2 = array(
			array(
				'id' => 'edd_pup_template',
				'name' => __( 'Email Template', 'edd' ),
				'desc' => __( 'Choose a template to be used for the product update emails.', 'edd-pup' ),
				'type' => 'select',
				'options' => edd_get_email_templates()
			)
		);
		
        return array_merge( $edd_settings, $settings, $settings2 );
}
add_filter( 'edd_settings_emails', 'edd_pup_settings' );

function edd_pup_template(){
	global $edd_options;
	
	return $edd_options['edd_pup_template'];
}

/**
 * Product Update Email Action Buttons (Preview, Test, Send)
 *
 * @access private
 * @global $edd_options Array of all the EDD Options
 * @since 0.9
*/
function edd_pup_email_template_buttons() {
	
	global $edd_options;

	$default_email_body = 'This is the default body';
	$email_body = isset( $edd_options['prod_updates_message'] ) ? stripslashes( $edd_options['prod_updates_message'] ) : $default_email_body;	
	
	ob_start();
	?>
	<a href="#prod-updates-email-preview" id="prod-updates-open-email-preview" class="button-secondary" title="<?php _e( 'Product Update Email Preview', 'edd' ); ?> "><?php _e( 'Preview Email', 'edd' ); ?></a>
	<a href="<?php echo wp_nonce_url( add_query_arg( array( 'edd_action' => 'pup_send_test_email' ) ), 'edd-pup-test-email' ); ?>" title="<?php _e( 'This will send a demo product update email to the From Email listed above.', 'edd-prod-updates' ); ?>" class="button-secondary"><?php _e( 'Send Test Email', 'edd' ); ?></a>
	<div style="margin:10px 0;">
	<?php echo submit_button('Send Product Update Emails', 'primary', 'send-prod-updates', false);?><span class="edd-pu-spin spinner"></span>
	</div>

	<div id="prod-updates-email-preview-wrap" style="display:none;">
		<div id="prod-updates-email-preview">
			<?php echo edd_apply_email_template( $email_body, null, null ); ?>
		</div>
	</div>
	<?php
	echo ob_get_clean();
}
add_action( 'edd_prod_updates_email_settings', 'edd_pup_email_template_buttons' );


/**
 * Trigger the sending of a Product Update Email
 *
 * @param array $data Parameters sent from Settings page
 * @return void
 */
function edd_pup_send_emails( $data ) {
	$start = microtime(TRUE); 
	
	if ( ! wp_verify_nonce( $data['_wpnonce'], 'edd_pup_send_emails' ) )
		return;

	// Send emails
    edd_pup_email_loop();

    // Remove the test email query arg
    wp_redirect( remove_query_arg( 'edd_action' ) );
    $finish = microtime(TRUE);
    $totaltime = $finish - $start; 
    write_log('edd_pup_send_emails took '.$totaltime.' seconds to execute.');
    
    exit;
}
add_action( 'edd_pup_send_emails', 'edd_pup_send_emails' );

function edd_pup_create_email( $email_id = false ){
    $start = microtime(TRUE);
	global $edd_options;
	
	// Set variables that are the same for all customers
	$from_name = isset( $edd_options['prod_updates_from_name'] ) ? $edd_options['prod_updates_from_name'] : get_bloginfo('name');
	//$from_name = apply_filters( 'edd_prod_updates_from_name', $from_name, $payment_id, $payment_data );
	
	$from_email = isset( $edd_options['prod_updates_from_email'] ) ? $edd_options['prod_updates_from_email'] : get_option('admin_email');
	//$from_email = apply_filters( 'edd_purchase_from_address', $from_email, $payment_id, $payment_data );

	$headers = "From: " . stripslashes_deep( html_entity_decode( $from_name, ENT_COMPAT, 'UTF-8' ) ) . " <$from_email>\r\n";
	$headers .= "Reply-To: ". $from_email . "\r\n";
	$headers .= "Content-Type: text/html; charset=utf-8\r\n";

	$subject = apply_filters( 'edd_purchase_subject', ! empty( $edd_options['prod_updates_subject'] )
		? wp_strip_all_tags( $edd_options['prod_updates_subject'], true )
		: __( 'New Product Update', 'edd' ) );
				
	$updated_products = $edd_options['prod_updates_products'];

	if ( false === $email_id ) {	
		// Build post parameters array for custom post
		$post = array(
		  'post_content'   => $edd_options['prod_updates_message'],
		  'post_name'      => '',
		  'post_title'     => $edd_options['prod_updates_subject'],
		  'post_status'    => 'draft', // move this to publish when send button is pressed
		  'post_type'      => 'edd_pup_email',
		  'post_author'    => '',
		  'ping_status'    => 'closed',
		  'post_parent'    => 0,
		  'menu_order'     => 0,
		  'to_ping'        => '',
		  'pinged'         => '',
		  'post_password'  => '',
		  'guid'           => '',
		  'post_content_filtered' => '',
		  'post_excerpt'   => '', //maybe $headers
		  'comment_status' => 'closed'
		);
	
		// Create post and get the ID
		$create_id = wp_insert_post( $post );
		
		// Insert custom meta for newly created post
		if ( 0 != $create_id )	{
			add_post_meta ( $create_id, '_edd_pup_from_name', $from_name, true );
			add_post_meta ( $create_id, '_edd_pup_from_email', $from_email, true );
			add_post_meta ( $create_id, '_edd_pup_subject', $edd_options['prod_updates_subject'], true );
			add_post_meta ( $create_id, '_edd_pup_message', $edd_options['prod_updates_message'], true );
			add_post_meta ( $create_id, '_edd_pup_headers', $headers, true );
			add_post_meta ( $create_id, '_edd_pup_updated_products', $updated_products, true );		
			write_log('edd_pup_create_email created for ID'.$create_id.'');
		}
		
		set_transient( 'edd_pup_email_id', $create_id, 24 * 3600 );
	}
	
	if ( 0 != $email_id )	{
		update_post_meta ( $email_id, '_edd_pup_from_name', $from_name, true );
		update_post_meta ( $email_id, '_edd_pup_from_email', $from_email, true );
		update_post_meta ( $email_id, '_edd_pup_subject', $edd_options['prod_updates_subject'], true );
		update_post_meta ( $email_id, '_edd_pup_message', $edd_options['prod_updates_message'], true );
		update_post_meta ( $email_id, '_edd_pup_headers', $headers, true );
		update_post_meta ( $email_id, '_edd_pup_updated_products', $updated_products, true );
		write_log('edd_pup_create_email updated for ID '.$email_id.'');	
	}
	
    $finish = microtime(TRUE);
    $totaltime = $finish - $start; 
    write_log('edd_pup_create_email took '.$totaltime.' seconds to execute.');
}

/**
 * Loop through customers and trigger email if they purchased updated product
 * 
 * 
 * @access public
 * @return void
 */
function edd_pup_email_loop(){
    $start = microtime(TRUE);
	
	global $edd_options;
	$email_data = get_post_custom( get_transient( 'edd_pup_email_id' ) );
	$payments 	= edd_pup_get_all_customers();
	
	// Start the loop
	foreach ( $payments as $customer ){
		
		// Don't send to customers who have unsubscribed from updates
		if ( edd_pup_user_send_updates( $customer->ID ) ){
			
			// Check what products customers are eligible for updates
			$customer_updates = edd_pup_eligible_updates( $customer->ID, $edd_options['prod_updates_products'] );	
			
			// Send email if customers have eligible updates available				
			if ( ! empty( $customer_updates ) ) {
				
				edd_pup_trigger_email( $customer->ID, $email_data['_edd_pup_subject'][0], $email_data['_edd_pup_message'][0], $email_data['_edd_pup_headers'][0]  );				
			
				// Reset file download limits for customers' eligible updates
				foreach ( $customer_updates as $download ) {
					$limit = edd_get_file_download_limit( $download['id'] );
					if ( ! empty( $limit ) ) {
						edd_set_file_download_limit_override( $download['id'], $customer->ID );
					}
				}
			}
		}
		// Flush customer specific transient
		delete_transient( 'edd_pup_eligible_updates_'. $customer->ID );
	}
	
	// Flush remaining transients
	delete_transient( 'edd_pup_email_id' );
	delete_transient( 'edd_pup_all_customers' );
	delete_transient( 'edd_pup_subject' );	
	delete_transient( 'edd_pup_email_body_header' );
	delete_transient( 'edd_pup_email_body_footer' );
	 
    $finish = microtime(TRUE);
    $totaltime = $finish - $start; 
    write_log('edd_pup_email_loop took '.$totaltime.' seconds to execute.');
}

/**
 * Email the product update to the customer in a customizable message
 *
 * @param int $payment_id Payment ID
 * @param int $email_id Email ID for a edd_pup_email post-type
 * @return void
 */
function edd_pup_trigger_email( $payment_id, $_subject, $_message, $headers ) {
    $start = microtime(TRUE);

	$payment_data = edd_get_payment_meta( $payment_id );
	$email        = edd_get_payment_user_email( $payment_id );
	
	/* If subject doesn't use tags (and thus is the same for each customer)
	 * then store it in a transient for quick access on subsequent loops. */
	$subject = get_transient( 'edd_pup_subject' );
		
	if (false === $subject) {
		
		$subject = edd_do_email_tags( $_subject, $payment_id );
		
		if ( $subject === $_subject ) {
			set_transient( 'edd_pup_subject', $subject, 60 * 60 );
		}
	}
	
	$email_body_header = get_transient( 'edd_pup_email_body_header' );
	
	if ( false === $email_body_header ) {
		
		$email_body_header = edd_get_email_body_header();
		
		set_transient( 'edd_pup_email_body_header', $email_body_header, 60 * 60 );
	}
	
	$email_body_footer = get_transient( 'edd_pup_email_body_footer' );
	
	if ( false === $email_body_footer ) {
		
		$email_body_footer = edd_get_email_body_footer();
		
		set_transient( 'edd_pup_email_body_footer', $email_body_footer, 60 * 60 );
	}

	$message = $email_body_header;
	$message .= apply_filters( 'edd_purchase_receipt', edd_email_template_tags( $_message, $payment_data, $payment_id ), $payment_id, $payment_data );
	$message .= $email_body_footer;

	// Allow add-ons to add file attachments
	$attachments = apply_filters( 'edd_pup_attachments', array(), $payment_id, $payment_data );
	if ( apply_filters( 'edd_email_purchase_receipt', true ) ) {
		//$mailresult = wp_mail( $email, $subject, $message, $headers, $attachments );
		$mailresult = true;
	}
	
	// Update payment notes to log this email being sent	
	edd_insert_payment_note($payment_id, 'Sent product update email "'. $subject .'"');

    $finish = microtime(TRUE);
    $totaltime = $finish - $start; 
    write_log('edd_pup_trigger_email took '.$totaltime.' seconds to execute.');
    
    return $mailresult;
}

/**
 * Count number of customers who will receive product update emails
 *
 * 
 * @access public
 * @return $customercount (number of customers eligible for product updates)
 */
function edd_pup_customer_count( $email_id = null, $products = null ){
	
	if ( empty( $email_id ) && empty( $products ) ) {
		return false;
	}
	
	if ( isset( $email_id ) ) {
		$products = get_post_meta( $email_id, '_edd_pup_updated_products', TRUE );
	}
	
	$total = 0;
	$payments = edd_pup_get_all_customers();
	
	foreach ( $payments as $customer ){	
		
		if ( edd_pup_user_send_updates( $customer->ID ) ){
		
			$customer_updates = edd_pup_eligible_updates( $customer->ID, $products, false );
			
			if ( ! empty( $customer_updates ) ) {
				$total++;
			}
		}
	}
    
    return $total;
}

/**
 * Returns all payment history posts / customers
 * 
 * @access public
 * @return object (all edd_payment post types)
 */
function edd_pup_get_all_customers(){

	$customers = get_transient( 'edd_pup_all_customers' );
	
	if ( false === $customers ) {
	
		$queryargs = array(
			'posts_per_page'   => -1,
			'offset'           => 0,
			'category'         => '',
			'orderby'          => 'ID',
			'order'            => 'DESC',
			'include'          => '',
			'exclude'          => '',
			'meta_key'         => '',
			'meta_value'       => '',
			'post_type'        => 'edd_payment',
			'post_mime_type'   => '',
			'post_parent'      => '',
			'post_status'      => 'publish',
			'suppress_filters' => true
			);
		$customers = get_posts($queryargs);
		
		set_transient( 'edd_pup_all_customers', $customers, 60 );
	}
		
	return $customers;
}

function edd_pup_get_all_downloads(){

	$products = get_transient( 'edd_pup_all_downloads' );
	
	if ( false === $products ) {
		$products = array();
		$downloads = get_posts(	array( 'post_type' => 'download', 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC' ) );
		if ( !empty( $downloads ) ) {
		    foreach ( $downloads as $download ) {
		    	
		        $products[ $download->ID ] = get_the_title( $download->ID );
		
		    }
		}
		
		set_transient( 'edd_pup_all_downloads', $products, 60 );
	}
	
	return $products;
}

/**
 * Returns products that a customer is eligible to receive updates for 
 * 
 * @access public
 * @param mixed $payment_id
 * @param mixed $updated_products	array of products selected to update stored
 * @param bool $object	determines whether to return array of item IDs or item objects
 * in $edd_options['prod_updates_products']
 *
 * @return array $customer_updates
 */
function edd_pup_eligible_updates( $payment_id, $updated_products, $object = true ){
	
	//$customer_updates = get_transient( 'edd_pup_eligible_updates_'.$payment_id );
	
	if ( empty( $payment_id) || empty( $updated_products ) ) {
		return false;
	}
	
	$customer_updates = false;
	
	if ( false === $customer_updates ) {
		global $edd_options;
		
		$customer_updates = '';
		$cart_items = edd_get_payment_meta_cart_details( $payment_id, false );
			
		if ( isset($edd_options['prod_updates_license']) && is_plugin_active('edd-software-licensing/edd-software-licenses.php' ) ) {
			$licenses = edd_pup_get_license_keys($payment_id);
		}
		
		foreach ( $cart_items as $item ){
		
			if ( array_key_exists( $item['id'], $updated_products ) ){
				
				if ( ! empty($licenses) && isset($edd_options['prod_updates_license']) && get_post_meta( $item['id'], '_edd_sl_enabled', true ) ) {
					
					$checkargs = array(
						'key'        => $licenses[$item['id']],
						'item_name'  => $item['name']
					);
					
					$check = edd_software_licensing()->check_license($checkargs);
					
					if ( $check === 'valid' ) {				
						if ( $object ){
							$customer_updates[] = $item;
						} else {
							$customer_updates[] = $item['id'];
						}		
					}
					
				} else {
						if ( $object ){
							$customer_updates[] = $item;
						} else {
							$customer_updates[] = $item['id'];
						}		
				}
			}	
		}
	
		set_transient( 'edd_pup_eligible_updates_'.$payment_id, $customer_updates, 60*60 );
	}
	
	return $customer_updates;
}

/**
 * Return array of license keys matched with download ID for payment/customer
 * 
 * @access public
 * @param mixed $payment_id
 *
 * @return array $key
 */
function edd_pup_get_license_keys( $payment_id ){
	$key = '';
	$licenses = edd_software_licensing()->get_licenses_of_purchase( $payment_id );
	
	if ( $licenses ) {	
		foreach ( $licenses as $license ){
			$id = get_post_meta( $license->ID, '_edd_sl_download_id', true );
			$key[$id] = get_post_meta( $license->ID, '_edd_sl_key', true );
		}
	}
	
	return $key;
}

/**
 * Sets up and stores a new product update email
 *
 * @since 1.0
 * @param array $data Receipt data
 * @uses edd_store_receipt()
 * @return void
 */
function edd_pup_create_email_new( $data ) {
	if ( isset( $data['edd-pup-email-add-nonce'] ) && wp_verify_nonce( $data['edd-pup-email-add-nonce'], 'edd-pup-email-add-nonce' ) ) {
		
		$posted = edd_pup_prepare_data( $data );
		$email_id = 0;
		
		if ( isset( $data['email-id'] ) ) {
			$email_id = $data['email-id'];
		}
		
		$post = edd_pup_save_email( $posted, $email_id );
		
		if ( 0 != $post ) {

			wp_redirect( add_query_arg( array( 'view' => 'edit_pup_email' , 'id' => $post ), $data['edd-pup-email'] ) ); edd_die();

		} else {
		
			wp_redirect( add_query_arg( 'edd-message', 'receipt_add_failed', $data['edd-pup-email'] ) ); edd_die();
			
		}		
	}
}
add_action( 'edd_add_pup_email', 'edd_pup_create_email_new' );
add_action( 'edd_edit_pup_email', 'edd_pup_create_email_new' );

function edd_pup_prepare_data( $data ) {
	// Setup the email details
	$posted = array();

	foreach ( $data as $key => $value ) {
		
		if ( $key != 'edd-pup-email-add-nonce' && $key != 'edd-action' && $key != 'edd-pup-email' ) {

			if ( 'email' == $key || 'product' == $key )
				$posted[ $key ] = $value;
			elseif ( is_string( $value ) || is_int( $value ) )
				$posted[ $key ] = strip_tags( addslashes( $value ) );
			elseif ( is_array( $value ) )
				$posted[ $key ] = array_map( 'absint', $value );
		}
	}
	
	return $posted;
}

/**
 * Stores a receipt. If the receipt already exists, it updates it, otherwise it creates a new one.
 *
 * @since 1.0
 * @param string $details
 * @param int $receipt_id
 * @return bool Whether or not receipt was created
 */
function edd_pup_save_email( $data, $email_id = null ) {
	
	// Set variables that are the same for all customers
	$from_name = isset( $data['from_name'] ) ? $data['from_name'] : get_bloginfo('name');
	$from_email = isset( $data['from_email'] ) ? $data['from_email'] : get_option('admin_email');
	$subject = apply_filters( 'edd_purchase_subject', ! empty( $data['subject'] )
		? wp_strip_all_tags( $data['subject'], true )
		: __( 'New Product Update', 'edd-pup' ) );
	$products = isset( $data['product'] ) ? $data['product'] : '';
		
	if ( 0 != $email_id ) {
	
		$updateargs = array(
			'ID' => $email_id,
			'post_content' => $data['message'],
			'post_title' => $data['title'],
			'post_excerpt' => $data['subject']
		);
		
		$update_id = wp_update_post( $updateargs );
		update_post_meta ( $email_id, '_edd_pup_from_name', $from_name );
		update_post_meta ( $email_id, '_edd_pup_from_email', $from_email );
		update_post_meta ( $email_id, '_edd_pup_subject', $data['subject'] );
		update_post_meta ( $email_id, '_edd_pup_message', $data['message'] );
		update_post_meta ( $email_id, '_edd_pup_updated_products', $products );
		update_post_meta ( $email_id, '_edd_pup_recipients', $data['recipients'] );
		write_log('edd_pup_create_email updated for ID '.$email_id.'');

		if ( ( $update_id != 0 ) && ( $update_id == $email_id ) ) {
			return $email_id;
		}
		
	} else {
		// Build post parameters array for custom post
		$post = array(
		  'post_content'   => $data['message'],
		  'post_name'      => '',
		  'post_title'     => $data['title'],
		  'post_status'    => 'draft',
		  'post_type'      => 'edd_pup_email',
		  'post_author'    => '',
		  'ping_status'    => 'closed',
		  'post_parent'    => 0,
		  'menu_order'     => 0,
		  'to_ping'        => '',
		  'pinged'         => '',
		  'post_password'  => '',
		  'guid'           => '',
		  'post_content_filtered' => '',
		  'post_excerpt'   => $data['subject'], //maybe $headers
		  'comment_status' => 'closed'
		);
	
		// Create post and get the ID
		$create_id = wp_insert_post( $post );
		
		// Insert custom meta for newly created post
		if ( 0 != $create_id )	{
			add_post_meta ( $create_id, '_edd_pup_from_name', $from_name, true );
			add_post_meta ( $create_id, '_edd_pup_from_email', $from_email, true );
			add_post_meta ( $create_id, '_edd_pup_subject', $data['subject'], true );
			add_post_meta ( $create_id, '_edd_pup_message', $data['message'], true );
			add_post_meta ( $create_id, '_edd_pup_updated_products', $products, true );
			add_post_meta ( $email_id, '_edd_pup_recipients', $data['recipients'] );	
			write_log('edd_pup_create_email created for ID'.$create_id.'');
		}
		
		//set_transient( 'edd_pup_email_id', $create_id, 24 * 3600 );
    	if ( 0 != $create_id) {	
			return $create_id;
		}
	}
}

function edd_pup_delete_email( $data ) {
	if ( ! wp_verify_nonce( $data['_wpnonce'], 'edd-pup-delete-nonce' ) )
		return;
		
	$goodbye = wp_delete_post( $data['id'], true );
	
	if ( false === $goodbye || empty( $goodbye ) ) {
		wp_redirect( add_query_arg( array( 'nope' => 'didntwork' ) ) );
	} else {
		wp_redirect( admin_url( 'edit.php?post_type=download&page=edd-prod-updates' ) );
	}
	
    exit;
}
add_action( 'edd_pup_delete_email', 'edd_pup_delete_email' );

function edd_pup_ajax_save( $posted ) {
	
	// Convert form data to array
	$data = array();
	parse_str($posted['form'], $data );
	
	//Sanitize our data
	$data['message'] 	= wp_kses_post( $data['message'] );
	$data['email-id']	= absint( $data['email-id'] );
	$data['recipients']	= absint( $data['recipients'] );
	$data['from_name'] 	= sanitize_text_field( $data['from_name'] );
	$data['from_email'] = sanitize_email( $data['from_email'] );
	$data['title']		= sanitize_text_field( $data['title'], 'ID:'. $data['email-id'], 'save' );
	$data['subject']	= sanitize_text_field( $data['subject'] );
	
	if ( isset( $data['product'] ) ) {
		$data['product'] = filter_var_array( $data['product'], FILTER_SANITIZE_STRING );
	} else {
		$data['product'] = '';
	}
	
	return edd_pup_save_email( $data, $data['email-id'] );
}

function edd_pup_ajax_preview() {
	
	$email_id = edd_pup_ajax_save( $_POST );
	
	if ( 0 != $email_id ){
	
		$email = get_post( $email_id );
		
		// Use $template_name = apply_filters( 'edd_email_template', $template_name, $payment_id );
		add_filter('edd_email_template', 'edd_pup_template' );
		
		echo edd_apply_email_template( $email->post_content, null, null );
	} else {
		_e('There was an error retrieving the post. Please contact support.', 'edd-pup');
	}
	
	die();
}
add_action( 'wp_ajax_edd_pup_ajax_preview', 'edd_pup_ajax_preview' );

/**
 * Trigger the sending of a Product Update Test Email
 *
 * @param array $data Parameters sent from Settings page
 * @return void
 */
function edd_pup_send_test_email() {
	//if ( ! wp_verify_nonce( $_POST['_wpnonce'], 'edd-pup-test-nonce' ) )
	//	return;
	
	$error = 0;	
	$form = array();
	parse_str($_POST['form'], $form );

	if ( empty( $form['test-email'] ) ) {
		_e( 'Please enter an email address to send the test to.', 'edd-pup' );		
	} else {
		
		$emails = explode( ',', $form['test-email'], 6 );
		
		if ( count( $emails ) > 5 ) {
			array_pop( $emails );
		}
		
		// Sanitize our email addresses to make sure they're valid
		foreach ( $emails as $key => $address ) {
			$clean = sanitize_email( $address );
			
			if ( is_email( $clean ) ) {
				$to[$key] = $clean;
			} else {
				$error++;
			}
		}
		
		if ( !empty( $to ) ) {
			$email_id = edd_pup_ajax_save( $_POST );
			
			// Send a test email
	    	$sent = edd_pup_test_email( $email_id, $to );
	    	
	    	if ( $error > 0 ) {
				_e( 'One or more of the emails entered were invalid. Test emails sent to: ' . implode(', ', $sent), 'edd-pup' );	    	
	    	} else {
	    	
				_e( 'Test email sent to: ' . implode(', ', $sent), 'edd-pup' );
			}
			
		} else if ( empty( $to ) && $error > 0 ) {
			_e( 'Your email address was invalid. Please enter a valid email address to send the test.', 'edd-pup' );
		}
	
	}	
	
    die();
}
add_action( 'wp_ajax_edd_pup_send_test_email', 'edd_pup_send_test_email' );

/**
 * Email the product update test email to the admin account
 *
 * @global $edd_options Array of all the EDD Options
 * @return void
 */
function edd_pup_test_email( $email_id, $to = null ) {	
	
	$email     = get_post( $email_id );
	$emailmeta = get_post_custom( $email_id );

	$default_email_body = __( "Dear", "edd" ) . " {name},\n\n";
	$default_email_body .= __( "Thank you for your purchase. Please click on the link(s) below to download your files.", "edd" ) . "\n\n";
	$default_email_body .= "{download_list}\n\n";
	$default_email_body .= "{sitename}";
	
	add_filter('edd_email_template', 'edd_pup_template' );
	$body = edd_apply_email_template( $email->post_content, $email_id, null );
	
	$message = edd_get_email_body_header();
	$message .= apply_filters( 'edd_prod_updates_message', edd_email_preview_template_tags( $body ), 0, array() );
	$message .= edd_get_email_body_footer();

//CHANGE THIS//
	$from_name = isset( $emailmeta['from_name'] ) ? $emailmeta['from_name'] : get_bloginfo('name');
	$from_email = isset( $emailmeta['from_email'] ) ? $emailmeta['from_email'] : get_option('admin_email');

	$subject = apply_filters( 'edd_prod_updates_subject', isset( $email->post_excerpt )
		? trim( $email->post_excerpt )
		: __( '(no subject)', 'edd-pup' ), 0 );
	$subject = edd_do_email_tags( $subject, $email_id );

	$headers = "From: " . stripslashes_deep( html_entity_decode( $from_name, ENT_COMPAT, 'UTF-8' ) ) . " <$from_email>\r\n";
	$headers .= "Reply-To: ". $from_email . "\r\n";
	//$headers .= "MIME-Version: 1.0\r\n";
	$headers .= "Content-Type: text/html; charset=utf-8\r\n";
	$headers = apply_filters( 'edd_test_purchase_headers', $headers );
	
	foreach ( $to as $recipient ) {
		wp_mail( $recipient, $subject, $message, $headers );
	}
	
	return $to;
}

/**
 * Generates HTML for email confirmation via AJAX on send button press
 * 
 * @access public
 * @return void
 * @since 0.9
 */
function edd_pup_email_confirm_html(){
	global $edd_options;
	
	$form = array();
	parse_str($_POST['form'], $form );
	
	parse_str( $_POST['url'], $url );
	
	$email_id = edd_pup_ajax_save( $_POST );

	$email     = get_post( $email_id );
	$emailmeta = get_post_custom( $email_id );
    
	$products = get_post_meta( $email_id, '_edd_pup_updated_products', true );
	$productlist = '';
	
	if ( empty( $products ) ) {
		echo 'nocheck';
		die();
	}
	
	if ( $url['view'] == 'add_pup_email' ) {
		
		$data = array( 'response' => 'add_post_redirect', 'id' => $email_id );
		echo json_encode($data);
		die();
	}
	
	foreach ( $products as $product_id => $product ) {
		$productlist .= '<li data-id="'. $product_id .'">'.$product.'</li>';
	}
	
	// Creates the email post-type or updates it if transient isn't set
	//edd_pup_create_email( $email_id );
	
	/*if ( 0 !== $email_id ) {
	
		delete_transient( 'edd_pup_all_customers' );
		delete_transient( 'edd_pup_subject' );
		
		$payments = edd_pup_get_all_customers();
		
		foreach ( $payments as $customer ){
			delete_transient( 'edd_pup_eligible_updates_'. $customer->ID );
		}	
	
	}*/
	
	$nonceurl = add_query_arg( array( 'view' => 'pup_send_ajax', 'id' => $email_id ), 'http://tbabloc.dev/wp-admin/edit.php?post_type=download&page=edd-prod-updates');
	
	$customercount = edd_pup_customer_count( $email_id, $products );
	
	// Construct the email message
	$default_email_body = 'Cannot retrieve message content';
	$email_body = isset( $email->post_content ) ? stripslashes( $email->post_content ) : $default_email_body;
	
	// Construct templated email HTML
	add_filter('edd_email_template', 'edd_pup_template' );
	$message = edd_apply_email_template( $email_body, null, null );
	
	ob_start();
	?>
		<!-- Begin send email confirmation message -->
			<div id="prod-updates-email-preview-confirm">
				<div id="prod-updates-email-confirm-titles">
					<h2><strong>Almost Ready to Send!</strong></h2>
					<p>Please carefully check the information below before sending your emails.</p>
				</div>
					<div id="prod-updates-email-preview-message">
						<div id="prod-updates-email-preview-header">
							<h3>Email Message Preview</h3>
							<ul class="prod-updates-email-confirm-info">
								<li><strong>From:</strong> <?php echo $emailmeta['_edd_pup_from_name'][0];?> (<?php echo $emailmeta['_edd_pup_from_email'][0];?>)</li>
								<li><strong>Subject:</strong> <?php echo $emailmeta['_edd_pup_subject'][0];?></li>
							</ul>
						</div>
				<?php echo $message ?>
				<div id="prod-updates-email-preview-footer">
					<h3>Additional Information</h3>
						<ul class="prod-updates-email-confirm-info">
							<li><strong>Updated Products:</strong></li>
								<ul id="prod-updates-email-confirm-prod-list">
									<?php echo $productlist;?>
								</ul>
							<li><strong>Recipients:</strong> <?php echo $customercount;?> customers will receive this email and have their downloads reset</li>
						</ul>
						<a href="<?php echo wp_nonce_url( $nonceurl, 'edd_pup_email_loop_ajax' ); ?>" id="prod-updates-email-ajax" class="button-primary button" title="<?php _e( 'Confirm and Send Emails', 'edd-prod-updates' ); ?>"><?php _e( 'Confirm and Send Emails', 'edd-prod-updates' ); ?></a>
						<button class="closebutton button-secondary">Close without sending</button>
					</div>
				</div>
			<!-- End send email confirmation message -->
	<?php
	echo ob_get_clean();
	
	die();
}
add_action( 'wp_ajax_edd_pup_confirm_ajax', 'edd_pup_email_confirm_html' );

// Helper function for debugging performance
function write_log ( $log )  {
     
     error_log( $log );
}