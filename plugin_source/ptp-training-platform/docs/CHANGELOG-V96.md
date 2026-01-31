# PTP Training Platform v96 Changelog

## v96.0.0 - Camp Product Template Refactor

### Camp Product Code Snippet Version

Created a standalone code snippet version of the camp product template that combines PHP + JS into a single file for easy deployment via code snippets plugin.

**New Files:**
- `ptp-camp-product-snippet.php` - Single file containing:
  - Template rendering (Hero, Price Bar, Reviews, Coaches, Schedule, FAQ, Checkout)
  - Inline JavaScript (Sticky footer, FAQ accordion, Smooth scroll, Loading states)
  - All helper methods and default content arrays
  - Direct-to-checkout redirect logic

**How to Use (Code Snippet):**
1. Install "Code Snippets" plugin
2. Create new PHP snippet
3. Paste contents of `ptp-camp-product-snippet.php`
4. Activate snippet
5. Add CSS separately via Customizer > Additional CSS or separate CSS snippet

**Camp Product Features:**
- World Cup 2026 themed hero section
- Price bar with Affirm payment messaging
- Google Reviews social proof card
- Info pills (dates, time, location, ages)
- Value proposition cards ("The PTP Difference")
- Coaches carousel with D1/MLS badges
- What's Included grid
- Daily schedule timeline
- Weekly World Cup schedule
- Friday Awards section
- Parent testimonials
- FAQ accordion
- Checkout section with stock messaging
- Sticky mobile footer
- Direct-to-checkout flow

### CSS Improvements

Full CSS file (`ptp-camp-product.css`) includes:
- PTP Design System variables (#FCB900 gold, #0A0A0A black)
- Oswald + Inter font stack
- Mobile-first responsive breakpoints (768px, 1024px)
- All section styling
- WooCommerce form overrides
- Loading states with spinner animation
- Accessibility focus states
- Reduced motion support

### Files Updated
- `ptp-training-platform.php` - Version bump to 96.0.0
- `includes/class-ptp-camp-product-template.php` - Refactored helper methods
- `assets/css/ptp-camp-product.css` - Full 1576-line CSS
- `assets/js/ptp-camp-product.js` - Vanilla JS functionality

### Product Meta Fields

Camp products support these meta fields (set in WooCommerce product editor):
- `_is_ptp_camp` - "yes" to enable camp template
- `_camp_date` - Display dates (e.g., "June 16-20, 2025")
- `_camp_time` - Display time (e.g., "9:00 AM - 3:00 PM")
- `_camp_location` - City name
- `_camp_state` - State abbreviation
- `_age_range` - Age range (e.g., "7-12 years")
- `_camp_hero_video` - Hero video URL
- `_camp_venue_name` - Venue/field name
- `_camp_venue_address` - Full address
- `_camp_map_link` - Google Maps link

### Global Settings (wp_options)

- `ptp_camp_phone` - Contact phone
- `ptp_camp_sms` - SMS number
- `ptp_camp_google_rating` - Rating to display
- `ptp_camp_google_reviews_count` - Review count
- `ptp_camp_google_reviews_url` - Link to reviews
- `ptp_camp_campers_count` - Total campers served
- `ptp_camp_hero_badge` - Badge text (e.g., "World Cup 2026")
- `ptp_camp_hero_slogan` - Slogan text
- `ptp_camp_hero_headline` - Main headline (supports `<span>` for gold text)
- `ptp_camp_hero_subheadline` - Subheadline
- `ptp_camp_sibling_discount` - Sibling discount text
