// supabase/functions/buy-package/index.ts
// 1-TAP PACKAGE PURCHASE
// Buy a 3/5/10 session pack with discount
// Deploy: supabase functions deploy buy-package

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
    'Access-Control-Max-Age': '86400',
  }
}

const PACKAGE_DISCOUNTS: Record<number, number> = {
  3: 10,
  5: 15,
  10: 20,
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
      session_count, 
      payment_method_id 
    } = await req.json()

    // Validate session count
    if (![3, 5, 10].includes(session_count)) {
      return new Response(JSON.stringify({ error: 'Invalid package size' }), {
        status: 400,
        headers: { ...corsHeaders, 'Content-Type': 'application/json' }
      })
    }

    // Get trainer
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

    // Check if user already has an active package with this trainer
    const { data: existingPkg } = await supabase
      .from('user_packages')
      .select('id, sessions_remaining')
      .eq('parent_id', user.id)
      .eq('trainer_id', trainer_id)
      .eq('status', 'active')
      .gt('sessions_remaining', 0)
      .single()

    if (existingPkg) {
      return new Response(JSON.stringify({ 
        error: 'You already have an active package with this trainer',
        existing_sessions: existingPkg.sessions_remaining,
        code: 'PACKAGE_EXISTS'
      }), {
        status: 400,
        headers: { ...corsHeaders, 'Content-Type': 'application/json' }
      })
    }

    // Calculate pricing
    const discount = PACKAGE_DISCOUNTS[session_count] || 0
    const originalPrice = trainer.hourly_rate * session_count
    const discountAmount = originalPrice * (discount / 100)
    const finalPrice = originalPrice - discountAmount

    // Process payment
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

        await supabase.auth.admin.updateUserById(user.id, {
          user_metadata: { stripe_customer_id: customerId }
        })
      }

      // Attach payment method
      await stripe.paymentMethods.attach(payment_method_id, {
        customer: customerId
      }).catch(() => {})

      // Charge
      paymentIntent = await stripe.paymentIntents.create({
        amount: Math.round(finalPrice * 100),
        currency: 'usd',
        customer: customerId,
        payment_method: payment_method_id,
        confirm: true,
        automatic_payment_methods: {
          enabled: true,
          allow_redirects: 'never'
        },
        metadata: {
          type: 'session_package',
          trainer_id,
          session_count: String(session_count),
          discount: String(discount)
        }
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

    // Get package template
    const { data: packageTemplate } = await supabase
      .from('session_packages')
      .select('id')
      .eq('session_count', session_count)
      .single()

    // Create user package
    const { data: userPackage, error: pkgError } = await supabase
      .from('user_packages')
      .insert({
        parent_id: user.id,
        trainer_id,
        package_id: packageTemplate?.id,
        sessions_total: session_count,
        sessions_used: 0,
        amount_paid: finalPrice,
        discount_applied: discountAmount,
        status: 'active',
        expires_at: new Date(Date.now() + 365 * 24 * 60 * 60 * 1000).toISOString() // 1 year
      })
      .select()
      .single()

    if (pkgError) {
      // Refund if package creation fails
      await stripe.refunds.create({ payment_intent: paymentIntent.id })
      throw pkgError
    }

    // Send notification
    await supabase.from('notifications').insert({
      user_id: user.id,
      type: 'purchase',
      title: '🎉 Package Purchased!',
      body: `You now have ${session_count} sessions with ${trainer.display_name}`,
      screen: 'Packages',
      params: { package_id: userPackage.id }
    })

    // Notify trainer
    if (trainer.user_id) {
      await supabase.from('notifications').insert({
        user_id: trainer.user_id,
        type: 'package_sold',
        title: '📦 Package Sold!',
        body: `Someone bought a ${session_count}-session package with you`,
        screen: 'Earnings'
      })
    }

    return new Response(JSON.stringify({
      success: true,
      package_id: userPackage.id,
      sessions: session_count,
      amount_paid: finalPrice,
      savings: discountAmount,
      message: `${session_count} sessions purchased! Save ${discount}%`
    }), {
      headers: { ...corsHeaders, 'Content-Type': 'application/json' }
    })

  } catch (error: any) {
    console.error('Buy package error:', error)
    return new Response(JSON.stringify({ error: error.message }), {
      status: 500,
      headers: { ...corsHeaders, 'Content-Type': 'application/json' }
    })
  }
})
