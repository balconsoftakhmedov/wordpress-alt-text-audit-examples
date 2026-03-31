# WordPress Image SEO Guide

Alt text is one piece of a larger image SEO puzzle. This guide covers the full picture: what each image field does, how Google interprets it, and what to optimize beyond the alt attribute.

---

## 1. The Four WordPress Image Fields

WordPress stores four separate pieces of text for each attachment. They serve different purposes for SEO, accessibility, and UX.

| Field | Where stored | What it's for | SEO value |
|-------|-------------|---------------|-----------|
| **Alt text** | `_wp_attachment_image_alt` postmeta | Describes the image for screen readers and crawlers | High — primary signal for image search |
| **Title** | `post_title` on the attachment post | Tooltip on hover (in some themes); also an internal label | Low — rarely crawled as an image signal |
| **Caption** | `post_excerpt` on the attachment post | Visible text below the image | Medium — visible text on-page, crawled as body content |
| **Description** | `post_content` on the attachment post | Shown on the attachment page (`/?attachment_id=X`) | Low for most sites; the attachment page is often noindexed |

### Alt text vs. title attribute

The `title` attribute on an `<img>` tag is a tooltip. It does not substitute for alt text and is not a meaningful accessibility signal. Screen readers handle it inconsistently — some announce it, some ignore it.

```html
<!-- Correct: alt text carries the meaning -->
<img src="widget.jpg" alt="Acme Widget Pro in matte red, front view" title="Acme Widget Pro">

<!-- Wrong: relying on title instead of alt -->
<img src="widget.jpg" title="Acme Widget Pro" alt="">
```

Google uses alt text as a primary signal for understanding image content. Google has explicitly said it does not place significant weight on the `title` attribute for image understanding.

### Caption vs. alt text

Captions are visible to sighted users. Alt text is read by screen readers and crawlers. They describe different things:

- **Alt text**: "A smiling developer typing at a standing desk in a bright office."
- **Caption**: "Our engineering team works in-office three days a week from our London HQ."

The caption provides context; the alt text describes what is visible. Both contribute to page relevance for image search.

---

## 2. Filename Conventions

Google reads image filenames. A file named `IMG_4291.jpg` tells Google nothing. A file named `acme-widget-pro-red-front-view.jpg` reinforces the alt text signal.

### Renaming images before upload

WordPress uses the original filename as the basis for the URL path (`wp-content/uploads/2025/06/acme-widget-pro-red-front-view.jpg`). Once published and indexed, renaming causes a 404 unless you set up redirects. **Always rename before uploading.**

Naming convention:

```
[subject]-[descriptor]-[view/context].jpg

Good examples:
  acme-widget-pro-red-front-view.jpg
  sarah-johnson-head-of-engineering-headshot.jpg
  homepage-hero-team-collaborating-office.jpg
  wcag-2-1-compliance-infographic.png

Bad examples:
  IMG_4291.jpg
  image1.jpg
  photo.jpg
  DSC_00821_final_FINAL_v3.jpg
  AcmeWidgetProRedFrontView.jpg (uppercase, no hyphens)
```

Use hyphens, not underscores. Google treats hyphens as word separators; underscores are treated as connectors (so `widget_pro` is read as `widgetpro`).

### Bulk rename before importing

```bash
# On Linux/Mac, rename files in a directory to lowercase with hyphens before uploading:
for f in *.jpg *.png *.webp; do
  newname=$(echo "$f" | tr '[:upper:]' '[:lower:]' | tr ' _' '-')
  mv "$f" "$newname"
done
```

---

## 3. Image Sitemaps

Google discovers images through both the page content and an image sitemap (or image tags in your main sitemap).

### WordPress + Yoast SEO

