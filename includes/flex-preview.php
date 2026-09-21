<?php
/**
 * wpadm — ACF Flexible Content preview.
 *
 * Shows how a layout looks before the page is saved. Values are read from the
 * edit form, written to a hidden preview post and rendered through the active
 * theme's template parts.
 *
 * Per-theme configuration (functions.php):
 *   wpadm/flex_preview/fields          — flexible field names, default ['layouts']
 *   wpadm/flex_preview/template_slug   — path to the template part
 *   wpadm/flex_preview/render_layout   — replace the render logic entirely
 *   wpadm/flex_preview/html_class      — class on <html>, default 'js'
 *   wpadm/flex_preview/inline_css      — extra CSS inside the preview <head>
 *
 * @package wpadm
 */

defined( 'ABSPATH' ) || exit;

/* -------------------------------------------------------------------------
 * Configuration
 * ---------------------------------------------------------------------- */

/**
 * Flexible content field names that get a preview button.
 * An empty array enables every flexible field.
 */
function wpadm_flex_preview_fields() {
	return (array) apply_filters( 'wpadm/flex_preview/fields', array( 'layouts' ) );
}

/**
 * Whether this field gets a preview button.
 */
function wpadm_flex_preview_is_enabled( $field ) {
	$allowed = wpadm_flex_preview_fields();

	if ( empty( $allowed ) ) {
		return true;
	}

	return in_array( $field['_name'] ?? $field['name'], $allowed, true );
}

/* -------------------------------------------------------------------------
 * 1. Button in the layout title
 * ---------------------------------------------------------------------- */

add_filter( 'acf/fields/flexible_content/layout_title', 'wpadm_flex_preview_layout_title', 10, 4 );
function wpadm_flex_preview_layout_title( $title, $field, $layout, $i ) {
	if ( ! wpadm_flex_preview_is_enabled( $field ) ) {
		return $title;
	}

	$title .= sprintf(
		' <button type="button" class="wpadm-fp-btn" data-field="%s" title="%s"><span class="dashicons dashicons-visibility"></span><span class="screen-reader-text">%s</span></button>',
		esc_attr( $field['_name'] ?? $field['name'] ),
		esc_attr__( 'Preview this layout', 'wpadm' ),
		esc_html__( 'Preview this layout', 'wpadm' )
	);

	return $title;
}

/* -------------------------------------------------------------------------
 * 2. Assets
 *
 * Build:
 *   src/scss/wpadm.scss           → assets/css/wpadm.min.css
 *   src/js/wpadm_flex_preview.js  → assets/js/wpadm_flex_preview.min.js
 *
 * Compiled files are committed, so the plugin runs from a zip without npm.
 * ---------------------------------------------------------------------- */

add_action( 'acf/input/admin_enqueue_scripts', 'wpadm_flex_preview_assets' );
function wpadm_flex_preview_assets() {
	$screen = get_current_screen();

	if ( ! $screen || 'post' !== $screen->base ) {
		return;
	}

	$css_rel = 'assets/css/wpadm.min.css';
	$js_rel  = 'assets/js/wpadm_flex_preview.min.js';

	wp_enqueue_style(
		'wpadm-flex-preview',
		wpadm_asset_url( $css_rel ),
		array( 'dashicons' ),
		wpadm_asset_version( $css_rel )
	);

	wp_enqueue_script(
		'wpadm-flex-preview',
		wpadm_asset_url( $js_rel ),
		array( 'jquery', 'acf-input' ),
		wpadm_asset_version( $js_rel ),
		true
	);

	wp_localize_script(
		'wpadm-flex-preview',
		'WPAdmFlexPreview',
		array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'wpadm_flex_preview' ),
			'postId'  => get_the_ID(),
			'i18n'    => array(
				'title'    => __( 'Layout preview', 'wpadm' ),
				'loading'  => __( 'Building preview…', 'wpadm' ),
				'close'    => __( 'Close', 'wpadm' ),
				'reload'   => __( 'Reload', 'wpadm' ),
				'desktop'  => __( 'Desktop', 'wpadm' ),
				'tablet'   => __( 'Tablet', 'wpadm' ),
				'mobile'   => __( 'Mobile', 'wpadm' ),
				'genError' => __( 'The preview could not be built. Save the page as a draft and try again.', 'wpadm' ),
				'noFields' => __( 'No ACF fields were found on this screen. See the browser console for details.', 'wpadm' ),
			),
		)
	);
}

/* -------------------------------------------------------------------------
 * 3. Preview post
 * ---------------------------------------------------------------------- */

