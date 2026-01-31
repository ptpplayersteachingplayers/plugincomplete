// supabase/functions/instant-payout/index.ts
// INSTANT PAYOUT FOR TRAINERS
// 1-tap cash out to bank account
// Deploy: supabase functions deploy instant-payout

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

const INSTANT_PAYOUT_FEE = 1.00 // $1 fee for instant
const MIN_PAYOUT = 1.00

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

    const { amount, payout_type = 'instant' } = await req.json()

    // Get trainer
    const { data: trainer, error: trainerError } = await supabase
      .from('trainers')
      .select('*, earnings:trainer_earnings(*)')
      .eq('user_id', user.id)
      .single()

    if (trainerError || !trainer) {
      return new Response(JSON.stringify({ error: 'Trainer not found' }), {
        status: 404,
        headers: { ...corsHeaders, 'Content-Type': 'application/json' }
      })
    }

    // Check Stripe Connect status
    if (!trainer.stripe_account_id || !trainer.stripe_payouts_enabled) {
      return new Response(JSON.stringify({ 
        error: 'Please connect your bank account first',
        code: 'STRIPE_NOT_CONNECTED'
      }), {
        status: 400,
        headers: { ...corsHeaders, 'Content-Type': 'application/json' }
      })
    }

    // Get available balance from earnings
    const availableBalance = trainer.earnings?.available_balance || 0
    const requestedAmount = amount || availableBalance

    if (requestedAmount < MIN_PAYOUT) {
      return new Response(JSON.stringify({ 
        error: `Minimum payout is $${MIN_PAYOUT}`,
        code: 'MIN_PAYOUT'
      }), {
        status: 400,
        headers: { ...corsHeaders, 'Content-Type': 'application/json' }
      })
    }

    if (requestedAmount > availableBalance) {
      return new Response(JSON.stringify({ 
        error: 'Insufficient balance',
        available: availableBalance,
        code: 'INSUFFICIENT_BALANCE'
      }), {
        status: 400,
        headers: { ...corsHeaders, 'Content-Type': 'application/json' }
      })
    }

    // Calculate fee and net
    const fee = payout_type === 'instant' ? INSTANT_PAYOUT_FEE : 0
    const netAmount = requestedAmount - fee

    // Create payout record
    const { data: payout, error: payoutError } = await supabase
      .from('payouts')
      .insert({
        trainer_id: trainer.id,
        amount: requestedAmount,
        fee,
        net_amount: netAmount,
        status: 'processing'
      })
      .select()
      .single()

    if (payoutError) throw payoutError

    try {
      // Create Stripe transfer to connected account
      const transfer = await stripe.transfers.create({
        amount: Math.round(netAmount * 100),
        currency: 'usd',
        destination: trainer.stripe_account_id,
        metadata: {
          payout_id: payout.id,
          trainer_id: trainer.id
        }
      })

      // If instant, create payout from connected account
      if (payout_type === 'instant') {
        await stripe.payouts.create({
          amount: Math.round(netAmount * 100),
          currency: 'usd',
          method: 'instant',
          metadata: { payout_id: payout.id }
        }, {
          stripeAccount: trainer.stripe_account_id
        })
      }

      // Update payout status
      await supabase
        .from('payouts')
        .update({
          status: 'completed',
          stripe_transfer_id: transfer.id,
          completed_at: new Date().toISOString()
        })
        .eq('id', payout.id)

      // Update trainer earnings
      await supabase
        .from('trainer_earnings')
        .update({
          available_balance: availableBalance - requestedAmount,
          total_paid_out: (trainer.earnings?.total_paid_out || 0) + netAmount,
          updated_at: new Date().toISOString()
        })
        .eq('trainer_id', trainer.id)

      // Send notification
      await supabase.from('notifications').insert({
        user_id: user.id,
        type: 'payout',
        title: '💰 Payout Sent!',
        body: `$${netAmount.toFixed(2)} is on the way to your bank`,
        screen: 'Earnings'
      })

      return new Response(JSON.stringify({
        success: true,
        payout_id: payout.id,
        amount: requestedAmount,
        fee,
        net_amount: netAmount,
        message: payout_type === 'instant' 
          ? `$${netAmount.toFixed(2)} sent instantly to your bank!`
          : `$${netAmount.toFixed(2)} will arrive in 1-2 business days`
      }), {
        headers: { ...corsHeaders, 'Content-Type': 'application/json' }
      })

    } catch (stripeError: any) {
      // Update payout as failed
      await supabase
        .from('payouts')
        .update({ status: 'failed' })
        .eq('id', payout.id)

      console.error('Stripe payout error:', stripeError)
      
      return new Response(JSON.stringify({ 
        error: stripeError.message || 'Payout failed',
        code: 'PAYOUT_FAILED'
      }), {
        status: 500,
        headers: { ...corsHeaders, 'Content-Type': 'application/json' }
      })
    }

  } catch (error: any) {
    console.error('Instant payout error:', error)
    return new Response(JSON.stringify({ error: error.message }), {
      status: 500,
      headers: { ...corsHeaders, 'Content-Type': 'application/json' }
    })
  }
})
