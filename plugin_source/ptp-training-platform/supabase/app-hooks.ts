/**
 * PTP Supabase Hooks for React Native / Expo
 * Copy these to your app's hooks folder
 * 
 * Install: npm install @supabase/supabase-js @tanstack/react-query
 */

import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useEffect, useState } from 'react'
import { supabase } from '../lib/supabase' // Your supabase client
import { useAuth } from './useAuth'

// ============================================================================
// TYPES
// ============================================================================

interface Trainer {
  id: string
  slug: string
  display_name: string
  headline: string
  photo_url: string
  hourly_rate: number
  location: string
  rating: number
  review_count: number
  specialties: string[]
  is_verified: boolean
}

interface Booking {
  id: string
  trainer_id: string
  session_date: string
  start_time: string
  status: 'pending' | 'confirmed' | 'completed' | 'cancelled'
  amount: number
  trainer_name: string
  trainer_photo: string
  player_name: string
}

interface Earnings {
  available_balance: number
  pending_balance: number
  total_earned: number
  completed_sessions: number
  upcoming_sessions: number
}

// ============================================================================
// PARENT HOOKS
// ============================================================================

/**
 * Get featured trainers for home screen
 */
export function useFeaturedTrainers() {
  return useQuery<Trainer[]>(['trainers', 'featured'], async () => {
    const { data, error } = await supabase
      .from('trainer_cards')
      .select('*')
      .eq('is_featured', true)
      .order('rating', { ascending: false })
      .limit(10)
    
    if (error) throw error
    return data || []
  })
}

/**
 * Search trainers nearby
 */
export function useNearbyTrainers(lat: number, lng: number, radius = 25) {
  return useQuery<Trainer[]>(
    ['trainers', 'nearby', lat, lng, radius],
    async () => {
      const { data, error } = await supabase.functions.invoke('nearby-trainers', {
        body: { lat, lng, radius }
      })
      
      if (error) throw error
      return data?.trainers || []
    },
    { enabled: lat !== 0 && lng !== 0 }
  )
}

/**
 * Get trainer profile with reviews
 */
export function useTrainerProfile(slug: string) {
  return useQuery(['trainer', slug], async () => {
    const { data: trainer, error } = await supabase
      .from('trainers')
      .select(`
        *,
        reviews:reviews(id, rating, comment, created_at, parent:profiles!parent_id(display_name))
      `)
      .eq('slug', slug)
      .eq('is_active', true)
      .single()
    
    if (error) throw error
    return trainer
  })
}

/**
 * Get trainer's available slots for a date
 */
export function useTrainerSlots(trainerId: string, date: string) {
  return useQuery(
    ['trainer', trainerId, 'slots', date],
    async () => {
      // Get trainer's availability
      const dayOfWeek = new Date(date).getDay()
      
      const { data: availability } = await supabase
        .from('availability')
        .select('*')
        .eq('trainer_id', trainerId)
        .or(`day_of_week.eq.${dayOfWeek},specific_date.eq.${date}`)
      
      // Get existing bookings
      const { data: bookings } = await supabase
        .from('bookings')
        .select('start_time')
        .eq('trainer_id', trainerId)
        .eq('session_date', date)
        .neq('status', 'cancelled')
      
      const bookedTimes = new Set(bookings?.map(b => b.start_time) || [])
      
      // Generate available slots
      const slots: string[] = []
      availability?.forEach(a => {
        if (a.is_blocked) return
        
        let time = new Date(`2000-01-01T${a.start_time}`)
        const end = new Date(`2000-01-01T${a.end_time}`)
        
        while (time < end) {
          const timeStr = time.toTimeString().slice(0, 8)
          if (!bookedTimes.has(timeStr)) {
            slots.push(timeStr)
          }
          time.setHours(time.getHours() + 1)
        }
      })
      
      return slots.sort()
    },
    { enabled: !!trainerId && !!date }
  )
}

/**
 * 1-Tap Booking
 */
export function useQuickBook() {
  const queryClient = useQueryClient()
  
  return useMutation(
    async (params: {
      trainer_id: string
      player_id: string
      date: string
      time: string
      payment_method_id: string
      location?: string
      notes?: string
    }) => {
      const { data, error } = await supabase.functions.invoke('quick-book', {
        body: params
      })
      
      if (error) throw error
      if (data.error) throw new Error(data.error)
      
      return data
    },
    {
      onSuccess: () => {
        queryClient.invalidateQueries(['bookings'])
      }
    }
  )
}

