# PTP Training Platform - Changelog v81

## v81.1.0 - Performance & Mobile Optimization

### Performance Improvements
- **Font Preloading**: Added `preconnect` and `dns-prefetch` for Google Fonts and external resources
- **Trainers List Caching**: Default trainer queries now cached for 5 minutes (transients)
- **Cache Invalidation**: Automatic cache clearing when trainers are updated
- **Skeleton Loading**: Added shimmer skeleton placeholders while loading trainers grid
- **Performance Class**: New `PTP_Performance` class for centralized optimizations

### Mobile Optimizations
- **Parent Dashboard**: Fixed viewport to allow user zoom (accessibility)
- **Parent Dashboard**: Added safe-area handling for iPhone notch
- **Parent Dashboard**: Added global 16px font rule for iOS zoom prevention
- **Trainers Grid**: Added 16px font-size for select elements (iOS zoom)
- **Trainers Grid**: Added 44px min-height touch targets for filters
- **Trainers Grid**: Added 48px min-height touch targets for buttons
- **Trainers Grid**: Added safe-area handling for map toggle button
- **Trainers Grid**: Added border-radius to map toggle button

### Bug Fixes
- **Trainers AJAX**: Fixed GET/POST mismatch (now uses `$_REQUEST`)
- **Trainers AJAX**: Fixed empty params error in SQL queries
- **Trainers AJAX**: Added database table existence check
- **Trainers AJAX**: Better error logging for debugging

## v81.0.0 - Mobile CSS Fixes

### Templates Updated
- `trainer-dashboard-v73.php`: Mobile responsive fixes
- `parent-dashboard-v2.php`: Mobile responsive fixes
- `login.php`: Self-contained with full mobile optimization
- `register.php`: Self-contained with full mobile optimization
- `logout.php`: Self-contained with full mobile optimization

### Mobile Features
- Complete HTML document structure for auth pages
- Theme hiding CSS (WordPress, Kadence, Astra, block themes)
- iOS zoom prevention (16px fonts on inputs)
- Touch-friendly targets (44px minimum)
- Responsive breakpoints (768px, 600px, 480px, 380px, 360px)
- Modern CSS (100dvh, flexbox, grid)
- No horizontal overflow
- Proper viewport meta tags

---

## Verification Checklist

### Speed Tests
- [ ] First Contentful Paint < 2s on 3G
- [ ] Time to Interactive < 4s on 3G
- [ ] No layout shifts (CLS < 0.1)
- [ ] Font swap working (no FOIT)

### Mobile Tests
- [ ] iOS Safari: No zoom on input focus
- [ ] iOS Safari: Safe area handling on iPhone X+
- [ ] Touch targets: All buttons/links easy to tap
- [ ] Landscape: No horizontal scrolling
- [ ] Android Chrome: Smooth scrolling

### Functionality Tests
- [ ] Trainers load on /find-trainers/
- [ ] Filters work correctly
- [ ] Trainer profile pages load
- [ ] Login/Register work
- [ ] Dashboard loads for trainers
- [ ] Dashboard loads for parents
