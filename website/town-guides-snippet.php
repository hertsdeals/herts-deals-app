<?php
/**
 * Herts Deals app: town guides  GET /wp-json/hd/v1/towns
 *
 * Sends the app every town's full local guide (the same guide the website shows:
 * hd_town_guide_new, else hd_town_guide), its "at a glance" lines and its nearby towns.
 *  - Built once and kept in a cached copy. Rebuilt only when a town (or a deal, which
 *    changes which nearby towns have offers) is saved. Visitors to the website are not affected.
 *  - The main app feed (/hd/v1/app) carries towns_version, so the app downloads this only
 *    when something has changed, and keeps a copy on the phone.
 * Copy of this snippet lives in the app repo: website/town-guides-snippet.php
 */

if ( ! function_exists( 'hd_app_towns_build' ) ) {
	function hd_app_towns_build() {
		$out   = array();
		$terms = get_terms( array( 'taxonomy' => 'location', 'hide_empty' => false ) );
		if ( is_wp_error( $terms ) ) { return array( 'version' => '', 'towns' => array() ); }
		$coords  = function_exists( 'herts_tseo_coords' ) ? herts_tseo_coords() : array();
		$allowed = function_exists( 'herts_town_guide_allowed' ) ? herts_town_guide_allowed() : wp_kses_allowed_html( 'post' );

		foreach ( $terms as $t ) {
			if ( ! $t->parent ) { continue; }
			$guide = trim( (string) get_term_meta( $t->term_id, 'hd_town_guide_new', true ) );
			if ( '' === $guide ) { $guide = trim( (string) get_term_meta( $t->term_id, 'hd_town_guide', true ) ); }
			$gl = json_decode( (string) get_term_meta( $t->term_id, 'hd_town_glance', true ), true );

			// Nearby towns: same rule as the website (towns with deals first, up to 6; at least 4).
			$nearby = array();
			if ( $coords && isset( $coords[ $t->slug ] ) && function_exists( 'herts_tseo_miles' ) ) {
				$live = array(); $quiet = array();
				foreach ( $terms as $o ) {
					if ( $o->term_id === $t->term_id || ! $o->parent || ! isset( $coords[ $o->slug ] ) ) { continue; }
					$m = herts_tseo_miles( $coords[ $t->slug ], $coords[ $o->slug ] );
					if ( $m > 15 ) { continue; }
					if ( $o->count > 0 ) { $live[] = array( $m, $o->term_id ); } else { $quiet[] = array( $m, $o->term_id ); }
				}
				$sort = function ( $a, $b ) { return $a[0] <=> $b[0]; };
				usort( $live, $sort ); usort( $quiet, $sort );
				$pick = array_slice( $live, 0, 6 );
				if ( count( $pick ) < 4 ) { $pick = array_merge( $pick, array_slice( $quiet, 0, 4 - count( $pick ) ) ); usort( $pick, $sort ); }
				foreach ( $pick as $p ) { $nearby[] = array( 'id' => (int) $p[1], 'miles' => max( 1, (int) round( $p[0] ) ) ); }
			}

			$out[ $t->term_id ] = array(
				'glance' => is_array( $gl ) ? array_map( 'strval', array_intersect_key( $gl, array( 'm' => 1, 'p' => 1, 't' => 1 ) ) ) : (object) array(),
				'guide'  => '' === $guide ? '' : wp_kses( $guide, $allowed ),
				'nearby' => $nearby,
			);
		}
		return array( 'version' => substr( md5( wp_json_encode( $out ) ), 0, 12 ), 'generated' => current_time( 'c' ), 'towns' => $out );
	}

	// Cached copy. It has an expiry, so WordPress does not load it on every page of the site.
	function hd_app_towns_get() {
		$data = get_transient( 'hd_app_towns' );
		if ( false === $data ) {
			$data = hd_app_towns_build();
			set_transient( 'hd_app_towns', $data, 7 * DAY_IN_SECONDS );
			update_option( 'hd_app_towns_version', $data['version'], false );
		}
		return $data;
	}

	function hd_app_towns_clear() {
		delete_transient( 'hd_app_towns' );
		delete_option( 'hd_app_towns_version' );
	}
}

add_action( 'rest_api_init', function () {
	register_rest_route( 'hd/v1', '/towns', array(
		'methods'             => 'GET',
		'permission_callback' => '__return_true',
		'callback'            => function ( $req ) {
			if ( $req->get_param( 'refresh' ) && current_user_can( 'manage_options' ) ) { hd_app_towns_clear(); }
			$res = new WP_REST_Response( hd_app_towns_get() );
			$res->header( 'Cache-Control', 'public, max-age=600' );
			return $res;
		},
	) );
} );

// Add the town guides version to the main app feed, so the app knows when to download again.
add_filter( 'rest_post_dispatch', function ( $res, $server, $req ) {
	if ( '/hd/v1/app' !== $req->get_route() || ! ( $res instanceof WP_REST_Response ) ) { return $res; }
	$data = $res->get_data();
	if ( is_array( $data ) ) {
		$v = (string) get_option( 'hd_app_towns_version', '' );
		if ( '' === $v ) { $t = hd_app_towns_get(); $v = $t['version']; }
		$data['towns_version'] = $v;
		$res->set_data( $data );
	}
	return $res;
}, 10, 3 );

// Rebuild when a town is edited or added, or a deal changes (nearby towns favour towns with offers).
add_action( 'edited_location', 'hd_app_towns_clear' );
add_action( 'created_location', 'hd_app_towns_clear' );
add_action( 'delete_location', 'hd_app_towns_clear' );
add_action( 'save_post_deal', 'hd_app_towns_clear' );
// Guides saved through the admin-only REST helper (snippet 166) don't fire edited_location.
add_action( 'updated_term_meta', function ( $mid, $term_id, $key ) {
	if ( in_array( $key, array( 'hd_town_guide', 'hd_town_guide_new', 'hd_town_glance' ), true ) ) { hd_app_towns_clear(); }
}, 10, 3 );
add_action( 'added_term_meta', function ( $mid, $term_id, $key ) {
	if ( in_array( $key, array( 'hd_town_guide', 'hd_town_guide_new', 'hd_town_glance' ), true ) ) { hd_app_towns_clear(); }
}, 10, 3 );
