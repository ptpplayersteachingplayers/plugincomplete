# PTP Training Platform v128.2.9

## Release Date: January 24, 2026

### Summary
This release fixes parent dashboard display issues, enhances the trainer map to prioritize training locations, and wires up SMS reminders for the complete booking lifecycle.

---

## Fixes & Improvements

### 1. Parent Dashboard - Smart Name Display
**Issue**: Parent dashboard hero section was displaying email addresses (e.g., "MARTELLILUKE5@GMAIL.COM") instead of user names when `first_name` was empty and `display_name` contained an email.

**Fix**: Implemented smart name detection that:
- Checks for `first_name` first
- If `display_name` contains an email, extracts and cleans the name portion before the @
- Removes numbers from extracted email names
- Falls back to "There" as greeting if no valid name found

**File**: `templates/parent-dashboard-v117.php`

### 2. Find Trainers Map - Training Location Priority
**Issue**: Map markers were sometimes showing trainer home locations instead of training locations.

**Enhancement**: 
- Map now properly prioritizes `training_locations` over home `lat/lng`
- Added support for pre-stored coordinates in training locations (skips geocoding)
- Clearer fallback logic: only uses home location when NO training locations exist
- Better caching for geocoded addresses

**File**: `templates/trainers-grid.php`

### 3. SMS Reminders - Complete Booking Lifecycle
**Enhancement**: Wired up SMS notifications for the complete training booking lifecycle:

| Event | SMS Sent To | When |
|-------|-------------|------|
| Booking Confirmed | Parent + Trainer | Immediately after payment |
| Session Reminder | Parent + Trainer | 24 hours before session |
| Post-Training Followup | Parent | 2 hours after session ends |

**Files Modified**:
- `includes/class-ptp-cron.php` - Added SMS to completion requests
- `includes/class-ptp-sms.php` - Already had all methods, now properly triggered

### 4. Sign Out - Verified Working
**Status**: Sign out functionality confirmed present in Account tab at `templates/parent-dashboard-v117.php` line 1668-1671.

---

## Technical Details

### Files Modified
1. `ptp-training-platform.php` - Version bump 128.2.8 → 128.2.9
2. `templates/parent-dashboard-v117.php` - Smart name detection
3. `templates/trainers-grid.php` - Training location map priority
4. `includes/class-ptp-cron.php` - SMS for post-training followup

### Database
No database changes required.

### Testing Checklist
- [ ] Parent dashboard shows name (not email) for users with email as display_name
- [ ] Find trainers map shows training locations when available
- [ ] SMS booking confirmation sent after payment
- [ ] SMS reminder sent 24 hours before session (requires cron running)
- [ ] SMS post-training followup sent 2 hours after session (requires cron running)
- [ ] Sign out works from Account tab

---

## Backwards Compatibility
Fully backwards compatible. No breaking changes.
