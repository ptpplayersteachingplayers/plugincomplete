# PTP Training Platform v89.0.0 - CHANGELOG

**Released:** January 14, 2026

## Summary
Complete fixes for cart, messaging, account/profile editing, and application pages.

---

## Fixed

### Cart (ptp-cart.php)
- **Dynamic width** - Cart now adapts to content width instead of fixed max-width
- Responsive breakpoints: 
  - Mobile: Full width with 20px padding
  - Tablet: Max 1200px  
  - Desktop: Min of 1400px or 85% viewport width
- Better visual balance across all screen sizes

### Messaging (messaging.php)
- **Fully functional messaging** - Now loads actual conversations from database
- Works for both trainers and parents
- Real-time message polling (3 second interval)
- Proper conversation switching
- Unread message counts
- Mobile-optimized split view (sidebar + chat)
- Auto-scroll to newest messages
- Mark as read when conversation is opened

### Account (account.php)
- **AJAX profile updates** - No more page reloads
- Toast notifications for success/error feedback
- Phone number auto-formatting (XXX) XXX-XXXX
- AJAX player adding with proper feedback
- Better error handling throughout

### Apply (apply.php)
- Better AJAX error handling
- Phone number auto-formatting
- More graceful handling of admin-post redirects
- Always shows success message on completion

---

## Technical Details

### Database Tables Used
- `ptp_conversations` - Conversation threads
- `ptp_messages` - Individual messages
- `ptp_trainers` - Trainer data
- `ptp_parents` - Parent data
- `ptp_players` - Player profiles

### AJAX Endpoints Used
- `ptp_update_profile` - Profile updates
- `ptp_add_player` - Add player
- `ptp_get_conversations` - List conversations
- `ptp_send_message` - Send message
- `ptp_get_new_messages` - Poll for new messages
- `ptp_trainer_apply` - Submit application

---

## Testing Checklist

- [ ] Cart displays at correct width on mobile/tablet/desktop
- [ ] Messaging loads conversations for logged-in users
- [ ] Can send and receive messages in real-time
- [ ] Profile edits save via AJAX with toast notification
- [ ] Can add players via AJAX
- [ ] Application form submits successfully
- [ ] Phone formatting works on all forms
