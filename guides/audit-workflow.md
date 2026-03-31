# WordPress Alt Text Audit: Step-by-Step Workflow

A repeatable process for auditing and fixing alt text on an existing WordPress site. Suitable for a solo developer, a client engagement, or a recurring maintenance workflow.

Estimated time for a site with 500–2,000 images: **4–8 hours** (plus writing time for alt descriptions).

---

## Phase 0: Before You Start

**Back up the database.** The bulk update scripts modify postmeta rows. If something goes wrong, you want a clean restore point.

```bash
wp db export backup-before-alt-audit-$(date +%Y-%m-%d).sql
```

**Record the current state.** Screenshot or export the current audit numbers so you can show improvement.

```bash
wp post list \
  --post_type=attachment \
  --post_mime_type=image \
  --meta_query='[{"key":"_wp_attachment_image_alt","compare":"NOT EXISTS"}]' \
  --format=count
```

Save this number. It's your baseline.

---

## Phase 1: Database Query — Find All Issues

### 1.1 Get the full scope

```bash
wp eval-file snippets/find-missing-alt.php
```

This prints a breakdown by image type and a table of all affected images. Pipe to a file if there are more than 50 results:

```bash
wp eval-file snippets/find-missing-alt.php > audit-raw-$(date +%Y-%m-%d).txt 2>&1
```

### 1.2 Export to CSV for writing

```bash
wp post list \
  --post_type=attachment \
  --post_mime_type=image \
  --meta_query='[{"key":"_wp_attachment_image_alt","compare":"NOT EXISTS"}]' \
  --fields=ID,post_title,guid,post_date \
  --format=csv \
  > images-missing-alt.csv
```

Open `images-missing-alt.csv` in Google Sheets or Excel.

### 1.3 Also catch empty-string alt text

The above query finds rows where `_wp_attachment_image_alt` doesn't exist. A second query finds rows that exist but contain only an empty string:

```bash
wp post list \
  --post_type=attachment \
  --post_mime_type=image \
  --meta_key=_wp_attachment_image_alt \
  --meta_value='' \
  --fields=ID,post_title,guid,post_date \
  --format=csv \
  >> images-missing-alt.csv
```

---

## Phase 2: Prioritize by Traffic and Importance

Not all images are equal. A missing alt on the homepage hero image is higher priority than a missing alt on an image in a draft post from 2019.

### 2.1 Prioritization criteria

| Priority | Image type | Why |
|----------|-----------|-----|
| P1 – Fix first | Homepage and landing page hero images | Highest traffic, most visible |
| P1 | Product main images (WooCommerce) | Google Images, Google Shopping, accessibility |
| P2 | Blog post featured images on high-traffic posts | SEO impact |
| P2 | Images on pages with significant organic traffic | Use Google Search Console to identify |
| P3 | All other post/page inline images | |
| P3 | Category and archive images | |
| P4 | Images in low-traffic posts, drafts, older content | |
| Defer | Intentionally decorative images | Set alt="" programmatically |

### 2.2 Identify high-traffic pages

Cross-reference your image list with Google Search Console or Google Analytics:

1. Export top 100 pages by traffic from GA or GSC.
2. For each URL, identify the featured image ID: `wp post get $(wp post url-to-id {url}) --field=_thumbnail_id`
3. Mark those attachment IDs as P1 in your spreadsheet.

### 2.3 Google Sheets template structure

Set up your CSV with these columns:

| Column | Content |
|--------|---------|
| `id` | WordPress attachment ID |
| `post_title` | Filename/title from the media library |
| `guid` | Full URL to the original image file |
| `post_date` | Upload date |
| `priority` | P1 / P2 / P3 / P4 |
| `context` | Where is this image used? (e.g., "Homepage hero", "Post 512 header") |
| `alt_text` | **The field you fill in** |
| `status` | Blank → Drafted → Approved → Done |

Share the sheet with the client or content team. They often know the context of images better than the developer does.

---

## Phase 3: Write the Alt Text

### 3.1 Writing guidelines

- **Describe what is in the image**, not what you want it to mean.
- **Include purpose when relevant**: "Before photo: cracked basement wall" not just "cracked wall".
- **Keep it under 125 characters** — screen readers can truncate longer strings.
- **Do not start with "Image of" or "Photo of"** — the user's screen reader already announces it as an image.
- **Include relevant details**: color, quantity, brand, action, setting.
- **Match the tone of the surrounding content**: a playful brand can have playful alt text.

### 3.2 Patterns by image type

```
Product image:
[Brand name] [Product name] – [color/variant] – [view/angle]
Example: "Acme Widget Pro 2000 – matte red – front view showing USB-C port"

Blog post hero:
[Subject/action] – [context if useful]
Example: "Developer reviewing code on a MacBook in a coffee shop"

Team photo:
[Person name], [role/title] (if appropriate for accessibility)
Example: "Sarah Johnson, Head of Engineering, smiling at a conference table"

Infographic:
[What the infographic shows, in one sentence]
Example: "Bar chart showing 68% of WordPress sites fail WCAG 2.1 image accessibility requirements"
— Then provide a long description in an adjacent paragraph or aria-describedby.

Decorative (divider, texture, icon that repeats adjacent text):
Set alt="" (empty string, not omitted).
```

