<?php
/**
 * Alt Text Audit on Post Save — Admin Notice Warning
 *
 * Hooks into the WordPress save_post action to check whether any images
 * embedded in the post content are missing alt text. If missing images are
 * found, an admin notice is shown the next time the editor loads.
 *
 * Two checks are performed:
 *   1. Images inserted via the block editor or classic editor (in post_content)
 *      are parsed with DOMDocument. Any <img> without an alt attribute, or
 *      with alt="" (non-decorative), triggers a warning.
 *   2. The featured image (post thumbnail) is checked against its postmeta.
 *
 * Intentionally empty alt="" is allowed — it marks the image as decorative.
 * This script only warns about missing alt attributes entirely.
 *
 * Installation:
 *   Add to your theme's functions.php, or drop into a must-use plugin file
 *   at wp-content/mu-plugins/alt-text-audit.php.
 *
 * @package AltAuditExamples
 */

// Guard: only run in wp-admin, never on the frontend.
if ( ! is_admin() ) {
    return;
}


/**
 * On post save, scan the post content for images missing alt attributes.
 * Store results in a transient so they survive the redirect after save.
 *
 * Hooked to save_post with priority 20 (after default save operations).
 *
 * @param int     $post_id The ID of the post being saved.
 * @param WP_Post $post    The post object.
 * @param bool    $update  Whether this is an update (true) or new post (false).
 */
function altaudit_check_images_on_save( int $post_id, WP_Post $post, bool $update ): void {
    // Skip autosaves — they fire too frequently and would create noise.
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
        return;
    }

    // Skip revisions.
    if ( wp_is_post_revision( $post_id ) ) {
        return;
    }

    // Only run for post types that are likely to contain inline images.
    // Add or remove types here to match your site.
    $allowed_post_types = apply_filters( 'altaudit_checked_post_types', [
        'post',
        'page',
        'product', // WooCommerce
    ] );

    if ( ! in_array( $post->post_type, $allowed_post_types, true ) ) {
        return;
    }

    // Check the user has permission to edit this post.
    if ( ! current_user_can( 'edit_post', $post_id ) ) {
        return;
    }

    $issues = [];

    // -------------------------------------------------------------------
    // Check 1: Images in the post content (classic editor or block editor).
    // -------------------------------------------------------------------
    $content = $post->post_content;

    if ( ! empty( $content ) && strpos( $content, '<img' ) !== false ) {
        $content_issues = altaudit_scan_content_for_missing_alt( $content );
        if ( ! empty( $content_issues ) ) {
            $issues['content'] = $content_issues;
        }
    }

    // -------------------------------------------------------------------
    // Check 2: Featured image (post thumbnail).
    // -------------------------------------------------------------------
    $thumbnail_id = get_post_thumbnail_id( $post_id );
    if ( $thumbnail_id ) {
        $thumb_alt = trim( (string) get_post_meta( $thumbnail_id, '_wp_attachment_image_alt', true ) );
        if ( $thumb_alt === '' ) {
            $thumb_url           = wp_get_attachment_url( $thumbnail_id );
            $issues['thumbnail'] = [
                'attachment_id' => $thumbnail_id,
                'url'           => $thumb_url,
            ];
        }
    }

    if ( empty( $issues ) ) {
        // All clear — delete any stale transient from a previous save.
        delete_transient( 'altaudit_missing_alt_' . $post_id );
        return;
    }

    // Store the issues in a transient keyed to the post ID.
    // The transient expires in 60 seconds — enough to survive the post-save redirect.
    set_transient( 'altaudit_missing_alt_' . $post_id, $issues, 60 );
}
add_action( 'save_post', 'altaudit_check_images_on_save', 20, 3 );


/**
 * Parse an HTML string and find all <img> elements with a missing alt attribute.
 *
 * Note: We treat a completely absent alt attribute as an error. An empty
 * alt="" is intentional (decorative image) and is not flagged.
 *
 * @param string $html The raw HTML to scan (typically post_content).
 *
 * @return array Array of arrays, each with 'src' and optionally 'id' if the
 *               attachment ID can be extracted from a data-id attribute.
 */
function altaudit_scan_content_for_missing_alt( string $html ): array {
    // DOMDocument generates warnings for HTML5 elements — suppress them.
    $dom = new DOMDocument();
    libxml_use_internal_errors( true );

    // Use UTF-8 charset declaration to avoid encoding issues with DOMDocument.
    $dom->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
    libxml_clear_errors();

    $images  = $dom->getElementsByTagName( 'img' );
    $missing = [];

    /** @var DOMElement $img */
    foreach ( $images as $img ) {
        // hasAttribute returns false when the attribute is entirely absent.
        if ( ! $img->hasAttribute( 'alt' ) ) {
            $entry = [
                'src' => esc_url( $img->getAttribute( 'src' ) ),
            ];

            // Gutenberg stores the attachment ID in data-id on <img> tags
            // inside Image blocks. Extract it if present.
            if ( $img->hasAttribute( 'data-id' ) ) {
                $entry['attachment_id'] = absint( $img->getAttribute( 'data-id' ) );
            }

            // class attribute can help identify the block type for debugging.
            if ( $img->hasAttribute( 'class' ) ) {
                $entry['class'] = sanitize_html_class( $img->getAttribute( 'class' ) );
            }

            $missing[] = $entry;
        }
        // alt="" is intentional (decorative) — not flagged.
    }

    return $missing;
}


