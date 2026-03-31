# WooCommerce Alt Text: Product, Gallery, Category, and Variation Images

WooCommerce adds several distinct image contexts beyond standard WordPress attachments. Each one stores and renders alt text differently. This guide covers all of them.

---

## 1. How WooCommerce Handles Alt Text

WooCommerce uses the standard WordPress attachment system for all its images. The alt text for each image is stored in `_wp_attachment_image_alt` postmeta — the same field as regular WordPress media.

The difference is how WooCommerce renders images and what fallback logic it uses when alt text is empty.

**WooCommerce fallback chain for product images (single product page):**

1. `_wp_attachment_image_alt` value on the attachment
2. If empty: `post_title` of the attachment (usually the filename without extension)
3. If still empty: `post_title` of the product itself

This fallback seems safe but it isn't. "DSC_4821" or "product-red-widget-v2" as announced alt text for a screen reader user is not useful.

---

## 2. Product Main Image

The product main image is the featured image of the WooCommerce product post. It is stored using the standard `_thumbnail_id` postmeta.

**Checking and updating the main product image alt text:**

```php
/**
 * Get the alt text for a WooCommerce product's main image.
 *
 * @param int $product_id The WooCommerce product post ID.
 *
 * @return string The current alt text, or empty string if not set.
 */
function altaudit_get_product_image_alt( int $product_id ): string {
    $thumbnail_id = get_post_thumbnail_id( $product_id );

    if ( ! $thumbnail_id ) {
        return ''; // No main image set.
    }

    return (string) get_post_meta( $thumbnail_id, '_wp_attachment_image_alt', true );
}


/**
 * Set the alt text for a WooCommerce product's main image.
 *
 * @param int    $product_id The WooCommerce product post ID.
 * @param string $alt_text   The alt text to set.
 *
 * @return bool True on success, false if the product has no featured image.
 */
function altaudit_set_product_image_alt( int $product_id, string $alt_text ): bool {
    $thumbnail_id = get_post_thumbnail_id( $product_id );

    if ( ! $thumbnail_id ) {
        return false;
    }

    update_post_meta(
        $thumbnail_id,
        '_wp_attachment_image_alt',
        sanitize_text_field( $alt_text )
    );

    return true;
}
```

---

## 3. Product Gallery Images

WooCommerce stores gallery image IDs as a comma-separated list in `_product_image_gallery` postmeta on the product post.

```php
// Get gallery attachment IDs for a product.
$gallery_ids = explode( ',', get_post_meta( $product_id, '_product_image_gallery', true ) );
$gallery_ids = array_filter( array_map( 'absint', $gallery_ids ) );

// Check alt text on each gallery image.
foreach ( $gallery_ids as $attachment_id ) {
    $alt = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
    if ( empty( $alt ) ) {
        echo "Gallery image ID {$attachment_id} is missing alt text.\n";
    }
}
```

**Bulk checking all products with gallery images missing alt text:**

```php
/**
 * Find all WooCommerce products where at least one gallery image is missing alt text.
 *
 * @return array Array of product IDs with attachment IDs of problematic gallery images.
 *               Format: [ product_id => [ attachment_id, ... ], ... ]
 */
function altaudit_find_products_with_missing_gallery_alt(): array {
    $products = get_posts( [
        'post_type'      => 'product',
        'posts_per_page' => -1,
        'post_status'    => 'publish',
        'fields'         => 'ids',
        'meta_key'       => '_product_image_gallery',
        'meta_compare'   => '!=',
        'meta_value'     => '',
    ] );

    $results = [];

    foreach ( $products as $product_id ) {
        $gallery_string = get_post_meta( $product_id, '_product_image_gallery', true );
        $gallery_ids    = array_filter( array_map( 'absint', explode( ',', $gallery_string ) ) );

        $missing_ids = [];
        foreach ( $gallery_ids as $attachment_id ) {
            $alt = trim( (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) );
            if ( $alt === '' ) {
                $missing_ids[] = $attachment_id;
            }
        }

        if ( ! empty( $missing_ids ) ) {
            $results[ $product_id ] = $missing_ids;
        }
    }

    return $results;
}
```

---

## 4. Product Category Images

WooCommerce product categories can have a thumbnail image. The image ID is stored in term meta under the key `thumbnail_id`.

```php
/**
 * Get the alt text for a WooCommerce product category image.
 *
 * @param int $term_id The product_cat term ID.
 *
 * @return string The current alt text, or empty string.
 */
function altaudit_get_category_image_alt( int $term_id ): string {
    $thumbnail_id = (int) get_term_meta( $term_id, 'thumbnail_id', true );

    if ( ! $thumbnail_id ) {
        return '';
    }

    return (string) get_post_meta( $thumbnail_id, '_wp_attachment_image_alt', true );
}


/**
 * Audit all WooCommerce product categories for missing image alt text.
 *
 * @return array Array of term objects for categories missing alt text on their image.
 */
function altaudit_find_categories_missing_alt(): array {
    $categories = get_terms( [
        'taxonomy'   => 'product_cat',
        'hide_empty' => false,
    ] );

    if ( is_wp_error( $categories ) || empty( $categories ) ) {
        return [];
    }

    $missing = [];

    foreach ( $categories as $term ) {
        $thumbnail_id = (int) get_term_meta( $term->term_id, 'thumbnail_id', true );

        if ( ! $thumbnail_id ) {
            continue; // No image set — not in scope for alt text.
        }

        $alt = trim( (string) get_post_meta( $thumbnail_id, '_wp_attachment_image_alt', true ) );

        if ( $alt === '' ) {
            $missing[] = $term;
        }
    }

    return $missing;
}
```

**Render category image with correct alt text in a theme template:**

