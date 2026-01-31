-- ============================================================================
-- PTP SUPABASE SCHEMA v1.0
-- Optimized for mobile app speed and simplicity
-- ============================================================================

-- Enable necessary extensions
CREATE EXTENSION IF NOT EXISTS "uuid-ossp";
CREATE EXTENSION IF NOT EXISTS "postgis"; -- For location-based trainer search

-- ============================================================================
-- USERS & AUTH
-- ============================================================================

-- Profiles table (extends Supabase auth.users)
CREATE TABLE public.profiles (
    id UUID PRIMARY KEY REFERENCES auth.users(id) ON DELETE CASCADE,
    role TEXT NOT NULL CHECK (role IN ('parent', 'trainer', 'admin')),
    display_name TEXT NOT NULL,
    email TEXT NOT NULL,
    phone TEXT,
    avatar_url TEXT,
    location TEXT,
    latitude DECIMAL(10, 8),
    longitude DECIMAL(11, 8),
    push_token TEXT, -- FCM/APNS token
    push_enabled BOOLEAN DEFAULT true,
    referral_code TEXT UNIQUE, -- For viral sharing
    referred_by UUID REFERENCES public.profiles(id),
    referral_credits DECIMAL(10, 2) DEFAULT 0,
    created_at TIMESTAMPTZ DEFAULT NOW(),
    updated_at TIMESTAMPTZ DEFAULT NOW()
);

-- ============================================================================
-- SESSION PACKAGES (Upsells)
-- ============================================================================

CREATE TABLE public.session_packages (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    name TEXT NOT NULL, -- "3-Session Pack", "5-Session Pack", etc.
    session_count INTEGER NOT NULL,
    discount_percent INTEGER NOT NULL, -- 10, 15, 20
    is_active BOOLEAN DEFAULT true,
    created_at TIMESTAMPTZ DEFAULT NOW()
);

-- Insert default packages
INSERT INTO session_packages (name, session_count, discount_percent) VALUES
    ('3-Session Starter', 3, 10),
    ('5-Session Pack', 5, 15),
    ('10-Session Pro', 10, 20);

-- User's purchased packages
CREATE TABLE public.user_packages (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    parent_id UUID REFERENCES public.profiles(id) ON DELETE CASCADE,
    trainer_id UUID REFERENCES public.trainers(id),
    package_id UUID REFERENCES public.session_packages(id),
    
    sessions_total INTEGER NOT NULL,
    sessions_used INTEGER DEFAULT 0,
    sessions_remaining INTEGER GENERATED ALWAYS AS (sessions_total - sessions_used) STORED,
    
    amount_paid DECIMAL(10, 2) NOT NULL,
    discount_applied DECIMAL(10, 2),
    
    expires_at TIMESTAMPTZ, -- Optional expiry
    status TEXT DEFAULT 'active' CHECK (status IN ('active', 'used', 'expired', 'refunded')),
    
    created_at TIMESTAMPTZ DEFAULT NOW()
);

CREATE INDEX idx_user_packages_parent ON user_packages (parent_id);
CREATE INDEX idx_user_packages_active ON user_packages (parent_id, trainer_id) 
    WHERE status = 'active' AND sessions_remaining > 0;

-- ============================================================================
-- CAMPS (Synced from WooCommerce)
-- ============================================================================

CREATE TABLE public.camps (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    wp_id INTEGER, -- WooCommerce product ID
    name TEXT NOT NULL,
    description TEXT,
    price DECIMAL(10, 2) NOT NULL,
    
    -- Schedule
    start_date DATE,
    end_date DATE,
    times TEXT, -- "9am-12pm"
    
    -- Location
    location TEXT,
    location_coords GEOGRAPHY(POINT, 4326),
    
    -- Capacity
    capacity INTEGER,
    spots_remaining INTEGER,
    
    -- Age groups
    age_min INTEGER,
    age_max INTEGER,
    
    -- Media
    image_url TEXT,
    
    -- Trainers at this camp
    trainer_ids UUID[],
    
    -- Status
    is_active BOOLEAN DEFAULT true,
    is_featured BOOLEAN DEFAULT false,
    
    created_at TIMESTAMPTZ DEFAULT NOW(),
    updated_at TIMESTAMPTZ DEFAULT NOW()
);

CREATE INDEX idx_camps_active ON camps (is_active) WHERE is_active = true;
CREATE INDEX idx_camps_date ON camps (start_date) WHERE is_active = true;

