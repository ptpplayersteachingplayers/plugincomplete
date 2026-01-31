# PTP Training Platform v129.0.0 Changelog

**Release Date:** January 24, 2026

## Summary

This release addresses three key issues:
1. **Find Trainers Map** - Now shows training locations instead of home locations
2. **SMS Reminders** - Properly wired for booking confirmation, session reminders, and post-training follow-up
3. **Parent Dashboard** - Fixed name display to properly handle email-based usernames

---

## 1. Find Trainers Map - Training Location Fix

### Problem
The map on `/find-trainers/` was showing markers at trainers' home locations instead of their actual training locations. This made it difficult for parents to find trainers who train near them.

### Solution
- Updated `trainers-grid.php` to prioritize `training_locations` data when placing map markers
- When a trainer has multiple training locations with coordinates, the first one is used as the primary marker
- Added `primary_location` field to display location name in search results
- Falls back to home location only if no training locations exist

### Files Changed
- `templates/trainers-grid.php` - Line ~450-500: Updated data preparation to extract training location coords

---

## 2. SMS Reminders - Full Lifecycle Wiring

### Problem
SMS notifications were only partially configured. Post-training follow-up messages weren't being sent after sessions completed.

### Solution
Added complete SMS lifecycle support:

1. **Booking Confirmation** (already working) - Sent when booking is confirmed
2. **Session Reminder** (already working) - Sent 24 hours before session
3. **Post-Training Follow-Up** (NEW) - Sent after session completes, prompts for review

### New Hooks Added
```php
add_action('ptp_booking_completed', array(__CLASS__, 'send_post_training_followup'), 10, 2);
add_action('ptp_session_completed', array(__CLASS__, 'send_post_training_followup'), 10, 2);
```

### Post-Training SMS Message
```
Hi {parent_first}! Hope {player_name} had a great session with {trainer_name} today!

Quick feedback helps other families:
{review_url}

Thanks for choosing PTP!
```

### Files Changed
- `includes/class-ptp-sms.php` - Added hooks and `send_post_training_followup()` method
- `includes/class-ptp-fixes-v129.php` - Additional scheduling logic for timed follow-ups

---

## 3. Parent Dashboard - Name Display Fix

### Problem
When users sign up with just their email (no first/last name), the dashboard header displayed their full email address in uppercase (e.g., "MARTELLILUKE5@GMAIL.COM").

### Solution
Improved name extraction logic to:
1. Detect if display_name is an email address
2. Extract the username portion before the @ symbol
3. Separate camelCase names (e.g., "martelliluke" → "Martelli Luke")
4. Remove trailing numbers
5. Replace common separators (., _, -) with spaces
6. Capitalize properly
7. Fall back to "There" if no valid name can be extracted

### Example Transformations
| Input | Output |
|-------|--------|
| `martelliluke5@gmail.com` | `Martelli` |
| `john.smith@example.com` | `John` |
| `JohnDoe123@test.com` | `John` |
| `user_name@domain.com` | `User` |

### Files Changed
- `templates/parent-dashboard-v117.php` - Lines ~26-50: Enhanced name detection logic
- `includes/class-ptp-fixes-v129.php` - Reusable name extraction utility

---

## New Files

### `includes/class-ptp-fixes-v129.php`
Contains:
- `send_post_training_sms()` - Post-training follow-up logic
- `schedule_post_training_followup()` - Schedules follow-up 2 hours after session ends
- `send_post_training_followup_scheduled()` - Handles scheduled cron event
- `add_training_location_coords()` - Filter for trainer map data
- `fix_parent_display_name()` - Name extraction filter
- `extract_display_name()` - Utility to extract name from email
- `extract_first_name()` - Utility to get first name

---

## Testing Checklist

### Map Testing
- [ ] Visit `/find-trainers/`
- [ ] Verify map markers appear at training locations, not home addresses
- [ ] Check that clicking markers shows trainer info with location name
- [ ] Test with trainers who have multiple training locations

### SMS Testing
- [ ] Create a test booking → Confirm booking SMS received
- [ ] Wait for 24-hour reminder → Confirm reminder SMS received  
- [ ] Complete a session → Confirm post-training follow-up SMS received
- [ ] Verify SMS log table shows all messages

### Dashboard Testing
- [ ] Log in as user with email-only account (no first name set)
- [ ] Verify dashboard header shows extracted name, not full email
- [ ] Test various email formats:
  - `firstname.lastname@domain.com`
  - `firstnamelastname123@domain.com`
  - `user_name@domain.com`

---

## Deployment Notes

1. Upload all changed files to production
2. Clear any caching plugins
3. Test SMS by creating a test booking
4. Verify map displays correctly on `/find-trainers/`

---

## Rollback

If issues occur, revert to v128.2.9 by:
1. Removing `includes/class-ptp-fixes-v129.php`
2. Removing the require line for v129 fixes in `ptp-training-platform.php`
3. Reverting version number to 128.2.9
