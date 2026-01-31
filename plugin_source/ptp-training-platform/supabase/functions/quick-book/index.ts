// supabase/functions/quick-book/index.ts
// 1-TAP BOOKING - Parents book in seconds
// Deploy: supabase functions deploy quick-book

import { serve } from 'https://deno.land/std@0.168.0/http/server.ts'
import { createClient } from 'https://esm.sh/@supabase/supabase-js@2'
import Stripe from 'https://esm.sh/stripe@12.0.0'

// SECURITY: Restrict CORS to allowed domains only
const ALLOWED_ORIGINS = (Deno.env.get('ALLOWED_ORIGINS') || 'https://ptptraining.com,https://app.ptptraining.com').split(',')

function getCorsHeaders(req: Request) {
  const origin = req.headers.get('Origin') || ''
  const allowedOrigin = ALLOWED_ORIGINS.includes(origin) ? origin : ALLOWED_ORIGINS[0]
  return {
    'Access-Control-Allow-Origin': allowedOrigin,
    'Access-Control-Allow-Headers': 'authorization, x-client-info, apikey, content-type',
    'Access-Control-Allow-Methods': 'POST, OPTIONS',
  }
}

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

    const stripe = new Stripe(Deno.env.get('STRIPE_SECRET_KEY') ?? '', {
      apiVersion: '2023-10-16',
    })

    // Get user from JWT
    const authHeader = req.headers.get('Authorization')!
    const { data: { user }, error: authError } = await supabase.auth.getUser(
      authHeader.replace('Bearer ', '')
    )

    if (authError || !user) {
      return new Response(JSON.stringify({ error: 'Unauthorized' }), {
        status: 401,
        headers: { ...corsHeaders, 'Content-Type': 'application/json' }
      })
    }

    const { 
      trainer_id, 
      player_id, 
      date, 
      time, 
      payment_method_id,
      location,
      notes 
    } = await req.json()

    // 1. Get trainer details
    const { data: trainer, error: trainerError } = await supabase
      .from('trainers')
      .select('*')
      .eq('id', trainer_id)
      .eq('is_active', true)
      .single()

    if (trainerError || !trainer) {
      return new Response(JSON.stringify({ error: 'Trainer not found' }), {
        status: 404,
        headers: { ...corsHeaders, 'Content-Type': 'application/json' }
      })
    }

    // 2. Check slot availability (prevent double booking)
    const { data: existingBooking } = await supabase
      .from('bookings')
      .select('id')
      .eq('trainer_id', trainer_id)
      .eq('session_date', date)
      .eq('start_time', time)
      .neq('status', 'cancelled')
      .single()

    if (existingBooking) {
      return new Response(JSON.stringify({ 
        error: 'This time slot is no longer available',
        code: 'SLOT_TAKEN'
      }), {
        status: 409,
        headers: { ...corsHeaders, 'Content-Type': 'application/json' }
      })
    }

    // 3. Get parent's Stripe customer ID
    const { data: paymentMethod } = await supabase
      .from('payment_methods')
      .select('*')
      .eq('user_id', user.id)
      .eq('stripe_payment_method_id', payment_method_id)
      .single()

    // 4. Calculate amounts
    const amount = trainer.hourly_rate
    const platformFee = amount * 0.20 // 20% platform fee
    const trainerPayout = amount - platformFee

    // 5. Charge the card
    let paymentIntent
    try {
      // Get or create Stripe customer
      let customerId = user.user_metadata?.stripe_customer_id

      if (!customerId) {
        const customer = await stripe.customers.create({
          email: user.email,
          metadata: { supabase_user_id: user.id }
        })
        customerId = customer.id

        // Save customer ID
        await supabase.auth.admin.updateUserById(user.id, {
          user_metadata: { stripe_customer_id: customerId }
        })
      }

      // Attach payment method if needed
      await stripe.paymentMethods.attach(payment_method_id, {
        customer: customerId
      }).catch(() => {}) // Ignore if already attached

      // Create payment intent
      paymentIntent = await stripe.paymentIntents.create({
        amount: Math.round(amount * 100),
        currency: 'usd',
        customer: customerId,
        payment_method: payment_method_id,
        confirm: true,
        automatic_payment_methods: {
          enabled: true,
          allow_redirects: 'never'
        },
        metadata: {
          trainer_id,
          player_id,
          session_date: date
        },
        // Split payment to trainer (if connected)
        ...(trainer.stripe_account_id && trainer.stripe_payouts_enabled ? {
          transfer_data: {
            destination: trainer.stripe_account_id,
            amount: Math.round(trainerPayout * 100)
          }
        } : {})
      })

      if (paymentIntent.status !== 'succeeded') {
        return new Response(JSON.stringify({ 
          error: 'Payment failed',
          code: 'PAYMENT_FAILED'
        }), {
          status: 402,
          headers: { ...corsHeaders, 'Content-Type': 'application/json' }
        })
      }
    } catch (stripeError: any) {
      return new Response(JSON.stringify({ 
        error: stripeError.message || 'Payment failed',
        code: 'PAYMENT_ERROR'
      }), {
        status: 402,
        headers: { ...corsHeaders, 'Content-Type': 'application/json' }
      })
    }

    // 6. Create booking
    const endTime = new Date(`2000-01-01T${time}`)
    endTime.setHours(endTime.getHours() + 1)
    const endTimeStr = endTime.toTimeString().slice(0, 8)

    const { data: booking, error: bookingError } = await supabase
      .from('bookings')
      .insert({
        trainer_id,
        parent_id: user.id,
        player_id,
        session_date: date,
        start_time: time,
        end_time: endTimeStr,
        duration_minutes: 60,
        location: location || trainer.location,
        location_type: 'trainer',
        status: 'confirmed',
        amount,
        trainer_payout: trainerPayout,
        platform_fee: platformFee,
        payment_status: 'completed',
        payment_intent_id: paymentIntent.id,
        parent_notes: notes
      })
      .select()
      .single()

    if (bookingError) {
      // Refund if booking fails
      await stripe.refunds.create({ payment_intent: paymentIntent.id })
      throw bookingError
    }

    // 7. Send notification to trainer
    await supabase.from('notifications').insert({
      user_id: trainer.user_id,
      type: 'booking',
      title: '🎯 New Booking!',
      body: `Training session booked for ${new Date(date).toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric' })} at ${time}`,
      screen: 'BookingDetail',
      params: { booking_id: booking.id }
    })

    // 8. Trigger push notification
    if (trainer.user_id) {
      await fetch(`${Deno.env.get('SUPABASE_URL')}/functions/v1/send-push`, {
        method: 'POST',
        headers: {
          'Authorization': `Bearer ${Deno.env.get('SUPABASE_SERVICE_ROLE_KEY')}`,
          'Content-Type': 'application/json'
        },
        body: JSON.stringify({
          user_id: trainer.user_id,
          title: '🎯 New Booking!',
          body: `Session on ${new Date(date).toLocaleDateString()}`,
          data: { screen: 'BookingDetail', booking_id: booking.id }
        })
      })
    }

    return new Response(JSON.stringify({
      success: true,
      booking_id: booking.id,
      message: 'Booking confirmed!',
      booking: {
        id: booking.id,
        date: booking.session_date,
        time: booking.start_time,
        trainer_name: trainer.display_name,
        trainer_photo: trainer.photo_url,
        amount: booking.amount,
        status: booking.status
      }
    }), {
      status: 200,
      headers: { ...corsHeaders, 'Content-Type': 'application/json' }
    })

  } catch (error: any) {
    console.error('Quick book error:', error)
    return new Response(JSON.stringify({ 
      error: error.message || 'Booking failed',
      code: 'SERVER_ERROR'
    }), {
      status: 500,
      headers: { ...corsHeaders, 'Content-Type': 'application/json' }
    })
  }
})