-- ============================================================================
-- UPSELL OFFERS (What to show users)
-- ============================================================================

CREATE TABLE public.upsell_offers (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    
    -- Trigger conditions
    trigger_type TEXT NOT NULL CHECK (trigger_type IN (
        'post_booking',      -- After booking a session
        'post_camp_purchase', -- After buying a camp
        'trainer_profile',   -- On trainer profile
        'checkout',          -- At checkout
        'dashboard'          -- On parent dashboard
    )),
    
    -- What to offer
    offer_type TEXT NOT NULL CHECK (offer_type IN (
        'package_upgrade',   -- Buy 3/5/10 pack
        'camp_bundle',       -- Training + Camp bundle
        'add_sibling',       -- Add another child
        'referral_bonus'     -- Refer a friend
    )),
    
    -- Content
    headline TEXT NOT NULL,
    subheadline TEXT,
    cta_text TEXT NOT NULL,
    
    -- Discount
    discount_percent INTEGER,
    discount_code TEXT,
    
    -- Targeting
    min_sessions_booked INTEGER, -- Show after X sessions
    target_age_groups TEXT[],    -- Filter by kid age
    
    -- Display
    priority INTEGER DEFAULT 0,
    is_active BOOLEAN DEFAULT true,
    
    created_at TIMESTAMPTZ DEFAULT NOW()
);

-- Insert default upsell offers
INSERT INTO upsell_offers (trigger_type, offer_type, headline, subheadline, cta_text, discount_percent, priority) VALUES
    ('post_booking', 'package_upgrade', 'Save Up to 20%', 'Book a package and lock in your trainer', 'View Packages', NULL, 10),
    ('post_booking', 'camp_bundle', 'Add a Camp & Save 15%', 'Your trainer teaches at our winter clinic!', 'Add to Bundle', 15, 5),
    ('trainer_profile', 'package_upgrade', 'Train Regularly, Save More', 'Get 10-20% off with session packages', 'See Packages', NULL, 10),
    ('post_camp_purchase', 'referral_bonus', 'Get $25 Free', 'Refer a friend and you both save', 'Share Now', NULL, 5),
    ('dashboard', 'add_sibling', 'Training for Siblings?', '15% off when you add another player', 'Add Player', 15, 3);

-- ============================================================================
-- TRAINERS
-- ============================================================================

CREATE TABLE public.trainers (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    user_id UUID REFERENCES public.profiles(id) ON DELETE CASCADE,
    wp_id INTEGER, -- WordPress sync ID
    slug TEXT UNIQUE NOT NULL,
    display_name TEXT NOT NULL,
    headline TEXT, -- "D1 Player at Villanova"
    bio TEXT,
    photo_url TEXT,
    video_url TEXT,
    
    -- Pricing
    hourly_rate DECIMAL(10, 2) NOT NULL DEFAULT 75.00,
    
    -- Location (for nearby search)
    location TEXT,
    coords GEOGRAPHY(POINT, 4326), -- PostGIS for fast geo queries
    service_radius_miles INTEGER DEFAULT 25,
    
    -- Credentials
    college TEXT,
    pro_experience TEXT,
    certifications TEXT[],
    specialties TEXT[], -- ['Finishing', 'Ball Control', 'Speed']
    age_groups TEXT[], -- ['6-8', '9-12', '13-16', '17+']
    
    -- Stats (denormalized for speed)
    rating DECIMAL(2, 1) DEFAULT 5.0,
    review_count INTEGER DEFAULT 0,
    total_sessions INTEGER DEFAULT 0,
    response_time_hours INTEGER DEFAULT 24,
    
    -- Status
    is_active BOOLEAN DEFAULT true,
    is_featured BOOLEAN DEFAULT false,
    is_verified BOOLEAN DEFAULT false,
    
    -- Stripe
    stripe_account_id TEXT,
    stripe_payouts_enabled BOOLEAN DEFAULT false,
    
    created_at TIMESTAMPTZ DEFAULT NOW(),
    updated_at TIMESTAMPTZ DEFAULT NOW()
);

-- Fast trainer search index
CREATE INDEX idx_trainers_coords ON trainers USING GIST (coords);
CREATE INDEX idx_trainers_active ON trainers (is_active) WHERE is_active = true;
CREATE INDEX idx_trainers_featured ON trainers (is_featured) WHERE is_featured = true;
CREATE INDEX idx_trainers_rating ON trainers (rating DESC);

-- ============================================================================
-- PLAYERS (Kids)
-- ============================================================================

