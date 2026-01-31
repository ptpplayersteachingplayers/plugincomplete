# PTP Training Platform v132.0.0 - Changelog

## Release Date: January 25, 2026

## New Features

### 1. Open Training Dates (Trainer Dashboard)
Trainers can now set specific future dates when they're available for training sessions.

**Location**: Trainer Dashboard → Schedule Tab

**Features**:
- Add specific dates with custom time windows (e.g., "Feb 15, 10am-6pm")
- Optional location field for each date (e.g., "Villanova turf fields")
- View and manage all upcoming open dates
- Remove dates with one click

**Benefits**:
- Families can see exactly when trainers are available
- Trainers can highlight special availability (school breaks, weekends)
- More precise than weekly recurring availability

### 2. Schedule Status Prompt
New banner at the top of the Schedule tab that encourages trainers to keep their schedule active.

**States**:
- 📅 **Inactive**: "Set Your Availability" - when no weekly hours or open dates set
- ✨ **Needs Dates**: "Add Specific Training Dates" - has weekly hours but no open dates
- 🟢 **Active**: "Schedule Active" - shows count of open dates in next 30 days

### 3. Apply Page Mobile Header Fix
Fixed the header overlap issue on the /apply page for mobile devices.

**Changes**:
- Added proper padding-top accounting for fixed header height
- iOS Safari safe-area support
- High-specificity CSS to override theme styles

### 4. Multi-Player Email Support
Confirmation emails now display all players for group/multi-player sessions.

**Parent Confirmation Email**:
- Shows "Players (3)" header with numbered badges for each player
- Subject line: "Booking Confirmed - Luke + 2 more with Coach Name"
- Hero text adapts: "3 players' session is all set" vs "Luke's session is all set"

**Trainer Notification Email**:
- Subject line: "New Group Booking - 3 Players" vs "New Booking - Luke"
- Players section shows numbered list of all registered players
- Clearly indicates group booking in header

## Technical Details

### Database
New table: `wp_ptp_open_dates`
```sql
CREATE TABLE wp_ptp_open_dates (
    id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    trainer_id bigint(20) UNSIGNED NOT NULL,
    date date NOT NULL,
    start_time time NOT NULL DEFAULT '09:00:00',
    end_time time NOT NULL DEFAULT '17:00:00',
    location varchar(255) DEFAULT '',
    notes text DEFAULT NULL,
    created_at datetime DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY trainer_date (trainer_id, date)
);
```

Uses existing `group_players` TEXT column in `wp_ptp_bookings` to store multi-player data as JSON.

### New AJAX Endpoints
- `ptp_add_open_date` - Add a new open training date
- `ptp_get_open_dates` - Get all open dates for a trainer
- `ptp_remove_open_date` - Remove an open date

### Files Modified
- `templates/apply.php` - Mobile header fix
- `templates/trainer-dashboard-v117.php` - Open dates UI and schedule prompt
- `includes/class-ptp-availability.php` - Open dates AJAX handlers and table creation
- `includes/class-ptp-unified-checkout.php` - Store players_data in group_players column
- `includes/class-ptp-email.php` - Multi-player support in send_booking_confirmation and send_trainer_new_booking
- `includes/class-ptp-email-templates.php` - Multi-player display in booking_confirmation template
- `ptp-training-platform.php` - Version bump

## Backward Compatibility
- All existing features remain unchanged
- Weekly availability still works as before
- Block dates feature still works as before
- Single player bookings work exactly as before
- Table is created automatically on first use

## Future Enhancements (Planned)
- Show open dates on trainer public profile
- Calendar view for open dates
- Recurring open date patterns (e.g., "every Saturday in February")
- Integration with booking calendar to highlight open dates
