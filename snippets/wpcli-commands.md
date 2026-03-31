# WP-CLI Commands for Alt Text Auditing

All commands require WP-CLI 2.x and must be run from the WordPress root directory (where `wp-config.php` lives), or with `--path=/path/to/wordpress` appended.

---

## 1. Count All Images Missing Alt Text

Get a fast integer count before doing anything else.

```bash
wp post list \
  --post_type=attachment \
  --post_mime_type=image \
  --meta_query='[{"key":"_wp_attachment_image_alt","compare":"NOT EXISTS"}]' \
  --format=count
```

**Example output:**
```
247
```

This only catches attachments with no `_wp_attachment_image_alt` row at all. To also catch rows that exist but are empty, use the `eval-file` command in section 2.

---

## 2. List All Images Missing Alt Text (Table View)

```bash
wp eval-file snippets/find-missing-alt.php
```

**Example output:**
```
Scanning for image attachments missing alt text...

Missing alt text by image type:
  image/jpeg             198
  image/png               37
  image/webp              12

Found 247 image(s) missing alt text:

+-------+--------------------------+------------+---------------------------------------------------------------+
| ID    | Filename                 | Uploaded   | URL                                                           |
+-------+--------------------------+------------+---------------------------------------------------------------+
| 4821  | hero-banner.jpg          | 2024-11-03 | https://example.com/wp-content/uploads/2024/11/hero.jpg       |
| 4808  | team-photo-2024.jpg      | 2024-10-22 | https://example.com/wp-content/uploads/2024/10/team.jpg       |
| 4791  | product-red-widget.png   | 2024-10-15 | https://example.com/wp-content/uploads/2024/10/widget.png     |
+-------+--------------------------+------------+---------------------------------------------------------------+

Audit complete. 247 image(s) need alt text.
```

---

## 3. Export Missing Alt Text to CSV

Use `wp post list` with `--format=csv` to export a CSV you can open in Google Sheets or Excel to write alt text.

```bash
wp post list \
  --post_type=attachment \
  --post_mime_type=image \
  --meta_query='[{"key":"_wp_attachment_image_alt","compare":"NOT EXISTS"}]' \
  --fields=ID,post_title,guid,post_date \
  --format=csv \
  > missing-alt-$(date +%Y-%m-%d).csv
```

**Example output (missing-alt-2025-06-01.csv):**
```csv
ID,post_title,guid,post_date
4821,hero-banner,https://example.com/wp-content/uploads/2024/11/hero.jpg,2024-11-03 09:12:44
4808,team-photo-2024,https://example.com/wp-content/uploads/2024/10/team.jpg,2024-10-22 14:33:01
4791,product-red-widget,https://example.com/wp-content/uploads/2024/10/widget.png,2024-10-15 11:05:22
```

Open the CSV, add a fifth column `alt_text`, write your descriptions, then save a two-column CSV (ID + alt_text) for import.

---

## 4. Bulk Update Alt Text from CSV (Dry Run First)

Always dry-run before applying changes.

```bash
# Step 1: dry run
wp eval-file snippets/bulk-update-alt.php -- --dry-run --file=alt-updates.csv

# Step 2: live run
wp eval-file snippets/bulk-update-alt.php -- --file=alt-updates.csv
```

**CSV format (`alt-updates.csv`):**
```csv
id,alt_text
4821,"Homepage hero banner showing a team of web developers collaborating around a laptop"
4808,"The Alt Audit team at their 2024 company offsite in Barcelona"
4791,"Red widget product photo on white background"
```

**Example dry-run output:**
```
--- DRY RUN (no changes will be written) ---
Loading CSV: /var/www/html/alt-updates.csv
Records loaded from CSV: 3

[DRY-RUN/INSERT] ID 4821: "Homepage hero banner showing a team of web developers collaborating around a laptop"
[DRY-RUN/INSERT] ID 4808: "The Alt Audit team at their 2024 company offsite in Barcelona"
[DRY-RUN/INSERT] ID 4791: "Red widget product photo on white background"

Summary: 3 processed | 3 updated | 0 skipped | 0 errors

Dry run complete. Re-run without --dry-run to apply changes.
```

