# PTP Supabase Integration

## 🎯 What This Does

Connects your WordPress platform to a Supabase-powered mobile app for lightning-fast, real-time experiences.

```
┌─────────────┐          ┌─────────────┐          ┌─────────────┐
│  WordPress  │  ──sync──▶│  Supabase   │◀──────── │  Mobile App │
│  (Backend)  │          │  (Realtime) │          │  (Fast UI)  │
└─────────────┘          └─────────────┘          └─────────────┘
```

## 📁 Files

| File | Purpose |
|------|---------|
| `schema.sql` | Complete Supabase database schema |
| `APP-GUIDE.md` | How to use from your app |
| `app-hooks.ts` | React Native hooks (copy to app) |
| `functions/` | Edge functions for 1-tap actions |

## ⚡ Quick Setup

### 1. Create Supabase Project
Go to [supabase.com](https://supabase.com) and create a new project.

### 2. Run Schema
Copy `schema.sql` into SQL Editor and run it.

### 3. Deploy Edge Functions
```bash
cd supabase
supabase functions deploy quick-book
supabase functions deploy confirm-session
supabase functions deploy instant-payout
supabase functions deploy nearby-trainers
supabase functions deploy send-push
```

### 4. Set Secrets
```bash
supabase secrets set STRIPE_SECRET_KEY=sk_live_xxx
supabase secrets set FCM_SERVER_KEY=xxx
```

### 5. Configure WordPress
1. Go to **PTP → Supabase Sync**
2. Enter your Supabase URL and keys
3. Click **Full Sync Now**

### 6. Copy Hooks to App
Copy `app-hooks.ts` to your React Native app's hooks folder.

## 🏃 Parent Flow (3 taps)

1. **Find** → Browse trainers
2. **Book** → Select time & pay (1-tap)
3. **Confirm** → After session (1-tap)

## 👨‍🏫 Trainer Flow (2 taps)

1. **Confirm** → Session complete (1-tap)
2. **Cash Out** → Instant payout (1-tap)

## 📱 Key App Queries

```typescript
// Home screen
const { data: featured } = useFeaturedTrainers()
const { data: upcoming } = useMyBookings()

// Book session
const { mutate: book } = useQuickBook()
book({ trainer_id, player_id, date, time, payment_method_id })

// Trainer earnings
const { data: earnings } = useTrainerEarnings()
const { mutate: cashOut } = useInstantPayout()
```

## 🔔 Real-Time Features

- New booking → Trainer gets push notification
- New message → Instant delivery
- Session confirmed → Earnings updated

All handled automatically by Supabase realtime + edge functions.

## 💡 Why Supabase?

| Feature | WordPress API | Supabase |
|---------|---------------|----------|
| Latency | 200-500ms | 20-50ms |
| Real-time | Polling | WebSocket |
| Offline | ❌ | ✅ |
| Auth | Custom | Built-in |
| RLS | Manual | Automatic |

Your app stays fast. WordPress handles payments & admin.
