// supabase/functions/send-push/index.ts
// PUSH NOTIFICATIONS - FCM/APNS
// Deploy: supabase functions deploy send-push

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

    const { user_id, title, body, data } = await req.json()

    // Get user's push token
    const { data: profile } = await supabase
      .from('profiles')
      .select('push_token, push_enabled')
      .eq('id', user_id)
      .single()

    if (!profile?.push_token || !profile?.push_enabled) {
      return new Response(JSON.stringify({ sent: false, reason: 'No push token' }), {
        headers: { ...corsHeaders, 'Content-Type': 'application/json' }
      })
    }

    // Send via FCM
    const fcmKey = Deno.env.get('FCM_SERVER_KEY')
    
    if (fcmKey) {
      const response = await fetch('https://fcm.googleapis.com/fcm/send', {
        method: 'POST',
        headers: {
          'Authorization': `key=${fcmKey}`,
          'Content-Type': 'application/json'
        },
        body: JSON.stringify({
          to: profile.push_token,
          notification: {
            title,
            body,
            sound: 'default',
            badge: 1
          },
          data: {
            ...data,
            click_action: 'FLUTTER_NOTIFICATION_CLICK'
          },
          priority: 'high'
        })
      })

      const result = await response.json()
      
      return new Response(JSON.stringify({ 
        sent: true, 
        result 
      }), {
        headers: { ...corsHeaders, 'Content-Type': 'application/json' }
      })
    }

    // Fallback: Expo push (if using Expo)
    const expoPushToken = profile.push_token
    if (expoPushToken.startsWith('ExponentPushToken')) {
      const response = await fetch('https://exp.host/--/api/v2/push/send', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          to: expoPushToken,
          title,
          body,
          data,
          sound: 'default',
          priority: 'high'
        })
      })

      const result = await response.json()
      
      return new Response(JSON.stringify({ 
        sent: true, 
        result 
      }), {
        headers: { ...corsHeaders, 'Content-Type': 'application/json' }
      })
    }

    return new Response(JSON.stringify({ sent: false, reason: 'Unknown token format' }), {
      headers: { ...corsHeaders, 'Content-Type': 'application/json' }
    })

  } catch (error: any) {
    console.error('Push error:', error)
    return new Response(JSON.stringify({ error: error.message }), {
      status: 500,
      headers: { ...corsHeaders, 'Content-Type': 'application/json' }
    })
  }
})
