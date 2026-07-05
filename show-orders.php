<?php
/**
 * Plugin Name: Show Orders
 * Description: Admin-only page that lists WooCommerce orders, optionally filtered by product, with CSV export.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'admin_menu', function () {
	add_management_page(
		'Show Orders',          // page title
		'Show Orders',          // menu label
		'edit_shop_orders',     // admins + shop managers (anyone who can manage orders)
		'show-orders',
		'show_orders_page'
	);
} );

// Fetch orders, optionally only those containing $product_id.
function show_orders_query( $product_id = 0 ) {
	$args = array( 'limit' => 1000, 'orderby' => 'date', 'order' => 'DESC' );

	if ( $product_id ) {
		// Get matching order IDs straight from WooCommerce's product lookup table (indexed).
		global $wpdb;
		$table = $wpdb->prefix . 'wc_order_product_lookup';
		$has_table = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;

		if ( $has_table ) {
			$ids = $wpdb->get_col( $wpdb->prepare(
				"SELECT DISTINCT order_id FROM {$table} WHERE product_id = %d OR variation_id = %d",
				$product_id, $product_id
			) );
			if ( ! $ids ) return array();
			$args['post__in'] = array_map( 'intval', $ids );
			return wc_get_orders( $args );
		}

		// ponytail: fallback when the analytics lookup table is absent — scan 1000 recent orders in PHP.
		$orders = wc_get_orders( $args );
		return array_filter( $orders, function ( $o ) use ( $product_id ) {
			foreach ( $o->get_items() as $item ) {
				if ( (int) $item->get_product_id() === $product_id || (int) $item->get_variation_id() === $product_id ) return true;
			}
			return false;
		} );
	}

	return wc_get_orders( $args );
}

// One entry per line item: [ product name, variant label, quantity ].
function show_orders_items( $o ) {
	$out = array();
	foreach ( $o->get_items() as $item ) {
		$product = $item->get_product();
		$variant = '';
		if ( $product && $product->is_type( 'variation' ) ) {
			// Returns slugs, URL-encoded for non-Latin (e.g. "%d7%9e%d7%91..."). urldecode → "מבוגר".
			$vals = array_map( 'urldecode', array_filter( $product->get_variation_attributes() ) );
			$variant = implode( ' / ', $vals );
		}
		$out[] = array(
			$product ? $product->get_title() : $item->get_name(), // base product, no variant suffix
			$variant,
			$item->get_quantity(),
		);
	}
	return $out;
}

// One row per line item. Order/Date/Customer/Status/Total repeat only on the first
// row of each order (blank on the rest) so totals aren't double-counted.
function show_orders_rows( $orders ) {
	$rows = array();
	foreach ( $orders as $o ) {
		$items = show_orders_items( $o );
		if ( ! $items ) $items = array( array( '', '', '' ) ); // order with no line items
		$first = true;
		foreach ( $items as $it ) {
			$rows[] = array(
				$first ? '#' . $o->get_order_number() : '',
				$first ? ( $o->get_date_created() ? $o->get_date_created()->date( 'Y-m-d H:i' ) : '' ) : '',
				$first ? ( trim( $o->get_formatted_billing_full_name() ) ?: $o->get_billing_email() ) : '',
				$it[0], // product
				$it[1], // variant
				$it[2], // quantity
				$first ? wc_get_order_status_name( $o->get_status() ) : '',
				$first ? $o->get_total() : '',
			);
			$first = false;
		}
	}
	return $rows;
}

// CSV export — must run before any HTML so we can send headers.
// Posted from the table form; only the checked (included) orders are exported.
add_action( 'admin_init', function () {
	if ( empty( $_POST['show_orders_csv'] ) ) return;
	if ( ! current_user_can( 'edit_shop_orders' ) ) wp_die( 'Not authorized.', 403 );
	check_admin_referer( 'show_orders_csv' );

	$include = isset( $_POST['include'] ) ? array_map( 'intval', (array) $_POST['include'] ) : array();
	if ( ! $include ) wp_die( 'No orders selected for export.' );

	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename=orders.csv' );
	$out = fopen( 'php://output', 'w' );
	fwrite( $out, "\xEF\xBB\xBF" ); // UTF-8 BOM so Excel reads Hebrew correctly
	fputcsv( $out, array( 'Order', 'Date', 'Customer', 'Product', 'Variant', 'Quantity', 'Status', 'Total' ) );
	$sum_total = 0;
	$sum_variant = array();
	foreach ( $include as $id ) {
		$o = wc_get_order( $id );
		if ( ! $o ) continue;
		$sum_total += (float) $o->get_total();
		foreach ( show_orders_items( $o ) as $it ) {
			$label = $it[1] !== '' ? $it[1] : '(no variant)';
			$sum_variant[ $label ] = ( $sum_variant[ $label ] ?? 0 ) + (int) $it[2];
		}
		foreach ( show_orders_rows( array( $o ) ) as $row ) fputcsv( $out, $row );
	}
	// Totals rows.
	fputcsv( $out, array() );
	foreach ( $sum_variant as $label => $qty ) fputcsv( $out, array( 'Total', '', '', '', $label, $qty, '', '' ) );
	fputcsv( $out, array( 'Total Paid', '', '', '', '', '', '', $sum_total ) );
	fclose( $out );
	exit;
} );

// Print / PDF export — renders checked orders as a standalone printable page
// and auto-opens the browser print dialog ("Save as PDF"). No PDF library needed.
add_action( 'admin_init', function () {
	if ( empty( $_POST['show_orders_pdf'] ) ) return;
	if ( ! current_user_can( 'edit_shop_orders' ) ) wp_die( 'Not authorized.', 403 );
	check_admin_referer( 'show_orders_csv' ); // same form, same nonce

	$include = isset( $_POST['include'] ) ? array_map( 'intval', (array) $_POST['include'] ) : array();
	if ( ! $include ) wp_die( 'No orders selected for export.' );

	$headers   = array( 'Order', 'Date', 'Customer', 'Product', 'Variant', 'Quantity', 'Status', 'Total' );
	$sum_total = 0;
	$sum_variant = array();
	$body = '';
	foreach ( $include as $id ) {
		$o = wc_get_order( $id );
		if ( ! $o ) continue;
		$sum_total += (float) $o->get_total();
		foreach ( show_orders_items( $o ) as $it ) {
			$label = $it[1] !== '' ? $it[1] : '(no variant)';
			$sum_variant[ $label ] = ( $sum_variant[ $label ] ?? 0 ) + (int) $it[2];
		}
		foreach ( show_orders_rows( array( $o ) ) as $row ) {
			$body .= '<tr>';
			foreach ( $row as $cell ) $body .= '<td>' . esc_html( $cell ) . '</td>';
			$body .= '</tr>';
		}
	}
	foreach ( $sum_variant as $label => $qty ) {
		$body .= '<tr class="total"><td>Total</td><td></td><td></td><td></td><td>' . esc_html( $label ) . '</td><td>' . esc_html( $qty ) . '</td><td></td><td></td></tr>';
	}
	$body .= '<tr class="total"><td>Total Paid</td><td></td><td></td><td></td><td></td><td></td><td></td><td>' . esc_html( $sum_total ) . '</td></tr>';

	$head = '';
	foreach ( $headers as $h ) $head .= '<th>' . esc_html( $h ) . '</th>';

	// Standalone HTML doc (not the admin chrome) so print output is clean. Auto-print on load.
	header( 'Content-Type: text/html; charset=utf-8' );
	echo '<!doctype html><html dir="rtl"><head><meta charset="utf-8"><title>Orders</title><style>'
		. 'body{font-family:sans-serif;font-size:12px}'
		. 'table{border-collapse:collapse;width:100%}'
		. 'th,td{border:1px solid #999;padding:4px 6px;text-align:right}'
		. 'thead{background:#eee}.total{font-weight:bold}'
		. '@media print{@page{size:landscape}}'
		. '</style></head><body onload="window.print()">'
		. '<table><thead><tr>' . $head . '</tr></thead><tbody>' . $body . '</tbody></table>'
		. '</body></html>';
	exit;
} );

function show_orders_page() {
	if ( ! current_user_can( 'edit_shop_orders' ) ) wp_die( 'Not authorized.', 403 );
	if ( ! function_exists( 'wc_get_orders' ) ) wp_die( 'WooCommerce not active.' );

	$product_id = isset( $_GET['product'] ) ? (int) $_GET['product'] : 0;
	$show       = ! empty( $_GET['show'] );

	echo '<div class="wrap"><h1>Show Orders</h1>';

	// Filter form: product dropdown + Show button.
	echo '<form method="get" style="margin-bottom:1em">';
	echo '<input type="hidden" name="page" value="show-orders"><input type="hidden" name="show" value="1">';
	echo '<select name="product">';
	echo '<option value="0">— All products —</option>';
	$products = wc_get_products( array( 'limit' => -1, 'orderby' => 'date', 'order' => 'DESC', 'status' => array( 'publish', 'private', 'draft', 'pending' ) ) );
	foreach ( $products as $p ) {
		printf(
			'<option value="%d"%s>%s</option>',
			$p->get_id(),
			selected( $product_id, $p->get_id(), false ),
			esc_html( $p->get_name() )
		);
	}
	echo '</select> ';
	echo '<button type="submit" class="button button-primary">Show Orders</button>';
	echo '</form>';

	if ( $show ) {
		$orders = show_orders_query( $product_id );

		// Table is a form: checked rows get exported. Uncheck to exclude from the CSV.
		echo '<form method="post">';
		wp_nonce_field( 'show_orders_csv' );
		echo '<p><button type="submit" name="show_orders_csv" value="1" class="button">Download CSV (checked rows)</button> ';
		echo '<button type="submit" name="show_orders_pdf" value="1" formtarget="_blank" class="button">Print / PDF (checked rows)</button></p>';

		echo '<table class="wp-list-table widefat striped"><thead><tr>';
		echo '<th><input type="checkbox" checked onclick="this.closest(\'table\').querySelectorAll(\'tbody input[type=checkbox]\').forEach(c=>c.checked=this.checked)"></th>';
		foreach ( array( 'Order', 'Date', 'Customer', 'Product', 'Variant', 'Quantity', 'Status', 'Total' ) as $h ) echo '<th>' . esc_html( $h ) . '</th>';
		echo '</tr></thead><tbody>';
		$sum_total   = 0;
		$sum_variant = array(); // variant label => total quantity
		foreach ( $orders as $o ) {
			$items = show_orders_items( $o );
			if ( ! $items ) $items = array( array( '', '', '' ) );
			$span  = count( $items );
			$sum_total += (float) $o->get_total();
			$first = true;
			foreach ( $items as $it ) {
				$label = $it[1] !== '' ? $it[1] : '(no variant)';
				if ( $it[2] !== '' ) $sum_variant[ $label ] = ( $sum_variant[ $label ] ?? 0 ) + (int) $it[2];
				echo '<tr>';
				if ( $first ) {
					printf(
						'<td rowspan="%1$d"><input type="checkbox" name="include[]" value="%2$d" checked></td>'
						. '<td rowspan="%1$d">#%3$s</td><td rowspan="%1$d">%4$s</td><td rowspan="%1$d">%5$s</td>',
						$span,
						$o->get_id(),
						esc_html( $o->get_order_number() ),
						esc_html( $o->get_date_created() ? $o->get_date_created()->date( 'Y-m-d H:i' ) : '' ),
						esc_html( trim( $o->get_formatted_billing_full_name() ) ?: $o->get_billing_email() )
					);
				}
				printf( '<td>%s</td><td>%s</td><td>%s</td>', esc_html( $it[0] ), esc_html( $it[1] ), esc_html( $it[2] ) );
				if ( $first ) {
					printf(
						'<td rowspan="%1$d">%2$s</td><td rowspan="%1$d">%3$s</td>',
						$span,
						esc_html( wc_get_order_status_name( $o->get_status() ) ),
						wp_kses_post( $o->get_formatted_order_total() )
					);
				}
				echo '</tr>';
				$first = false;
			}
		}
		if ( ! $orders ) echo '<tr><td colspan="9">No orders.</td></tr>';
		echo '</tbody>';

		if ( $orders ) {
			$variant_lines = array();
			foreach ( $sum_variant as $label => $qty ) $variant_lines[] = esc_html( $label . ': ' . $qty );
			echo '<tfoot><tr style="font-weight:bold">';
			echo '<td colspan="5">Totals</td>';
			echo '<td>' . implode( '<br>', $variant_lines ) . '</td>';
			echo '<td></td>';
			echo '<td>' . wp_kses_post( wc_price( $sum_total ) ) . '</td>';
			echo '</tr></tfoot>';
		}
		echo '</table></form>';
	}

	echo '</div>';
}
