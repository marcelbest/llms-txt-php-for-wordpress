<?php
// Full walkthrough: https://marcelbest.com/webdesign/2026/llms-txt-fuer-wordpress-ohne-plugin/ (German)

/**
 * llms.txt and llms-full.txt
 *
 * Serves dynamically generated plain-text files for LLM consumption:
 * - /llms.txt                          — full index (all posts, list only)
 * - /llms-full.txt                     — 20 most recent posts with full content
 * - /llms-full-category-{slug}.txt     — all posts of a category, full content
 * - /llms-full-tag-{slug}.txt          — all posts of a tag, full content
 */

define( 'YOURTHEME_LLMS_FULL_POST_LIMIT', 20 );

if ( ! function_exists( 'yourtheme_llms_register_rewrites' ) ) :
function yourtheme_llms_register_rewrites() {
    add_rewrite_rule( '^llms\.txt/?$', 'index.php?yourtheme_llms=1', 'top' );
    add_rewrite_rule( '^llms-full\.txt/?$', 'index.php?yourtheme_llms_full=1', 'top' );
    add_rewrite_rule( '^llms-full-category-([a-z0-9-]+)\.txt/?$', 'index.php?yourtheme_llms_full=1&yourtheme_llms_taxonomy=category&yourtheme_llms_term=$matches[1]', 'top' );
    add_rewrite_rule( '^llms-full-tag-([a-z0-9-]+)\.txt/?$', 'index.php?yourtheme_llms_full=1&yourtheme_llms_taxonomy=post_tag&yourtheme_llms_term=$matches[1]', 'top' );
}
endif;
add_action( 'init', 'yourtheme_llms_register_rewrites' );

if ( ! function_exists( 'yourtheme_llms_query_vars' ) ) :
function yourtheme_llms_query_vars( $vars ) {
    $vars[] = 'yourtheme_llms';
    $vars[] = 'yourtheme_llms_full';
    $vars[] = 'yourtheme_llms_taxonomy';
    $vars[] = 'yourtheme_llms_term';
    return $vars;
}
endif;
add_filter( 'query_vars', 'yourtheme_llms_query_vars' );

if ( ! function_exists( 'yourtheme_llms_redirect_canonical' ) ) :
/**
 * Keeps the file names without a trailing slash. With a permalink structure
 * ending in a slash WordPress would redirect /llms.txt to /llms.txt/.
 *
 * @param string $redirect_url The redirect URL.
 * @return string|false
 */
function yourtheme_llms_redirect_canonical( $redirect_url ) {
    if ( get_query_var( 'yourtheme_llms' ) || get_query_var( 'yourtheme_llms_full' ) ) {
        return false;
    }

    return $redirect_url;
}
endif;
add_filter( 'redirect_canonical', 'yourtheme_llms_redirect_canonical' );

if ( ! function_exists( 'yourtheme_llms_template_redirect' ) ) :
function yourtheme_llms_template_redirect() {
    $is_llms      = get_query_var( 'yourtheme_llms' );
    $is_llms_full = get_query_var( 'yourtheme_llms_full' );

    if ( ! $is_llms && ! $is_llms_full ) {
        return;
    }

    $full     = (bool) $is_llms_full;
    $taxonomy = sanitize_key( get_query_var( 'yourtheme_llms_taxonomy' ) );
    $term     = sanitize_title( get_query_var( 'yourtheme_llms_term' ) );

    if ( $full && $taxonomy && $term ) {
        $term_obj = get_term_by( 'slug', $term, $taxonomy );
        if ( ! $term_obj ) {
            status_header( 404 );
            exit;
        }
    }

    header( 'Content-Type: text/plain; charset=utf-8' );
    header( 'X-Robots-Tag: noindex' );
    nocache_headers(); // Prevent browsers and proxies from caching dynamic content — llms.txt changes with every new post.

    yourtheme_llms_output( $full, $taxonomy, $term );
    exit;
}
endif;
add_action( 'template_redirect', 'yourtheme_llms_template_redirect' );

if ( ! function_exists( 'yourtheme_llms_is_excluded' ) ) :
function yourtheme_llms_is_excluded( $post_id ) {
    $excluded = '1' === get_post_meta( $post_id, '_noindex', true );
    return (bool) apply_filters( 'llms_txt_post_excluded', $excluded, $post_id );
}
endif;

