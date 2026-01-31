# PTP v117 Staging Test Checklist

## Pre-Test Setup
- [ ] Upload `ptp-training-platform-v117.x.x.zip` to staging
- [ ] Deactivate old PTP plugin
- [ ] Activate v117 plugin
- [ ] Clear all caches (WP cache, CDN, browser)

---

## Desktop Tests (Chrome/Safari)

### 1. Public Pages
| Test | URL | Expected | Pass |
|------|-----|----------|------|
| Homepage loads | `/` | No errors, trainers visible | ☐ |
| Find trainers | `/find-trainers/` | Grid loads, filters work | ☐ |
| Trainer profile | Click any trainer | Profile loads, book button visible | ☐ |
| Login page | `/login/` | Form renders | ☐ |
| Register page | `/register/` | Form renders | ☐ |

### 2. Parent Flow
| Test | Steps | Expected | Pass |
|------|-------|----------|------|
| Login | Email + password | Redirects to `/my-training/` | ☐ |
| Dashboard | View `/my-training/` | Shows upcoming sessions, players | ☐ |
| Messages | Click messages tab | Conversations load | ☐ |
| Send message | Type + send | Message appears in thread | ☐ |
| View trainer | Click trainer profile | Profile loads | ☐ |
| Book session | Select date/time → checkout | Payment form loads | ☐ |
| Complete booking | Enter test card `4242...` | Confirmation shown | ☐ |

### 3. Trainer Flow
| Test | Steps | Expected | Pass |
|------|-------|----------|------|
| Login | Email + password | Redirects to `/trainer-dashboard/` | ☐ |
| Dashboard loads | View dashboard | Earnings, sessions, messages visible | ☐ |
| Update availability | Click schedule → toggle day | Saves without error | ☐ |
| View earnings | Click earnings tab | Shows totals, recent payouts | ☐ |
| Messages | Click messages | Conversations load | ☐ |
| Reply to message | Type + send | Message appears | ☐ |
| Edit profile | Update bio → save | Changes persist on reload | ☐ |

### 4. Booking & Payment
| Test | Steps | Expected | Pass |
|------|-------|----------|------|
| Add to cart | Select session → add | Cart icon updates | ☐ |
| View cart | Click cart | Items listed with prices | ☐ |
| Checkout | Enter payment info | Stripe form works | ☐ |
| Payment success | Submit `4242 4242 4242 4242` | Success page, email sent | ☐ |
| Payment failure | Submit `4000 0000 0000 0002` | Error shown, can retry | ☐ |

---

## Mobile Tests (iPhone Safari + Android Chrome)

### 5. Mobile Navigation
| Test | Expected | Pass |
|------|----------|------|
| Homepage responsive | No horizontal scroll, readable text | ☐ |
| Menu opens/closes | Hamburger works, links clickable | ☐ |
| Trainer grid | Cards stack vertically | ☐ |
| Buttons tappable | 44px+ tap targets, no mis-taps | ☐ |

### 6. Mobile Trainer Dashboard
| Test | Expected | Pass |
|------|----------|------|
| Dashboard loads | All sections visible | ☐ |
| Tabs work | Can switch between tabs | ☐ |
| Schedule editor | Can tap days, toggle availability | ☐ |
| No horizontal scroll | Content fits viewport | ☐ |
| Forms usable | Inputs not cut off, keyboard doesn't hide fields | ☐ |

### 7. Mobile Parent Dashboard
| Test | Expected | Pass |
|------|----------|------|
| Dashboard loads | Sessions, players visible | ☐ |
| Messages work | Can read and send | ☐ |
| Booking flow | Calendar scrollable, times tappable | ☐ |
| Checkout | Payment form usable on mobile | ☐ |

### 8. Mobile Booking Flow
| Test | Expected | Pass |
|------|----------|------|
| Find trainer | Grid loads, can scroll | ☐ |
| View profile | Loads, book button visible | ☐ |
| Select date | Calendar usable | ☐ |
| Select time | Time slots tappable | ☐ |
| Checkout | Form fields work, keyboard doesn't break layout | ☐ |
| Payment | Stripe form works on mobile | ☐ |

---

## Error Monitoring

### 9. Console & Logs
| Check | How | Expected | Pass |
|-------|-----|----------|------|
| JS errors | Browser console | No red errors | ☐ |
| PHP errors | `wp-content/debug.log` | No fatal errors | ☐ |
| 404s | Network tab | No broken resources | ☐ |
| AJAX failures | Network tab → XHR | All return 200 | ☐ |

---

## Quick Smoke Test (5 minutes)

If short on time, just do these:

1. [ ] `/trainer-dashboard/` loads for logged-in trainer
2. [ ] `/my-training/` loads for logged-in parent  
3. [ ] Can send a message (either direction)
4. [ ] Can update trainer availability
5. [ ] Booking flow reaches payment step

---

## Test Cards (Stripe)

| Card | Result |
|------|--------|
| `4242 4242 4242 4242` | Success |
| `4000 0000 0000 0002` | Decline |
| `4000 0025 0000 3155` | Requires 3DS |

Use any future expiry (e.g., `12/28`) and any CVC (e.g., `123`).

---

## Post-Test

- [ ] All critical tests pass
- [ ] No console errors
- [ ] No PHP fatal errors in logs
- [ ] Ready for production deploy

**Sign-off:** _______________ **Date:** _______________
