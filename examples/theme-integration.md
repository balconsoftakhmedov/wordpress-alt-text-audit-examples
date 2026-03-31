# Alt Text in WordPress Theme Templates

How you output images in your theme determines whether alt text is included automatically, must be passed explicitly, or gets dropped entirely. This document covers every common pattern.

---

## 1. `wp_get_attachment_image()` — The Correct Way

`wp_get_attachment_image()` is the single most reliable way to output an attachment image. It reads `_wp_attachment_image_alt` from the database and includes it in the rendered `<img>` tag automatically.

```php
<?php
// Basic usage — WordPress pulls alt text from the media library automatically.
echo wp_get_attachment_image( $attachment_id, 'large' );
// Output: <img src="..." alt="A golden retriever puppy on a green lawn" ...>
```

### Overriding Alt Text at the Template Level

Sometimes the media library alt text is generic ("product photo") but in a specific context you need something more descriptive. Pass `alt` in the `$attr` array:

```php
<?php
// Override the stored alt text for this specific use case.
echo wp_get_attachment_image(
    $attachment_id,
    'full',
    false,                                          // $icon — false for regular images
    [ 'alt' => esc_attr( get_the_title() ) ]       // use post title as alt text
);
```

### Decorative Images (Intentional Empty Alt)

When an image is purely decorative — a visual divider, background texture, icon that repeats text already in the UI — pass an explicit empty string. This tells screen readers to skip the image rather than announcing the filename.

```php
<?php
// Decorative: empty string, not omitted. This is intentional and correct.
echo wp_get_attachment_image(
    $decoration_image_id,
    'thumbnail',
    false,
    [ 'alt' => '' ]  // explicit empty string = "skip this image"
);
```

**Never omit the alt attribute entirely.** An absent `alt` forces screen readers to announce the filename, which is worse than silence.

---

## 2. `the_post_thumbnail()` and `get_the_post_thumbnail()`

These are wrappers around `wp_get_attachment_image()` for the featured image. They accept the same `$attr` parameter.

```php
<?php
// Outputs the featured image with alt text from the media library.
the_post_thumbnail( 'large' );

// Add a CSS class without losing the alt text.
the_post_thumbnail( 'large', [ 'class' => 'hero-image' ] );

// Override alt text — useful when the featured image context needs
// a description specific to the post, not the generic media library value.
the_post_thumbnail( 'large', [ 'alt' => esc_attr( get_the_title() ) ] );
```

`get_the_post_thumbnail()` returns the HTML string instead of printing it:

```php
<?php
$thumb_html = get_the_post_thumbnail( get_the_ID(), 'medium', [
    'class' => 'post-thumbnail',
    'alt'   => esc_attr( get_the_title() ),
] );
```

---

## 3. `get_the_post_thumbnail_caption()`

Captions are separate from alt text. Alt text describes the image for users who cannot see it. Captions provide supplementary context for all users.

```php
<?php
// Always display both — they serve different purposes.
the_post_thumbnail( 'large', [ 'alt' => esc_attr( get_the_title() ) ] );

$caption = get_the_post_thumbnail_caption();
if ( $caption ) {
    echo '<figcaption>' . esc_html( $caption ) . '</figcaption>';
}
```

Combine them in a `<figure>` element for correct semantic HTML:

```php
<?php
$thumb_id = get_post_thumbnail_id();
$caption  = get_the_post_thumbnail_caption();

if ( $thumb_id ) {
    $has_caption = ! empty( $caption );
    $tag         = $has_caption ? 'figure' : 'div';  // figure requires figcaption to be meaningful

    echo '<' . $tag . ' class="post-featured-image">';
    the_post_thumbnail( 'large' );
    if ( $has_caption ) {
        echo '<figcaption>' . esc_html( $caption ) . '</figcaption>';
    }
    echo '</' . $tag . '>';
}
```

---

## 4. Custom Image Sizes

Register custom sizes in `functions.php`, then use the size name with `wp_get_attachment_image()`:

```php
// In functions.php (or a plugin):
add_action( 'after_setup_theme', function() {
    add_image_size( 'hero-banner', 1440, 600, true );   // hard crop
    add_image_size( 'card-thumb',  480,  320, true );
    add_image_size( 'avatar',       96,   96, true );
} );
```