Yoast SEO automatically adds `<image:image>` tags to your XML sitemap for images attached to posts and pages. The tag includes the image URL, title, and caption — but not the alt text (the XML sitemap format doesn't have an alt text field).

Verify Yoast is including images:

```bash
curl -s https://example.com/sitemap_index.xml | grep -i "image\|sitemap"
```

Then check a post sitemap entry for `<image:image>` tags:

```bash
curl -s https://example.com/post-sitemap.xml | grep -c "image:image"
```

### Rank Math

Rank Math includes images in sitemaps by default. Enable under Rank Math > Sitemap Settings > Image Sitemap.

### Manual sitemap addition

If you're not using an SEO plugin, add image sitemap entries manually. The format:

```xml
<url>
  <loc>https://example.com/product/acme-widget-pro/</loc>
  <image:image>
    <image:loc>https://example.com/wp-content/uploads/2025/06/acme-widget-pro-red-front-view.jpg</image:loc>
    <image:title>Acme Widget Pro – Red, Front View</image:title>
    <image:caption>The Acme Widget Pro in matte red, showing the front panel and USB-C port.</image:caption>
  </image:image>
</url>
```

---

## 4. Schema Markup for Images

Structured data (Schema.org) provides machine-readable context for images. The most relevant schemas for WordPress sites:

### ImageObject

Use `ImageObject` within a larger schema to associate descriptive metadata with an image:

```php
<?php
// In a single product page template or as part of a Product schema.
$attachment_id = get_post_thumbnail_id();
$image_data    = wp_get_attachment_image_src( $attachment_id, 'full' );
$alt_text      = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );

$image_schema = [
    '@type'       => 'ImageObject',
    'url'         => $image_data[0],
    'width'       => $image_data[1],
    'height'      => $image_data[2],
    'name'        => get_the_title( $attachment_id ),
    'description' => $alt_text,
];

echo '<script type="application/ld+json">' . wp_json_encode( $image_schema ) . '</script>';
```

### Product schema with image

```php
<?php
// Full Product schema with ImageObject for WooCommerce.
function altaudit_product_schema( int $product_id ): array {
    $product      = wc_get_product( $product_id );
    $image_id     = $product->get_image_id();
    $image_data   = wp_get_attachment_image_src( $image_id, 'full' );
    $alt_text     = get_post_meta( $image_id, '_wp_attachment_image_alt', true );
    $gallery_ids  = $product->get_gallery_image_ids();

    $images = [];

    // Main image.
    if ( $image_data ) {
        $images[] = [
            '@type'       => 'ImageObject',
            'url'         => $image_data[0],
            'width'       => $image_data[1],
            'height'      => $image_data[2],
            'description' => $alt_text ?: $product->get_name(),
        ];
    }

    // Gallery images.
    foreach ( $gallery_ids as $gallery_id ) {
        $gallery_data = wp_get_attachment_image_src( $gallery_id, 'full' );
        $gallery_alt  = get_post_meta( $gallery_id, '_wp_attachment_image_alt', true );
        if ( $gallery_data ) {
            $images[] = [
                '@type'       => 'ImageObject',
                'url'         => $gallery_data[0],
                'width'       => $gallery_data[1],
                'height'      => $gallery_data[2],
                'description' => $gallery_alt ?: $product->get_name(),
            ];
        }
    }

    return [
        '@context'    => 'https://schema.org',
        '@type'       => 'Product',
        'name'        => $product->get_name(),
        'image'       => count( $images ) === 1 ? $images[0] : $images,
        'description' => wp_strip_all_tags( $product->get_description() ),
        'sku'         => $product->get_sku(),
    ];
}

add_action( 'wp_head', function() {
    if ( is_product() ) {
        $schema = altaudit_product_schema( get_the_ID() );
        echo '<script type="application/ld+json">' . wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . '</script>';
    }
} );
```

---

## 5. Google Images Optimization

Google Images is a significant traffic source for e-commerce, news, and visual content sites. Key factors:

### What Google uses to understand images

1. **Alt text** — highest weight for image understanding
2. **Surrounding text** — the paragraph or heading adjacent to the image
3. **Page title and heading structure** — H1 on the page is context for every image on it
4. **Image filename** — secondary signal
5. **Caption** — visible text directly below the image
6. **Schema markup** — `ImageObject` description and name
7. **Links to/from the image's URL** — rare but relevant for image-heavy pages

### Enabling Google Image search features

Certain structured data enables rich features in Google Images:

- **Product images** with `Product` schema (including `offers`) can appear in the Shopping tab
- **Recipe images** with `Recipe` schema can appear in the Visual Stories carousel
- **Video thumbnails** with `VideoObject` schema get video badges

For each: ensure the primary image has descriptive alt text, a keyword-relevant filename, and is included in the `image` property of the schema.

---

## 6. Lazy Loading and Core Web Vitals

### Lazy loading and alt text

Lazy loading defers image loading until the image enters the viewport. Alt text is always rendered regardless of whether the image has loaded — it appears before load, and for users on slow connections, it may be the only feedback they get.

WordPress 5.5+ adds `loading="lazy"` automatically via `wp_get_attachment_image()`. You do not need to add it manually.

**Do not lazy-load above-the-fold images.** This delays the Largest Contentful Paint (LCP) and hurts Core Web Vitals.

```php
<?php
// Hero image: disable lazy loading, mark as high-fetch-priority.
echo wp_get_attachment_image( $hero_id, 'hero-banner', false, [
    'loading'      => 'eager',        // Override WordPress default of "lazy"
    'fetchpriority' => 'high',        // Hint to browser: load this first
    'decoding'     => 'async',        // Decode asynchronously to avoid blocking
] );

// Below-the-fold image: let WordPress apply lazy loading (default).
echo wp_get_attachment_image( $below_fold_id, 'full' );
```

### CLS (Cumulative Layout Shift) and images

Image dimensions must be set to prevent layout shift. `wp_get_attachment_image()` automatically includes `width` and `height` attributes based on the registered image size. These attributes tell the browser to reserve space before the image loads.

**Always define width and height when using raw `<img>` tags:**

```php
<?php
$image_src = wp_get_attachment_image_src( $attachment_id, 'large' );
if ( $image_src ) {
    printf(
        '<img src="%s" alt="%s" width="%d" height="%d" loading="lazy">',
        esc_url( $image_src[0] ),
        esc_attr( $alt_text ),
        (int) $image_src[1],
        (int) $image_src[2]
    );
}
```

### WebP format

Modern hosting stacks and plugins (Imagify, ShortPixel, Smush) convert JPEG/PNG to WebP. WebP images are typically 25–35% smaller. Smaller images load faster, improving LCP.

Alt text is format-agnostic — your `_wp_attachment_image_alt` value applies equally to the JPEG and its WebP counterpart.

---

## 7. WordPress Image Fields: Full Taxonomy

Where each field lives in the database and how to update it programmatically:

```php
<?php
$attachment_id = 4821;

// Alt text (_wp_attachment_image_alt postmeta)
update_post_meta( $attachment_id, '_wp_attachment_image_alt', 'Descriptive alt text here' );

// Title (post_title on the attachment post)
wp_update_post( [ 'ID' => $attachment_id, 'post_title' => 'Human-readable title' ] );

// Caption (post_excerpt on the attachment post)
wp_update_post( [ 'ID' => $attachment_id, 'post_excerpt' => 'Visible caption below the image' ] );

// Description (post_content on the attachment post)
wp_update_post( [
    'ID'           => $attachment_id,
    'post_content' => 'Long-form description of the image, shown on the attachment page.',
] );
```

For SEO purposes, prioritise in this order: **alt text > caption > title > description**.

---

## 8. Summary: Quick Wins

| Action | Effort | SEO Impact |
|--------|--------|------------|
| Add alt text to images missing it entirely | Medium | High |
| Rename files from IMG_XXXX to descriptive names (before upload) | Low | Medium |
| Add captions to high-value images (product, hero, blog) | Medium | Medium |
| Ensure images are included in XML sitemap | Low | Medium |
| Add `Product`/`ImageObject` schema to e-commerce pages | Medium | High for Google Shopping |
| Mark decorative images with `alt=""` | Low | Low SEO, high accessibility |
| Disable lazy loading on LCP images | Low | High for Core Web Vitals |
| Convert JPEG/PNG to WebP | Low (plugin) | Medium for LCP |
