# Changelog

All notable changes to this project are documented here.
The format is based on [Keep a Changelog](https://keepachangelog.com/), and this project adheres to [Semantic Versioning](https://semver.org/).

## [1.0.0] - 2026-06-07

First public release. Extracted from the classic theme on marcelbest.com and hardened for general use. Full walkthrough: <https://marcelbest.com/webdesign/2026/llms-txt-fuer-wordpress-ohne-plugin/>

### Features

- Dynamic virtual endpoints (no static files, no plugin):
  - `/llms.txt` — index of all pages, posts, categories and tags
  - `/llms-full.txt` — the N most recent posts with full content, plus all pages
  - `/llms-full-category-{slug}.txt` — full content of a category
  - `/llms-full-tag-{slug}.txt` — full content of a tag
- Served as `text/plain` with `X-Robots-Tag: noindex` and `nocache_headers()`
- Exclusion via `_noindex` post meta, extensible through the `llms_txt_post_excluded` filter
- Configurable post limit (`YOURTHEME_LLMS_FULL_POST_LIMIT`)
- Automatic rewrite-rule flush on theme activation and on version change

### Content handling (the hardening that shaped 1.0.0)

These improvements were developed and tested on the live theme before being consolidated into this release. The dates show when each landed.

- 2026-06-06 — Password-protected posts and pages are excluded (`has_password`)
- 2026-06-06 — True plain-text output: titles, excerpts and links no longer carry HTML entities (clean inline handling instead of `esc_html()`, `esc_url_raw()` for URLs)
- 2026-06-06 — Accordion/FAQ toggle "+" cleanup keeps doubled pluses ("C++") intact
- 2026-06-07 — Separators inserted before tag stripping, so tables and `<br><br>`-separated paragraphs no longer collapse into run-on text
- 2026-06-07 — Links kept as Markdown `[text](url)`; images contribute their alt text (gallery/lightbox images become `[alt](url)`); dead, anchor and empty links are dropped
