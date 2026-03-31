<?php
/**
 * Find WordPress Image Attachments Missing Alt Text
 *
 * Queries the WordPress database for all image attachments that either:
 *   1. Have no _wp_attachment_image_alt postmeta row at all, OR
 *   2. Have the row but the value is an empty string
 *
 * Usage:
 *   // As a standalone script loaded via WP-CLI:
 *   //   wp eval-file snippets/find-missing-alt.php
 *
 *   // Or include in a plugin/theme and call the function directly:
 *   $results = altaudit_find_images_missing_alt();
 *   foreach ( $results as $item ) {
 *       echo $item['id'] . ' | ' . $item['url'] . ' | ' . $item['filename'] . "\n";
 *   }
 *
 * Requirements: WordPress must be loaded (global $wpdb must be available).
 *
 * @package AltAuditExamples
 */

/**
 * Returns an array of image attachments that are missing alt text.
 *
 * Each item in the returned array contains:
 *   - id       (int)    The attachment post ID
 *   - url      (string) The full URL to the original image file
 *   - filename (string) Just the filename (e.g. "hero-image.jpg")
 *   - title    (string) The attachment post title (often the filename without extension)
 *   - date     (string) The post date, useful for prioritising recent uploads
 *
 * @param array $args {
 *     Optional. Override default query behaviour.
 *
 *     @type int    $limit       Max number of results to return. Default 500.
 *                               Set to -1 to return all (careful on large sites).
 *     @type string $mime_type   Limit to a specific MIME type group.
 *                               Default 'image' (matches image/jpeg, image/png, etc.)
 *     @type string $order       'ASC' or 'DESC'. Default 'DESC' (newest first).
 *     @type string $orderby     Any valid WP_Query orderby value. Default 'date'.
 * }
 *
 * @return array Array of associative arrays, each describing one attachment.
 *               Returns an empty array if no attachments are missing alt text.
 */
function altaudit_find_images_missing_alt( array $args = [] ): array {
    global $wpdb;

    // Merge caller-supplied options with sensible defaults.
    $options = wp_parse_args( $args, [
        'limit'     => 500,
        'mime_type' => 'image',
        'order'     => 'DESC',
        'orderby'   => 'date',
    ] );

    // Sanitise the ORDER BY direction — only allow ASC or DESC.
    $order = strtoupper( $options['order'] ) === 'ASC' ? 'ASC' : 'DESC';

    // Map the public orderby option to a real column name.
    $allowed_orderby = [
        'date'     => 'p.post_date',
        'title'    => 'p.post_title',
        'id'       => 'p.ID',
        'modified' => 'p.post_modified',
    ];
    $orderby_column = $allowed_orderby[ $options['orderby'] ] ?? 'p.post_date';

    // Build the LIMIT clause. -1 means no limit.
    $limit_clause = '';
    if ( (int) $options['limit'] > 0 ) {
        $limit_clause = $wpdb->prepare( 'LIMIT %d', (int) $options['limit'] );
    }

    /*
     * Strategy: LEFT JOIN the postmeta table on the alt text key.
     * Rows where the join produces NULL (key doesn't exist) OR where
     * meta_value is an empty string are both treated as "missing".
     *
     * This single query is faster than two separate WP_Query calls
     * for sites with thousands of media attachments.
     */
    $mime_like = $wpdb->esc_like( $options['mime_type'] ) . '%';

    // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    // The ORDER BY column and direction are sanitised above; they cannot
    // be passed as %s placeholders because prepare() wraps strings in quotes.
    $sql = $wpdb->prepare(
        "
        SELECT
            p.ID                AS id,
            p.guid              AS url,
            p.post_title        AS title,
            p.post_date         AS date,
            pm.meta_value       AS alt_text
        FROM {$wpdb->posts} AS p
        LEFT JOIN {$wpdb->postmeta} AS pm
            ON pm.post_id  = p.ID
            AND pm.meta_key = '_wp_attachment_image_alt'
        WHERE
            p.post_type   = 'attachment'
            AND p.post_status  = 'inherit'
            AND p.post_mime_type LIKE %s
            AND (
                pm.meta_id    IS NULL          -- no alt text row at all
                OR pm.meta_value = ''          -- row exists but value is empty
            )
        ORDER BY {$orderby_column} {$order}
        {$limit_clause}
        ",
        $mime_like
    );
    // phpcs:enable

    $rows = $wpdb->get_results( $sql, ARRAY_A ); // ARRAY_A = associative array

    if ( empty( $rows ) ) {
        return [];
    }

    // Enrich each row with the bare filename, extracted from the URL.
    $results = [];
    foreach ( $rows as $row ) {
        $results[] = [
            'id'       => (int) $row['id'],
            'url'      => $row['url'],
            'filename' => basename( $row['url'] ),
            'title'    => $row['title'],
            'date'     => $row['date'],
            // alt_text will be NULL (never set) or '' (set but empty).
            // Normalise to empty string for consistent downstream handling.
            'alt_text' => (string) $row['alt_text'],
        ];
    }

    return $results;
}


