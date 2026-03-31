# WordPress Alt Text Audit: Code Examples, Snippets & Tools

A practical resource for WordPress developers who need to audit, fix, and maintain alt text across WordPress sites. Every snippet here runs in a real WordPress environment — no pseudocode, no filler.

---

## What This Repository Is

This repo gives you working code to:

- **Find** every image in your WordPress database that is missing alt text
- **Fix** alt text in bulk via PHP, WP-CLI, or the REST API
- **Prevent** future regressions by hooking into the save cycle
- **Integrate** correct alt text output into themes and block editor content
- **Understand** how WooCommerce, Gutenberg, and the WordPress media library each handle alt text differently

It is organized for quick copy-paste use during an audit engagement. Start with the [5-minute quick-start](#quick-start-audit-a-site-in-5-minutes) below, then drill into the section relevant to your task.

---

## Quick-Start: Audit a WordPress Site for Missing Alt Text in 5 Minutes

You need SSH or wp-cli access to the server.

### Step 1 — Count the damage (30 seconds)

```bash
wp post list \
  --post_type=attachment \
  --post_mime_type=image \
  --meta_query='[{"key":"_wp_attachment_image_alt","compare":"NOT EXISTS"}]' \
  --fields=ID,post_title,guid \
  --format=count
```

This prints a single integer: the number of image attachments with no alt text meta at all.

### Step 2 — Export the full list to CSV (1 minute)

```bash
wp eval-file snippets/find-missing-alt.php --skip-wordpress
# or run the WP-CLI commands documented in snippets/wpcli-commands.md
```

See [snippets/wpcli-commands.md](snippets/wpcli-commands.md) for the full export workflow.

### Step 3 — Inspect a sample manually (1 minute)

Open your WordPress admin, go to **Media > Library**, switch to List View, and sort by the "Alt Text" column (if your theme adds it). Spot-check 10 images to understand the pattern: Are they product shots? Blog header images? Decorative icons?

### Step 4 — Bulk update from a CSV (2 minutes)

Prepare a two-column CSV (`attachment_id,alt_text`), then run:

```bash
wp eval-file snippets/bulk-update-alt.php -- --file=alt-text-updates.csv
```

See [snippets/bulk-update-alt.php](snippets/bulk-update-alt.php) for the full script with dry-run mode.

### Step 5 — Verify

```bash
wp post list \
  --post_type=attachment \
  --post_mime_type=image \
  --meta_query='[{"key":"_wp_attachment_image_alt","compare":"NOT EXISTS"}]' \
  --fields=ID \
  --format=count
```

The number should be zero (or close to it after accounting for intentionally decorative images).

---

## Contents

### Snippets (copy-paste PHP and CLI)

| File | What It Does |
|------|-------------|
| [snippets/find-missing-alt.php](snippets/find-missing-alt.php) | Query the database for all image attachments missing alt text |
| [snippets/bulk-update-alt.php](snippets/bulk-update-alt.php) | Bulk update alt text from an array or CSV, with dry-run mode |
| [snippets/audit-on-save.php](snippets/audit-on-save.php) | Admin notice warning when a post is saved with images missing alt text |
| [snippets/wpcli-commands.md](snippets/wpcli-commands.md) | WP-CLI commands for listing, exporting, and updating alt text |
| [snippets/rest-api-example.php](snippets/rest-api-example.php) | Audit and update alt text via the WordPress REST API |

### Examples (patterns for common WordPress contexts)

| File | What It Covers |
|------|---------------|
| [examples/theme-integration.md](examples/theme-integration.md) | Correct alt text output in theme templates |
| [examples/gutenberg-blocks.md](examples/gutenberg-blocks.md) | Alt text in Image, Gallery, Cover, and Media & Text blocks |
| [examples/woocommerce-images.md](examples/woocommerce-images.md) | Product, gallery, category, and variation images in WooCommerce |

### Guides (process and strategy)

| File | What It Covers |
|------|---------------|
| [guides/audit-workflow.md](guides/audit-workflow.md) | End-to-end audit workflow: find, prioritize, write, implement, verify |
| [guides/seo-best-practices.md](guides/seo-best-practices.md) | Alt text vs title vs caption, image SEO, sitemaps, schema, CWV |

---

## Who This Is For

- WordPress developers doing accessibility audits for clients
- SEO engineers improving image search visibility
- Agency teams building repeatable audit processes
- Site owners who inherited a WordPress install with years of untagged images

---

## Requirements

- WordPress 5.0+ (REST API and block editor examples require 5.0+)
- PHP 7.4+ (all snippets use modern PHP but avoid 8.x-only syntax for compatibility)
- WP-CLI 2.x for command-line examples
- WooCommerce 6.0+ for WooCommerce-specific examples

---

## Contributing

Pull requests welcome. If you find a snippet that doesn't work on a specific WordPress version or hosting environment, open an issue with your WP version, PHP version, and the error.

---

## License

MIT. Use freely in client projects, internal tools, and open-source plugins.

---

**Automate with the Alt Audit WordPress Plugin → https://altaudit.com/wordpress-alt-text-plugin**

The Alt Audit plugin connects your WordPress site to AI-powered alt text generation. It surfaces missing alt text in the media library, lets you generate and approve descriptions in bulk, and keeps a log of every change — without leaving wp-admin.