CREATE TABLE public.players (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    parent_id UUID REFERENCES public.profiles(id) ON DELETE CASCADE,
    wp_id INTEGER,
    first_name TEXT NOT NULL,
    last_name TEXT,
    age INTEGER,
    birth_date DATE,
    skill_level TEXT CHECK (skill_level IN ('beginner', 'intermediate', 'advanced', 'elite')),
    position TEXT,
    team_name TEXT,
    photo_url TEXT,
    notes TEXT,
    created_at TIMESTAMPTZ DEFAULT NOW()
);

CREATE INDEX idx_players_parent ON players (parent_id);

-- ============================================================================
-- AVAILABILITY (Trainer schedules)
-- ============================================================================

CREATE TABLE public.availability (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    trainer_id UUID REFERENCES public.trainers(id) ON DELETE CASCADE,
    
    -- Recurring weekly slots
    day_of_week INTEGER CHECK (day_of_week BETWEEN 0 AND 6), -- 0=Sunday
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    
    -- Or specific date override
    specific_date DATE,
    is_blocked BOOLEAN DEFAULT false, -- Block this slot
    
    created_at TIMESTAMPTZ DEFAULT NOW()
);

CREATE INDEX idx_availability_trainer ON availability (trainer_id);
CREATE INDEX idx_availability_date ON availability (specific_date) WHERE specific_date IS NOT NULL;

-- ============================================================================
-- BOOKINGS
-- ============================================================================

CREATE TABLE public.bookings (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    wp_id INTEGER,
    
    -- Participants
    trainer_id UUID REFERENCES public.trainers(id),
    parent_id UUID REFERENCES public.profiles(id),
    player_id UUID REFERENCES public.players(id),
    
    -- Schedule
    session_date DATE NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    duration_minutes INTEGER DEFAULT 60,
    timezone TEXT DEFAULT 'America/New_York',
    
    -- Location
    location TEXT,
    location_type TEXT CHECK (location_type IN ('trainer', 'player', 'facility')),
    location_coords GEOGRAPHY(POINT, 4326),
    
    -- Status
    status TEXT NOT NULL DEFAULT 'pending' 
        CHECK (status IN ('pending', 'confirmed', 'completed', 'cancelled', 'no_show')),
    
    -- Payment
    amount DECIMAL(10, 2) NOT NULL,
    trainer_payout DECIMAL(10, 2) NOT NULL,
    platform_fee DECIMAL(10, 2),
    payment_status TEXT DEFAULT 'pending'
        CHECK (payment_status IN ('pending', 'completed', 'refunded', 'failed')),
    payment_intent_id TEXT,
    package_id UUID REFERENCES public.user_packages(id), -- If booked with package
    
    -- Confirmation (both must confirm for payout)
    trainer_confirmed BOOLEAN DEFAULT false,
    parent_confirmed BOOLEAN DEFAULT false,
    confirmed_at TIMESTAMPTZ,
    
    -- Notes
    parent_notes TEXT,
    trainer_notes TEXT,
    
    -- Metadata
    created_at TIMESTAMPTZ DEFAULT NOW(),
    updated_at TIMESTAMPTZ DEFAULT NOW(),
    cancelled_at TIMESTAMPTZ,
    cancelled_by UUID,
    cancel_reason TEXT
);

CREATE INDEX idx_bookings_trainer ON bookings (trainer_id, session_date);
CREATE INDEX idx_bookings_parent ON bookings (parent_id, session_date);
CREATE INDEX idx_bookings_status ON bookings (status);
CREATE INDEX idx_bookings_upcoming ON bookings (session_date, start_time) 
    WHERE status IN ('pending', 'confirmed');

-- ============================================================================
-- MESSAGES (Real-time chat)
-- ============================================================================

CREATE TABLE public.conversations (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    trainer_id UUID REFERENCES public.trainers(id),
    parent_id UUID REFERENCES public.profiles(id),
    
    -- Last message preview (denormalized for list view)
    last_message TEXT,
    last_message_at TIMESTAMPTZ,
    last_message_by UUID,
    
    -- Unread counts
    trainer_unread INTEGER DEFAULT 0,
    parent_unread INTEGER DEFAULT 0,
    
    created_at TIMESTAMPTZ DEFAULT NOW()
);

CREATE UNIQUE INDEX idx_conversations_pair ON conversations (trainer_id, parent_id);