/**
 * Get parent's upcoming bookings
 */
export function useMyBookings() {
  const { user } = useAuth()
  
  return useQuery<Booking[]>(
    ['bookings', user?.id],
    async () => {
      const { data, error } = await supabase
        .from('upcoming_bookings')
        .select('*')
        .eq('parent_id', user?.id)
        .order('session_date', { ascending: true })
      
      if (error) throw error
      return data || []
    },
    { enabled: !!user }
  )
}

/**
 * Get parent's players (kids)
 */
export function useMyPlayers() {
  const { user } = useAuth()
  
  return useQuery(
    ['players', user?.id],
    async () => {
      const { data, error } = await supabase
        .from('players')
        .select('*')
        .eq('parent_id', user?.id)
        .order('first_name')
      
      if (error) throw error
      return data || []
    },
    { enabled: !!user }
  )
}

/**
 * Confirm session completed (parent side)
 */
export function useConfirmSession() {
  const queryClient = useQueryClient()
  
  return useMutation(
    async (params: { booking_id: string; rating?: number; feedback?: string }) => {
      const { data, error } = await supabase.functions.invoke('confirm-session', {
        body: params
      })
      
      if (error) throw error
      return data
    },
    {
      onSuccess: () => {
        queryClient.invalidateQueries(['bookings'])
      }
    }
  )
}

// ============================================================================
// TRAINER HOOKS
// ============================================================================

/**
 * Get trainer's earnings dashboard
 */
export function useTrainerEarnings() {
  const { user } = useAuth()
  
  return useQuery<Earnings>(
    ['trainer', 'earnings', user?.id],
    async () => {
      // First get trainer ID
      const { data: trainer } = await supabase
        .from('trainers')
        .select('id')
        .eq('user_id', user?.id)
        .single()
      
      if (!trainer) throw new Error('Not a trainer')
      
      const { data, error } = await supabase
        .from('trainer_earnings')
        .select('*')
        .eq('trainer_id', trainer.id)
        .single()
      
      if (error && error.code !== 'PGRST116') throw error
      
      return data || {
        available_balance: 0,
        pending_balance: 0,
        total_earned: 0,
        completed_sessions: 0,
        upcoming_sessions: 0
      }
    },
    { enabled: !!user }
  )
}

/**
 * Get trainer's upcoming sessions
 */
export function useTrainerBookings() {
  const { user } = useAuth()
  
  return useQuery<Booking[]>(
    ['trainer', 'bookings', user?.id],
    async () => {
      const { data: trainer } = await supabase
        .from('trainers')
        .select('id')
        .eq('user_id', user?.id)
        .single()
      
      if (!trainer) return []
      
      const { data, error } = await supabase
        .from('bookings')
        .select(`
          *,
          player:players(first_name, age),
          parent:profiles!parent_id(display_name, phone)
        `)
        .eq('trainer_id', trainer.id)
        .in('status', ['pending', 'confirmed'])
        .gte('session_date', new Date().toISOString().split('T')[0])
        .order('session_date', { ascending: true })
      
      if (error) throw error
      return data || []
    },
    { enabled: !!user }
  )
}

/**
 * Instant payout
 */
export function useInstantPayout() {
  const queryClient = useQueryClient()
  
  return useMutation(
    async (amount?: number) => {
      const { data, error } = await supabase.functions.invoke('instant-payout', {
        body: { amount, payout_type: 'instant' }
      })
      
      if (error) throw error
      if (data.error) throw new Error(data.error)
      
      return data
    },
    {
      onSuccess: () => {
        queryClient.invalidateQueries(['trainer', 'earnings'])
      }
    }
  )
}

/**
 * Update trainer availability
 */
export function useUpdateAvailability() {
  const { user } = useAuth()
  const queryClient = useQueryClient()
  
  return useMutation(
    async (slots: Array<{
      day_of_week?: number
      specific_date?: string
      start_time: string
      end_time: string
      is_blocked?: boolean
    }>) => {
      const { data: trainer } = await supabase
        .from('trainers')
        .select('id')
        .eq('user_id', user?.id)
        .single()
      
      if (!trainer) throw new Error('Not a trainer')
      
      // Clear existing and re-insert
      await supabase
        .from('availability')
        .delete()
        .eq('trainer_id', trainer.id)
      
      const { error } = await supabase
        .from('availability')
        .insert(slots.map(s => ({ ...s, trainer_id: trainer.id })))
      
      if (error) throw error
    },
    {
      onSuccess: () => {
        queryClient.invalidateQueries(['trainer', 'availability'])
      }
    }
  )
}

