<?php
/**
 * Plugin Name: Morning Recent Customers & Invoices
 * Description: Admin-only page listing recent clients and invoices from Morning (greeninvoice) API.
 */

// Put these in wp-config.php:
//   define( 'MORNING_API_KEY',    'your-key-id' );
//   define( 'MORNING_API_SECRET', 'your-secret' );

if ( ! defined( 'ABSPATH' ) ) exit;

const MORNING_BASE = 'https://api.greeninvoice.co.il/api/v1';

/** Get a JWT, cached for ~50 min (token lives 1h). */
function morning_token() {
	$cached = get_transient( 'morning_jwt' );
	if ( $cached ) return $cached;

	if ( ! defined( 'MORNING_API_KEY' ) || ! defined( 'MORNING_API_SECRET' ) ) {
		return new WP_Error( 'morning', 'MORNING_API_KEY / MORNING_API_SECRET not set in wp-config.php' );
	}

	$res = wp_remote_post( MORNING_BASE . '/account/token', array(
		'headers' => array( 'Content-Type' => 'application/json' ),
		'body'    => wp_json_encode( array(
			'id'     => MORNING_API_KEY,
			'secret' => MORNING_API_SECRET,
		) ),
		'timeout' => 20,
	) );
	if ( is_wp_error( $res ) ) return $res;
	if ( wp_remote_retrieve_response_code( $res ) !== 200 ) {
		return new WP_Error( 'morning', 'Token request failed: ' . wp_remote_retrieve_body( $res ) );
	}

	$token = json_decode( wp_remote_retrieve_body( $res ), true )['token'] ?? null;
	if ( ! $token ) return new WP_Error( 'morning', 'No token in response.' );

	set_transient( 'morning_jwt', $token, 50 * MINUTE_IN_SECONDS );
	return $token;
}

/** POST a search endpoint with the JWT. Returns ['items'=>[...]] decoded or WP_Error. */
function morning_search( $path, $body ) {
	$token = morning_token();
	if ( is_wp_error( $token ) ) return $token;

	$res = wp_remote_post( MORNING_BASE . $path, array(
		'headers' => array(
			'Content-Type'  => 'application/json',
			'Authorization' => 'Bearer ' . $token,
		),
		'body'    => wp_json_encode( $body ),
		'timeout' => 20,
	) );
	if ( is_wp_error( $res ) ) return $res;
	if ( wp_remote_retrieve_response_code( $res ) !== 200 ) {
		return new WP_Error( 'morning', "Search $path failed: " . wp_remote_retrieve_body( $res ) );
	}
	return json_decode( wp_remote_retrieve_body( $res ), true );
}

add_action( 'admin_menu', function () {
	add_management_page(
		'Morning Recent',
		'Morning Recent',
		'manage_options',        // admins only
		'morning-recent',
		'morning_render_page'
	);
	add_management_page(
		'Export Orders',
		'Export Orders',
		'manage_options',
		'morning-orders',
		'morning_render_orders'
	);
} );

function morning_render_orders() {
	if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Not authorized.', 403 );
	if ( ! function_exists( 'wc_get_orders' ) ) wp_die( 'WooCommerce not active.' );

	echo '<div class="wrap"><h1>Export Orders</h1>';
	echo '<p>Pick a date range and download the orders as a CSV file.</p>';

	// From/To date pickers -> streams CSV via admin-post.
	echo '<form method="get" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin:1em 0;display:flex;gap:8px;align-items:center">';
	echo '<input type="hidden" name="action" value="morning_export_orders">';
	wp_nonce_field( 'morning_export', '_mexport' );
	echo '<label>From <input type="date" name="from" required></label>';
	echo '<label>To <input type="date" name="to" required></label>';
	echo '<button class="button button-primary">Download CSV</button>';
	echo '</form>';

	echo '</div>';
}