CREATE TABLE public.messages (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    conversation_id UUID REFERENCES public.conversations(id) ON DELETE CASCADE,
    sender_id UUID REFERENCES public.profiles(id),
    
    message TEXT NOT NULL,
    is_read BOOLEAN DEFAULT false,
    
    -- Optional attachment
    attachment_url TEXT,
    attachment_type TEXT,
    
    created_at TIMESTAMPTZ DEFAULT NOW()
);

CREATE INDEX idx_messages_conversation ON messages (conversation_id, created_at DESC);

-- ============================================================================
-- REVIEWS
-- ============================================================================

CREATE TABLE public.reviews (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    trainer_id UUID REFERENCES public.trainers(id) ON DELETE CASCADE,
    parent_id UUID REFERENCES public.profiles(id),
    booking_id UUID REFERENCES public.bookings(id),
    
    rating INTEGER NOT NULL CHECK (rating BETWEEN 1 AND 5),
    comment TEXT,
    
    -- Response from trainer
    trainer_response TEXT,
    responded_at TIMESTAMPTZ,
    
    created_at TIMESTAMPTZ DEFAULT NOW()
);

CREATE INDEX idx_reviews_trainer ON reviews (trainer_id, created_at DESC);

-- ============================================================================
-- NOTIFICATIONS
-- ============================================================================

CREATE TABLE public.notifications (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    user_id UUID REFERENCES public.profiles(id) ON DELETE CASCADE,
    
    type TEXT NOT NULL, -- 'booking', 'message', 'review', 'payout', 'reminder'
    title TEXT NOT NULL,
    body TEXT NOT NULL,
    
    -- Deep link data
    screen TEXT, -- 'BookingDetail', 'Chat', 'Earnings'
    params JSONB, -- { "booking_id": "xxx" }
    
    is_read BOOLEAN DEFAULT false,
    created_at TIMESTAMPTZ DEFAULT NOW()
);

CREATE INDEX idx_notifications_user ON notifications (user_id, is_read, created_at DESC);

-- ============================================================================
-- SAVED PAYMENT METHODS
-- ============================================================================

CREATE TABLE public.payment_methods (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    user_id UUID REFERENCES public.profiles(id) ON DELETE CASCADE,
    
    stripe_payment_method_id TEXT NOT NULL,
    brand TEXT, -- 'visa', 'mastercard'
    last4 TEXT,
    exp_month INTEGER,
    exp_year INTEGER,
    
    is_default BOOLEAN DEFAULT false,
    created_at TIMESTAMPTZ DEFAULT NOW()
);

CREATE INDEX idx_payment_methods_user ON payment_methods (user_id);

-- ============================================================================
-- FAVORITES
-- ============================================================================

CREATE TABLE public.favorites (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    parent_id UUID REFERENCES public.profiles(id) ON DELETE CASCADE,
    trainer_id UUID REFERENCES public.trainers(id) ON DELETE CASCADE,
    created_at TIMESTAMPTZ DEFAULT NOW(),
    UNIQUE(parent_id, trainer_id)
);

-- ============================================================================
-- REFERRALS
-- ============================================================================

CREATE TABLE public.referrals (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    referrer_id UUID REFERENCES public.profiles(id),
    referee_id UUID REFERENCES public.profiles(id),
    code TEXT NOT NULL,
    
    -- Rewards
    referrer_credit DECIMAL(10, 2) DEFAULT 25.00,
    referee_discount_percent INTEGER DEFAULT 20,
    
    status TEXT DEFAULT 'pending' CHECK (status IN ('pending', 'completed', 'expired')),
    completed_at TIMESTAMPTZ,
    
    created_at TIMESTAMPTZ DEFAULT NOW()
);

CREATE UNIQUE INDEX idx_referrals_code ON referrals (code);

-- ============================================================================
-- TRAINER EARNINGS (Denormalized for fast dashboard)
-- ============================================================================

CREATE TABLE public.trainer_earnings (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    trainer_id UUID REFERENCES public.trainers(id) ON DELETE CASCADE,
    
    -- Running totals (updated by trigger)
    total_earned DECIMAL(10, 2) DEFAULT 0,
    available_balance DECIMAL(10, 2) DEFAULT 0,
    pending_balance DECIMAL(10, 2) DEFAULT 0,
    total_paid_out DECIMAL(10, 2) DEFAULT 0,
    
    -- Stats
    completed_sessions INTEGER DEFAULT 0,
    upcoming_sessions INTEGER DEFAULT 0,
    
    updated_at TIMESTAMPTZ DEFAULT NOW()
);

