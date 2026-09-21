# Flexible Content Preview (`wpadm`)

Preview ACF Flexible Content layouts before the page is saved.

Each layout title gets an eye icon. Clicking it collects the current, unsaved
values from the form, writes them to a hidden preview post and renders the
layout on the front end — with the theme's real CSS, fonts and JavaScript. The
result appears in an iframe modal with desktop, tablet and mobile width
switches.

Works in both the classic and the block editor.

## Requirements

- WordPress 6.0+
- PHP 7.4+
- ACF **Pro** (Flexible Content is a Pro feature)

The plugin folder and text domain stay `wpadm`, so future admin modules can
live alongside this one without renaming anything.

Without ACF Pro the plugin hooks into nothing and shows an admin notice.

## Installation

Copy the folder to `wp-content/plugins/wpadm` and activate it. Compiled assets
are committed, so no `npm install` is needed to use the plugin.

## Configuration

Everything goes through filters in the theme's `functions.php`. The defaults
suit a theme with a flexible field named `layouts` and parts under
`template_parts/layouts/`.

### Flexible field name

```php
add_filter( 'wpadm/flex_preview/fields', function () {
    return [ 'page_sections' ];
} );
```

An empty array enables the preview on every flexible field.

### Template part path

```php
add_filter( 'wpadm/flex_preview/template_slug', function ( $slug, $layout ) {
    return 'template_parts/blocks/' . $layout;
}, 10, 2 );
```

### Custom render logic

For themes that do not use `get_template_part()`:

```php
add_filter( 'wpadm/flex_preview/render_layout', function ( $handled, $layout ) {
    my_theme_render_section( $layout );
    return true;
}, 10, 2 );
```

### Blank preview from scroll animations

Neither of the two filters below is required. They only matter for themes that
hide content with CSS and wait for JavaScript to reveal it — AOS, WOW.js,
ScrollReveal, GSAP ScrollTrigger or a hand-rolled equivalent. Themes without
that pattern need nothing here.

Such rules are usually scoped to a `js` class that the theme sets on `<html>`
from an inline script. The preview does not run `header.php`, so it sets the
class itself to match the real front end. Dropping it is the first thing to try
when a preview renders blank:

```php
add_filter( 'wpadm/flex_preview/html_class', '__return_empty_string' );
```

If the theme hides content some other way, inject CSS that overrides it:

```php
add_filter( 'wpadm/flex_preview/inline_css', function () {
    return '
        html.js .fade-up:not(.fade-up-initialized),
        html.js .fade-right:not(.fade-right-initialized) { visibility: visible !important; }
    ';
} );
```

Reach for this only if the preview is actually blank. Most animation libraries
mark elements as initialised as soon as their script runs, and the theme's own
scripts do load inside the preview — so the hiding rule releases on its own.

## Translation

Source strings are English. The text domain is `wpadm` and `languages/wpadm.pot`
holds all 19 translatable strings.

Translate with Loco Translate, Poedit, WPML, Polylang or any other tool — they
all read the `.pot`. Place compiled files as `languages/wpadm-{locale}.mo`,
for example `wpadm-sr_RS.mo` or `wpadm-de_DE.mo`.

Strings used in JavaScript are passed through `wp_localize_script()`, so they
are translated on the PHP side and need no separate JS translation setup.

Regenerate the `.pot` after adding strings:

```bash
wp i18n make-pot . languages/wpadm.pot --exclude=node_modules,assets
```

## Development

```bash
npm install
npm run build      # css + js
npm run watch      # rebuild on change
npm run prod       # production build
```

| Source | Output |
|---|---|
| `src/scss/wpadm.scss` | `assets/css/wpadm.min.css` |
| `src/js/wpadm_flex_preview.js` | `assets/js/wpadm_flex_preview.min.js` |

Compiled files under `assets/` are **committed** — the plugin has to work from
a zip. With `WP_DEBUG` on, cache busting uses `filemtime` instead of the plugin
version, so changes show up without bumping the version number.

The SCSS deliberately avoids theme variables. `src/scss/wpadm/_variables.scss`
carries the WordPress admin palette so the module fits any theme unchanged.

## How it works

1. JavaScript collects every `acf[...]` input from the form, skipping clone
   rows and disabled fields.
2. AJAX sends them; `acf_save_post()` writes them to a hidden draft post linked
   to the original through `_wpadm_flex_preview_id`.
3. The returned URL, signed with a nonce, loads in an iframe.
4. `template_redirect` intercepts the request, points the loop at the original
   post and renders the requested layout through `have_rows()` / `the_row()`,
   so existing template parts work unchanged.

Preview posts are excluded from admin lists and the front end, and are deleted
along with the original.

## Limitations

- Images must be uploaded before previewing — ACF stores only the attachment ID.
- A post that has never been saved has no ID; save a draft and try again.
- `acf_save_post()` skips `acf/validate_value`, so the preview does not surface
  validation errors.