// ============================================================================
// MESSAGING HOOKS
// ============================================================================

/**
 * Get conversations list
 */
export function useConversations() {
  const { user } = useAuth()
  
  return useQuery(
    ['conversations', user?.id],
    async () => {
      const { data, error } = await supabase
        .from('conversations')
        .select(`
          *,
          trainer:trainers(display_name, photo_url),
          parent:profiles!parent_id(display_name)
        `)
        .or(`parent_id.eq.${user?.id},trainer_id.in.(select id from trainers where user_id='${user?.id}')`)
        .order('last_message_at', { ascending: false })
      
      if (error) throw error
      return data || []
    },
    { enabled: !!user }
  )
}

/**
 * Get messages in a conversation
 */
export function useMessages(conversationId: string) {
  return useQuery(
    ['messages', conversationId],
    async () => {
      const { data, error } = await supabase
        .from('messages')
        .select('*')
        .eq('conversation_id', conversationId)
        .order('created_at', { ascending: true })
      
      if (error) throw error
      return data || []
    },
    { enabled: !!conversationId }
  )
}

/**
 * Send a message
 */
export function useSendMessage() {
  const { user } = useAuth()
  const queryClient = useQueryClient()
  
  return useMutation(
    async ({ conversationId, message }: { conversationId: string; message: string }) => {
      const { error } = await supabase
        .from('messages')
        .insert({
          conversation_id: conversationId,
          sender_id: user?.id,
          message
        })
      
      if (error) throw error
    },
    {
      onSuccess: (_, { conversationId }) => {
        queryClient.invalidateQueries(['messages', conversationId])
        queryClient.invalidateQueries(['conversations'])
      }
    }
  )
}

/**
 * Subscribe to new messages in real-time
 */
export function useMessageSubscription(conversationId: string, onMessage: (msg: any) => void) {
  useEffect(() => {
    const channel = supabase
      .channel(`messages:${conversationId}`)
      .on('postgres_changes', {
        event: 'INSERT',
        schema: 'public',
        table: 'messages',
        filter: `conversation_id=eq.${conversationId}`
      }, (payload) => {
        onMessage(payload.new)
      })
      .subscribe()
    
    return () => {
      supabase.removeChannel(channel)
    }
  }, [conversationId, onMessage])
}

// ============================================================================
// NOTIFICATION HOOKS
// ============================================================================

/**
 * Get unread notifications
 */
export function useNotifications() {
  const { user } = useAuth()
  
  return useQuery(
    ['notifications', user?.id],
    async () => {
      const { data, error } = await supabase
        .from('notifications')
        .select('*')
        .eq('user_id', user?.id)
        .order('created_at', { ascending: false })
        .limit(50)
      
      if (error) throw error
      return data || []
    },
    { enabled: !!user }
  )
}

/**
 * Mark notification as read
 */
export function useMarkNotificationRead() {
  const queryClient = useQueryClient()
  
  return useMutation(
    async (notificationId: string) => {
      await supabase
        .from('notifications')
        .update({ is_read: true })
        .eq('id', notificationId)
    },
    {
      onSuccess: () => {
        queryClient.invalidateQueries(['notifications'])
      }
    }
  )
}

/**
 * Subscribe to new notifications
 */
export function useNotificationSubscription(userId: string, onNotification: (n: any) => void) {
  useEffect(() => {
    const channel = supabase
      .channel(`notifications:${userId}`)
      .on('postgres_changes', {
        event: 'INSERT',
        schema: 'public',
        table: 'notifications',
        filter: `user_id=eq.${userId}`
      }, (payload) => {
        onNotification(payload.new)
      })
      .subscribe()
    
    return () => {
      supabase.removeChannel(channel)
    }
  }, [userId, onNotification])
}

// ============================================================================
// FAVORITES HOOKS
// ============================================================================

/**
 * Get favorite trainers
 */