CREATE UNIQUE INDEX idx_trainer_earnings_trainer ON trainer_earnings (trainer_id);

-- ============================================================================
-- PAYOUTS
-- ============================================================================

CREATE TABLE public.payouts (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    trainer_id UUID REFERENCES public.trainers(id),
    
    amount DECIMAL(10, 2) NOT NULL,
    fee DECIMAL(10, 2) DEFAULT 0,
    net_amount DECIMAL(10, 2) NOT NULL,
    
    status TEXT DEFAULT 'pending' CHECK (status IN ('pending', 'processing', 'completed', 'failed')),
    stripe_transfer_id TEXT,
    
    created_at TIMESTAMPTZ DEFAULT NOW(),
    completed_at TIMESTAMPTZ
);

CREATE INDEX idx_payouts_trainer ON payouts (trainer_id, created_at DESC);

-- ============================================================================
-- ROW LEVEL SECURITY (RLS)
-- ============================================================================

ALTER TABLE profiles ENABLE ROW LEVEL SECURITY;
ALTER TABLE trainers ENABLE ROW LEVEL SECURITY;
ALTER TABLE players ENABLE ROW LEVEL SECURITY;
ALTER TABLE bookings ENABLE ROW LEVEL SECURITY;
ALTER TABLE conversations ENABLE ROW LEVEL SECURITY;
ALTER TABLE messages ENABLE ROW LEVEL SECURITY;
ALTER TABLE notifications ENABLE ROW LEVEL SECURITY;
ALTER TABLE payment_methods ENABLE ROW LEVEL SECURITY;

-- Profiles: Users can read all, update own
CREATE POLICY "Public profiles are viewable by everyone" ON profiles
    FOR SELECT USING (true);

CREATE POLICY "Users can update own profile" ON profiles
    FOR UPDATE USING (auth.uid() = id);

-- Trainers: Public read, trainers update own
CREATE POLICY "Trainers are viewable by everyone" ON trainers
    FOR SELECT USING (is_active = true);

CREATE POLICY "Trainers can update own profile" ON trainers
    FOR UPDATE USING (auth.uid() = user_id);

-- Players: Parents see own kids
CREATE POLICY "Parents can manage own players" ON players
    FOR ALL USING (auth.uid() = parent_id);

-- Bookings: Participants can see their bookings
CREATE POLICY "Users can view own bookings" ON bookings
    FOR SELECT USING (
        auth.uid() = parent_id OR 
        auth.uid() IN (SELECT user_id FROM trainers WHERE id = trainer_id)
    );

CREATE POLICY "Parents can create bookings" ON bookings
    FOR INSERT WITH CHECK (auth.uid() = parent_id);

-- Messages: Conversation participants only
CREATE POLICY "Conversation participants can view messages" ON messages
    FOR SELECT USING (
        conversation_id IN (
            SELECT id FROM conversations 
            WHERE parent_id = auth.uid() 
            OR trainer_id IN (SELECT id FROM trainers WHERE user_id = auth.uid())
        )
    );

CREATE POLICY "Conversation participants can send messages" ON messages
    FOR INSERT WITH CHECK (
        conversation_id IN (
            SELECT id FROM conversations 
            WHERE parent_id = auth.uid() 
            OR trainer_id IN (SELECT id FROM trainers WHERE user_id = auth.uid())
        )
    );

-- Notifications: Users see own
CREATE POLICY "Users see own notifications" ON notifications
    FOR ALL USING (auth.uid() = user_id);

-- Payment methods: Users see own
CREATE POLICY "Users see own payment methods" ON payment_methods
    FOR ALL USING (auth.uid() = user_id);

-- ============================================================================
-- FUNCTIONS & TRIGGERS
-- ============================================================================

-- Update trainer rating after review
CREATE OR REPLACE FUNCTION update_trainer_rating()
RETURNS TRIGGER AS $$
BEGIN
    UPDATE trainers SET
        rating = (SELECT AVG(rating) FROM reviews WHERE trainer_id = NEW.trainer_id),
        review_count = (SELECT COUNT(*) FROM reviews WHERE trainer_id = NEW.trainer_id),
        updated_at = NOW()
    WHERE id = NEW.trainer_id;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER on_review_insert
    AFTER INSERT ON reviews
    FOR EACH ROW EXECUTE FUNCTION update_trainer_rating();