**Example live-run output:**
```
--- LIVE RUN ---
Loading CSV: /var/www/html/alt-updates.csv
Records loaded from CSV: 3

[OK] ID 4821: alt text set to "Homepage hero banner showing a team of web developers collaborating around a laptop"
[OK] ID 4808: alt text set to "The Alt Audit team at their 2024 company offsite in Barcelona"
[OK] ID 4791: alt text set to "Red widget product photo on white background"

Summary: 3 processed | 3 updated | 0 skipped | 0 errors
Success: Bulk update complete.
```

---

## 5. Update a Single Attachment's Alt Text

For quick one-off updates:

```bash
wp post meta update 4821 _wp_attachment_image_alt "Homepage hero: team of developers around a laptop"
```

**Example output:**
```
Success: Updated custom field '_wp_attachment_image_alt'.
```

Verify it was set correctly:

```bash
wp post meta get 4821 _wp_attachment_image_alt
```

**Example output:**
```
Homepage hero: team of developers around a laptop
```

---

## 6. Check All Images on a Specific Post

List all image attachment IDs referenced in a specific post's content, then check their alt text:

```bash
# Get the post content and extract attachment IDs from class="wp-image-XXXX"
wp post get 512 --field=post_content | grep -oP 'wp-image-\K[0-9]+'
```

**Example output:**
```
4821
4808
```

Then check each one:

```bash
for id in 4821 4808; do
  alt=$(wp post meta get $id _wp_attachment_image_alt 2>/dev/null)
  echo "ID $id: ${alt:-(MISSING)}"
done
```

**Example output:**
```
ID 4821: Homepage hero: team of developers around a laptop
ID 4808: (MISSING)
```

---

## 7. Check Images Uploaded in the Last 30 Days

Narrow the audit to recent uploads only — useful for ongoing monitoring.

```bash
wp post list \
  --post_type=attachment \
  --post_mime_type=image \
  --date_query='[{"after":"30 days ago","inclusive":true}]' \
  --meta_query='[{"key":"_wp_attachment_image_alt","compare":"NOT EXISTS"}]' \
  --fields=ID,post_title,post_date \
  --format=table
```

**Example output:**
```
+------+---------------------------+---------------------+
| ID   | post_title                | post_date           |
+------+---------------------------+---------------------+
| 5102 | march-team-photo          | 2025-05-28 10:14:22 |
| 5089 | new-product-launch-banner | 2025-05-21 08:55:11 |
+------+---------------------------+---------------------+
```

---

## 8. Regenerate Thumbnails After a Bulk Alt Text Update

Not required for alt text changes, but useful if you're also changing image metadata:

```bash
wp media regenerate --yes
```

---

## 9. Verify the Audit Is Clean

After running your bulk update, confirm zero remaining gaps:

```bash
wp post list \
  --post_type=attachment \
  --post_mime_type=image \
  --meta_query='[{"key":"_wp_attachment_image_alt","compare":"NOT EXISTS"}]' \
  --format=count
```

Target: `0`

Also check for rows that exist but are empty (a different condition):

```bash
wp post list \
  --post_type=attachment \
  --post_mime_type=image \
  --meta_key=_wp_attachment_image_alt \
  --meta_value='' \
  --meta_compare='=' \
  --fields=ID,post_title \
  --format=count
```

Both should return `0` when the audit is complete.

---

## Tips

- **Multisite:** Add `--url=https://yoursubsite.com` to target a specific subsite.
- **Large databases:** Add `--posts_per_page=100` and paginate with `--paged=2`, `--paged=3`, etc. to avoid memory exhaustion.
- **SSH tunnels:** If you can't run WP-CLI directly, pipe commands over SSH: `ssh user@host "cd /var/www && wp post list --format=count ..."`
- **Cron-based monitoring:** Add the count command to a cron job and alert when the result is greater than 0.