if ( ! function_exists( 'yourtheme_llms_get_excluded_ids' ) ) :
function yourtheme_llms_get_excluded_ids( $post_type ) {
    $posts = get_posts( array(
        'post_type'      => $post_type,
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'fields'         => 'ids',
    ) );

    // Prime the post meta cache in a single query, so the per-post _noindex
    // lookups in yourtheme_llms_is_excluded() hit the cache instead of each
    // running its own query (avoids N+1). The filter stays per-post, so
    // extensions can still add their own exclusion logic.
    update_meta_cache( 'post', $posts );

    return array_values( array_filter( $posts, 'yourtheme_llms_is_excluded' ) );
}
endif;

if ( ! function_exists( 'yourtheme_llms_clean_content' ) ) :
function yourtheme_llms_clean_content( $content ) {
    $content = strip_shortcodes( $content );

    // Replace images with their alt text — that is the actual content of an image.
    // An image wrapped in a link (gallery/lightbox) then becomes "[alt](url)" via
    // the link step below. Images without alt text carry nothing and are dropped.
    $content = preg_replace_callback(
        '#<img\b[^>]*>#i',
        function( $matches ) {
            if ( preg_match( '#(?<![\w-])alt\s*=\s*([\'"])(.*?)\1#i', $matches[0], $alt ) ) {
                return trim( html_entity_decode( $alt[2], ENT_QUOTES, 'UTF-8' ) );
            }
            return '';
        },
        $content
    );

    // Convert links to Markdown so their URLs survive (sources, internal links,
    // button/CTA targets). Runs before tag stripping. Dropped entirely (no output):
    // same-page anchors (#…), schemes esc_url_raw() rejects (javascript:, data: …),
    // <a> without href, and image/icon-only links (empty text — e.g. gallery and
    // lightbox image links, whose bare media URL is just noise without alt text).
    $content = preg_replace_callback(
        '#<a\b([^>]*)>(.*?)</a>#is',
        function( $matches ) {
            $text = trim( wp_strip_all_tags( $matches[2] ) );
            if ( ! preg_match( '#(?<![\w-])href\s*=\s*([\'"])(.*?)\1#i', $matches[1], $href ) ) {
                return '';
            }
            $url  = trim( html_entity_decode( $href[2], ENT_QUOTES, 'UTF-8' ) );
            $safe = esc_url_raw( $url );
            if ( '' === $safe || '#' === $url[0] ) {
                return '';
            }
            if ( '' === $text ) {
                return '';
            }
            return '[' . $text . '](' . $safe . ')';
        },
        $content
    );

    // Insert separators BEFORE stripping tags. wp_strip_all_tags() removes tags
    // without adding whitespace, so block and table content would otherwise collapse
    // into run-on text (table cells glued together, adjacent paragraphs joined).
    // Table cells become " | ", table rows / list items become single newlines,
    // block-level elements become blank-line breaks.
    $content = preg_replace( '#</t[dh]>\s*<t[dh][^>]*>#i', ' | ', $content );
    $content = preg_replace( '#<br\s*/?>#i', "\n", $content );
    $content = preg_replace( '#</(li|tr|dt|dd)>#i', "\n", $content );
    $content = preg_replace( '#</(p|div|h[1-6]|blockquote|figcaption|figure|pre|ul|ol|dl|table|section|article)>#i', "\n\n", $content );

    // Order matters: strip tags first, then decode entities. This yields readable
    // plain text and keeps literal characters intact (e.g. "5 < 10", stored as
    // "5 &lt; 10"). Do NOT strip again after decoding — a second wp_strip_all_tags()
    // would treat a lone "<" as an unclosed tag and eat the rest of the line. Output
    // is served as text/plain, so decoded characters are never read as markup.
    $content = wp_strip_all_tags( $content );
    $content = html_entity_decode( $content, ENT_QUOTES, 'UTF-8' );
    // Remove toggle-button artifacts: a lone "+" left as bare text at a line end
    // after an accordion/FAQ heading (e.g. "What is llms.txt?+"). The negative
    // lookbehind keeps doubled pluses intact, so "C++" or "Notepad++" survive.
    $content = preg_replace( '/[ \t]*(?<!\+)\+[ \t]*$/m', '', $content );
    $content = preg_replace( '/\n{3,}/', "\n\n", $content );
    return trim( $content );
}
endif;

// Plain-text output must not contain HTML entities, so esc_html() is wrong
// for inline strings (it turns "&" into "&amp;"). Strip tags and decode
// entities to clean single-line text instead.
if ( ! function_exists( 'yourtheme_llms_clean_inline' ) ) :
function yourtheme_llms_clean_inline( $text ) {
    $text = wp_strip_all_tags( $text, true );
    $text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
    return trim( $text );
}
endif;