-- Update conversation last message
CREATE OR REPLACE FUNCTION update_conversation_last_message()
RETURNS TRIGGER AS $$
BEGIN
    UPDATE conversations SET
        last_message = NEW.message,
        last_message_at = NEW.created_at,
        last_message_by = NEW.sender_id,
        trainer_unread = CASE 
            WHEN NEW.sender_id = parent_id THEN trainer_unread + 1 
            ELSE trainer_unread 
        END,
        parent_unread = CASE 
            WHEN NEW.sender_id != parent_id THEN parent_unread + 1 
            ELSE parent_unread 
        END
    WHERE id = NEW.conversation_id;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER on_message_insert
    AFTER INSERT ON messages
    FOR EACH ROW EXECUTE FUNCTION update_conversation_last_message();

-- Update trainer earnings on booking status change
CREATE OR REPLACE FUNCTION update_trainer_earnings()
RETURNS TRIGGER AS $$
BEGIN
    -- Recalculate earnings
    INSERT INTO trainer_earnings (trainer_id, total_earned, available_balance, pending_balance, completed_sessions, upcoming_sessions)
    SELECT 
        NEW.trainer_id,
        COALESCE(SUM(CASE WHEN status = 'completed' THEN trainer_payout ELSE 0 END), 0),
        COALESCE(SUM(CASE WHEN status = 'completed' AND trainer_confirmed AND parent_confirmed THEN trainer_payout ELSE 0 END), 0),
        COALESCE(SUM(CASE WHEN status = 'confirmed' THEN trainer_payout ELSE 0 END), 0),
        COUNT(CASE WHEN status = 'completed' THEN 1 END),
        COUNT(CASE WHEN status IN ('pending', 'confirmed') AND session_date >= CURRENT_DATE THEN 1 END)
    FROM bookings
    WHERE trainer_id = NEW.trainer_id
    ON CONFLICT (trainer_id) DO UPDATE SET
        total_earned = EXCLUDED.total_earned,
        available_balance = EXCLUDED.available_balance,
        pending_balance = EXCLUDED.pending_balance,
        completed_sessions = EXCLUDED.completed_sessions,
        upcoming_sessions = EXCLUDED.upcoming_sessions,
        updated_at = NOW();
    
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER on_booking_change
    AFTER INSERT OR UPDATE ON bookings
    FOR EACH ROW EXECUTE FUNCTION update_trainer_earnings();

-- Auto-create profile on signup
CREATE OR REPLACE FUNCTION handle_new_user()
RETURNS TRIGGER AS $$
BEGIN
    INSERT INTO public.profiles (id, role, display_name, email)
    VALUES (
        NEW.id,
        COALESCE(NEW.raw_user_meta_data->>'role', 'parent'),
        COALESCE(NEW.raw_user_meta_data->>'display_name', split_part(NEW.email, '@', 1)),
        NEW.email
    );
    RETURN NEW;
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;

CREATE TRIGGER on_auth_user_created
    AFTER INSERT ON auth.users
    FOR EACH ROW EXECUTE FUNCTION handle_new_user();

-- ============================================================================
-- VIEWS (For simple app queries)
-- ============================================================================

-- Trainer card view (for lists)
CREATE OR REPLACE VIEW trainer_cards AS
SELECT 
    t.id,
    t.slug,
    t.display_name,
    t.headline,
    t.photo_url,
    t.hourly_rate,
    t.location,
    t.rating,
    t.review_count,
    t.total_sessions,
    t.specialties,
    t.is_verified,
    t.is_featured,
    ST_Y(t.coords::geometry) as latitude,
    ST_X(t.coords::geometry) as longitude
FROM trainers t
WHERE t.is_active = true;

-- Upcoming bookings view (for dashboard)
CREATE OR REPLACE VIEW upcoming_bookings AS
SELECT 
    b.*,
    t.display_name as trainer_name,
    t.photo_url as trainer_photo,
    t.slug as trainer_slug,
    p.first_name as player_name,
    p.age as player_age
FROM bookings b
JOIN trainers t ON b.trainer_id = t.id
LEFT JOIN players p ON b.player_id = p.id
WHERE b.status IN ('pending', 'confirmed')
AND b.session_date >= CURRENT_DATE
ORDER BY b.session_date, b.start_time;

-- ============================================================================
-- INITIAL DATA
-- ============================================================================

-- Insert sample availability template
-- (Trainers can copy this pattern)

COMMENT ON TABLE trainers IS 'Soccer trainers available for booking';
COMMENT ON TABLE bookings IS 'Training session bookings between parents and trainers';
COMMENT ON TABLE messages IS 'Real-time chat messages between parents and trainers';
