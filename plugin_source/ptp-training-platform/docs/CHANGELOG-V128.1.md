# PTP Training Platform v128.1.0

## Release Date: January 2026

### Training Thank You Page Simplified
- **Removed all extra content** from training confirmation page
- Kept only: Success badge, hero headline, trainer card, session details (date/time/location/package/player)
- Removed: Testimonials, camp upsells, package credits section, referral prompts, Instagram announcement opt-in, share trainer CTAs
- Clean, simple confirmation that focuses on what matters
- Training-only pages now return early, skipping all camp-related sections

### Parent Dashboard Mobile Fixes
- **Messages Tab**: Fixed card overflow, added proper padding wrapper, improved message item sizing on mobile
- **Settings Tab**: Fixed input field overflow with box-sizing, added proper padding containers, improved quick links styling
- Added comprehensive mobile CSS for screens under 480px:
  - Properly sized message avatars (44px on mobile)
  - Truncated message previews (max-width: 140px)
  - Better spacing in account settings form
  - Consistent padding throughout tabs

### SMS Service Clarification
- SMS notifications are wired via WordPress hooks (`ptp_booking_confirmed`, `ptp_session_reminder`, `ptp_message_sent`)
- Sending happens through `PTP_SMS_V71` class using Twilio or Salesmsg APIs
- The n8n endpoints (`/wp-json/ptp/v1/bookings/*`) are for data retrieval, not SMS triggering
- No external URL endpoint for SMS - it's all internal hook-based

### Files Changed
- `templates/thank-you-v100.php` - Simplified training confirmation
- `templates/parent-dashboard-v117.php` - Mobile fixes for Messages + Settings tabs
- `ptp-training-platform.php` - Version bump