// CSV export: orders created between From/To. Streams a download.
add_action( 'admin_post_morning_export_orders', function () {
	if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Not authorized.', 403 );
	check_admin_referer( 'morning_export', '_mexport' );

	$from = sanitize_text_field( $_GET['from'] ?? '' );
	$to   = sanitize_text_field( $_GET['to'] ?? '' );
	if ( ! $from || ! $to ) wp_die( 'Pick both dates.' );

	$orders = wc_get_orders( array(
		'limit'        => -1,
		'orderby'      => 'date',
		'order'        => 'DESC',
		// inclusive range on creation date
		'date_created' => $from . '...' . $to . ' 23:59:59',
	) );

	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="orders_' . $from . '_' . $to . '.csv"' );

	$out = fopen( 'php://output', 'w' );
	fwrite( $out, "\xEF\xBB\xBF" ); // UTF-8 BOM so Excel reads Hebrew
	fputcsv( $out, array( 'Order', 'Date', 'Customer', 'Email', 'Status', 'Total', 'GreenInvoice' ) );

	foreach ( $orders as $o ) {
		// ponytail: GI doc number meta key varies by plugin version — adjust if blank.
		$gi = $o->get_meta( '_gi_doc_number' ) ?: $o->get_meta( '_greeninvoice_document_number' );
		fputcsv( $out, array(
			$o->get_order_number(),
			$o->get_date_created() ? $o->get_date_created()->date( 'Y-m-d H:i' ) : '',
			trim( $o->get_formatted_billing_full_name() ),
			$o->get_billing_email(),
			wc_get_order_status_name( $o->get_status() ),
			$o->get_total(),
			$gi,
		) );
	}
	fclose( $out );
	exit;
} );

function morning_render_page() {
	if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Not authorized.', 403 );

	echo '<div class="wrap"><h1>Morning — Recent Customers &amp; Invoices</h1>';

	// 20 most recent invoices (type 305 = invoice/receipt). Sorted newest first.
	$docs = morning_search( '/documents/search', array(
		'pageSize' => 20,
		'sort'     => 'creationDate',
		'type'     => array( 305 ),
	) );
	morning_table( 'Recent Invoices', $docs, function ( $d ) {
		return array(
			$d['number']    ?? '',
			$d['client']['name'] ?? '',
			number_format( (float) ( $d['amount'] ?? 0 ), 2 ),
			$d['documentDate'] ?? ( $d['creationDate'] ?? '' ),
		);
	}, array( 'Number', 'Client', 'Amount', 'Date' ) );

	// 20 most recently active clients.
	$clients = morning_search( '/clients/search', array(
		'pageSize' => 20,
		'sort'     => 'creationDate',
	) );
	morning_table( 'Recent Customers', $clients, function ( $c ) {
		return array(
			$c['name']  ?? '',
			$c['emails'][0] ?? ( $c['email'] ?? '' ),
			$c['phone'] ?? '',
		);
	}, array( 'Name', 'Email', 'Phone' ) );

	echo '</div>';
}

/** Render a result set (or its WP_Error) as a wp-list-table. */
function morning_table( $title, $result, $row_fn, $headers ) {
	echo '<h2>' . esc_html( $title ) . '</h2>';
	if ( is_wp_error( $result ) ) {
		echo '<div class="notice notice-error"><p>' . esc_html( $result->get_error_message() ) . '</p></div>';
		return;
	}
	$items = $result['items'] ?? array();
	echo '<table class="wp-list-table widefat striped"><thead><tr>';
	foreach ( $headers as $h ) echo '<th>' . esc_html( $h ) . '</th>';
	echo '</tr></thead><tbody>';
	foreach ( $items as $item ) {
		echo '<tr>';
		foreach ( $row_fn( $item ) as $cell ) echo '<td>' . esc_html( (string) $cell ) . '</td>';
		echo '</tr>';
	}
	if ( ! $items ) echo '<tr><td colspan="' . count( $headers ) . '">No results.</td></tr>';
	echo '</tbody></table>';
}
