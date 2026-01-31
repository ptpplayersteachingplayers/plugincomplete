// supabase/functions/get-upsells/index.ts
// SMART UPSELL RECOMMENDATIONS
// Shows relevant offers based on context
// Deploy: supabase functions deploy get-upsells

import { serve } from 'https://deno.land/std@0.168.0/http/server.ts'
import { createClient } from 'https://esm.sh/@supabase/supabase-js@2'

// SECURITY: Restrict CORS to allowed domains only
const ALLOWED_ORIGINS = (Deno.env.get('ALLOWED_ORIGINS') || 'https://ptptraining.com,https://app.ptptraining.com').split(',')

function getCorsHeaders(req: Request) {
  const origin = req.headers.get('Origin') || ''
  const allowedOrigin = ALLOWED_ORIGINS.includes(origin) ? origin : ALLOWED_ORIGINS[0]
  return {
    'Access-Control-Allow-Origin': allowedOrigin,
    'Access-Control-Allow-Headers': 'authorization, x-client-info, apikey, content-type',
    'Access-Control-Allow-Methods': 'POST, GET, OPTIONS',
  }
}

// Discount tiers
const PACKAGE_DISCOUNTS = {
  3: 10,  // 10% off 3-session pack
  5: 15,  // 15% off 5-session pack
  10: 20, // 20% off 10-session pack
}

const BUNDLE_DISCOUNT = 15    // 15% off training + camp
const SIBLING_DISCOUNT = 15   // 15% off additional child
const REFERRAL_CREDIT = 25    // $25 referral bonus

