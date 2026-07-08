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

// Column headers, shared by table/CSV/PDF. ילד/מבוגר hold summed quantities.
const SHOW_ORDERS_VARIANTS = array( 'ילד', 'מבוגר' );
function show_orders_headers() {
	return array_merge(
		array( 'הזמנה', 'תאריך', 'לקוח', 'מוצר' ),
		SHOW_ORDERS_VARIANTS,
		array( 'סטטוס', 'סה"כ' )
	);
}

// Sum an order's line-item quantities into the two variant buckets by matching
// the variant label against each bucket name. ponytail: substring match — a label
// "מבוגר / כחול" still counts as מבוגר. Returns [ 'ילד' => n, 'מבוגר' => n ].
function show_orders_variant_qty( $o ) {
	$q = array_fill_keys( SHOW_ORDERS_VARIANTS, 0 );
	foreach ( show_orders_items( $o ) as $it ) {
		foreach ( SHOW_ORDERS_VARIANTS as $v ) {
			if ( $it[1] !== '' && mb_strpos( $it[1], $v ) !== false ) $q[ $v ] += (int) $it[2];
		}
	}
	return $q;
}

// One row per order. Products joined into one cell; variant quantities summed
// into their own columns.
function show_orders_rows( $orders ) {
	$rows = array();
	foreach ( $orders as $o ) {
		$items    = show_orders_items( $o );
		$products = array();
		foreach ( $items as $it ) if ( $it[0] !== '' ) $products[ $it[0] ] = true;
		$q = show_orders_variant_qty( $o );
		$rows[] = array_merge(
			array(
				'#' . $o->get_order_number(),
				$o->get_date_created() ? $o->get_date_created()->date( 'Y-m-d H:i' ) : '',
				trim( $o->get_formatted_billing_full_name() ) ?: $o->get_billing_email(),
				implode( ', ', array_keys( $products ) ),
			),
			array_map( function ( $v ) use ( $q ) { return $q[ $v ] ?: ''; }, SHOW_ORDERS_VARIANTS ),
			array(
				wc_get_order_status_name( $o->get_status() ),
				$o->get_total(),
			)
		);
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
	fputcsv( $out, show_orders_headers() );
	$sum_total = 0;
	$sum_variant = array_fill_keys( SHOW_ORDERS_VARIANTS, 0 );
	foreach ( $include as $id ) {
		$o = wc_get_order( $id );
		if ( ! $o ) continue;
		$sum_total += (float) $o->get_total();
		foreach ( show_orders_variant_qty( $o ) as $v => $qty ) $sum_variant[ $v ] += $qty;
		foreach ( show_orders_rows( array( $o ) ) as $row ) fputcsv( $out, $row );
	}
	// Totals row: variant sums under their columns (4,5), grand total under Total (7).
	fputcsv( $out, array() );
	$totals = array( 'Total', '', '', '' );
	foreach ( SHOW_ORDERS_VARIANTS as $v ) $totals[] = $sum_variant[ $v ];
	$totals[] = '';
	$totals[] = $sum_total;
	fputcsv( $out, $totals );
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

	$headers   = show_orders_headers();
	$sum_total = 0;
	$sum_variant = array_fill_keys( SHOW_ORDERS_VARIANTS, 0 );
	$body = '';
	foreach ( $include as $id ) {
		$o = wc_get_order( $id );
		if ( ! $o ) continue;
		$sum_total += (float) $o->get_total();
		foreach ( show_orders_variant_qty( $o ) as $v => $qty ) $sum_variant[ $v ] += $qty;
		foreach ( show_orders_rows( array( $o ) ) as $row ) {
			$body .= '<tr>';
			foreach ( $row as $cell ) $body .= '<td>' . esc_html( $cell ) . '</td>';
			$body .= '</tr>';
		}
	}
	// Totals row: 4 leading cells (הזמנה/תאריך/לקוח/מוצר), variant sums, blank status, grand total.
	$body .= '<tr class="total"><td>סה"כ</td><td></td><td></td><td></td>';
	foreach ( SHOW_ORDERS_VARIANTS as $v ) $body .= '<td>' . esc_html( $sum_variant[ $v ] ) . '</td>';
	$body .= '<td></td><td>' . esc_html( $sum_total ) . '</td></tr>';

	$head = '';
	foreach ( $headers as $h ) $head .= '<th>' . esc_html( $h ) . '</th>';

	// Standalone HTML doc (not the admin chrome) so print output is clean. Auto-print on load.
	header( 'Content-Type: text/html; charset=utf-8' );
	echo '<!doctype html><html dir="rtl"><head><meta charset="utf-8"><title>Orders</title><style>'
		. 'body{font-family:sans-serif;font-size:9px}'
		. 'table{border-collapse:collapse;width:100%;table-layout:fixed}'
		. 'th,td{border:1px solid #999;padding:1px 4px;text-align:right;line-height:1.15;word-wrap:break-word;overflow-wrap:break-word}'
		. 'td:nth-child(5),th:nth-child(5),td:nth-child(6),th:nth-child(6){text-align:center;width:7%}' // ילד/מבוגר
		. 'td:nth-child(1),th:nth-child(1),td:nth-child(7),th:nth-child(7),td:nth-child(8),th:nth-child(8){width:8%}' // הזמנה/סטטוס/סה"כ
		. 'thead{background:#eee}.total{font-weight:bold}'
		. '@media print{@page{size:portrait;margin:8mm}}'
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
		echo '<input type="hidden" name="product" value="' . esc_attr( $product_id ) . '">';
		echo '<p><button type="submit" name="show_orders_csv" value="1" class="button">Download CSV (checked rows)</button> ';
		echo '<button type="submit" name="show_orders_pdf" value="1" formtarget="_blank" class="button">Print / PDF (checked rows)</button></p>';

		$headers = show_orders_headers();
		echo '<table class="wp-list-table widefat striped"><thead><tr>';
		echo '<th><input type="checkbox" onclick="this.closest(\'table\').querySelectorAll(\'tbody input[type=checkbox]\').forEach(c=>c.checked=this.checked)"></th>';
		foreach ( $headers as $h ) echo '<th>' . esc_html( $h ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $orders as $o ) {
			$q = show_orders_variant_qty( $o );
			echo '<tr>';
			// Pre-check only orders "בטיפול" (WooCommerce 'processing'); leave others unchecked.
			$checked = $o->get_status() === 'processing' ? ' checked' : '';
			// data-* carries this order's numbers so the footer can re-sum checked rows in JS.
			printf(
				'<td><input type="checkbox" name="include[]" value="%d" data-total="%s" data-child="%d" data-adult="%d"%s></td>',
				$o->get_id(), esc_attr( (float) $o->get_total() ), $q['ילד'], $q['מבוגר'], $checked
			);
			// show_orders_rows returns exactly one row per order.
			$row = show_orders_rows( array( $o ) )[0];
			foreach ( $row as $cell ) echo '<td>' . esc_html( $cell ) . '</td>';
			echo '</tr>';
		}
		if ( ! $orders ) echo '<tr><td colspan="' . ( count( $headers ) + 1 ) . '">No orders.</td></tr>';
		echo '</tbody>';

		if ( $orders ) {
			echo '<tfoot><tr style="font-weight:bold">';
			echo '<td colspan="5">סה"כ (מסומנים)</td>'; // checkbox + Order/Date/Customer/Product
			echo '<td id="sum-child"></td><td id="sum-adult"></td>'; // ילד / מבוגר
			echo '<td></td>'; // Status
			echo '<td id="sum-total"></td>';
			echo '</tr></tfoot>';
		}
		echo '</table></form>';

		if ( $orders ) {
			// Live totals: sum data-* over checked boxes, recompute on any change.
			$currency = html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' );
			printf( '<script>(function(){
				var sym=%s;
				var tbl=document.currentScript.previousElementSibling.querySelector("table")||document.querySelector(".wp-list-table");
				function recompute(){
					var child=0,adult=0,total=0;
					tbl.querySelectorAll("tbody input[type=checkbox]:checked").forEach(function(c){
						child+=+c.dataset.child||0; adult+=+c.dataset.adult||0; total+=+c.dataset.total||0;
					});
					document.getElementById("sum-child").textContent=child||"";
					document.getElementById("sum-adult").textContent=adult||"";
					document.getElementById("sum-total").textContent=sym+total.toFixed(2);
				}
				tbl.addEventListener("change",function(e){ if(e.target.type==="checkbox") recompute(); });
				recompute();
			})();</script>', wp_json_encode( $currency ) );
		}
	}

	echo '</div>';
}