/**
 * Returns a summary count broken down by image MIME type.
 *
 * Useful at the start of an audit to understand the scope.
 *
 * Example output:
 *   [
 *       'image/jpeg' => 142,
 *       'image/png'  => 38,
 *       'image/webp' => 12,
 *       'image/gif'  => 3,
 *   ]
 *
 * @return array Associative array of mime_type => count.
 */
function altaudit_missing_alt_count_by_type(): array {
    global $wpdb;

    $rows = $wpdb->get_results(
        "
        SELECT
            p.post_mime_type AS mime_type,
            COUNT(*)         AS total
        FROM {$wpdb->posts} AS p
        LEFT JOIN {$wpdb->postmeta} AS pm
            ON pm.post_id  = p.ID
            AND pm.meta_key = '_wp_attachment_image_alt'
        WHERE
            p.post_type      = 'attachment'
            AND p.post_status = 'inherit'
            AND p.post_mime_type LIKE 'image%'
            AND (
                pm.meta_id    IS NULL
                OR pm.meta_value = ''
            )
        GROUP BY p.post_mime_type
        ORDER BY total DESC
        ",
        ARRAY_A
    );

    if ( empty( $rows ) ) {
        return [];
    }

    $counts = [];
    foreach ( $rows as $row ) {
        $counts[ $row['mime_type'] ] = (int) $row['total'];
    }

    return $counts;
}


// ---------------------------------------------------------------------------
// CLI runner — only executes when this file is invoked directly via WP-CLI.
// When included in plugin code, the functions above are available but this
// section is skipped.
// ---------------------------------------------------------------------------
if ( defined( 'WP_CLI' ) && WP_CLI ) {

    WP_CLI::log( 'Scanning for image attachments missing alt text...' );
    WP_CLI::log( '' );

    // Print a type breakdown first.
    $counts = altaudit_missing_alt_count_by_type();
    if ( empty( $counts ) ) {
        WP_CLI::success( 'No images are missing alt text. Nothing to do.' );
        return;
    }

    WP_CLI::log( 'Missing alt text by image type:' );
    foreach ( $counts as $mime => $count ) {
        WP_CLI::log( sprintf( '  %-20s %d', $mime, $count ) );
    }
    WP_CLI::log( '' );

    // Fetch the actual records.
    $missing = altaudit_find_images_missing_alt( [ 'limit' => -1 ] );
    $total   = count( $missing );

    WP_CLI::log( "Found {$total} image(s) missing alt text:" );
    WP_CLI::log( '' );

    // Format as a table for readability in the terminal.
    $table_data = array_map( function( $item ) {
        return [
            'ID'       => $item['id'],
            'Filename' => $item['filename'],
            'Uploaded' => date( 'Y-m-d', strtotime( $item['date'] ) ),
            'URL'      => $item['url'],
        ];
    }, $missing );

    // WP_CLI\Utils\format_items prints a table to stdout.
    \WP_CLI\Utils\format_items( 'table', $table_data, [ 'ID', 'Filename', 'Uploaded', 'URL' ] );

    WP_CLI::log( '' );
    WP_CLI::success( "Audit complete. {$total} image(s) need alt text." );
    WP_CLI::log( 'Next step: populate alt text and run snippets/bulk-update-alt.php' );
}
