<?php
/**
 * Herts Deals app: push notifications when a new deal goes live
 *
 * How it works
 * - When a deal is published (straight away or on its scheduled date), it is added to a small queue.
 * - About two minutes later, a background job (WP-Cron) sends one notification per deal through
 *   Firebase Cloud Messaging to phones following the deal's towns ("town_<id>") or its business ("biz_<id>").
 *   Phones choose their topics in the app; the website never sees who they are.
 * - Quiet hours: nothing is sent before 9am or after 8pm (UK time). Deals that go live overnight are sent at 9am.
 * - Busy days: if 3 or more deals go out for one town at the same time, that town gets one summary instead.
 * - Each deal is only ever sent once.
 * - Nothing happens on page views, so it cannot slow the site down.
 *
 * Setup: Tools > App notifications > upload the Firebase service account key (.json).
 * It is stored in the database (not autoloaded, never shown again). Alternatively define
 * HD_FCM_SERVICE_ACCOUNT in wp-config.php as a file path or the JSON itself.
 * Until a key is installed, this snippet does nothing.
 *
 * Status and recent sends: Tools > App notifications.
 */

if ( ! defined( 'ABSPATH' ) ) { return; }

defined( 'HD_PUSH_QUIET_FROM' ) || define( 'HD_PUSH_QUIET_FROM', 20 ); // no sends from 8pm…
defined( 'HD_PUSH_QUIET_TO' ) || define( 'HD_PUSH_QUIET_TO', 9 );      // …until 9am
defined( 'HD_PUSH_SUMMARY_AT' ) || define( 'HD_PUSH_SUMMARY_AT', 3 );  // deals for one town in one send that become a summary

/* ---------- Firebase key ---------- */

function hd_push_account( $reset = false ) {
	static $acc = null;
	if ( null !== $acc && ! $reset ) { return $acc; }
	$acc = false;
	if ( defined( 'HD_FCM_SERVICE_ACCOUNT' ) && HD_FCM_SERVICE_ACCOUNT ) {
		$raw = HD_FCM_SERVICE_ACCOUNT;
		if ( '{' !== substr( ltrim( $raw ), 0, 1 ) ) {
			$raw = is_readable( $raw ) ? file_get_contents( $raw ) : '';
		}
	} else {
		$raw = get_option( 'hd_fcm_service_account', '' );
	}
	$j = json_decode( (string) $raw, true );
	if ( is_array( $j ) && ! empty( $j['client_email'] ) && ! empty( $j['private_key'] ) && ! empty( $j['project_id'] ) ) {
		$acc = $j;
	}
	return $acc;
}

function hd_push_b64( $s ) { return rtrim( strtr( base64_encode( $s ), '+/', '-_' ), '=' ); }

/* Short-lived Google access token, cached for 50 minutes. */
function hd_push_token() {
	$t = get_transient( 'hd_push_token' );
	if ( $t ) { return $t; }
	$acc = hd_push_account();
	if ( ! $acc ) { return new WP_Error( 'hd_push', 'Firebase key not installed' ); }
	$now    = time();
	$head   = hd_push_b64( wp_json_encode( array( 'alg' => 'RS256', 'typ' => 'JWT' ) ) );
	$claims = hd_push_b64( wp_json_encode( array(
		'iss'   => $acc['client_email'],
		'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
		'aud'   => 'https://oauth2.googleapis.com/token',
		'iat'   => $now,
		'exp'   => $now + 3600,
	) ) );
	$sig = '';
	if ( ! openssl_sign( $head . '.' . $claims, $sig, $acc['private_key'], 'sha256WithRSAEncryption' ) ) {
		return new WP_Error( 'hd_push', 'Could not sign with the Firebase key' );
	}
	$res = wp_remote_post( 'https://oauth2.googleapis.com/token', array(
		'timeout' => 15,
		'body'    => array(
			'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
			'assertion'  => $head . '.' . $claims . '.' . hd_push_b64( $sig ),
		),
	) );
	if ( is_wp_error( $res ) ) { return $res; }
	$j = json_decode( wp_remote_retrieve_body( $res ), true );
	if ( empty( $j['access_token'] ) ) {
		return new WP_Error( 'hd_push', 'Google refused the Firebase key: ' . substr( wp_remote_retrieve_body( $res ), 0, 200 ) );
	}
	set_transient( 'hd_push_token', $j['access_token'], 50 * MINUTE_IN_SECONDS );
	return $j['access_token'];
}

