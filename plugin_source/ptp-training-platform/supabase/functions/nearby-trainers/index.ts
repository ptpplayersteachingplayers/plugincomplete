// supabase/functions/nearby-trainers/index.ts
// FIND TRAINERS NEAR ME - Fast geo search
// Deploy: supabase functions deploy nearby-trainers

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
    'Access-Control-Allow-Methods': 'GET, OPTIONS',
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

    const url = new URL(req.url)
    const lat = parseFloat(url.searchParams.get('lat') || '0')
    const lng = parseFloat(url.searchParams.get('lng') || '0')
    const radius = parseFloat(url.searchParams.get('radius') || '25') // miles
    const limit = parseInt(url.searchParams.get('limit') || '20')
    const specialty = url.searchParams.get('specialty')
    const minRating = parseFloat(url.searchParams.get('min_rating') || '0')
    const maxPrice = parseFloat(url.searchParams.get('max_price') || '999')

    // Build query with PostGIS
    let query = supabase
      .rpc('nearby_trainers', {
        user_lat: lat,
        user_lng: lng,
        radius_miles: radius
      })
      .select('*')
      .eq('is_active', true)
      .gte('rating', minRating)
      .lte('hourly_rate', maxPrice)
      .order('distance')
      .limit(limit)

    if (specialty) {
      query = query.contains('specialties', [specialty])
    }

    const { data: trainers, error } = await query

    if (error) {
      // Fallback to simple query if PostGIS not available
      const { data: fallbackTrainers, error: fallbackError } = await supabase
        .from('trainers')
        .select('*')
        .eq('is_active', true)
        .gte('rating', minRating)
        .lte('hourly_rate', maxPrice)
        .order('rating', { ascending: false })
        .limit(limit)

      if (fallbackError) throw fallbackError

      return new Response(JSON.stringify({
        trainers: fallbackTrainers,
        count: fallbackTrainers?.length || 0
      }), {
        headers: { ...corsHeaders, 'Content-Type': 'application/json' }
      })
    }

    return new Response(JSON.stringify({
      trainers,
      count: trainers?.length || 0,
      search: { lat, lng, radius }
    }), {
      headers: { ...corsHeaders, 'Content-Type': 'application/json' }
    })

  } catch (error: any) {
    console.error('Nearby trainers error:', error)
    return new Response(JSON.stringify({ error: error.message }), {
      status: 500,
      headers: { ...corsHeaders, 'Content-Type': 'application/json' }
    })
  }
})
