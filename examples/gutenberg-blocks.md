# Alt Text in Gutenberg Blocks

The block editor handles alt text differently depending on the block type. Some blocks have a dedicated alt text field in the sidebar. Others inherit it from the media library. A few have no alt text mechanism at all — and those need special attention.

---

## 1. Image Block

The Image block is the most straightforward. It has a dedicated "Alt text (alternative text)" field in the block sidebar under Settings > Image settings.

**Setting alt text in the editor:**

1. Click the image to select the block.
2. In the right sidebar, click **Block** tab.
3. Scroll to **Image settings**.
4. Fill in the "Alt text" field.

When you leave the field empty and check "Mark as decorative," the block outputs `alt=""` — correct for ornamental images.

**How the Image block stores alt text:**

The alt text is stored directly in the block markup in `post_content`:

```html
<!-- wp:image {"id":4821} -->
<figure class="wp-block-image">
    <img src="https://example.com/wp-content/uploads/hero.jpg"
         alt="A team of developers collaborating around a standing desk"
         class="wp-image-4821"/>
</figure>
<!-- /wp:image -->
```

Note: The alt text in the block markup is separate from `_wp_attachment_image_alt` in the media library. **They can be different.** Setting alt text on an image in the media library does not retroactively update existing Image blocks that use that attachment.

**Setting alt text programmatically (changing stored block HTML):**

```php
/**
 * Update the alt text attribute in all Image block occurrences
 * that use a specific attachment ID.
 *
 * This modifies the stored post_content — use carefully and always
 * run on a staging site first.
 *
 * @param int    $attachment_id The attachment ID to target.
 * @param string $new_alt       The new alt text string.
 */
function altaudit_update_image_block_alt( int $attachment_id, string $new_alt ): void {
    global $wpdb;

    // Find all posts that contain this attachment in their content.
    $post_ids = $wpdb->get_col( $wpdb->prepare(
        "SELECT ID FROM {$wpdb->posts}
         WHERE post_status NOT IN ('trash', 'auto-draft')
         AND post_content LIKE %s",
        '%wp-image-' . $attachment_id . '%'
    ) );

    if ( empty( $post_ids ) ) {
        return;
    }

    $escaped_alt = esc_attr( sanitize_text_field( $new_alt ) );

    foreach ( $post_ids as $post_id ) {
        $post    = get_post( $post_id );
        $content = $post->post_content;

        // Replace the alt attribute on <img> tags for this specific attachment ID.
        // The class wp-image-{ID} uniquely identifies the image in block markup.
        $updated = preg_replace_callback(
            '/<img([^>]*)class="([^"]*wp-image-' . $attachment_id . '[^"]*)"([^>]*)>/i',
            function( $matches ) use ( $escaped_alt ) {
                $img_tag = $matches[0];

                // Remove any existing alt attribute.
                $img_tag = preg_replace( '/\s*alt="[^"]*"/i', '', $img_tag );

                // Insert the new alt attribute before the class attribute.
                $img_tag = str_replace( '<img', '<img alt="' . $escaped_alt . '"', $img_tag );

                return $img_tag;
            },
            $content
        );

        if ( $updated !== $content ) {
            wp_update_post( [
                'ID'           => $post_id,
                'post_content' => $updated,
            ] );
        }
    }
}
```

---

## 2. Gallery Block

The Gallery block renders multiple Image blocks internally. Each image in the gallery has its own alt text, pulled from the media library at render time.

**How Gallery block resolves alt text:**

When a Gallery block is rendered on the frontend, WordPress calls `wp_get_attachment_image()` for each image in the gallery. This means **the alt text comes from `_wp_attachment_image_alt` in the database**, not from the block markup.

This is actually better behaviour than the Image block — updating `_wp_attachment_image_alt` immediately affects gallery output everywhere.

**Checking gallery images in the editor:**

The Gallery block sidebar does not show alt text fields individually. To set alt text on gallery images, edit each image in the **media library** (Media > Library > click the image > edit alt text in the right panel).

Or use the snippets from this repo:

```bash
wp eval-file snippets/bulk-update-alt.php -- --file=gallery-alt-updates.csv
```

**Common pitfall:** A developer uploads 50 product images for a gallery without setting alt text. The gallery renders fine visually, but every image has an empty alt attribute. Fix: use `snippets/find-missing-alt.php` to identify all gallery images, write descriptions, run the bulk update.

---

## 3. Cover Block

The Cover block uses an image as a background. The `<img>` tag is rendered with `role="img"` and the alt text in the block settings sidebar.

**Setting Cover block alt text:**

1. Select the Cover block.
2. In the sidebar, go to **Media settings** (or scroll down in Block settings).
3. Fill in "Alternative Text."

If the cover image is purely decorative (text on top makes the image redundant), check "Decorative image" to output `alt=""`.

**Rendered output with alt text:**

