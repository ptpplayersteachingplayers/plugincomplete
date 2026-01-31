# PTP Supabase App Integration

## 🚀 Quick Start

Your Supabase app connects directly to these tables and edge functions. Everything is optimized for speed and simplicity.

---

## 📱 For Parents (5 Key Actions)

### 1. Find Trainers Near Me
```typescript
// Simple query
const { data: trainers } = await supabase
  .from('trainer_cards')  // Use the view for speed
  .select('*')
  .order('rating', { ascending: false })
  .limit(20)

// With location (edge function)
const { data } = await supabase.functions.invoke('nearby-trainers', {
  body: { lat: 40.0379, lng: -76.3055, radius: 25 }
})
```

### 2. Book a Session (1-Tap)
```typescript
const { data, error } = await supabase.functions.invoke('quick-book', {
  body: {
    trainer_id: 'xxx',
    player_id: 'xxx',
    date: '2024-01-15',
    time: '10:00:00',
    payment_method_id: 'pm_xxx'
  }
})

// Returns: { success: true, booking_id: 'xxx', message: 'Booking confirmed!' }
```

### 3. View My Bookings
```typescript
const { data: bookings } = await supabase
  .from('upcoming_bookings')  // Use the view
  .select('*')
  .eq('parent_id', user.id)
  .order('session_date', { ascending: true })
```

### 4. Message Trainer
```typescript
// Send message
await supabase.from('messages').insert({
  conversation_id: 'xxx',
  sender_id: user.id,
  message: 'Hi! Looking forward to the session.'
})

// Real-time subscription
supabase
  .channel('messages')
  .on('postgres_changes', {
    event: 'INSERT',
    schema: 'public',
    table: 'messages',
    filter: `conversation_id=eq.${conversationId}`
  }, (payload) => {
    // New message received!
    addMessage(payload.new)
  })
  .subscribe()
```

### 5. Confirm Session Completed
```typescript
const { data } = await supabase.functions.invoke('confirm-session', {
  body: {
    booking_id: 'xxx',
    rating: 5,
    feedback: 'Great session!'
  }
})
```

---

## 💰 Upsells & Packages (Boost Revenue)

### Show Upsell Offers
```typescript
// After booking
const { data: upsells } = useUpsells({
  trigger: 'post_booking',
  trainerId: 'xxx',
  justBooked: 'training'
})

// Returns:
// [
//   { type: 'package', name: '5-Session Pack', discount: 15, price: 318.75, popular: true },
//   { type: 'camp_bundle', name: 'Winter Clinic', discount: 15, price: 212.50 },
//   { type: 'referral', headline: 'Get $25 Free', referral_code: 'PTPABC123' }
// ]
```

### Buy a Package (1-tap)
```typescript
const { mutate: buyPackage } = useBuyPackage()

buyPackage({
  trainer_id: 'xxx',
  session_count: 5,  // 3, 5, or 10
  payment_method_id: 'pm_xxx'
})

// → "5 sessions purchased! Save 15%"
```

### Check for Active Package
```typescript
const { data: package } = useHasPackage(trainerId)

if (package) {
  // Show: "You have 3 sessions remaining"
  // Book with package instead of paying
}
```

### Use Package Session
```typescript
// During booking, if user has package:
const { mutate: useSession } = usePackageSession(packageId)

// Create booking marked as "from package"
useSession(newBookingId)
```

### Referral Flow
```typescript
// Get user's referral info
const { data: referral } = useReferralInfo()
// → { code: 'PTPABC123', credits: 25, referral_count: 2 }

// Apply a referral code (new users)
const { mutate: applyCode } = useApplyReferralCode()
applyCode('FRIEND123')
// → { success: true, discount: 20 } // 20% off first booking
```

---

## 👨‍🏫 For Trainers (5 Key Actions)

### 1. View My Dashboard
```typescript
// Get earnings in one query
const { data: earnings } = await supabase
  .from('trainer_earnings')
  .select('*')
  .eq('trainer_id', trainerId)
  .single()

// Returns:
// {
//   available_balance: 340.00,
//   pending_balance: 160.00,
//   total_earned: 1240.00,
//   completed_sessions: 18,
//   upcoming_sessions: 3
// }
```

