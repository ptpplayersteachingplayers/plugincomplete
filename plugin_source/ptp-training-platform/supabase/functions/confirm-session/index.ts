// supabase/functions/confirm-session/index.ts
// 1-TAP SESSION CONFIRMATION
// Both trainer and parent must confirm for payout to be released
// Deploy: supabase functions deploy confirm-session

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

    const { booking_id, rating, feedback } = await req.json()

    // Get booking with trainer info
    const { data: booking, error: bookingError } = await supabase
      .from('bookings')
      .select(`
        *,
        trainer:trainers(id, user_id, display_name)
      `)
      .eq('id', booking_id)
      .single()

    if (bookingError || !booking) {
      return new Response(JSON.stringify({ error: 'Booking not found' }), {
        status: 404,
        headers: { ...corsHeaders, 'Content-Type': 'application/json' }
      })
    }

    // Determine if user is trainer or parent
    const isTrainer = booking.trainer?.user_id === user.id
    const isParent = booking.parent_id === user.id

    if (!isTrainer && !isParent) {
      return new Response(JSON.stringify({ error: 'Not authorized for this booking' }), {
        status: 403,
        headers: { ...corsHeaders, 'Content-Type': 'application/json' }
      })
    }

    // Update confirmation
    const updateData: any = {
      updated_at: new Date().toISOString()
    }

    if (isTrainer) {
      updateData.trainer_confirmed = true
    } else {
      updateData.parent_confirmed = true
    }

    // Check if both have now confirmed
    const bothConfirmed = 
      (isTrainer && booking.parent_confirmed) || 
      (isParent && booking.trainer_confirmed)

    if (bothConfirmed) {
      updateData.status = 'completed'
      updateData.confirmed_at = new Date().toISOString()
    }

    // Update booking
    const { error: updateError } = await supabase
      .from('bookings')
      .update(updateData)
      .eq('id', booking_id)

    if (updateError) throw updateError

    // If parent is confirming, maybe add a review
    if (isParent && rating) {
      await supabase.from('reviews').insert({
        trainer_id: booking.trainer_id,
        parent_id: user.id,
        booking_id: booking_id,
        rating,
        comment: feedback
      })
    }

    // Send notification to other party
    const notifyUserId = isTrainer ? booking.parent_id : booking.trainer?.user_id
    const notifyMessage = bothConfirmed 
      ? '✅ Session completed! ' + (isTrainer ? 'Earnings are now available.' : 'Thanks for training with us!')
      : `${isTrainer ? 'Trainer' : 'Parent'} confirmed the session`

    if (notifyUserId) {
      await supabase.from('notifications').insert({
        user_id: notifyUserId,
        type: 'booking',
        title: bothConfirmed ? '✅ Session Completed' : '👍 Session Confirmed',
        body: notifyMessage,
        screen: 'BookingDetail',
        params: { booking_id }
      })

      // Push notification
      await fetch(`${Deno.env.get('SUPABASE_URL')}/functions/v1/send-push`, {
        method: 'POST',
        headers: {
          'Authorization': `Bearer ${Deno.env.get('SUPABASE_SERVICE_ROLE_KEY')}`,
          'Content-Type': 'application/json'
        },
        body: JSON.stringify({
          user_id: notifyUserId,
          title: bothConfirmed ? '✅ Session Completed' : '👍 Session Confirmed',
          body: notifyMessage,
          data: { screen: 'BookingDetail', booking_id }
        })
      })
    }

    return new Response(JSON.stringify({
      success: true,
      status: bothConfirmed ? 'completed' : 'confirmed',
      both_confirmed: bothConfirmed,
      message: bothConfirmed 
        ? (isTrainer ? 'Session completed! Earnings are now available to cash out.' : 'Session confirmed! Thank you.')
        : 'Confirmed! Waiting for ' + (isTrainer ? 'parent' : 'trainer') + ' to confirm.'
    }), {
      headers: { ...corsHeaders, 'Content-Type': 'application/json' }
    })

  } catch (error: any) {
    console.error('Confirm session error:', error)
    return new Response(JSON.stringify({ error: error.message }), {
      status: 500,
      headers: { ...corsHeaders, 'Content-Type': 'application/json' }
    })
  }
})