```html
<div class="wp-block-cover">
    <span
        aria-hidden="true"
        class="wp-block-cover__background has-background-dim"
    ></span>
    <img
        class="wp-block-cover__image-background wp-image-4891"
        alt="A blurred cityscape at dusk, used as a decorative section background"
        src="https://example.com/wp-content/uploads/cityscape.jpg"
        data-object-fit="cover"
    />
    <div class="wp-block-cover__inner-container">
        <h2>Get in Touch</h2>
    </div>
</div>
```

**Programmatically update Cover block alt text in stored content:**

```php
/**
 * Set the alt text attribute on Cover block img tags in post content.
 *
 * Cover blocks store the alt text in the block attributes JSON:
 *   <!-- wp:cover {"url":"...","alt":"..."} -->
 *
 * We need to update BOTH the block comment attributes AND the rendered <img> tag.
 *
 * @param int    $post_id The post to update.
 * @param int    $attachment_id The attachment used in the cover block.
 * @param string $new_alt New alt text.
 */
function altaudit_update_cover_block_alt( int $post_id, int $attachment_id, string $new_alt ): void {
    $post    = get_post( $post_id );
    $content = $post->post_content;

    $clean_alt = sanitize_text_field( $new_alt );

    // Update the block comment attributes JSON.
    $content = preg_replace_callback(
        '/<!-- wp:cover (\{[^}]+\}) -->/i',
        function( $matches ) use ( $attachment_id, $clean_alt ) {
            $attrs = json_decode( $matches[1], true );
            if ( ! $attrs ) {
                return $matches[0];
            }
            // Only update if this cover block uses our target attachment.
            if ( isset( $attrs['id'] ) && (int) $attrs['id'] === $attachment_id ) {
                $attrs['alt'] = $clean_alt;
                return '<!-- wp:cover ' . wp_json_encode( $attrs ) . ' -->';
            }
            return $matches[0];
        },
        $content
    );

    // Update the rendered img tag.
    $content = preg_replace(
        '/(<img[^>]*wp-image-' . $attachment_id . '[^>]*alt=")[^"]*(")/i',
        '${1}' . esc_attr( $clean_alt ) . '${2}',
        $content
    );

    wp_update_post( [ 'ID' => $post_id, 'post_content' => $content ] );
}
```

---

## 4. Media & Text Block

The Media & Text block shows an image alongside a text column. The alt text field is in the block sidebar under "Media settings."

**Rendered output:**

```html
<div class="wp-block-media-text">
    <figure class="wp-block-media-text__media">
        <img
            src="https://example.com/wp-content/uploads/feature.jpg"
            alt="Screenshot showing the dashboard with 247 images flagged"
            class="wp-image-5002 size-full"
        />
    </figure>
    <div class="wp-block-media-text__content">
        <p>Our audit tool found 247 images...</p>
    </div>
</div>
```

---

## 5. Common Pitfalls with Gutenberg and Alt Text

### Pitfall 1: Media library alt text doesn't auto-populate Image blocks

When you upload an image and set alt text in the media library, new Image blocks you insert will pre-fill the alt text field — but existing blocks are not updated. This means a bulk update to `_wp_attachment_image_alt` will not fix Image blocks already in posts.

**Solution:** After updating media library alt text, use `altaudit_update_image_block_alt()` above to sync existing post content.

### Pitfall 2: Duplicated images have independent alt text

When the editor duplicates an Image block (Ctrl+D), the new block retains the alt text string from the original — but as an independent copy. If the source attachment's alt text later changes, the duplicate block is not updated.

### Pitfall 3: The "replace image" flow resets alt text

When an editor uses "Replace" in the Image block toolbar to swap the image, the alt text field resets to the new attachment's media library value. If the media library value is empty, the block renders with no alt text. Train editors to always check the alt text field after replacing an image.

### Pitfall 4: Reusable blocks

If an Image block is saved as a reusable block (now "Synced patterns" in WP 6.3+), the alt text in the synced pattern is shared. Changing alt text in one place updates all instances — which is usually good, but can cause problems if the same image is used in different contexts with different meaningful descriptions.

---

## Checking Gutenberg Block Alt Text Programmatically

```php
/**
 * Parse all Image blocks in a post and return those missing alt text.
 *
 * @param int $post_id The post to scan.
 *
 * @return array Array of block instances with missing alt, including src and block index.
 */
function altaudit_find_image_blocks_missing_alt( int $post_id ): array {
    $post    = get_post( $post_id );
    $blocks  = parse_blocks( $post->post_content );
    $missing = [];

    foreach ( $blocks as $index => $block ) {
        if ( $block['blockName'] === 'core/image' ) {
            $attrs   = $block['attrs'];
            $alt_in_attrs = $attrs['alt'] ?? null;

            // If the alt attribute is absent from block attrs, the block
            // relies on the media library value. Check both.
            $media_alt = '';
            if ( isset( $attrs['id'] ) ) {
                $media_alt = get_post_meta( $attrs['id'], '_wp_attachment_image_alt', true );
            }

            if ( $alt_in_attrs === null && $media_alt === '' ) {
                $missing[] = [
                    'block_index'   => $index,
                    'attachment_id' => $attrs['id'] ?? null,
                    'url'           => $attrs['url'] ?? '',
                ];
            }
        }
    }

    return $missing;
}
```