/**
 * Display admin notices on the post edit screen when issues were found on save.
 *
 * The notice is shown once and then the transient is deleted.
 */
function altaudit_display_missing_alt_notice(): void {
    // Determine the current post ID from the URL.
    $screen = get_current_screen();
    if ( ! $screen || $screen->base !== 'post' ) {
        return;
    }

    // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    $post_id = absint( $_GET['post'] ?? 0 );
    if ( ! $post_id ) {
        return;
    }

    $issues = get_transient( 'altaudit_missing_alt_' . $post_id );
    if ( ! $issues ) {
        return;
    }

    // Delete the transient immediately — show the notice only once.
    delete_transient( 'altaudit_missing_alt_' . $post_id );

    // Build the notice message.
    $lines = [];

    if ( ! empty( $issues['content'] ) ) {
        $count   = count( $issues['content'] );
        $lines[] = sprintf(
            _n(
                '<strong>%d image</strong> in the post content is missing an alt attribute.',
                '<strong>%d images</strong> in the post content are missing alt attributes.',
                $count,
                'altaudit'
            ),
            $count
        );
        // List the first 5 affected src values to help the editor identify them.
        $examples = array_slice( $issues['content'], 0, 5 );
        foreach ( $examples as $img ) {
            $lines[] = '&nbsp;&nbsp;&mdash; <code>' . esc_html( $img['src'] ) . '</code>';
        }
        if ( $count > 5 ) {
            $lines[] = '&nbsp;&nbsp;&mdash; &hellip; and ' . ( $count - 5 ) . ' more.';
        }
    }

    if ( ! empty( $issues['thumbnail'] ) ) {
        $edit_url = esc_url( get_edit_post_link( $issues['thumbnail']['attachment_id'] ) );
        $lines[]  = 'The <strong>featured image</strong> is missing alt text. '
                    . '<a href="' . $edit_url . '">Edit in media library &rarr;</a>';
    }

    // Render the notice.
    echo '<div class="notice notice-warning is-dismissible">';
    echo '<p><strong>Alt Text Audit:</strong> This post was saved with accessibility issues.</p>';
    echo '<ul style="list-style:disc;padding-left:1.5em;margin-bottom:.5em;">';
    foreach ( $lines as $line ) {
        echo '<li>' . wp_kses_post( $line ) . '</li>';
    }
    echo '</ul>';
    echo '<p style="font-size:.85em;color:#555;">Images need descriptive alt text for screen readers and image search. '
         . 'Empty <code>alt=""</code> is allowed for purely decorative images.</p>';
    echo '</div>';
}
add_action( 'admin_notices', 'altaudit_display_missing_alt_notice' );


/**
 * Optional: add an "images missing alt text" column to the Posts list table.
 *
 * This gives editors a quick visual indicator without opening each post.
 * The column shows a count of inline images that have no alt attribute.
 *
 * To disable this column, remove or comment out the two add_filter calls below.
 */

/**
 * Register the custom column on the posts list table.
 *
 * @param array  $columns   Existing columns.
 * @param string $post_type The current post type.
 *
 * @return array Modified columns array.
 */
function altaudit_add_alt_text_column( array $columns ): array {
    // Insert after the title column.
    $new = [];
    foreach ( $columns as $key => $value ) {
        $new[ $key ] = $value;
        if ( $key === 'title' ) {
            $new['altaudit_missing'] = '&#x26A0; Alt Issues';
        }
    }
    return $new;
}
add_filter( 'manage_posts_columns', 'altaudit_add_alt_text_column' );
add_filter( 'manage_pages_columns', 'altaudit_add_alt_text_column' );

/**
 * Render the column value for each row.
 *
 * @param string $column_name The column being rendered.
 * @param int    $post_id     The post ID for this row.
 */
function altaudit_render_alt_text_column( string $column_name, int $post_id ): void {
    if ( $column_name !== 'altaudit_missing' ) {
        return;
    }

    $content = get_post_field( 'post_content', $post_id );

    if ( empty( $content ) || strpos( $content, '<img' ) === false ) {
        echo '<span style="color:#aaa;">—</span>';
        return;
    }

    $missing = altaudit_scan_content_for_missing_alt( $content );
    $count   = count( $missing );

    if ( $count === 0 ) {
        echo '<span style="color:green;" title="All images have alt attributes">&#10003;</span>';
    } else {
        printf(
            '<span style="color:#d63638;font-weight:600;" title="%s">%d missing</span>',
            esc_attr( "This post contains {$count} image(s) without alt attributes." ),
            $count
        );
    }
}
add_action( 'manage_posts_custom_column', 'altaudit_render_alt_text_column', 10, 2 );
add_action( 'manage_pages_custom_column', 'altaudit_render_alt_text_column', 10, 2 );
