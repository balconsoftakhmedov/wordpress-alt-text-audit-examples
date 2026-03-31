<?php
/**
 * Bulk Update Alt Text on WordPress Image Attachments
 *
 * Accepts an array of [attachment_id => alt_text] pairs and writes the
 * _wp_attachment_image_alt postmeta value for each one. Includes:
 *
 *   - Dry-run mode (--dry-run) — logs what would change without writing anything
 *   - Safety checks — skips IDs that aren't image attachments
 *   - Per-item logging — records every success and failure
 *   - CSV import — can read from a two-column CSV file (id,alt_text)
 *
 * Usage (WP-CLI):
 *
 *   # Dry run first — always do this before a real update
 *   wp eval-file snippets/bulk-update-alt.php -- --dry-run --file=alt-updates.csv
 *
 *   # Live run
 *   wp eval-file snippets/bulk-update-alt.php -- --file=alt-updates.csv
 *
 *   # Overwrite existing alt text (default: skip attachments that already have alt text)
 *   wp eval-file snippets/bulk-update-alt.php -- --file=alt-updates.csv --overwrite
 *
 * CSV format (no header row required, but a header row is detected and skipped):
 *
 *   123,"A golden retriever puppy sitting on a green lawn"
 *   456,"Close-up of a MacBook keyboard showing the escape key"
 *   789,"Abstract blue watercolour background"
 *
 * @package AltAuditExamples
 */

/**
 * Bulk update alt text for multiple WordPress image attachments.
 *
 * @param array $updates   Associative array of attachment_id (int) => alt_text (string).
 * @param array $options {
 *     Optional settings.
 *
 *     @type bool   $dry_run    If true, log actions but do not write to the database.
 *                              Default false.
 *     @type bool   $overwrite  If true, overwrite existing non-empty alt text.
 *                              Default false (skip attachments that already have alt text).
 *     @type bool   $log        If true, return a detailed log array. Default true.
 * }
 *
 * @return array {
 *     Summary of the operation.
 *
 *     @type int   $processed  Total items attempted.
 *     @type int   $updated    Items successfully written to the database.
 *     @type int   $skipped    Items skipped (not an image, already has alt text, etc.).
 *     @type int   $errors     Items that failed due to a database error.
 *     @type array $log        Per-item log entries (only when $options['log'] is true).
 * }
 */
function altaudit_bulk_update_alt_text( array $updates, array $options = [] ): array {
    // Merge caller options with defaults.
    $opts = wp_parse_args( $options, [
        'dry_run'   => false,
        'overwrite' => false,
        'log'       => true,
    ] );

    $summary = [
        'processed' => 0,
        'updated'   => 0,
        'skipped'   => 0,
        'errors'    => 0,
        'log'       => [],
    ];

    if ( empty( $updates ) ) {
        $summary['log'][] = 'No updates provided. Nothing to do.';
        return $summary;
    }

    foreach ( $updates as $raw_id => $alt_text ) {
        $summary['processed']++;

        $attachment_id = absint( $raw_id );

        // ---------------------------------------------------------------
        // Safety check 1: ID must be a positive integer.
        // ---------------------------------------------------------------
        if ( $attachment_id <= 0 ) {
            $summary['skipped']++;
            $summary['log'][] = "[SKIP] Invalid ID: {$raw_id}";
            continue;
        }

        // ---------------------------------------------------------------
        // Safety check 2: Post must exist and be an attachment.
        // ---------------------------------------------------------------
        $post = get_post( $attachment_id );
        if ( ! $post || $post->post_type !== 'attachment' ) {
            $summary['skipped']++;
            $summary['log'][] = "[SKIP] ID {$attachment_id}: not a valid attachment.";
            continue;
        }

        // ---------------------------------------------------------------
        // Safety check 3: Attachment must be an image (not a PDF, video, etc.)
        // ---------------------------------------------------------------
        $mime = get_post_mime_type( $attachment_id );
        if ( strpos( $mime, 'image/' ) !== 0 ) {
            $summary['skipped']++;
            $summary['log'][] = "[SKIP] ID {$attachment_id}: not an image (mime: {$mime}).";
            continue;
        }

        // ---------------------------------------------------------------
        // Safety check 4: Respect the overwrite flag.
        // ---------------------------------------------------------------
        $existing_alt = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
        if ( ! $opts['overwrite'] && $existing_alt !== '' && $existing_alt !== false ) {
            $summary['skipped']++;
            $summary['log'][] = "[SKIP] ID {$attachment_id}: already has alt text (use --overwrite to replace). Current: \"{$existing_alt}\"";
            continue;
        }

        // ---------------------------------------------------------------
        // Sanitise the alt text value before storing.
        // wp_strip_all_tags removes HTML tags; sanitize_text_field trims
        // whitespace and removes invalid UTF-8 characters.
        // ---------------------------------------------------------------
        $clean_alt = sanitize_text_field( wp_strip_all_tags( (string) $alt_text ) );

        // ---------------------------------------------------------------
        // Dry-run mode: log the planned change and continue without writing.
        // ---------------------------------------------------------------
        if ( $opts['dry_run'] ) {
            $action = $existing_alt !== '' ? 'UPDATE' : 'INSERT';
            $summary['log'][] = "[DRY-RUN/{$action}] ID {$attachment_id}: \"{$clean_alt}\"";
            // Count dry-run as "would update" for the summary.
            $summary['updated']++;
            continue;
        }

        // ---------------------------------------------------------------
        // Write to the database.
        // update_post_meta creates the row if it doesn't exist, or updates
        // it if it does — no need for add_post_meta / update_post_meta logic.
        // ---------------------------------------------------------------
        $result = update_post_meta( $attachment_id, '_wp_attachment_image_alt', $clean_alt );

        if ( $result === false ) {
            // update_post_meta returns false only on a DB error (not when the
            // value is unchanged, which returns 0 — we treat 0 as success).
            $summary['errors']++;
            $summary['log'][] = "[ERROR] ID {$attachment_id}: database update failed.";
        } else {
            $summary['updated']++;
            $summary['log'][] = "[OK] ID {$attachment_id}: alt text set to \"{$clean_alt}\"";
        }
    }

    return $summary;
}