```php
<?php
// In a category archive template or widget.
$term          = get_queried_object();
$thumbnail_id  = (int) get_term_meta( $term->term_id, 'thumbnail_id', true );

if ( $thumbnail_id ) {
    // wp_get_attachment_image() reads _wp_attachment_image_alt automatically.
    echo wp_get_attachment_image( $thumbnail_id, 'woocommerce_thumbnail', false, [
        'class' => 'product-category__image',
        // Override alt if the category name is more descriptive than the media library value.
        'alt'   => esc_attr( $term->name . ' — product category' ),
    ] );
}
```

---

## 5. Variation Images

WooCommerce variable products can assign a unique image to each variation. The variation image ID is stored as `image_id` on the `WC_Product_Variation` object (postmeta key: `_thumbnail_id` on the variation post).

```php
/**
 * Check all variation images for a variable product for missing alt text.
 *
 * @param int $product_id The parent variable product ID.
 *
 * @return array [ variation_id => attachment_id ] for variations with missing alt.
 */
function altaudit_find_variation_images_missing_alt( int $product_id ): array {
    $product = wc_get_product( $product_id );

    if ( ! $product || ! $product->is_type( 'variable' ) ) {
        return [];
    }

    $variations = $product->get_available_variations();
    $missing    = [];

    foreach ( $variations as $variation ) {
        $variation_id = $variation['variation_id'];
        $image_id     = $variation['image_id'] ?? 0;

        if ( ! $image_id ) {
            continue; // No variation-specific image — falls back to parent product image.
        }

        $alt = trim( (string) get_post_meta( $image_id, '_wp_attachment_image_alt', true ) );
        if ( $alt === '' ) {
            $missing[ $variation_id ] = $image_id;
        }
    }

    return $missing;
}
```

---

## 6. Bulk Update WooCommerce Product Images

Use the function from `snippets/bulk-update-alt.php` for bulk updates. To generate the update list programmatically:

```php
/**
 * Build an [attachment_id => alt_text] array for all WooCommerce product
 * main images and gallery images that are missing alt text.
 *
 * This populates alt text from the product name — a useful starting point
 * that you should then review and refine for SEO.
 *
 * @return array Ready to pass to altaudit_bulk_update_alt_text().
 */
function altaudit_generate_product_alt_text_from_names(): array {
    $products = get_posts( [
        'post_type'      => [ 'product', 'product_variation' ],
        'posts_per_page' => -1,
        'post_status'    => 'publish',
        'fields'         => 'ids',
    ] );

    $updates = [];

    foreach ( $products as $product_id ) {
        $product = wc_get_product( $product_id );
        if ( ! $product ) {
            continue;
        }

        $product_name = $product->get_name();

        // Main product image.
        $main_image_id = $product->get_image_id();
        if ( $main_image_id ) {
            $existing_alt = get_post_meta( $main_image_id, '_wp_attachment_image_alt', true );
            if ( empty( $existing_alt ) ) {
                // Use "Product name – product image" pattern.
                $updates[ $main_image_id ] = $product_name . ' – product image';
            }
        }

        // Gallery images (only for parent products, not variations).
        if ( $product->is_type( 'simple' ) || $product->is_type( 'variable' ) ) {
            $gallery_ids = $product->get_gallery_image_ids();
            foreach ( $gallery_ids as $i => $gallery_id ) {
                $existing_alt = get_post_meta( $gallery_id, '_wp_attachment_image_alt', true );
                if ( empty( $existing_alt ) ) {
                    $photo_number = $i + 2; // Main image is #1.
                    $updates[ $gallery_id ] = $product_name . " – product photo {$photo_number}";
                }
            }
        }
    }

    return $updates;
}
```

---

## 7. SEO Best Practices for WooCommerce Product Alt Text

**Do:**

- Describe what is in the photo: color, material, angle, quantity. "Blue ceramic coffee mug, 12oz, matte finish, shown from above."
- Include the product name naturally — not keyword-stuffed. "Acme Widget Pro 2000 – red, front view."
- For gallery images, vary the description: "Acme Widget Pro 2000 – side view showing USB-C port."
- For variation images, include the variant: "Acme Widget Pro 2000 – blue colorway."
- Keep alt text under 125 characters. Screen readers truncate longer strings.

**Don't:**

- Use the filename ("DSC_8421.jpg" or "product-v2-final-FINAL.jpg").
- Repeat the same alt text on every gallery image ("product photo", "product photo", "product photo").
- Stuff with keywords: "buy cheap blue widget best price free shipping discount".
- Leave alt text empty on the main product image — this is the image Google indexes for Google Images and Google Shopping.

**WooCommerce-specific pitfall — placeholder image:**

If a product has no image set, WooCommerce renders a placeholder. The placeholder has no meaningful alt text (it renders as "Placeholder" or empty). Fix: always set a product image before publishing. Use a custom placeholder that has descriptive alt text:

```php
// Override the WooCommerce placeholder image URL.
add_filter( 'woocommerce_placeholder_img_src', function() {
    return get_stylesheet_directory_uri() . '/images/product-placeholder.jpg';
} );

// Override the placeholder <img> tag to add alt text.
add_filter( 'woocommerce_placeholder_img', function( $html ) {
    return str_replace(
        'alt=""',
        'alt="Product image coming soon"',
        $html
    );
} );
```

---

## WP-CLI: Audit All WooCommerce Product Images

```bash
# Count products with no main image alt text.
wp eval '
$products = get_posts(["post_type"=>"product","posts_per_page"=>-1,"fields"=>"ids"]);
$missing = 0;
foreach($products as $id){
    $thumb = get_post_thumbnail_id($id);
    if($thumb && !get_post_meta($thumb,"_wp_attachment_image_alt",true)) $missing++;
}
echo "Products missing main image alt text: $missing\n";
'
```