export function useFavorites() {
  const { user } = useAuth()
  
  return useQuery(
    ['favorites', user?.id],
    async () => {
      const { data, error } = await supabase
        .from('favorites')
        .select(`
          trainer_id,
          trainer:trainers(*)
        `)
        .eq('parent_id', user?.id)
      
      if (error) throw error
      return data?.map(f => f.trainer) || []
    },
    { enabled: !!user }
  )
}

/**
 * Toggle favorite
 */
export function useToggleFavorite() {
  const { user } = useAuth()
  const queryClient = useQueryClient()
  
  return useMutation(
    async (trainerId: string) => {
      // Check if exists
      const { data: existing } = await supabase
        .from('favorites')
        .select('id')
        .eq('parent_id', user?.id)
        .eq('trainer_id', trainerId)
        .single()
      
      if (existing) {
        await supabase.from('favorites').delete().eq('id', existing.id)
      } else {
        await supabase.from('favorites').insert({
          parent_id: user?.id,
          trainer_id: trainerId
        })
      }
    },
    {
      onSuccess: () => {
        queryClient.invalidateQueries(['favorites'])
      }
    }
  )
}

// ============================================================================
// UPSELL & PACKAGE HOOKS
// ============================================================================

interface Upsell {
  type: 'package' | 'camp_bundle' | 'training' | 'referral' | 'add_sibling'
  id: string
  name: string
  headline: string
  subheadline?: string
  price?: number
  original_price?: number
  discount_percent?: number
  savings?: number
  cta: string
  popular?: boolean
  trainer_id?: string
  camp_id?: string
  referral_code?: string
}

interface Package {
  id: string
  trainer_id: string
  sessions_total: number
  sessions_remaining: number
  status: 'active' | 'used' | 'expired'
  expires_at?: string
}

/**
 * Get upsell offers based on context
 */
export function useUpsells(options: {
  trigger: 'post_booking' | 'trainer_profile' | 'dashboard' | 'checkout'
  trainerId?: string
  justBooked?: 'training' | 'camp'
}) {
  return useQuery<Upsell[]>(
    ['upsells', options.trigger, options.trainerId, options.justBooked],
    async () => {
      const params = new URLSearchParams({
        trigger: options.trigger,
        ...(options.trainerId && { trainer_id: options.trainerId }),
        ...(options.justBooked && { just_booked: options.justBooked }),
      })

      const { data, error } = await supabase.functions.invoke('get-upsells', {
        body: null,
        headers: { 'Content-Type': 'application/json' },
      })
      
      // If edge function not available, use direct query
      if (error) {
        const params = new URLSearchParams({
          trigger: options.trigger,
          ...(options.trainerId && { trainer_id: options.trainerId }),
        })
        
        const response = await fetch(
          `${supabase.supabaseUrl}/functions/v1/get-upsells?${params}`,
          {
            headers: {
              Authorization: `Bearer ${(await supabase.auth.getSession()).data.session?.access_token}`,
            },
          }
        )
        const result = await response.json()
        return result.upsells || []
      }
      
      return data?.upsells || []
    },
    { staleTime: 60000 } // Cache for 1 minute
  )
}

/**
 * Get user's active packages
 */
export function useMyPackages() {
  const { user } = useAuth()
  
  return useQuery<Package[]>(
    ['packages', user?.id],
    async () => {
      const { data, error } = await supabase
        .from('user_packages')
        .select(`
          *,
          trainer:trainers(display_name, photo_url, slug)
        `)
        .eq('parent_id', user?.id)
        .eq('status', 'active')
        .gt('sessions_remaining', 0)
        .order('created_at', { ascending: false })
      
      if (error) throw error
      return data || []
    },
    { enabled: !!user }
  )
}

/**
 * Check if user has package with trainer
 */
export function useHasPackage(trainerId: string) {
  const { user } = useAuth()
  
  return useQuery<Package | null>(
    ['package', user?.id, trainerId],
    async () => {
      const { data, error } = await supabase
        .from('user_packages')
        .select('*')
        .eq('parent_id', user?.id)
        .eq('trainer_id', trainerId)
        .eq('status', 'active')
        .gt('sessions_remaining', 0)
        .single()
      
      if (error && error.code !== 'PGRST116') throw error
      return data || null
    },
    { enabled: !!user && !!trainerId }
  )
}

/**
 * Buy a session package (1-tap)
 */