/* Send one message. $target is array( 'condition' => "..." ) or array( 'topic' => '...' ). */
function hd_push_send( $target, $title, $body, $data, $image = '' ) {
	$acc   = hd_push_account();
	$token = hd_push_token();
	if ( is_wp_error( $token ) ) { return $token; }
	$data = array_map( 'strval', $data );
	$msg  = array_merge( $target, array(
		'notification' => array( 'title' => $title, 'body' => $body ),
		'data'         => $data,
		'android'      => array( 'priority' => 'normal', 'notification' => array_filter( array( 'channel_id' => 'deals', 'image' => $image ) ) ),
		'apns'         => array( 'payload' => array( 'aps' => array( 'sound' => 'default' ) ) ),
	) );
	$res = wp_remote_post( 'https://fcm.googleapis.com/v1/projects/' . rawurlencode( $acc['project_id'] ) . '/messages:send', array(
		'timeout' => 15,
		'headers' => array( 'Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json' ),
		'body'    => wp_json_encode( array( 'message' => $msg ) ),
	) );
	if ( is_wp_error( $res ) ) { return $res; }
	$code = wp_remote_retrieve_response_code( $res );
	if ( 200 !== (int) $code ) {
		if ( 401 === (int) $code ) { delete_transient( 'hd_push_token' ); }
		return new WP_Error( 'hd_push', 'Firebase error ' . $code . ': ' . substr( wp_remote_retrieve_body( $res ), 0, 200 ) );
	}
	return true;
}

function hd_push_log( $line ) {
	$log = get_option( 'hd_push_log', array() );
	array_unshift( $log, wp_date( 'j M H:i' ) . '  ' . $line );
	update_option( 'hd_push_log', array_slice( $log, 0, 60 ), false );
}

/* ---------- Queue ---------- */

add_action( 'transition_post_status', function ( $new, $old, $post ) {
	if ( 'deal' !== $post->post_type || 'publish' !== $new || 'publish' === $old ) { return; }
	if ( ! hd_push_account() || get_post_meta( $post->ID, '_hd_push_sent', true ) ) { return; }
	$q = get_option( 'hd_push_queue', array() );
	$q[ $post->ID ] = time();
	update_option( 'hd_push_queue', $q, false );
	if ( ! wp_next_scheduled( 'hd_push_run' ) ) {
		// A short delay lets the deal's details, towns and business finish saving first.
		wp_schedule_single_event( time() + 120, 'hd_push_run' );
	}
}, 10, 3 );

/* Seconds until sending is allowed (0 = now). */
function hd_push_wait() {
	$now = new DateTime( 'now', wp_timezone() );
	$h   = (int) $now->format( 'G' );
	if ( $h >= HD_PUSH_QUIET_TO && $h < HD_PUSH_QUIET_FROM ) { return 0; }
	$next = clone $now;
	if ( $h >= HD_PUSH_QUIET_FROM ) { $next->modify( '+1 day' ); }
	$next->setTime( HD_PUSH_QUIET_TO, 0, 0 );
	return max( 60, $next->getTimestamp() - $now->getTimestamp() );
}

function hd_push_q( $id ) { return "'" . preg_replace( '/[^a-zA-Z0-9_.~%-]/', '', $id ) . "' in topics"; }

