# PTP Training Platform v93 - Camp Product Template Integration

## What's New

### Camp Product Template (WooCommerce)
Integrated the PTP Camp Template as a built-in feature for camp product pages.

**Features:**
- Custom WooCommerce single product template for camp products
- Video hero section with autoplay background
- Mobile-optimized sticky footer with "Sign Up Now" CTA
- Google Reviews integration
- Coach carousel section
- Photo gallery with touch swipe
- Video slider for camp highlights
- Day-by-day schedule breakdown
- FAQ accordion
- Location map embed
- Affirm payment messaging
- Sibling discount display
- Stock urgency badges (Almost Full, X seats left)

**Configuration:**
- Settings page under WooCommerce → Camp Template
- Target by category slugs (default: `summer,summer-camps`)
- Or target individual products via `_ptp_template = camp` meta
- Customizable hero content, coaches, reviews, gallery
- Contact phone/SMS integration

**Mobile Optimizations:**
- Sticky footer appears after scrolling past hero
- Hides when checkout section is in view
- Safe area padding for iPhone notch
- Touch-friendly 44px tap targets
- Horizontal swipe sliders

### Find Trainers Page Fixes (v93)
- **Fixed:** Map close X button no longer shows on desktop (only on mobile fullscreen)
- **Fixed:** Added stronger CSS selectors to remove gap at page top

## Files Added
- `includes/class-ptp-camp-product-template.php` - Main class
- `assets/css/ptp-camp-product.css` - Styles
- `assets/js/ptp-camp-product.js` - JavaScript
- `templates/single-product-camp.php` - Template

## Usage

1. Create a WooCommerce product
2. Either:
   - Add it to a category with slug `summer` or `summer-camps`
   - Or edit the product and set "Use Camp Template" to Yes
3. Set product meta fields:
   - `_camp_date` - Date text (e.g., "June 10-14")
   - `_camp_time` - Time text (e.g., "9:00 AM - 12:00 PM")
   - `_camp_location` - City name
   - `_camp_state` - State code
   - `_age_range` - Ages (e.g., "6-14 years")
   - `_camp_hero_video` - Video URL for hero
   - `_camp_venue_name` - Venue name
   - `_camp_venue_address` - Full address
   - `_camp_map_embed` - Google Maps embed URL
   - `_camp_map_link` - Google Maps link for directions

## Settings (WooCommerce → Camp Template)

- **Category Slugs:** Comma-separated category slugs
- **Phone/SMS:** Contact numbers for footer buttons
- **Google Rating/Reviews:** Display values
- **Coaches JSON:** Array of coach objects
- **Reviews JSON:** Array of review objects
- **Hero Content:** Badge, slogan, headline, subheadline
- **Default Hero Video:** Fallback video URL