/**
 * Returns (and creates if needed) the hidden post that holds preview values.
 *
 * @param int $post_id ID of the post being edited.
 * @return int|WP_Error
 */
function wpadm_flex_preview_get_post( $post_id ) {
	$preview_id = (int) get_post_meta( $post_id, '_wpadm_flex_preview_id', true );

	if ( $preview_id && get_post( $preview_id ) && 'trash' !== get_post_status( $preview_id ) ) {
		return $preview_id;
	}

	$preview_id = wp_insert_post(
		array(
			'post_type'   => get_post_type( $post_id ),
			'post_status' => 'draft',
			/* translators: %s: title of the post being previewed. */
			'post_title'  => sprintf( __( '[wpadm-preview] %s', 'wpadm' ), get_the_title( $post_id ) ),
			'post_name'   => 'wpadm-flex-preview-' . $post_id,
			'post_author' => get_current_user_id(),
		),
		true
	);

	if ( is_wp_error( $preview_id ) ) {
		return $preview_id;
	}

	update_post_meta( $post_id, '_wpadm_flex_preview_id', $preview_id );
	update_post_meta( $preview_id, '_wpadm_flex_preview_parent', $post_id );

	return $preview_id;
}

/**
 * Keep preview posts out of admin lists, menus and the front end.
 */
add_action( 'pre_get_posts', 'wpadm_flex_preview_hide_posts' );
function wpadm_flex_preview_hide_posts( $query ) {
	if ( ! empty( $_GET['wpadm_flex_preview'] ) ) {
		return;
	}

	$hide = array(
		'key'     => '_wpadm_flex_preview_parent',
		'compare' => 'NOT EXISTS',
	);

	$existing = $query->get( 'meta_query' );

	/*
	 * Appending to the caller's array looks equivalent and is not. A meta_query
	 * carrying 'relation' => 'OR' puts every clause in that array on the same
	 * side of the OR, so the appended clause does not narrow the result — it
	 * widens it to everything that is not a preview post, and the caller's own
	 * filter stops being applied at all.
	 *
	 * Nesting keeps whatever relation they asked for intact, inside an AND.
	 */
	if ( ! empty( $existing ) && is_array( $existing ) ) {
		$meta_query = array(
			'relation' => 'AND',
			$existing,
			$hide,
		);
	} else {
		$meta_query = array( $hide );
	}

	$query->set( 'meta_query', $meta_query );
}

/**
 * Delete the preview post when the original is deleted.
 */
add_action( 'before_delete_post', 'wpadm_flex_preview_cleanup' );
function wpadm_flex_preview_cleanup( $post_id ) {
	$preview_id = (int) get_post_meta( $post_id, '_wpadm_flex_preview_id', true );

	if ( $preview_id ) {
		wp_delete_post( $preview_id, true );
	}
}

/* -------------------------------------------------------------------------
 * 4. AJAX: store values and return the preview URL
 * ---------------------------------------------------------------------- */

add_action( 'wp_ajax_wpadm_flex_preview', 'wpadm_flex_preview_ajax' );
function wpadm_flex_preview_ajax() {
	check_ajax_referer( 'wpadm_flex_preview', 'nonce' );

	$post_id = absint( $_POST['post_id'] ?? 0 );
	$row     = isset( $_POST['row'] ) ? (int) $_POST['row'] : -1;
	$field   = sanitize_key( $_POST['field'] ?? '' );

	if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
		wp_send_json_error( array( 'message' => __( 'You do not have permission to edit this page.', 'wpadm' ) ), 403 );
	}

	if ( empty( $_POST['acf'] ) || ! is_array( $_POST['acf'] ) ) {
		wp_send_json_error( array( 'message' => __( 'The form did not submit any fields.', 'wpadm' ) ) );
	}

	$preview_id = wpadm_flex_preview_get_post( $post_id );

	if ( is_wp_error( $preview_id ) ) {
		wp_send_json_error( array( 'message' => $preview_id->get_error_message() ) );
	}

	// ACF sanitizes values per field type inside acf_update_value().
	acf_save_post( $preview_id, wp_unslash( $_POST['acf'] ) );

	$url = add_query_arg(
		array(
			'wpadm_flex_preview' => $preview_id,
			'row'                => $row,
			'field'              => $field,
			'_wpnonce'           => wp_create_nonce( 'wpadm_flex_preview_view_' . $preview_id ),
		),
		home_url( '/' )
	);

	wp_send_json_success( array( 'url' => $url ) );
}

/* -------------------------------------------------------------------------
 * 5. Front-end render
 * ---------------------------------------------------------------------- */

