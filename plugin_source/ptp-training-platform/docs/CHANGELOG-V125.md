# PTP Training Platform v125.0.0 Changelog

**Release Date:** January 23, 2026

## New Features

### Calendar Enhancements
- **Recurring Sessions** - Create weekly or bi-weekly recurring sessions with a single click
  - Select trainer, day of week, time, duration
  - Preview all dates before creating
  - Auto-detects and warns about conflicts
  - Skip conflicting dates automatically
  
- **Conflict Detection** - Prevent double-booking trainers
  - Real-time conflict checking when creating sessions
  - "Check Conflicts" button shows all conflicts in next 30 days
  - Checks both admin sessions AND parent bookings
  
- **Session Reminders** - Automated email/SMS reminders
  - Cron job sends reminders 24 hours before sessions
  - Emails both trainer and parent
  - SMS via Salesmsg integration (if configured)
  - Manual reminder sending from admin
  
### Google Reviews Integration v2.0
- **Review-to-Google Mapping** - Convert platform reviews to Google reviews
  - After 4-5 star review, shows popup asking to leave Google review
  - Tracks: prompted, clicked, dismissed
  - Click rate analytics in admin
  - Won't re-prompt users who clicked or recently dismissed
  
- **Admin Dashboard** (PTP Settings > Google Reviews)
  - Enter Google Place ID
  - View click-through stats
  - Copy direct Google review link
  - See current Google rating

### SEO Titles v125
- Removed all MLS references (now "NCAA D1 college athletes")
- Updated year to 2026
- Added "youth camps", "kid camps" keywords
- 115+ location pages including all Main Line cities

## Bug Fixes

### Find Trainers Page (/find-trainers/)
- **Fixed header gap** - Removed space between theme header and hero
- **Hides PTP plugin header** - Uses theme/Elementor header only
- Cleaner CSS without redundant overrides
- JavaScript backup for gap removal

## Technical Changes

### New Files
- `includes/class-ptp-calendar-enhancements.php` - Recurring sessions, conflict detection, reminders
- `includes/class-ptp-google-reviews.php` - Updated to v2.0 with review mapping

### Database
- Added `reminder_sent` column to `ptp_sessions` table
- Added `reminder_sent` column to `ptp_bookings` table
- Created `ptp_google_review_requests` tracking table

### Cron Jobs
- Added `ptp_send_session_reminders` - Runs hourly to send 24hr reminders

## Admin Calendar New Buttons
- **🔄 Recurring** - Opens recurring session creation modal
- **⚠️ Conflicts** - Shows all detected conflicts in next 30 days

## Usage

### Create Recurring Sessions
1. Go to PTP Training > Schedule Calendar
2. Click "🔄 Recurring" button
3. Select trainer, day, time, duration
4. Set frequency (weekly/bi-weekly) and number of sessions
5. Click "Preview" to see dates and check for conflicts
6. Click "Create Sessions" to bulk create

### Setup Google Reviews
1. Go to PTP Settings > Google Reviews
2. Enter your Google Place ID (find at Google's Place ID Finder)
3. Optionally add Google Maps API key to display reviews
4. Parents who leave 4-5 star reviews will automatically see prompt