### 2. Set Availability
```typescript
// Set weekly schedule
await supabase.from('availability').upsert([
  { trainer_id, day_of_week: 1, start_time: '09:00', end_time: '12:00' }, // Monday
  { trainer_id, day_of_week: 1, start_time: '14:00', end_time: '18:00' },
  { trainer_id, day_of_week: 3, start_time: '09:00', end_time: '17:00' }, // Wednesday
  { trainer_id, day_of_week: 5, start_time: '09:00', end_time: '17:00' }, // Friday
])

// Block specific date
await supabase.from('availability').insert({
  trainer_id,
  specific_date: '2024-01-20',
  is_blocked: true
})
```

### 3. View Upcoming Sessions
```typescript
const { data: bookings } = await supabase
  .from('bookings')
  .select(`
    *,
    player:players(first_name, age),
    parent:profiles!parent_id(display_name, phone)
  `)
  .eq('trainer_id', trainerId)
  .in('status', ['pending', 'confirmed'])
  .gte('session_date', new Date().toISOString().split('T')[0])
  .order('session_date', { ascending: true })
```

### 4. Confirm Session & Get Paid
```typescript
const { data } = await supabase.functions.invoke('confirm-session', {
  body: { booking_id: 'xxx' }
})

// After both confirm, earnings become available
```

### 5. Cash Out (Instant)
```typescript
const { data } = await supabase.functions.invoke('instant-payout', {
  body: { 
    amount: 340.00,  // or omit for full balance
    payout_type: 'instant'  // or 'standard' (no fee)
  }
})

// Returns: { success: true, net_amount: 339.00, message: '$339.00 sent instantly!' }
```

---

## 🔔 Real-Time Subscriptions

```typescript
// New booking notification (for trainers)
supabase
  .channel('trainer-bookings')
  .on('postgres_changes', {
    event: 'INSERT',
    schema: 'public',
    table: 'bookings',
    filter: `trainer_id=eq.${trainerId}`
  }, (payload) => {
    showNotification('New Booking!', payload.new)
  })
  .subscribe()

// Message notifications
supabase
  .channel('my-messages')
  .on('postgres_changes', {
    event: 'INSERT',
    schema: 'public',
    table: 'messages',
    filter: `conversation_id=in.(${conversationIds.join(',')})`
  }, handleNewMessage)
  .subscribe()

// Booking status updates (for parents)
supabase
  .channel('my-bookings')
  .on('postgres_changes', {
    event: 'UPDATE',
    schema: 'public',
    table: 'bookings',
    filter: `parent_id=eq.${userId}`
  }, handleBookingUpdate)
  .subscribe()
```

---

## 💳 Stripe Integration

### Parent: Save Payment Method
```typescript
// 1. Create SetupIntent
const { data } = await supabase.functions.invoke('create-setup-intent')

// 2. Use Stripe SDK to collect card
const { setupIntent } = await stripe.confirmCardSetup(data.client_secret, {
  payment_method: { card: cardElement }
})

// 3. Save to database
await supabase.from('payment_methods').insert({
  user_id: user.id,
  stripe_payment_method_id: setupIntent.payment_method,
  brand: 'visa',
  last4: '4242',
  is_default: true
})
```

### Trainer: Connect Bank Account
```typescript
// 1. Start Stripe Connect
const { data } = await supabase.functions.invoke('stripe-connect-link')
// Returns: { url: 'https://connect.stripe.com/...' }

// 2. Open in browser/webview
Linking.openURL(data.url)

// 3. Handle return (deep link)
// Your app receives callback, trainer is now connected
```

---

## 📊 App Screens → Queries

| Screen | Query |
|--------|-------|
| **Home** (Parent) | `upcoming_bookings` view + `trainer_cards` featured |
| **Find Trainers** | `nearby-trainers` edge function |
| **Trainer Profile** | `trainers` + `reviews` + `availability` + `useUpsells()` |
| **Book Session** | `quick-book` edge function |
| **Booking Success** | `useUpsells({ trigger: 'post_booking' })` |
| **My Bookings** | `bookings` filtered by parent_id |
| **My Packages** | `useMyPackages()` |
| **Messages** | `conversations` + `messages` + realtime |
| **Home** (Trainer) | `trainer_earnings` + upcoming `bookings` |
| **Earnings** | `trainer_earnings` + `payouts` history |
| **Availability** | `availability` filtered by trainer_id |
| **Referrals** | `useReferralInfo()` |

