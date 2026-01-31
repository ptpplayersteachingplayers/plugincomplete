# PTP Training Platform v95 Changelog

## v95.1.0 - Add to Cart Upsell Popup

### NEW: Add to Cart Popup

When a customer adds a camp to cart on the **product page**, a popup now appears showing:
- ✓ ADDED TO CART confirmation
- 2-Camp Pack ($945, save 10%) 
- 3-Camp Pack ($1,260, save 20%)
- Link to All-Access for year-round training

**Files Added:**
- `includes/class-ptp-camp-pack-upsell.php` - Popup rendering and AJAX fragments
- `assets/js/camp-upsell.js` - Popup behavior and event handling

**How it works:**
1. Customer clicks "Add to Cart" on a camp product
2. WooCommerce fires `added_to_cart` event
3. Popup slides up with upsell options
4. Customer can: View Camp Packs, Continue to Checkout, or Close

## v95.0.0 - Membership Tiers & Checkout Upsell

### Membership System Restructured

**New 3-Tier Structure:**
| Tier | Price | Value | Savings | Includes |
|------|-------|-------|---------|----------|
| 2-Camp Pack | $945 | $1,050 | 10% | 2 summer camps ($473/camp) |
| 3-Camp Pack | $1,260 | $1,575 | 20% | 3 summer camps ($420/camp) |
| All-Access Pass | $4,000 | $5,930 | 33% | 6 camps + 12 private + 6 clinics + 4 video + 4 mentorship |

**Psychology:**
- Camp packs as entry point for price-sensitive families
- Clear per-camp cost comparison shows savings
- All-Access highlighted as "BEST VALUE" with full component list
- Upgrade nudge at bottom encourages All-Access consideration

### Checkout Page Upsell

Added membership upsell section to camp checkout that:
- Shows when buying 1-3 camps individually
- Displays camp pack options with savings percentages
- Links to /membership-tiers/ page for full comparison
- Always shows All-Access teaser at bottom

**Upsell Logic:**
- 1 camp in cart → Shows both 2-camp and 3-camp options
- 2 camps in cart → Suggests upgrading to 3-camp pack
- 3 camps in cart → Celebrates choice, suggests All-Access
- 4+ camps → No upsell shown (they're committed)

### Mobile-First CSS Updates

- Responsive breakpoints: 320px → 375px → 480px → 600px → 900px
- Touch-friendly buttons (44px minimum tap targets)
- Safe area support for notched phones
- Reduced motion support for accessibility
- 2-column grid at 600px, 3-column at 900px

### Shortcodes

```
[ptp_membership_tiers]   - 3-tier comparison page
[ptp_all_access]         - Detailed All-Access page with value stack
[ptp_video_analysis]     - Individual purchase ($100/hr)
[ptp_mentorship]         - Individual purchase ($100/hr)
[ptp_member_dashboard]   - Member credits dashboard
```

### Files Changed
- `includes/class-ptp-all-access-pass.php` - Complete rewrite with new tier structure
- `includes/class-ptp-camp-checkout-v87.php` - Added membership upsell + is_camp_product()
- `assets/css/ptp-all-access.css` - Mobile-first responsive CSS
- `assets/js/ptp-all-access.js` - Updated tier handling

### Setup Required

1. Create WordPress pages:
   - `/membership-tiers/` → `[ptp_membership_tiers]`
   - `/all-access-pass/` → `[ptp_all_access]`
   - `/video-analysis/` → `[ptp_video_analysis]`
   - `/mentorship/` → `[ptp_mentorship]`
   - `/my-membership/` → `[ptp_member_dashboard]`

2. Ensure Stripe keys are configured in plugin settings

3. Configure Stripe webhook for checkout.session.completed events
