<?php
/**
 * Plugin Name:       Flexible Content Preview
 * Plugin URI:        https://github.com/dakakiki/wpacfpreview
 * Description:       Preview ACF Flexible Content layouts in the admin before the page is saved.
 * Version:           1.0.3
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Davor Kikindjanin
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wpadm
 * Domain Path:       /languages
 *
 * @package wpadm
 */

defined( 'ABSPATH' ) || exit;

define( 'WPADM_VERSION', '1.0.3' );
define( 'WPADM_FILE', __FILE__ );
define( 'WPADM_PATH', plugin_dir_path( __FILE__ ) );
define( 'WPADM_URL', plugin_dir_url( __FILE__ ) );

/**
 * Cache busting version for an asset.
 *
 * Uses the plugin version in production. With WP_DEBUG on it falls back to
 * filemtime, so changes show up without bumping the version number.
 *
 * @param string $rel Path relative to the plugin root.
 * @return string|int
 */
function wpadm_asset_version( $rel ) {
	if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		$path = WPADM_PATH . ltrim( $rel, '/' );

		if ( file_exists( $path ) ) {
			return filemtime( $path );
		}
	}

	return WPADM_VERSION;
}

/**
 * Full URL for an asset.
 *
 * @param string $rel Path relative to the plugin root.
 * @return string
 */
function wpadm_asset_url( $rel ) {
	return WPADM_URL . ltrim( $rel, '/' );
}

/**
 * Whether ACF Pro is available.
 *
 * Flexible Content is a Pro feature, so checking the field type is more
 * reliable than checking the ACF class — the free version passes the first
 * check but not the second. ACF_PRO covers builds where the field type store
 * is populated differently.
 *
 * Must not run before init:5, which is when ACF registers its field types.
 */
function wpadm_has_acf_pro() {
	if ( ! class_exists( 'ACF' ) ) {
		return false;
	}

	if ( defined( 'ACF_PRO' ) && ACF_PRO ) {
		return true;
	}

	return function_exists( 'acf_get_field_type' ) && acf_get_field_type( 'flexible_content' );
}

/**
 * Load the text domain.
 */
add_action( 'init', 'wpadm_load_textdomain', 1 );
function wpadm_load_textdomain() {
	load_plugin_textdomain( 'wpadm', false, dirname( plugin_basename( WPADM_FILE ) ) . '/languages' );
}

/**
 * Load modules.
 *
 * Runs on init at priority 20 rather than plugins_loaded: ACF registers its
 * field types on init:5, so acf_get_field_type() returns nothing before that
 * and the dependency check would fail even with ACF Pro active.
 *
 * Everything this plugin hooks into — acf/fields/*, pre_get_posts,
 * template_redirect, admin enqueue — fires after init, so nothing is missed.
 */
add_action( 'init', 'wpadm_bootstrap', 20 );
function wpadm_bootstrap() {
	if ( ! wpadm_has_acf_pro() ) {
		add_action( 'admin_notices', 'wpadm_missing_acf_notice' );
		return;
	}

	require_once WPADM_PATH . 'includes/flex-preview.php';
}

/**
 * Notice shown when ACF Pro is missing.
 */
function wpadm_missing_acf_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	printf(
		'<div class="notice notice-warning"><p><strong>%s</strong> %s</p></div>',
		esc_html__( 'Flexible Content Preview:', 'wpadm' ),
		esc_html__( 'this plugin requires ACF Pro with the Flexible Content field. Activate ACF Pro to enable the preview.', 'wpadm' )
	);
}