if ( ! function_exists( 'yourtheme_llms_output_post_full' ) ) :
function yourtheme_llms_output_post_full( $post ) {
    $permalink = get_permalink( $post->ID );
    $title     = get_the_title( $post->ID );
    $date      = get_the_date( 'Y-m-d', $post->ID );
    $tags      = get_the_tags( $post->ID );
    $tag_list  = $tags ? implode( ', ', array_map( fn( $t ) => $t->name, $tags ) ) : '';
    $excerpt   = yourtheme_llms_clean_content( $post->post_excerpt );
    $content   = yourtheme_llms_clean_content( $post->post_content );

    echo '### [' . yourtheme_llms_clean_inline( $title ) . '](' . esc_url_raw( $permalink ) . ")\n\n";
    echo esc_html( $date );
    if ( $tag_list ) {
        echo ' | Tags: ' . yourtheme_llms_clean_inline( $tag_list );
    }
    echo "\n\n";
    if ( $excerpt ) {
        echo $excerpt . "\n\n";
    }
    if ( $content ) {
        echo $content . "\n\n";
    }
    echo "---\n\n";
}
endif;

if ( ! function_exists( 'yourtheme_llms_output' ) ) :
function yourtheme_llms_output( $full = false, $taxonomy = '', $term = '' ) {
    $site_name = get_bloginfo( 'name' );
    $tagline   = get_bloginfo( 'description' );
    $site_url  = home_url();

    echo '# ' . yourtheme_llms_clean_inline( $site_name ) . "\n\n";

    if ( $tagline ) {
        echo '> ' . yourtheme_llms_clean_inline( $tagline ) . "\n\n";
    }

    // Taxonomy-filtered file
    if ( $full && $taxonomy && $term ) {
        $term_obj  = get_term_by( 'slug', $term, $taxonomy );
        $term_name = $term_obj ? $term_obj->name : $term;
        $label     = 'category' === $taxonomy ? 'Category' : 'Tag';

        echo '> ' . $label . ': ' . yourtheme_llms_clean_inline( $term_name ) . "\n";
        echo '> Full index: ' . esc_url_raw( $site_url ) . "/llms.txt\n\n";

        $excluded   = yourtheme_llms_get_excluded_ids( 'post' );
        $query_args = array(
            'post_type'      => 'post',
            'post_status'    => 'publish',
            'has_password'   => false,
            'posts_per_page' => -1,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'tax_query'      => array(
                array(
                    'taxonomy' => $taxonomy,
                    'field'    => 'slug',
                    'terms'    => $term,
                ),
            ),
        );
        if ( ! empty( $excluded ) ) {
            $query_args['post__not_in'] = $excluded;
        }
        $posts = get_posts( $query_args );
        if ( $posts ) {
            echo '## ' . yourtheme_llms_clean_inline( $term_name ) . "\n\n";
            foreach ( $posts as $post ) {
                yourtheme_llms_output_post_full( $post );
            }
        }
        return;
    }

    // llms-full.txt
    if ( $full ) {
        echo '> ' . YOURTHEME_LLMS_FULL_POST_LIMIT . ' most recent posts with full content. Full index: ' . esc_url_raw( $site_url ) . "/llms.txt\n\n";

        $excluded_pages = yourtheme_llms_get_excluded_ids( 'page' );
        $page_args = array(
            'post_type'      => 'page',
            'post_status'    => 'publish',
            'has_password'   => false,
            'posts_per_page' => -1,
            'orderby'        => 'menu_order',
            'order'          => 'ASC',
        );
        if ( ! empty( $excluded_pages ) ) {
            $page_args['post__not_in'] = $excluded_pages;
        }
        $pages = get_posts( $page_args );
        if ( $pages ) {
            echo "## Pages\n\n";
            foreach ( $pages as $page ) {
                echo '### [' . yourtheme_llms_clean_inline( get_the_title( $page->ID ) ) . '](' . esc_url_raw( get_permalink( $page->ID ) ) . ")\n\n";
                $content = yourtheme_llms_clean_content( $page->post_content );
                if ( $content ) {
                    echo $content . "\n\n";
                }
                echo "---\n\n";
            }
        }

        $excluded_posts = yourtheme_llms_get_excluded_ids( 'post' );
        $post_args = array(
            'post_type'      => 'post',
            'post_status'    => 'publish',
            'has_password'   => false,
            'posts_per_page' => YOURTHEME_LLMS_FULL_POST_LIMIT,
            'orderby'        => 'date',
            'order'          => 'DESC',
        );
        if ( ! empty( $excluded_posts ) ) {
            $post_args['post__not_in'] = $excluded_posts;
        }
        $posts = get_posts( $post_args );
        if ( $posts ) {
            echo "## Blog\n\n";
            foreach ( $posts as $post ) {
                yourtheme_llms_output_post_full( $post );
            }
        }
        return;
    }

    // llms.txt — index only
    echo '> Full content available at: ' . esc_url_raw( $site_url ) . "/llms-full.txt\n\n";

    $excluded_pages = yourtheme_llms_get_excluded_ids( 'page' );
    $page_args = array(
        'post_type'      => 'page',
        'post_status'    => 'publish',
        'has_password'   => false,
        'posts_per_page' => -1,
        'orderby'        => 'menu_order',
        'order'          => 'ASC',
    );
    if ( ! empty( $excluded_pages ) ) {
        $page_args['post__not_in'] = $excluded_pages;
    }
    $pages = get_posts( $page_args );
    if ( $pages ) {
        echo "## Pages\n\n";
        foreach ( $pages as $page ) {
            $excerpt = yourtheme_llms_clean_content(
                isset( $page->post_excerpt ) && $page->post_excerpt
                    ? $page->post_excerpt
                    : wp_trim_words( $page->post_content, 30, '...' )
            );
            echo '- [' . yourtheme_llms_clean_inline( get_the_title( $page->ID ) ) . '](' . esc_url_raw( get_permalink( $page->ID ) ) . ')';
            if ( $excerpt ) {
                echo ': ' . $excerpt;
            }
            echo "\n";
        }
        echo "\n";
    }

    $excluded_posts = yourtheme_llms_get_excluded_ids( 'post' );
    $post_args = array(
        'post_type'      => 'post',
        'post_status'    => 'publish',
        'has_password'   => false,
        'posts_per_page' => -1,
        'orderby'        => 'date',
        'order'          => 'DESC',
    );
    if ( ! empty( $excluded_posts ) ) {
        $post_args['post__not_in'] = $excluded_posts;
    }
    $posts = get_posts( $post_args );
    if ( $posts ) {
        echo "## Blog\n\n";
        foreach ( $posts as $post ) {
            $date     = get_the_date( 'Y-m-d', $post->ID );
            $tags     = get_the_tags( $post->ID );
            $tag_list = $tags ? implode( ', ', array_map( fn( $t ) => $t->name, $tags ) ) : '';
            $excerpt  = yourtheme_llms_clean_content(
                $post->post_excerpt
                    ? $post->post_excerpt
                    : wp_trim_words( $post->post_content, 40, '...' )
            );
            echo '- [' . yourtheme_llms_clean_inline( get_the_title( $post->ID ) ) . '](' . esc_url_raw( get_permalink( $post->ID ) ) . ') — ' . esc_html( $date );
            if ( $excerpt ) {
                echo ': ' . $excerpt;
            }
            if ( $tag_list ) {
                echo ' [Tags: ' . yourtheme_llms_clean_inline( $tag_list ) . ']';
            }
            echo "\n";
        }
        echo "\n";
    }

    $categories = get_categories( array( 'hide_empty' => true ) );
    if ( $categories ) {
        echo "## Categories\n\n";
        foreach ( $categories as $cat ) {
            echo '- [' . yourtheme_llms_clean_inline( $cat->name ) . '](' . esc_url_raw( $site_url . '/llms-full-category-' . $cat->slug . '.txt' ) . ')';
            if ( $cat->description ) {
                echo ': ' . yourtheme_llms_clean_inline( $cat->description );
            }
            echo ' (' . (int) $cat->count . " posts)\n";
        }
        echo "\n";
    }

    $tags = get_tags( array( 'hide_empty' => true ) );
    if ( $tags ) {
        echo "## Tags\n\n";
        foreach ( $tags as $tag ) {
            echo '- [' . yourtheme_llms_clean_inline( $tag->name ) . '](' . esc_url_raw( $site_url . '/llms-full-tag-' . $tag->slug . '.txt' ) . ')';
            echo ' (' . (int) $tag->count . " posts)\n";
        }
    }
}
endif;

add_action( 'after_switch_theme', 'flush_rewrite_rules' );
add_action( 'switch_theme', 'flush_rewrite_rules' );

add_action( 'init', function() {
    $version = wp_get_theme()->get( 'Version' );
    if ( get_option( 'yourtheme_llms_rewrite_version' ) !== $version ) {
        flush_rewrite_rules();
        update_option( 'yourtheme_llms_rewrite_version', $version );
    }
}, 20 );
