<?php
/**
 * WordPress REST API — Alt Text Audit and Update Examples
 *
 * Demonstrates how to use the WordPress REST API to:
 *   1. Retrieve all image attachments and check their alt text
 *   2. Filter for attachments with missing or empty alt text
 *   3. Paginate through large media libraries
 *   4. Update alt text on individual attachments via PATCH
 *   5. Run a full audit loop from PHP (e.g. in a script or admin tool)
 *
 * The code is self-contained vanilla PHP using wp_remote_get() / wp_remote_post().
 * No external HTTP libraries are required. Designed to be run in a WordPress context
 * (e.g. a custom WP-CLI command, a one-time admin page, or a background job).
 *
 * Authentication: Application Passwords (introduced in WordPress 5.6).
 * Generate one at: Users > Your Profile > Application Passwords.
 *
 * @package AltAuditExamples
 */


// ---------------------------------------------------------------------------
// Configuration — edit these values before running.
// ---------------------------------------------------------------------------

/**
 * Your WordPress site base URL. No trailing slash.
 *
 * @var string
 */
const ALTAUDIT_SITE_URL = 'https://example.com';

/**
 * WordPress username for the application password owner.
 * The user must have the 'upload_files' capability (Editor or Administrator).
 *
 * @var string
 */
const ALTAUDIT_USER = 'your_username';

/**
 * Application Password generated in WordPress admin.
 * Format: "xxxx xxxx xxxx xxxx xxxx xxxx" (spaces are fine — WordPress handles them).
 *
 * @var string
 */
const ALTAUDIT_APP_PASSWORD = 'xxxx xxxx xxxx xxxx xxxx xxxx';

/**
 * How many attachments to fetch per page.
 * WordPress REST API maximum is 100 per request.
 *
 * @var int
 */
const ALTAUDIT_PER_PAGE = 100;


// ---------------------------------------------------------------------------
// Core Functions
// ---------------------------------------------------------------------------

/**
 * Build the Authorization header value for HTTP Basic Auth.
 *
 * WordPress Application Passwords use Basic Auth with the username and
 * app password as credentials.
 *
 * @return string Base64-encoded "user:password" string ready for the header.
 */
function altaudit_rest_auth_header(): string {
    return 'Basic ' . base64_encode( ALTAUDIT_USER . ':' . ALTAUDIT_APP_PASSWORD );
}


/**
 * Fetch one page of image attachments from the REST API.
 *
 * Endpoint: GET /wp-json/wp/v2/media
 * Parameters:
 *   - media_type=image  — only return image attachments
 *   - per_page          — items per page (max 100)
 *   - page              — page number (1-indexed)
 *   - _fields           — limit the fields returned to reduce payload size
 *
 * @param int $page     Page number to fetch (starts at 1).
 * @param int $per_page Number of items per page.
 *
 * @return array|WP_Error {
 *     @type array $items      Array of attachment objects from the API.
 *     @type int   $total      Total number of matching attachments (from X-WP-Total header).
 *     @type int   $total_pages Total pages available (from X-WP-TotalPages header).
 * }
 */
function altaudit_rest_get_media_page( int $page = 1, int $per_page = ALTAUDIT_PER_PAGE ) {
    // Only request the fields we actually need — keeps the response small.
    $fields = implode( ',', [
        'id',
        'title',
        'source_url',
        'alt_text',
        'date',
        'mime_type',
        'link',
    ] );

    $url = add_query_arg( [
        'media_type' => 'image',
        'per_page'   => $per_page,
        'page'       => $page,
        '_fields'    => $fields,
        'orderby'    => 'date',
        'order'      => 'desc',
    ], ALTAUDIT_SITE_URL . '/wp-json/wp/v2/media' );

    $response = wp_remote_get( $url, [
        'headers' => [
            'Authorization' => altaudit_rest_auth_header(),
            'Accept'        => 'application/json',
        ],
        'timeout' => 30,
    ] );

    if ( is_wp_error( $response ) ) {
        return $response;
    }

    $status = wp_remote_retrieve_response_code( $response );
    if ( $status !== 200 ) {
        $body = wp_remote_retrieve_body( $response );
        return new WP_Error(
            'rest_api_error',
            "REST API returned HTTP {$status}",
            [ 'body' => $body ]
        );
    }

    $body  = wp_remote_retrieve_body( $response );
    $items = json_decode( $body, true );

    if ( ! is_array( $items ) ) {
        return new WP_Error( 'json_decode_error', 'Could not decode JSON response.' );
    }

    // Extract pagination totals from response headers.
    $headers     = wp_remote_retrieve_headers( $response );
    $total       = (int) ( $headers['x-wp-total'] ?? 0 );
    $total_pages = (int) ( $headers['x-wp-totalpages'] ?? 1 );

    return [
        'items'       => $items,
        'total'       => $total,
        'total_pages' => $total_pages,
    ];
}