export function useBuyPackage() {
  const queryClient = useQueryClient()
  
  return useMutation(
    async (params: {
      trainer_id: string
      session_count: 3 | 5 | 10
      payment_method_id: string
    }) => {
      const { data, error } = await supabase.functions.invoke('buy-package', {
        body: params
      })
      
      if (error) throw error
      if (data.error) throw new Error(data.error)
      
      return data
    },
    {
      onSuccess: (_, variables) => {
        queryClient.invalidateQueries(['packages'])
        queryClient.invalidateQueries(['package', variables.trainer_id])
        queryClient.invalidateQueries(['upsells'])
      }
    }
  )
}

/**
 * Use a session from package (for booking)
 */
export function usePackageSession(packageId: string) {
  const queryClient = useQueryClient()
  
  return useMutation(
    async (bookingId: string) => {
      // Decrement sessions_used
      const { data: pkg } = await supabase
        .from('user_packages')
        .select('sessions_used, sessions_total')
        .eq('id', packageId)
        .single()
      
      if (!pkg) throw new Error('Package not found')
      
      const newUsed = (pkg.sessions_used || 0) + 1
      const newStatus = newUsed >= pkg.sessions_total ? 'used' : 'active'
      
      await supabase
        .from('user_packages')
        .update({ 
          sessions_used: newUsed,
          status: newStatus
        })
        .eq('id', packageId)
      
      // Link booking to package
      await supabase
        .from('bookings')
        .update({ package_id: packageId, payment_status: 'completed' })
        .eq('id', bookingId)
      
      return { sessions_remaining: pkg.sessions_total - newUsed }
    },
    {
      onSuccess: () => {
        queryClient.invalidateQueries(['packages'])
      }
    }
  )
}

// ============================================================================
// REFERRAL HOOKS
// ============================================================================

/**
 * Get referral info
 */
export function useReferralInfo() {
  const { user } = useAuth()
  
  return useQuery(
    ['referral', user?.id],
    async () => {
      const { data, error } = await supabase
        .from('profiles')
        .select('referral_code, referral_credits')
        .eq('id', user?.id)
        .single()
      
      if (error) throw error
      
      // Get referral count
      const { count } = await supabase
        .from('referrals')
        .select('id', { count: 'exact', head: true })
        .eq('referrer_id', user?.id)
        .eq('status', 'completed')
      
      return {
        code: data?.referral_code,
        credits: data?.referral_credits || 0,
        referral_count: count || 0,
        share_text: `Train with the pros! Use code ${data?.referral_code} for 20% off your first session. 🎯⚽`,
        share_url: `https://ptpsoccercamps.com/training?ref=${data?.referral_code}`,
      }
    },
    { enabled: !!user }
  )
}

/**
 * Apply referral code
 */
export function useApplyReferralCode() {
  const { user } = useAuth()
  
  return useMutation(
    async (code: string) => {
      // Find referrer
      const { data: referrer } = await supabase
        .from('profiles')
        .select('id')
        .eq('referral_code', code.toUpperCase())
        .single()
      
      if (!referrer) {
        throw new Error('Invalid referral code')
      }
      
      if (referrer.id === user?.id) {
        throw new Error('Cannot use your own referral code')
      }
      
      // Check if already used a referral
      const { data: profile } = await supabase
        .from('profiles')
        .select('referred_by')
        .eq('id', user?.id)
        .single()
      
      if (profile?.referred_by) {
        throw new Error('You have already used a referral code')
      }
      
      // Apply referral
      await supabase
        .from('profiles')
        .update({ referred_by: referrer.id })
        .eq('id', user?.id)
      
      // Create referral record
      await supabase.from('referrals').insert({
        referrer_id: referrer.id,
        referee_id: user?.id,
        code,
        status: 'pending'
      })
      
      return { success: true, discount: 20 }
    }
  )
}

// ============================================================================
// CAMP HOOKS
// ============================================================================

/**
 * Get upcoming camps
 */
export function useUpcomingCamps(options?: { limit?: number; trainerId?: string }) {
  return useQuery(
    ['camps', options?.trainerId],
    async () => {
      let query = supabase
        .from('camps')
        .select('*')
        .eq('is_active', true)
        .gt('spots_remaining', 0)
        .gte('start_date', new Date().toISOString().split('T')[0])
        .order('start_date')
        .limit(options?.limit || 10)
      
      if (options?.trainerId) {
        query = query.contains('trainer_ids', [options.trainerId])
      }
      
      const { data, error } = await query
      if (error) throw error
      return data || []
    }
  )
}