add_action( 'template_redirect', 'wpadm_flex_preview_render', 1 );
function wpadm_flex_preview_render() {
	if ( empty( $_GET['wpadm_flex_preview'] ) ) {
		return;
	}

	$preview_id = absint( $_GET['wpadm_flex_preview'] );
	$nonce      = sanitize_text_field( $_GET['_wpnonce'] ?? '' );

	if ( ! wp_verify_nonce( $nonce, 'wpadm_flex_preview_view_' . $preview_id ) ) {
		wp_die( esc_html__( 'This preview link has expired. Close the modal and try again.', 'wpadm' ), 403 );
	}

	$parent_id = (int) get_post_meta( $preview_id, '_wpadm_flex_preview_parent', true );

	if ( ! $parent_id || ! current_user_can( 'edit_post', $parent_id ) ) {
		wp_die( esc_html__( 'You do not have permission to view this preview.', 'wpadm' ), 403 );
	}

	$row   = isset( $_GET['row'] ) ? (int) $_GET['row'] : -1;
	$field = sanitize_key( $_GET['field'] ?? '' );

	if ( ! $field ) {
		$fields = wpadm_flex_preview_fields();
		$field  = $fields[0] ?? 'layouts';
	}

	/*
	 * Themes often decide which scripts to load based on get_queried_object_id().
	 * Without this they would look at the home page and miss layout-specific files.
	 */
	global $wp_query;
	$wp_query->queried_object    = get_post( $preview_id );
	$wp_query->queried_object_id = $preview_id;

	// The loop points at the ORIGINAL post so the_title(), get_the_ID() and
	// the featured image return the real values.
	global $post;
	$post = get_post( $parent_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride
	setup_postdata( $post );

	show_admin_bar( false );

	add_filter(
		'body_class',
		function ( $classes ) {
			$classes[] = 'wpadm-is-flex-preview';
			return $classes;
		}
	);

	nocache_headers();

	$extra_css = (string) apply_filters( 'wpadm/flex_preview/inline_css', '' );

	/*
	 * Themes commonly set a "js" class on <html> from an inline script in
	 * header.php, and scope their pre-animation hiding rules to it. The preview
	 * does not run header.php, so the class is set here to match the real front
	 * end. Return an empty string from the filter if a theme's hiding rules
	 * leave the preview blank.
	 */
	$html_class = (string) apply_filters( 'wpadm/flex_preview/html_class', 'js' );

	?>
<!doctype html>
<html <?php language_attributes(); ?><?php echo $html_class ? ' class="' . esc_attr( $html_class ) . '"' : ''; ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex, nofollow">
	<?php wp_head(); ?>
	<style>
		body.wpadm-is-flex-preview { margin: 0 !important; }
		.wpadm-preview-empty {
			font: 14px/1.6 -apple-system, "Segoe UI", sans-serif;
			color: #50575e; padding: 48px 24px; text-align: center;
		}
		<?php echo $extra_css; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	</style>
</head>
<body <?php body_class(); ?>>
	<?php
	$rendered = 0;
	$index    = 0;

	if ( have_rows( $field, $preview_id ) ) {
		while ( have_rows( $field, $preview_id ) ) {
			the_row();

			if ( $row > -1 && $index !== $row ) {
				$index++;
				continue;
			}

			wpadm_flex_preview_render_layout( get_row_layout() );
			$rendered++;
			$index++;
		}
	}

	if ( ! $rendered ) {
		printf(
			'<p class="wpadm-preview-empty">%s</p>',
			esc_html__( 'This layout has no content to display yet. Fill in the fields and reload the preview.', 'wpadm' )
		);
	}

	wp_reset_postdata();
	wp_footer();
	?>
</body>
</html>
	<?php
	exit;
}

/**
 * Renders a single layout.
 *
 * Uses get_template_part() rather than a relative include() — a relative path
 * resolves against the calling file's directory, which would break from here.
 *
 * @param string $layout Layout name from ACF.
 */
function wpadm_flex_preview_render_layout( $layout ) {
	if ( apply_filters( 'wpadm/flex_preview/render_layout', false, $layout ) ) {
		return;
	}

	$slug = apply_filters( 'wpadm/flex_preview/template_slug', 'template_parts/layouts/' . $layout, $layout );

	if ( ! locate_template( $slug . '.php' ) ) {
		printf(
			'<p class="wpadm-preview-empty">%s</p>',
			esc_html(
				sprintf(
					/* translators: %s: path to the missing template file, without extension. */
					__( 'Missing template file: %s.php', 'wpadm' ),
					$slug
				)
			)
		);
		return;
	}

	get_template_part( $slug );
}