/**
 * Fetch ALL image attachments by paginating through the REST API automatically.
 *
 * On sites with thousands of images this may take several seconds.
 * Consider adding a progress callback for long-running scripts.
 *
 * @param callable|null $progress_callback Optional. Called after each page with
 *                                          ($current_page, $total_pages, $items_so_far).
 *
 * @return array|WP_Error Flat array of all attachment objects, or WP_Error on failure.
 */
function altaudit_rest_get_all_media( callable $progress_callback = null ) {
    $all_items  = [];
    $page       = 1;
    $total_pages = null;

    do {
        $result = altaudit_rest_get_media_page( $page );

        if ( is_wp_error( $result ) ) {
            return $result; // Bubble up errors.
        }

        $all_items   = array_merge( $all_items, $result['items'] );
        $total_pages = $result['total_pages'];

        if ( is_callable( $progress_callback ) ) {
            call_user_func( $progress_callback, $page, $total_pages, count( $all_items ) );
        }

        $page++;

    } while ( $page <= $total_pages );

    return $all_items;
}


/**
 * Filter an array of REST API attachment objects to only those missing alt text.
 *
 * The REST API returns alt_text as an empty string when not set, never as null.
 * We treat both '' and whitespace-only strings as "missing".
 *
 * @param array $attachments Array of attachment objects from altaudit_rest_get_all_media().
 *
 * @return array Filtered array containing only attachments with empty alt_text.
 */
function altaudit_rest_filter_missing_alt( array $attachments ): array {
    return array_values(
        array_filter( $attachments, function( $item ) {
            return trim( (string) ( $item['alt_text'] ?? '' ) ) === '';
        } )
    );
}


/**
 * Update the alt text of a single attachment via the REST API.
 *
 * Endpoint: POST /wp-json/wp/v2/media/{id} (WordPress REST API uses POST for updates,
 * not PATCH, despite the spec — both work but POST is more widely supported).
 *
 * @param int    $attachment_id The WordPress attachment post ID.
 * @param string $alt_text      The new alt text value. Will be sanitized server-side
 *                               by WordPress, but sanitize here too for safety.
 *
 * @return array|WP_Error The updated attachment object from the API, or WP_Error on failure.
 */
function altaudit_rest_update_alt_text( int $attachment_id, string $alt_text ) {
    if ( $attachment_id <= 0 ) {
        return new WP_Error( 'invalid_id', 'Attachment ID must be a positive integer.' );
    }

    $url = ALTAUDIT_SITE_URL . '/wp-json/wp/v2/media/' . $attachment_id;

    $response = wp_remote_post( $url, [
        'method'  => 'POST', // WordPress REST API accepts POST for updates.
        'headers' => [
            'Authorization' => altaudit_rest_auth_header(),
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
        ],
        'body'    => wp_json_encode( [
            'alt_text' => sanitize_text_field( $alt_text ),
        ] ),
        'timeout' => 15,
    ] );

    if ( is_wp_error( $response ) ) {
        return $response;
    }

    $status = wp_remote_retrieve_response_code( $response );
    if ( $status !== 200 ) {
        $body = wp_remote_retrieve_body( $response );
        return new WP_Error(
            'rest_update_error',
            "REST API returned HTTP {$status} when updating attachment {$attachment_id}",
            [ 'body' => $body ]
        );
    }

    $body   = wp_remote_retrieve_body( $response );
    $result = json_decode( $body, true );

    if ( ! is_array( $result ) ) {
        return new WP_Error( 'json_decode_error', 'Could not decode JSON response from update.' );
    }

    return $result;
}