serve(async (req) => {
  const corsHeaders = getCorsHeaders(req)
  
  if (req.method === 'OPTIONS') {
    return new Response('ok', { headers: corsHeaders })
  }

  try {
    const supabase = createClient(
      Deno.env.get('SUPABASE_URL') ?? '',
      Deno.env.get('SUPABASE_SERVICE_ROLE_KEY') ?? ''
    )

    const url = new URL(req.url)
    const trigger = url.searchParams.get('trigger') || 'dashboard' // post_booking, trainer_profile, etc.
    const trainerId = url.searchParams.get('trainer_id')
    const justBooked = url.searchParams.get('just_booked') // 'training' or 'camp'

    // Get user from JWT (optional - works for logged in users)
    let user = null
    const authHeader = req.headers.get('Authorization')
    if (authHeader) {
      const { data } = await supabase.auth.getUser(authHeader.replace('Bearer ', ''))
      user = data?.user
    }

    const upsells: any[] = []

    // ========================================
    // 1. PACKAGE UPGRADES
    // ========================================
    if (trigger === 'post_booking' || trigger === 'trainer_profile') {
      // Get trainer's hourly rate
      let trainerRate = 75
      if (trainerId) {
        const { data: trainer } = await supabase
          .from('trainers')
          .select('hourly_rate, display_name')
          .eq('id', trainerId)
          .single()
        
        if (trainer) {
          trainerRate = trainer.hourly_rate
        }
      }

      // Check if user already has a package with this trainer
      let hasPackage = false
      if (user && trainerId) {
        const { data: existingPkg } = await supabase
          .from('user_packages')
          .select('id')
          .eq('parent_id', user.id)
          .eq('trainer_id', trainerId)
          .eq('status', 'active')
          .gt('sessions_remaining', 0)
          .single()
        
        hasPackage = !!existingPkg
      }

      if (!hasPackage) {
        // Add package options
        const packages = [
          { sessions: 3, discount: 10, name: '3-Session Starter' },
          { sessions: 5, discount: 15, name: '5-Session Pack', popular: true },
          { sessions: 10, discount: 20, name: '10-Session Pro' },
        ]

        packages.forEach(pkg => {
          const originalPrice = trainerRate * pkg.sessions
          const discountedPrice = originalPrice * (1 - pkg.discount / 100)
          const savings = originalPrice - discountedPrice

          upsells.push({
            type: 'package',
            id: `package_${pkg.sessions}`,
            name: pkg.name,
            headline: `Save ${pkg.discount}% with ${pkg.sessions} Sessions`,
            subheadline: `Lock in ${pkg.sessions} sessions with your trainer`,
            sessions: pkg.sessions,
            discount_percent: pkg.discount,
            original_price: originalPrice,
            price: discountedPrice,
            savings: savings,
            cta: 'Buy Package',
            popular: pkg.popular || false,
            trainer_id: trainerId,
          })
        })
      }
    }

    // ========================================
    // 2. CAMP BUNDLE
    // ========================================
    if (justBooked === 'training' || trigger === 'trainer_profile') {
      // Find upcoming camps (optionally by trainer)
      let campQuery = supabase
        .from('camps')
        .select('*')
        .eq('is_active', true)
        .gt('spots_remaining', 0)
        .gte('start_date', new Date().toISOString().split('T')[0])
        .order('start_date')
        .limit(3)

      if (trainerId) {
        campQuery = campQuery.contains('trainer_ids', [trainerId])
      }

      const { data: camps } = await campQuery

      camps?.forEach(camp => {
        const bundlePrice = camp.price * (1 - BUNDLE_DISCOUNT / 100)
        
        upsells.push({
          type: 'camp_bundle',
          id: `camp_${camp.id}`,
          name: camp.name,
          headline: `Add Camp & Save ${BUNDLE_DISCOUNT}%`,
          subheadline: camp.location ? `${camp.start_date} • ${camp.location}` : camp.start_date,
          image_url: camp.image_url,
          original_price: camp.price,
          price: bundlePrice,
          discount_percent: BUNDLE_DISCOUNT,
          savings: camp.price - bundlePrice,
          spots_remaining: camp.spots_remaining,
          cta: 'Add to Bundle',
          camp_id: camp.id,
        })
      })
    }

    // ========================================
    // 3. TRAINING AFTER CAMP
    // ========================================
    if (justBooked === 'camp') {
      // Get featured trainers
      const { data: trainers } = await supabase
        .from('trainer_cards')
        .select('*')
        .eq('is_featured', true)
        .order('rating', { ascending: false })
        .limit(4)

      trainers?.forEach(trainer => {
        upsells.push({
          type: 'training',
          id: `training_${trainer.id}`,
          name: `Train with ${trainer.display_name}`,
          headline: 'Continue Your Progress',
          subheadline: trainer.headline || `${trainer.rating}★ • ${trainer.review_count} reviews`,
          image_url: trainer.photo_url,
          price: trainer.hourly_rate,
          trainer_slug: trainer.slug,
          cta: 'Book Training',
        })
      })
    }

    // ========================================
    // 4. REFERRAL OFFER
    // ========================================
    if (trigger === 'post_booking' || trigger === 'dashboard') {
      // Check if user has referral code
      let referralCode = null
      if (user) {
        const { data: profile } = await supabase
          .from('profiles')
          .select('referral_code')
          .eq('id', user.id)
          .single()
        
        referralCode = profile?.referral_code
        
        // Generate if missing
        if (!referralCode) {
          referralCode = `PTP${user.id.slice(0, 6).toUpperCase()}`
          await supabase
            .from('profiles')
            .update({ referral_code: referralCode })
            .eq('id', user.id)
        }
      }

      upsells.push({
        type: 'referral',
        id: 'referral',
        name: 'Refer a Friend',
        headline: `Get $${REFERRAL_CREDIT} Free`,
        subheadline: 'Share with friends, you both save',
        referral_code: referralCode,
        referrer_credit: REFERRAL_CREDIT,
        referee_discount: 20, // 20% off first booking
        cta: 'Share Now',
        share_text: `Train with the best! Use my code ${referralCode} for 20% off your first session.`,
      })
    }

    // ========================================
    // 5. ADD SIBLING
    // ========================================
    if (user && (trigger === 'dashboard' || trigger === 'post_booking')) {
      // Check how many players user has
      const { count } = await supabase
        .from('players')
        .select('id', { count: 'exact', head: true })
        .eq('parent_id', user.id)

      if (count && count >= 1) {
        upsells.push({
          type: 'add_sibling',
          id: 'add_sibling',
          name: 'Add Another Player',
          headline: `${SIBLING_DISCOUNT}% Off Siblings`,
          subheadline: 'Train multiple kids and save',
          discount_percent: SIBLING_DISCOUNT,
          cta: 'Add Player',
        })
      }
    }

    // Sort by priority (packages first, then bundles, then referral)
    const priorityOrder: Record<string, number> = {
      package: 1,
      camp_bundle: 2,
      training: 3,
      add_sibling: 4,
      referral: 5,
    }

    upsells.sort((a, b) => {
      const aPriority = priorityOrder[a.type] || 99
      const bPriority = priorityOrder[b.type] || 99
      // Popular items first within same type
      if (aPriority === bPriority) {
        return (b.popular ? 1 : 0) - (a.popular ? 1 : 0)
      }
      return aPriority - bPriority
    })

    return new Response(JSON.stringify({
      upsells,
      count: upsells.length,
      trigger,
    }), {
      headers: { ...corsHeaders, 'Content-Type': 'application/json' }
    })

  } catch (error: any) {
    console.error('Get upsells error:', error)
    return new Response(JSON.stringify({ error: error.message, upsells: [] }), {
      status: 500,
      headers: { ...corsHeaders, 'Content-Type': 'application/json' }
    })
  }
})