add_action( 'hd_push_run', function () {
	$q = get_option( 'hd_push_queue', array() );
	if ( ! $q || ! hd_push_account() ) { return; }
	$wait = hd_push_wait();
	if ( $wait ) { wp_schedule_single_event( time() + $wait, 'hd_push_run' ); return; }
	update_option( 'hd_push_queue', array(), false );

	// Gather the deals that are still live, with their towns and business.
	$items = array();
	foreach ( array_keys( $q ) as $id ) {
		$d = get_post( $id );
		if ( ! $d || 'publish' !== $d->post_status || get_post_meta( $id, '_hd_push_sent', true ) ) { continue; }
		$lc  = get_post_meta( $id, 'linked_company', true );
		$cid = (int) ( is_array( $lc ) ? reset( $lc ) : $lc );
		if ( ! $cid || 'publish' !== get_post_status( $cid ) ) { hd_push_log( "Skipped deal $id (no live business linked)" ); continue; }
		$towns = wp_get_post_terms( $id, 'location', array( 'fields' => 'all' ) );
		$towns = is_wp_error( $towns ) ? array() : array_values( array_filter( $towns, function ( $t ) { return (int) $t->parent > 0; } ) );
		$img   = get_post_meta( $id, 'deal_image', true );
		$img   = is_array( $img ) ? ( isset( $img['ID'] ) ? $img['ID'] : 0 ) : $img;
		$items[] = array(
			'id'    => $id,
			'title' => html_entity_decode( get_post_meta( $id, 'deal_title', true ) ?: get_the_title( $d ), ENT_QUOTES ),
			'biz'   => $cid,
			'name'  => html_entity_decode( get_the_title( $cid ), ENT_QUOTES ),
			'towns' => $towns,
			'image' => ( $img ? wp_get_attachment_image_url( (int) $img, 'medium_large' ) : '' ) ?: (string) get_the_post_thumbnail_url( $id, 'medium_large' ),
		);
		update_post_meta( $id, '_hd_push_sent', time() ); // never send the same deal twice
	}
	if ( ! $items ) { return; }

	// Busy towns get one summary instead of a notification per deal.
	$per_town = array();
	foreach ( $items as $it ) { foreach ( $it['towns'] as $t ) { $per_town[ $t->term_id ][] = $it; } }
	$busy = array();
	foreach ( $per_town as $tid => $list ) {
		if ( count( $list ) < HD_PUSH_SUMMARY_AT ) { continue; }
		$busy[ $tid ] = true;
		$t     = get_term( $tid, 'location' );
		$names = array_slice( array_unique( wp_list_pluck( $list, 'name' ) ), 0, 3 );
		$r     = hd_push_send(
			array( 'topic' => 'town_' . $tid ),
			count( $list ) . ' new deals in ' . $t->name,
			'Including ' . implode( ', ', $names ) . '. Tap to see them.',
			array( 'town' => $tid )
		);
		hd_push_log( ( true === $r ? 'Sent' : 'FAILED' ) . ': summary of ' . count( $list ) . ' deals to ' . $t->name . ( true === $r ? '' : ' (' . $r->get_error_message() . ')' ) );
	}

	// One notification per deal, to its business followers and its (non-busy) towns.
	// A Firebase "condition" can name up to 5 topics, and each phone gets the message once.
	foreach ( $items as $it ) {
		$normal = array_values( array_filter( $it['towns'], function ( $t ) use ( $busy ) { return empty( $busy[ $t->term_id ] ); } ) );
		$place  = $it['towns'] ? $it['towns'][0]->name : '';
		$chunks = $normal ? array_chunk( $normal, 4 ) : array( array() );
		foreach ( $chunks as $i => $ts ) {
			$parts = array_map( function ( $t ) { return hd_push_q( 'town_' . $t->term_id ); }, $ts );
			if ( 0 === $i ) { array_unshift( $parts, hd_push_q( 'biz_' . $it['biz'] ) ); }
			if ( ! $parts ) { continue; }
			$r = hd_push_send(
				array( 'condition' => implode( ' || ', $parts ) ),
				$place ? 'New in ' . $place . ': ' . $it['name'] : 'New deal from ' . $it['name'],
				$it['title'],
				array( 'deal' => $it['id'], 'biz' => $it['biz'] ),
				$it['image']
			);
			hd_push_log( ( true === $r ? 'Sent' : 'FAILED' ) . ': ' . $it['name'] . ' – ' . $it['title'] . ( true === $r ? '' : ' (' . $r->get_error_message() . ')' ) );
		}
	}
} );

/* ---------- Tools > App notifications ---------- */

/* Upload or remove the Firebase key (admins only). */
add_action( 'admin_post_hd_push_key', function () {
	if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Not allowed' ); }
	check_admin_referer( 'hd_push_key' );
	$back = admin_url( 'tools.php?page=hd-app-notifications' );
	if ( ! empty( $_POST['remove'] ) ) {
		delete_option( 'hd_fcm_service_account' );
		delete_transient( 'hd_push_token' );
		hd_push_log( 'Firebase key removed' );
		wp_safe_redirect( add_query_arg( 'hd_msg', 'removed', $back ) ); exit;
	}
	$f   = isset( $_FILES['hd_key'] ) ? $_FILES['hd_key'] : null;
	$raw = ( $f && empty( $f['error'] ) && $f['size'] > 0 && $f['size'] < 20000 && is_uploaded_file( $f['tmp_name'] ) ) ? file_get_contents( $f['tmp_name'] ) : '';
	$j   = json_decode( (string) $raw, true );
	if ( ! is_array( $j ) || ( $j['type'] ?? '' ) !== 'service_account' || empty( $j['private_key'] ) || empty( $j['client_email'] ) || empty( $j['project_id'] ) ) {
		wp_safe_redirect( add_query_arg( 'hd_msg', 'bad', $back ) ); exit;
	}
	$keep = array_intersect_key( $j, array_flip( array( 'type', 'project_id', 'private_key_id', 'private_key', 'client_email', 'client_id', 'token_uri' ) ) );
	update_option( 'hd_fcm_service_account', wp_json_encode( $keep ), false );
	delete_transient( 'hd_push_token' );
	$test = hd_push_token_fresh();
	hd_push_log( is_wp_error( $test ) ? 'Firebase key saved but Google refused it: ' . $test->get_error_message() : 'Firebase key installed and checked with Google' );
	wp_safe_redirect( add_query_arg( 'hd_msg', is_wp_error( $test ) ? 'refused' : 'ok', $back ) ); exit;
} );