### 3.3 Handling images you cannot identify

Some old uploads have filenames like `IMG_4892.jpg` with no context. Options:

1. Ask the content team — they uploaded it.
2. Reverse image search to identify the subject.
3. Use an AI image description tool (see footer).
4. If you genuinely cannot determine what the image shows and it appears to be decorative, set `alt=""`.

---

## Phase 4: Implement the Changes

### 4.1 Prepare the import CSV

Once the spreadsheet is filled in and approved, export the `id` and `alt_text` columns as a CSV:

```
id,alt_text
4821,"Acme Widget Pro 2000 – matte red – front view showing USB-C port"
4808,"Sarah Johnson Head of Engineering smiling at a conference table"
4791,"Bar chart showing 68 percent of WordPress sites fail WCAG 2.1 image accessibility"
```

Save as `alt-text-approved.csv`.

### 4.2 Dry run

**Always dry run first.**

```bash
wp eval-file snippets/bulk-update-alt.php -- --dry-run --file=alt-text-approved.csv
```

Review the output. Confirm the IDs look right. Confirm no SKIP or ERROR lines for unexpected reasons.

### 4.3 Live run

```bash
wp eval-file snippets/bulk-update-alt.php -- --file=alt-text-approved.csv
```

Log the output:

```bash
wp eval-file snippets/bulk-update-alt.php -- --file=alt-text-approved.csv | tee bulk-update-log-$(date +%Y-%m-%d).txt
```

### 4.4 Handle Gutenberg Image blocks

If the site uses the block editor and images were inserted as Image blocks, the postmeta update does not automatically update the alt text attribute stored in `post_content`. Check whether the block markup needs updating:

```bash
# Check if any posts still have wp-image-XXXX without an alt attribute
# (simplified grep — use find-missing-alt.php for a full audit)
wp post list --post_type=post,page --fields=ID --format=ids | xargs -I{} wp post get {} --field=post_content | grep -oP 'wp-image-\K[0-9]+' | sort -u > block-image-ids.txt
```

For each ID in the list, run `altaudit_update_image_block_alt()` from the Gutenberg guide.

---

## Phase 5: Verify

### 5.1 Re-run the database check

```bash
# Should return 0 (or close to it)
wp post list \
  --post_type=attachment \
  --post_mime_type=image \
  --meta_query='[{"key":"_wp_attachment_image_alt","compare":"NOT EXISTS"}]' \
  --format=count

# Also check for empty-string values
wp post list \
  --post_type=attachment \
  --post_mime_type=image \
  --meta_key=_wp_attachment_image_alt \
  --meta_value='' \
  --format=count
```

### 5.2 Spot-check in the browser

Visit 5–10 pages and use browser DevTools to inspect `<img>` elements. Confirm the `alt` attributes contain your new descriptions.

### 5.3 Accessibility scanner

Run a free accessibility scan using a browser extension (e.g. axe DevTools, WAVE) on the homepage, a product page, and a blog post. Images with empty or missing alt text will appear as errors.

### 5.4 Google Search Console

Image SEO improvements take 2–6 weeks to index. Set a calendar reminder to check GSC → Search results → Image search in 4 weeks. Look for increased impressions and clicks.

---

## Phase 6: Prevent Regressions

Install `snippets/audit-on-save.php` to warn editors when they save posts containing images without alt text. See that file for full instructions.

Add a recurring audit to your maintenance checklist:

```bash
# Monthly check — add to a cron job or maintenance script
wp post list \
  --post_type=attachment \
  --post_mime_type=image \
  --date_query='[{"after":"30 days ago"}]' \
  --meta_query='[{"key":"_wp_attachment_image_alt","compare":"NOT EXISTS"}]' \
  --fields=ID,post_title,post_date \
  --format=table
```

---

## Timeline Estimate

| Phase | Time (500-image site) | Time (5,000-image site) |
|-------|-----------------------|-------------------------|
| Phase 0: Backup & baseline | 15 min | 30 min |
| Phase 1: Export & query | 30 min | 45 min |
| Phase 2: Prioritize | 1 hour | 2 hours |
| Phase 3: Write alt text (you) | 2–4 hours | 10–20 hours |
| Phase 3: Write alt text (client) | Add 1–2 weeks | Add 2–4 weeks |
| Phase 4: Implement | 30 min | 1 hour |
| Phase 5: Verify | 30 min | 1 hour |
| **Total (developer time)** | **~5 hours** | **~25 hours** |

For sites with thousands of images, AI-assisted alt text generation cuts Phase 3 significantly. See [Alt Audit](https://altaudit.com/wordpress-alt-text-plugin) for automated generation within the WordPress media library.