---

## 🏗️ Database Views (Pre-optimized)

### `trainer_cards` - For listing trainers
```sql
SELECT id, slug, display_name, headline, photo_url, 
       hourly_rate, location, rating, review_count,
       specialties, is_verified, is_featured
FROM trainers WHERE is_active = true
```

### `upcoming_bookings` - For dashboards
```sql
SELECT b.*, 
       t.display_name as trainer_name,
       t.photo_url as trainer_photo,
       p.first_name as player_name
FROM bookings b
JOIN trainers t ON b.trainer_id = t.id
LEFT JOIN players p ON b.player_id = p.id
WHERE b.status IN ('pending', 'confirmed')
AND b.session_date >= CURRENT_DATE
```

---

## ⚡ Edge Functions Reference

| Function | Method | Purpose |
|----------|--------|---------|
| `quick-book` | POST | 1-tap booking with payment |
| `confirm-session` | POST | Confirm completed session |
| `instant-payout` | POST | Trainer cash out |
| `nearby-trainers` | GET | Geo search for trainers |
| `get-upsells` | GET | Smart upsell recommendations |
| `buy-package` | POST | Purchase 3/5/10 session pack |
| `send-push` | POST | Push notification (internal) |
| `stripe-connect-link` | POST | Get Stripe onboarding URL |
| `create-setup-intent` | POST | Save card for parent |

---

## 🔐 Row Level Security

All tables have RLS enabled. Users can only:
- **Read:** Public trainer profiles, own bookings/messages/players
- **Write:** Own profile, own players, send messages in own conversations
- **Create:** Bookings (as parent), messages (in own conversations)

Service role key bypasses RLS for admin operations.

---

## 📱 React Native Example

```typescript
// hooks/useBookings.ts
export function useUpcomingBookings() {
  const { user } = useAuth()
  
  return useQuery(['bookings', user?.id], async () => {
    const { data, error } = await supabase
      .from('upcoming_bookings')
      .select('*')
      .eq('parent_id', user.id)
      .order('session_date')
    
    if (error) throw error
    return data
  })
}

// hooks/useQuickBook.ts
export function useQuickBook() {
  const queryClient = useQueryClient()
  
  return useMutation(
    async (booking: QuickBookParams) => {
      const { data, error } = await supabase.functions.invoke('quick-book', {
        body: booking
      })
      if (error) throw error
      return data
    },
    {
      onSuccess: () => {
        queryClient.invalidateQueries(['bookings'])
        showToast('Booking confirmed! 🎉')
      }
    }
  )
}
```

---

## 🚀 Deploy Checklist

1. **Run schema.sql** in Supabase SQL editor
2. **Deploy edge functions:**
   ```bash
   supabase functions deploy quick-book
   supabase functions deploy confirm-session
   supabase functions deploy instant-payout
   supabase functions deploy nearby-trainers
   supabase functions deploy send-push
   ```
3. **Set secrets:**
   ```bash
   supabase secrets set STRIPE_SECRET_KEY=sk_live_xxx
   supabase secrets set FCM_SERVER_KEY=xxx
   ```
4. **Enable realtime** for: bookings, messages, notifications
5. **Test** with Supabase dashboard

---

## 💡 Tips for Speed

1. **Use views** (`trainer_cards`, `upcoming_bookings`) - pre-joined, indexed
2. **Subscribe once** - batch your realtime subscriptions
3. **Cache aggressively** - trainer profiles don't change often
4. **Optimistic updates** - update UI before server confirms
5. **Edge functions** - run complex logic close to user

---

## 🔗 WordPress Sync

The `PTP_Supabase_Bridge` class syncs data from WordPress:
- Trainer profiles → `trainers` table
- Bookings → `bookings` table
- Messages → `messages` table

Configure in WordPress Admin → PTP → Supabase Sync