/**
 * Read a two-column CSV file into the format expected by altaudit_bulk_update_alt_text().
 *
 * The CSV may optionally have a header row. If the first column of the first
 * row cannot be cast to a positive integer, it is treated as a header and skipped.
 *
 * Expected format (with or without the header line):
 *
 *   id,alt_text
 *   123,"A golden retriever puppy sitting on a green lawn"
 *   456,"Close-up of a MacBook keyboard showing the escape key"
 *
 * @param string $file_path Absolute path to the CSV file.
 *
 * @return array|WP_Error Associative array [id => alt_text], or WP_Error on failure.
 */
function altaudit_load_csv( string $file_path ) {
    if ( ! file_exists( $file_path ) ) {
        return new WP_Error( 'file_not_found', "CSV file not found: {$file_path}" );
    }

    if ( ! is_readable( $file_path ) ) {
        return new WP_Error( 'file_not_readable', "CSV file is not readable: {$file_path}" );
    }

    $handle = fopen( $file_path, 'r' );
    if ( ! $handle ) {
        return new WP_Error( 'file_open_failed', "Could not open CSV file: {$file_path}" );
    }

    $updates    = [];
    $line_num   = 0;
    $skip_first = false; // Will be set true if we detect a header row.

    while ( ( $row = fgetcsv( $handle, 0, ',', '"', '\\' ) ) !== false ) {
        $line_num++;

        // Skip empty lines.
        if ( empty( $row ) || ( count( $row ) === 1 && trim( $row[0] ) === '' ) ) {
            continue;
        }

        // We need at least two columns.
        if ( count( $row ) < 2 ) {
            continue;
        }

        $col_id  = trim( $row[0] );
        $col_alt = trim( $row[1] );

        // Detect and skip a header row on the first non-empty line.
        if ( $line_num === 1 && ! is_numeric( $col_id ) ) {
            $skip_first = true;
            continue;
        }

        $id = (int) $col_id;
        if ( $id <= 0 ) {
            // Not a valid ID — skip silently.
            continue;
        }

        $updates[ $id ] = $col_alt;
    }

    fclose( $handle );

    return $updates;
}


// ---------------------------------------------------------------------------
// CLI runner — only executes when invoked via WP-CLI eval-file.
// ---------------------------------------------------------------------------
if ( defined( 'WP_CLI' ) && WP_CLI ) {

    // Parse arguments passed after "--" in the WP-CLI command.
    // Example: wp eval-file bulk-update-alt.php -- --dry-run --file=updates.csv --overwrite
    $cli_args = WP_CLI::get_runner()->arguments;
    $assoc    = WP_CLI::get_runner()->assoc_args ?? [];

    $dry_run   = isset( $assoc['dry-run'] ) || isset( $assoc['dry_run'] );
    $overwrite = isset( $assoc['overwrite'] );
    $file      = $assoc['file'] ?? null;

    if ( ! $file ) {
        WP_CLI::error( 'You must supply --file=path/to/updates.csv' );
    }

    // Resolve relative paths from the current working directory.
    if ( ! path_is_absolute( $file ) ) {
        $file = getcwd() . '/' . $file;
    }

    WP_CLI::log( $dry_run ? '--- DRY RUN (no changes will be written) ---' : '--- LIVE RUN ---' );
    WP_CLI::log( "Loading CSV: {$file}" );

    $updates = altaudit_load_csv( $file );

    if ( is_wp_error( $updates ) ) {
        WP_CLI::error( $updates->get_error_message() );
    }

    WP_CLI::log( 'Records loaded from CSV: ' . count( $updates ) );
    WP_CLI::log( '' );

    $result = altaudit_bulk_update_alt_text( $updates, [
        'dry_run'   => $dry_run,
        'overwrite' => $overwrite,
        'log'       => true,
    ] );

    // Print the per-item log.
    foreach ( $result['log'] as $entry ) {
        WP_CLI::log( $entry );
    }

    WP_CLI::log( '' );
    WP_CLI::log( sprintf(
        'Summary: %d processed | %d updated | %d skipped | %d errors',
        $result['processed'],
        $result['updated'],
        $result['skipped'],
        $result['errors']
    ) );

    if ( $dry_run ) {
        WP_CLI::log( '' );
        WP_CLI::log( 'Dry run complete. Re-run without --dry-run to apply changes.' );
    } else {
        WP_CLI::success( 'Bulk update complete.' );
    }
}
