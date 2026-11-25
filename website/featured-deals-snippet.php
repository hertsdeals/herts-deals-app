<?php
/**
 * Herts Deals app: featured deals  (Tools > App featured deals)
 *
 * Dan picks which deals appear first on the app's home screen, in order, each with an
 * optional end date (for paid slots). Expired or unpublished deals drop off automatically.
 * The list is added to the app feed (/hd/v1/app) as "featured": [{id, until}].
 * When a town is selected in the app, featured deals in that town are shown first.
 * Copy of this snippet lives in the app repo: website/featured-deals-snippet.php
 */

if ( ! function_exists( 'hd_app_featured_list' ) ) {
	// Live list: published deals whose end date (if any) hasn't passed, in Dan's order.
	function hd_app_featured_list() {
		$rows  = get_option( 'hd_app_featured', array() );
		$today = current_time( 'Y-m-d' );
		$out   = array();
		foreach ( (array) $rows as $r ) {
			$id    = isset( $r['id'] ) ? (int) $r['id'] : 0;
			$until = isset( $r['until'] ) ? (string) $r['until'] : '';
			if ( ! $id || 'publish' !== get_post_status( $id ) || 'deal' !== get_post_type( $id ) ) { continue; }
			if ( '' !== $until && $until < $today ) { continue; }
			$out[] = array( 'id' => $id, 'until' => $until );
		}
		return $out;
	}
}

add_filter( 'rest_post_dispatch', function ( $res, $server, $req ) {
	if ( '/hd/v1/app' !== $req->get_route() || ! ( $res instanceof WP_REST_Response ) ) { return $res; }
	$data = $res->get_data();
	if ( is_array( $data ) ) {
		$data['featured'] = hd_app_featured_list();
		$res->set_data( $data );
	}
	return $res;
}, 11, 3 );

add_action( 'admin_menu', function () {
	add_management_page( 'App featured deals', 'App featured deals', 'manage_options', 'hd-app-featured', 'hd_app_featured_page' );
} );

function hd_app_featured_page() {
	if ( ! current_user_can( 'manage_options' ) ) { return; }
	$saved = false;
	if ( isset( $_POST['hd_feat_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['hd_feat_nonce'] ) ), 'hd_feat_save' ) ) {
		$ids    = isset( $_POST['hd_feat_id'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['hd_feat_id'] ) ) : array();
		$untils = isset( $_POST['hd_feat_until'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['hd_feat_until'] ) ) : array();
		$rows   = array(); $seen = array();
		foreach ( $ids as $i => $id ) {
			if ( ! $id || isset( $seen[ $id ] ) ) { continue; }
			$u = isset( $untils[ $i ] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $untils[ $i ] ) ? $untils[ $i ] : '';
			$rows[] = array( 'id' => $id, 'until' => $u );
			$seen[ $id ] = 1;
		}
		update_option( 'hd_app_featured', $rows, false );
		$saved = true;
	}

	$rows  = (array) get_option( 'hd_app_featured', array() );
	$deals = get_posts( array( 'post_type' => 'deal', 'post_status' => 'publish', 'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC' ) );
	$opts  = array();
	foreach ( $deals as $d ) {
		$co = get_post_meta( $d->ID, 'hd_company_name', true );
		$t  = get_post_meta( $d->ID, 'deal_title', true );
		$opts[ $d->ID ] = trim( ( $co ? $co . ': ' : '' ) . html_entity_decode( $t ? $t : get_the_title( $d ) ) );
	}
	asort( $opts, SORT_NATURAL | SORT_FLAG_CASE );
	$today = current_time( 'Y-m-d' );

	echo '<div class="wrap"><h1>App featured deals</h1>';
	if ( $saved ) { echo '<div class="notice notice-success"><p>Saved. The app shows the new order the next time it is opened.</p></div>'; }
	echo '<p>These deals appear first on the app\'s home screen, in this order, marked "Featured". If someone has picked a town, featured deals in that town come first.<br>Leave the end date empty to keep a deal featured until you remove it. Deals drop off automatically after their end date, or if the deal is taken down.</p>';
	echo '<form method="post">';
	wp_nonce_field( 'hd_feat_save', 'hd_feat_nonce' );
	echo '<table class="widefat striped" style="max-width:900px"><thead><tr><th style="width:40px">#</th><th>Deal</th><th style="width:170px">Featured until (optional)</th><th style="width:110px">Status</th></tr></thead><tbody>';
	$n = max( 8, count( $rows ) + 2 );
	for ( $i = 0; $i < $n; $i++ ) {
		$cur   = isset( $rows[ $i ]['id'] ) ? (int) $rows[ $i ]['id'] : 0;
		$until = isset( $rows[ $i ]['until'] ) ? $rows[ $i ]['until'] : '';
		echo '<tr><td>' . ( $i + 1 ) . '</td><td><select name="hd_feat_id[]" style="width:100%"><option value="0">(none)</option>';
		foreach ( $opts as $id => $label ) {
			echo '<option value="' . (int) $id . '"' . selected( $cur, $id, false ) . '>' . esc_html( $label ) . '</option>';
		}
		if ( $cur && ! isset( $opts[ $cur ] ) ) { echo '<option value="' . $cur . '" selected>(deal ' . $cur . ' is no longer live)</option>'; }
		echo '</select></td><td><input type="date" name="hd_feat_until[]" value="' . esc_attr( $until ) . '"></td><td>';
		if ( ! $cur ) { echo '—'; }
		elseif ( ! isset( $opts[ $cur ] ) ) { echo '<span style="color:#b32d2e">Not live</span>'; }
		elseif ( '' !== $until && $until < $today ) { echo '<span style="color:#b32d2e">Ended</span>'; }
		else { echo '<span style="color:#008a20">Showing</span>'; }
		echo '</td></tr>';
	}
	echo '</tbody></table><p class="description">To change the order, choose the deals again in the order you want. Save to add more empty rows.</p>';
	submit_button( 'Save featured deals' );
	echo '</form></div>';
}
