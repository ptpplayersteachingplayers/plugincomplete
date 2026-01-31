# PTP Training Platform v68.0.0

## Release Notes - December 28, 2025

### Trainer Profile Improvements

This release makes the trainer profile page cleaner and more professional, with improved mobile experience.

#### Changes Made:

1. **Simplified Camps & Clinics Cross-Sell**
   - Cleaner, minimal card design
   - Mobile-first horizontal scroll  
   - Smaller, more compact cards (160px on mobile, 4-column grid on desktop)
   - Shows only 4 camps instead of 6
   - Shows only essential info: name, date/location, price

2. **Removed "Save with Packages" Bottom Section**
   - Eliminated the redundant packages section that appeared at page bottom
   - Package options (5-pack, 10-pack) remain in the booking widget sidebar

3. **Relocated Message Trainer to Sidebar**
   - "Message Trainer" form now sits under the booking widget on right side
   - Creates better visual flow with all CTAs together
   - More professional layout matching modern booking platforms

4. **Cleaner Layout**
   - Removed Message and Text Us cards from left content column
   - Simplified "Questions? Text us" link in booking widget
   - Reduced visual clutter throughout

### Files Changed:
- `templates/trainer-profile-v2.php` - Updated layout (v68)
- `includes/class-ptp-camps-crosssell.php` - Simplified design

### Design System:
- Fonts: Oswald (headings), Inter (body)
- Colors: #FCB900 (gold), #0A0A0A (black)
- Rounded corners (8-12px border-radius)
- Uppercase labels with letter-spacing