```php
// In a template file:
echo wp_get_attachment_image( $hero_id, 'hero-banner', false, [
    'class'   => 'hero-banner__image',
    'loading' => 'eager',   // disable lazy loading for above-the-fold images
    'fetchpriority' => 'high',
] );
```

---

## 5. Handling `attachment_url_to_postid()` for Inline Images

When you have a URL (not an ID) — for example from a custom field that stores a URL — resolve it to an ID first, then use `wp_get_attachment_image()`:

```php
<?php
// Custom field returns a URL string.
$image_url = get_field( 'team_member_photo' ); // ACF example

$attachment_id = attachment_url_to_postid( $image_url );

if ( $attachment_id ) {
    // Now we can get the alt text from the media library properly.
    echo wp_get_attachment_image( $attachment_id, 'medium' );
} else {
    // Fallback: we don't have an ID, so we must provide alt text manually.
    // Never output <img> without alt text.
    echo '<img src="' . esc_url( $image_url ) . '" alt="' . esc_attr( get_the_title() ) . '">';
}
```

---

## 6. `wp_get_attachment_image_src()` — When You Need the URL Only

If you need the image URL for CSS background-image, Open Graph meta tags, or other non-`<img>` uses:

```php
<?php
$image_data = wp_get_attachment_image_src( $attachment_id, 'full' );
// Returns: [ 'https://...', width, height, is_intermediate ]

if ( $image_data ) {
    $url    = $image_data[0];
    $width  = $image_data[1];
    $height = $image_data[2];
}
```

For CSS backgrounds, alt text is not applicable. But if you're also rendering a fallback `<img>`, always include alt text:

```php
<div
    class="hero"
    style="background-image: url('<?php echo esc_url( $url ); ?>')"
    role="img"
    aria-label="<?php echo esc_attr( get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) ); ?>"
>
    <!-- aria-label provides alt text for the background image for screen readers -->
</div>
```

---

## 7. Do Not Use `<img>` Tags with Hardcoded Strings

Common anti-pattern found in older themes:

```php
<?php
// BAD — alt text is hardcoded or missing entirely.
$url = get_the_post_thumbnail_url( get_the_ID(), 'large' );
echo '<img src="' . esc_url( $url ) . '">';               // No alt — fail
echo '<img src="' . esc_url( $url ) . '" alt="Image">';   // Useless alt — also fail
```

```php
<?php
// GOOD — use wp_get_attachment_image() or pass meaningful alt text.
$thumb_id = get_post_thumbnail_id();
if ( $thumb_id ) {
    echo wp_get_attachment_image( $thumb_id, 'large' );
} else {
    // Fallback image — describe it in context.
    echo '<img src="' . esc_url( $fallback_url ) . '" alt="' . esc_attr( get_the_title() ) . '">';
}
```

---

## 8. SVG Images

WordPress doesn't natively handle SVG as attachments in older versions. If you output SVG inline, use the `<title>` element and `aria-labelledby`:

```php
<?php
// Inline SVG with accessible title.
$icon_id = 'icon-' . uniqid();
?>
<svg aria-labelledby="<?php echo esc_attr( $icon_id ); ?>" role="img">
    <title id="<?php echo esc_attr( $icon_id ); ?>">Download PDF</title>
    <use href="#icon-download"></use>
</svg>
```

For decorative SVGs, use `aria-hidden="true"`:

```php
<svg aria-hidden="true" focusable="false">
    <use href="#icon-decorative-divider"></use>
</svg>
```

---

## Quick Reference

| Function | Alt text source | Recommended for |
|----------|----------------|-----------------|
| `wp_get_attachment_image()` | Media library (auto) | All attachment images |
| `the_post_thumbnail()` | Media library (auto) | Featured images in the loop |
| `get_the_post_thumbnail()` | Media library (auto) | Featured images outside the loop |
| `<img alt="...">` (manual) | You provide it | Non-attachment images, URLs from external sources |
| CSS `background-image` | N/A — use `aria-label` on container | Decorative backgrounds only |

**Rule of thumb:** If you can get an attachment ID, use `wp_get_attachment_image()`. If you can't, you're responsible for the alt text string.
