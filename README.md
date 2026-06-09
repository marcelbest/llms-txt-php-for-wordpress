# llms.txt for WordPress (PHP, no plugin)

Dynamically generated `llms.txt` and full-content endpoints for **classic** WordPress themes — a single PHP file, no plugin, no static files.

It serves clean, Markdown-flavoured plain text for LLMs, following the spirit of [llmstxt.org](https://llmstxt.org) and extending it with full-content endpoints per category and tag.

> **Full walkthrough (German):** <https://marcelbest.com/webdesign/2026/llms-txt-fuer-wordpress-ohne-plugin/>
> The blog post explains the how and why in detail; this README is the quick start.

## Endpoints

| URL | Content |
|-----|---------|
| `/llms.txt` | Index of all pages, posts, categories and tags — list with short descriptions |
| `/llms-full.txt` | The N most recent posts with full content, plus all pages |
| `/llms-full-category-{slug}.txt` | All posts of a category, full content |
| `/llms-full-tag-{slug}.txt` | All posts of a tag, full content |

Only `/llms.txt` is part of the llmstxt.org spec; the full-content endpoints are an extension.

## Requirements

- WordPress with pretty permalinks enabled
- A **classic** theme (PHP `functions.php`). Block themes / full site editing are not supported without changes.
- PHP 8.0+

## Installation

1. Copy `llms-txt.php` into your theme, e.g. `inc/llms-txt.php`.
2. Require it from your theme's `functions.php`:
   ```php
   require get_template_directory() . '/inc/llms-txt.php';
   ```
3. Replace the `yourtheme_` function prefix and the `YOURTHEME_` constant prefix with your own namespace (function names, query vars and the rewrite-version option).
4. Flush permalinks once: **Settings → Permalinks → Save** (or re-activate the theme).

No dependencies, no build step.

## Excluding content

Posts and pages with the `_noindex` post meta set to `'1'` are excluded from all endpoints. Other SEO plugins can hook into the `llms_txt_post_excluded` filter:

```php
add_filter( 'llms_txt_post_excluded', function( $excluded, $post_id ) {
    return $excluded || ( '1' === get_post_meta( $post_id, '_yoast_wpseo_meta-robots-noindex', true ) );
}, 10, 2 );
```

Password-protected posts/pages, drafts and private content are excluded automatically.

## Configuration

- `YOURTHEME_LLMS_FULL_POST_LIMIT` (default `20`) — number of posts included in `/llms-full.txt`.

## How content is cleaned

Raw post content is reduced to clean plain text before output:

- **Links** are kept as Markdown `[text](url)` so their URLs survive; dead links (same-page anchors, `javascript:`/`data:`, missing `href`) are dropped.
- **Images** contribute their `alt` text; an image wrapped in a link becomes `[alt](url)`.
- **Tables** become pipe-separated rows; block elements get proper line breaks (no run-on text).
- Shortcodes and HTML are stripped; entities are decoded to real characters.

## License

[GPL-2.0-or-later](LICENSE).

## Credits

Built by [Marcel Best](https://marcelbest.com). Issues and pull requests welcome.