function hd_push_token_fresh() {
	hd_push_account( true );
	delete_transient( 'hd_push_token' );
	return hd_push_token();
}

add_action( 'admin_menu', function () {
	add_management_page( 'App notifications', 'App notifications', 'manage_options', 'hd-app-notifications', function () {
		$acc  = hd_push_account();
		$q    = get_option( 'hd_push_queue', array() );
		$next = wp_next_scheduled( 'hd_push_run' );
		$log  = get_option( 'hd_push_log', array() );
		$msgs = array( 'ok' => 'Key installed and checked with Google. Notifications are on.', 'bad' => 'That file is not a Firebase service account key (.json). Nothing was saved.', 'refused' => 'The key was saved, but Google refused it. See Recent activity.', 'removed' => 'Key removed. Notifications are off.' );
		$m    = isset( $_GET['hd_msg'] ) ? sanitize_key( $_GET['hd_msg'] ) : '';
		echo '<div class="wrap"><h1>App notifications</h1>';
		if ( isset( $msgs[ $m ] ) ) { echo '<div class="notice notice-' . ( 'ok' === $m || 'removed' === $m ? 'success' : 'error' ) . '"><p>' . esc_html( $msgs[ $m ] ) . '</p></div>'; }
		echo '<p><strong>Firebase key:</strong> ' . ( $acc ? 'installed (project ' . esc_html( $acc['project_id'] ) . ')' : 'not installed yet. Notifications are switched off until you upload it below.' ) . '</p>';
		if ( ! defined( 'HD_FCM_SERVICE_ACCOUNT' ) ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" enctype="multipart/form-data" style="background:#fff;border:1px solid #ddd;padding:12px 16px;max-width:640px">';
			wp_nonce_field( 'hd_push_key' );
			echo '<input type="hidden" name="action" value="hd_push_key">';
			if ( $acc ) {
				echo '<p>The key is stored privately in the database and is never shown again.</p><p><button class="button" name="remove" value="1" onclick="return confirm(\'Remove the Firebase key? Notifications will stop.\')">Remove key</button></p>';
			} else {
				echo '<p><strong>Upload the Firebase key</strong> (Firebase console &gt; Project settings &gt; Service accounts &gt; Generate new private key). After uploading, delete the downloaded file from your computer.</p>';
				echo '<p><input type="file" name="hd_key" accept=".json,application/json" required> <button class="button button-primary">Upload key</button></p>';
			}
			echo '</form>';
		}
		echo '<p><strong>Waiting to send:</strong> ' . count( $q ) . ( $next ? ' (next send ' . esc_html( wp_date( 'j M H:i', $next ) ) . ')' : '' ) . '</p>';
		echo '<p>New deals are sent automatically when they go live, between 9am and 8pm. To send your own message (for example to everyone, or to one town), use the Firebase console: Messaging, then "New campaign", and choose the topic <code>all</code>, <code>town_ID</code> or <code>cat_ID</code>.</p>';
		$tw = get_terms( array( 'taxonomy' => 'location', 'hide_empty' => false ) );
		$ct = get_terms( array( 'taxonomy' => 'deal_category', 'hide_empty' => false, 'parent' => 0 ) );
		$tw = is_wp_error( $tw ) ? array() : array_filter( $tw, function ( $t ) { return (int) $t->parent > 0; } );
		echo '<details><summary>Topic names for your own messages</summary><p>' . implode( ', ', array_map( function ( $t ) { return esc_html( $t->name ) . ' <code>town_' . (int) $t->term_id . '</code>'; }, $tw ) ) . '</p>';
		echo '<p>' . ( is_wp_error( $ct ) ? '' : implode( ', ', array_map( function ( $t ) { return esc_html( $t->name ) . ' <code>cat_' . (int) $t->term_id . '</code>'; }, $ct ) ) ) . '</p></details>';
		echo '<h2>Recent activity</h2>';
		echo $log ? '<pre style="background:#fff;padding:12px;border:1px solid #ddd;white-space:pre-wrap">' . esc_html( implode( PHP_EOL, $log ) ) . '</pre>' : '<p>Nothing sent yet.</p>';
		echo '</div>';
	} );
} );