/**
 * Bulk update alt text for multiple attachments via the REST API.
 *
 * Adds a small delay between requests to avoid overwhelming the server.
 * On a busy shared host, increase $delay_ms. On a dedicated server, 0 is fine.
 *
 * @param array $updates   Associative array of [attachment_id => alt_text].
 * @param int   $delay_ms  Milliseconds to wait between API calls. Default 200ms.
 *
 * @return array {
 *     @type int   $updated  Number of successful updates.
 *     @type int   $errors   Number of failures.
 *     @type array $log      Per-item result log.
 * }
 */
function altaudit_rest_bulk_update( array $updates, int $delay_ms = 200 ): array {
    $summary = [
        'updated' => 0,
        'errors'  => 0,
        'log'     => [],
    ];

    foreach ( $updates as $id => $alt_text ) {
        $result = altaudit_rest_update_alt_text( (int) $id, (string) $alt_text );

        if ( is_wp_error( $result ) ) {
            $summary['errors']++;
            $summary['log'][] = [
                'id'      => $id,
                'status'  => 'error',
                'message' => $result->get_error_message(),
            ];
        } else {
            $summary['updated']++;
            $summary['log'][] = [
                'id'       => $id,
                'status'   => 'ok',
                'alt_text' => $result['alt_text'] ?? '',
            ];
        }

        // Throttle requests to be a good citizen on shared hosting.
        if ( $delay_ms > 0 ) {
            usleep( $delay_ms * 1000 );
        }
    }

    return $summary;
}


// ---------------------------------------------------------------------------
// Example: Full Audit Script
// Run via WP-CLI: wp eval-file snippets/rest-api-example.php
// ---------------------------------------------------------------------------
if ( defined( 'WP_CLI' ) && WP_CLI ) {

    WP_CLI::log( 'Starting REST API alt text audit...' );
    WP_CLI::log( 'Site: ' . ALTAUDIT_SITE_URL );
    WP_CLI::log( '' );

    // Step 1: Fetch all media with a progress indicator.
    $all_media = altaudit_rest_get_all_media( function( $page, $total_pages, $count ) {
        WP_CLI::log( "  Fetched page {$page}/{$total_pages} ({$count} items so far)..." );
    } );

    if ( is_wp_error( $all_media ) ) {
        WP_CLI::error( $all_media->get_error_message() );
    }

    $total_fetched = count( $all_media );
    WP_CLI::log( '' );
    WP_CLI::log( "Total images fetched: {$total_fetched}" );

    // Step 2: Filter for missing alt text.
    $missing = altaudit_rest_filter_missing_alt( $all_media );
    $count   = count( $missing );

    WP_CLI::log( "Images missing alt text: {$count}" );
    WP_CLI::log( '' );

    if ( $count === 0 ) {
        WP_CLI::success( 'All images have alt text. Nothing to do.' );
        return;
    }

    // Step 3: Print the findings.
    WP_CLI\Utils\format_items(
        'table',
        array_map( function( $item ) {
            return [
                'ID'       => $item['id'],
                'Title'    => substr( $item['title']['rendered'] ?? '', 0, 40 ),
                'Uploaded' => date( 'Y-m-d', strtotime( $item['date'] ) ),
                'URL'      => substr( $item['source_url'], -60 ),
            ];
        }, array_slice( $missing, 0, 20 ) ),
        [ 'ID', 'Title', 'Uploaded', 'URL' ]
    );

    if ( $count > 20 ) {
        WP_CLI::log( "... and " . ( $count - 20 ) . " more." );
    }

    WP_CLI::log( '' );

    // Step 4: Demonstrate a single update.
    // In a real script you'd read alt text from a CSV or AI service here.
    $first   = $missing[0];
    $test_id = $first['id'];

    WP_CLI::log( "Demo: updating alt text on attachment ID {$test_id}..." );

    $update_result = altaudit_rest_update_alt_text(
        $test_id,
        'Example alt text set via REST API by alt text audit script'
    );

    if ( is_wp_error( $update_result ) ) {
        WP_CLI::warning( 'Update failed: ' . $update_result->get_error_message() );
    } else {
        WP_CLI::success( 'Updated. New alt_text: "' . ( $update_result['alt_text'] ?? '' ) . '"' );
    }
}
