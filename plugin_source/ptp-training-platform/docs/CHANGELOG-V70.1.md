# PTP Training Platform v70.1.0 - Changelog

**Release Date:** December 28, 2025

## Fixes Applied

### 🔴 Critical: Admin Trainer Image Management
- Added `photo_url` field to admin trainer edit form
- New photo upload button with preview in edit modal
- AJAX handler `ptp_admin_upload_trainer_photo` for async uploads
- Photo removal functionality
- Added `intro_video_url`, `is_featured`, and `sort_order` fields to admin update

### 🟡 Security: Debug Logging
Wrapped all production logging with `WP_DEBUG` checks in:
- `class-ptp-stripe.php` - All API call logging
- `class-ptp-payments.php` - Payment error logging
- `class-ptp-booking.php` - Booking creation logging

### 🟢 Branding: Instagram Handle
Updated Instagram references in emails:
- `@ptpsummercamps` → `@ptp.training`
- `instagram.com/ptpsummercamps` → `instagram.com/ptp.training`

## Files Modified
- `admin/class-ptp-admin-ajax.php`
- `admin/class-ptp-admin.php`
- `includes/class-ptp-stripe.php`
- `includes/class-ptp-payments.php`
- `includes/class-ptp-booking.php`
- `includes/class-ptp-email.php`
- `ptp-training-platform.php` (version bump)

## Upgrade Instructions
1. Replace existing plugin folder with this version
2. No database changes required
3. Clear any caching plugins after update
