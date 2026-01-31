# PTP Training Platform v128.0.1

## Release Date: January 2026

## Summary
Hotfix for critical error introduced in v128.0.0 with Google OAuth web login.

---

## Bug Fixes

### 1. Critical Error: Google OAuth Route Handling

**Problem:** The Google OAuth web login was using `template_redirect` hook which fires too late in the WordPress lifecycle. When visiting `/login/google/` or `/register/google/`, WordPress would sometimes 404 before the route handler could intercept, causing critical errors.

**Solution:** 
- Changed from `template_redirect` to `parse_request` hook with priority 1
- This intercepts the routes before WordPress determines a 404
- Fixed regex to handle query strings and WordPress subdirectory installations

**Files Changed:**
- `includes/class-ptp-google-web-login.php`

### 2. Missing class_exists Check in register.php

**Problem:** The register template was calling `PTP_Trainer::get_by_user_id()` without checking if the class exists first, which could cause fatal errors in edge cases.

**Solution:** Added `class_exists('PTP_Trainer')` check before calling the method.

**Files Changed:**
- `templates/register.php`

### 3. Invalid HTML: Nested Body Tag in login.php

**Problem:** The login template had a `<body>` tag inside the template after `get_header()` was called, creating invalid HTML.

**Solution:** Removed the nested `<body>` tag and moved the class to the wrapper div.

**Files Changed:**
- `templates/login.php`

---

## Testing Checklist

### Google OAuth Login
- [ ] Visit `/login/google/` redirects to Google OAuth consent
- [ ] Visit `/register/google/` redirects to Google OAuth consent
- [ ] Query strings like `/login/google/?redirect_to=/checkout/` work correctly
- [ ] OAuth callback completes and logs user in
- [ ] New users via Google OAuth are created correctly
- [ ] Existing users can link their Google account

### Login/Register Pages
- [ ] Login page loads without PHP errors
- [ ] Register page loads without PHP errors
- [ ] HTML validates (no nested body tags)
- [ ] Logged-in users are redirected to appropriate dashboard

---

## Files Changed

```
Modified:
- ptp-training-platform.php (version bump to 128.0.1)
- includes/class-ptp-google-web-login.php (route handling fix)
- templates/login.php (HTML fix)
- templates/register.php (class_exists check)

Added:
- docs/CHANGELOG-V128.0.1.md
```
