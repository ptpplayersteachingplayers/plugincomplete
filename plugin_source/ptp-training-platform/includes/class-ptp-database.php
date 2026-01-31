<?php
/**
 * Database Class - Creates and manages all plugin tables
 */

defined('ABSPATH') || exit;

class PTP_Database {
    
    public static function create_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        
        // Trainers table
        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ptp_trainers (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id bigint(20) UNSIGNED NOT NULL,
            display_name varchar(100) NOT NULL,
            slug varchar(100) NOT NULL,
            email varchar(255) DEFAULT '',
            phone varchar(20) DEFAULT '',
            headline varchar(255) DEFAULT '',
            bio text,
            photo_url varchar(500) DEFAULT '',
            cover_photo_url varchar(500) DEFAULT '',
            gallery text,
            hourly_rate decimal(10,2) NOT NULL DEFAULT 0,
            location varchar(255) DEFAULT '',
            city varchar(100) DEFAULT '',
            state varchar(50) DEFAULT '',
            latitude decimal(10,8) DEFAULT NULL,
            longitude decimal(11,8) DEFAULT NULL,
            travel_radius int(11) DEFAULT 15,
            training_locations text,
            college varchar(255) DEFAULT '',
            team varchar(255) DEFAULT '',
            playing_level varchar(50) DEFAULT '',
            position varchar(100) DEFAULT '',
            experience_years int(11) DEFAULT 0,
            specialties text,
            instagram varchar(100) DEFAULT '',
            facebook varchar(100) DEFAULT '',
            twitter varchar(100) DEFAULT '',
            safesport_doc_url varchar(500) DEFAULT '',
            safesport_verified tinyint(1) DEFAULT 0,
            safesport_expiry date DEFAULT NULL,
            safesport_requested_at datetime DEFAULT NULL,
            background_doc_url varchar(500) DEFAULT '',
            background_verified tinyint(1) DEFAULT 0,
            background_requested_at datetime DEFAULT NULL,
            tax_id_last4 varchar(4) DEFAULT '',
            tax_id_type enum('ssn','ein') DEFAULT 'ssn',
            legal_name varchar(255) DEFAULT '',
            tax_address_line1 varchar(255) DEFAULT '',
            tax_address_line2 varchar(255) DEFAULT '',
            tax_city varchar(100) DEFAULT '',
            tax_state varchar(2) DEFAULT '',
            tax_zip varchar(10) DEFAULT '',
            w9_submitted tinyint(1) DEFAULT 0,
            w9_submitted_at datetime DEFAULT NULL,
            w9_requested_at datetime DEFAULT NULL,
            contractor_agreement_signed tinyint(1) DEFAULT 0,
            contractor_agreement_signed_at datetime DEFAULT NULL,
            contractor_agreement_ip varchar(45) DEFAULT '',
            stripe_account_id varchar(255) DEFAULT '',
            stripe_charges_enabled tinyint(1) DEFAULT 0,
            stripe_payouts_enabled tinyint(1) DEFAULT 0,
            payout_method enum('stripe','venmo','paypal','zelle','cashapp','direct_deposit','check') DEFAULT 'venmo',
            payout_venmo varchar(100) DEFAULT '',
            payout_paypal varchar(255) DEFAULT '',
            payout_zelle varchar(255) DEFAULT '',
            payout_cashapp varchar(100) DEFAULT '',
            payout_bank_name varchar(100) DEFAULT '',
            payout_bank_routing varchar(9) DEFAULT '',
            payout_bank_account varchar(20) DEFAULT '',
            payout_bank_account_type enum('checking','savings') DEFAULT 'checking',
            payout_check_address text DEFAULT NULL,
            status enum('pending','active','inactive','rejected') DEFAULT 'pending',
            is_featured tinyint(1) DEFAULT 0,
            sort_order int(11) DEFAULT 0,
            is_verified tinyint(1) DEFAULT 0,
            is_background_checked tinyint(1) DEFAULT 0,
            total_sessions int(11) DEFAULT 0,
            total_earnings decimal(10,2) DEFAULT 0,
            total_paid decimal(10,2) DEFAULT 0,
            average_rating decimal(3,2) DEFAULT 0,
            review_count int(11) DEFAULT 0,
            happy_student_score int(11) DEFAULT 0,
            reliability_score int(11) DEFAULT 100,
            responsiveness_score int(11) DEFAULT 100,
            return_rate int(11) DEFAULT 0,
            is_supercoach tinyint(1) DEFAULT 0,
            supercoach_awarded_at datetime DEFAULT NULL,
            intro_video_url varchar(500) DEFAULT '',
            lesson_lengths varchar(50) DEFAULT '60',
            max_participants int(11) DEFAULT 1,
            trainer_faqs longtext DEFAULT NULL,
            bio_sections text DEFAULT NULL,
            session_preferences text DEFAULT NULL,
            coaching_why text DEFAULT NULL,
            training_philosophy text DEFAULT NULL,
            training_policy text DEFAULT NULL,
            approved_at datetime DEFAULT NULL,
            onboarding_reminder_count int(11) DEFAULT 0,
            last_onboarding_reminder_at datetime DEFAULT NULL,
            onboarding_completed_at datetime DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY user_id (user_id),
            UNIQUE KEY slug (slug),
            KEY status (status),
            KEY location (latitude, longitude),
            KEY sort_featured (is_featured DESC, sort_order ASC)
        ) $charset_collate;";
        dbDelta($sql);
        
        // Parents table
        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ptp_parents (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id bigint(20) UNSIGNED NOT NULL,
            first_name varchar(50) DEFAULT '',
            last_name varchar(50) DEFAULT '',
            display_name varchar(100) NOT NULL,
            email varchar(255) DEFAULT '',
            phone varchar(20) DEFAULT '',
            location varchar(255) DEFAULT '',
            latitude decimal(10,8) DEFAULT NULL,
            longitude decimal(11,8) DEFAULT NULL,
            emergency_name varchar(100) DEFAULT '',
            emergency_phone varchar(20) DEFAULT '',
            emergency_relation varchar(50) DEFAULT '',
            medical_info text DEFAULT NULL,
            total_sessions int(11) DEFAULT 0,
            total_spent decimal(10,2) DEFAULT 0,
            notification_email tinyint(1) DEFAULT 1,
            notification_sms tinyint(1) DEFAULT 1,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY user_id (user_id),
            KEY email (email)
        ) $charset_collate;";
        dbDelta($sql);
        
        // Players table (children)
        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ptp_players (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            parent_id bigint(20) UNSIGNED NOT NULL,
            first_name varchar(50) DEFAULT '',
            last_name varchar(50) DEFAULT '',
            name varchar(100) NOT NULL,
            dob date DEFAULT NULL,
            age int(11) DEFAULT NULL,
            shirt_size varchar(10) DEFAULT '',
            team varchar(100) DEFAULT '',
            skill_level enum('beginner','intermediate','advanced','elite') DEFAULT 'beginner',
            position varchar(100) DEFAULT '',
            goals text,
            notes text,
            is_active tinyint(1) DEFAULT 1,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY parent_id (parent_id)
        ) $charset_collate;";
        dbDelta($sql);
        
        // Availability table
        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ptp_availability (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            trainer_id bigint(20) UNSIGNED NOT NULL,
            day_of_week tinyint(1) NOT NULL,
            start_time time NOT NULL,
            end_time time NOT NULL,
            is_active tinyint(1) DEFAULT 1,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY trainer_id (trainer_id),
            KEY day_of_week (day_of_week)
        ) $charset_collate;";
        dbDelta($sql);
        
        // Availability exceptions (blocked dates, special hours)
        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ptp_availability_exceptions (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            trainer_id bigint(20) UNSIGNED NOT NULL,
            exception_date date NOT NULL,
            is_available tinyint(1) DEFAULT 0,
            start_time time DEFAULT NULL,
            end_time time DEFAULT NULL,
            reason varchar(255) DEFAULT '',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY trainer_id (trainer_id),
            KEY exception_date (exception_date)
        ) $charset_collate;";
        dbDelta($sql);
        
        // Bookings table
        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ptp_bookings (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            booking_number varchar(20) NOT NULL,
            trainer_id bigint(20) UNSIGNED NOT NULL,
            parent_id bigint(20) UNSIGNED NOT NULL,
            player_id bigint(20) UNSIGNED NOT NULL,
            guest_email varchar(255) DEFAULT '',
            guest_phone varchar(20) DEFAULT '',
            session_date date NOT NULL,
            start_time time NOT NULL,
            end_time time NOT NULL,
            duration_minutes int(11) DEFAULT 60,
            location varchar(255) DEFAULT '',
            location_notes text,
            hourly_rate decimal(10,2) NOT NULL,
            total_amount decimal(10,2) NOT NULL,
            platform_fee decimal(10,2) DEFAULT 0,
            trainer_payout decimal(10,2) NOT NULL,
            status enum('pending','confirmed','completed','cancelled','no_show') DEFAULT 'pending',
            parent_confirmed tinyint(1) DEFAULT 0,
            trainer_confirmed tinyint(1) DEFAULT 0,
            parent_confirmed_at datetime DEFAULT NULL,
            trainer_confirmed_at datetime DEFAULT NULL,
            cancelled_by varchar(20) DEFAULT NULL,
            cancellation_reason text,
            cancelled_at datetime DEFAULT NULL,
            payment_status enum('pending','paid','refunded','failed') DEFAULT 'pending',
            payment_intent_id varchar(255) DEFAULT '',
            stripe_payment_id varchar(255) DEFAULT '',
            payout_status enum('pending','queued','completed') DEFAULT 'pending',
            payout_date datetime DEFAULT NULL,
            stripe_transfer_id varchar(255) DEFAULT '',
            refund_status enum('none','pending','completed') DEFAULT 'none',
            refund_amount decimal(10,2) DEFAULT 0,
            stripe_refund_id varchar(255) DEFAULT '',
            reminder_sent tinyint(1) DEFAULT 0,
            hour_reminder_sent tinyint(1) DEFAULT 0,
            review_request_sent tinyint(1) DEFAULT 0,
            completion_request_sent tinyint(1) DEFAULT 0,
            is_recurring tinyint(1) DEFAULT 0,
            recurring_id bigint(20) UNSIGNED DEFAULT NULL,
            package_credit_id bigint(20) UNSIGNED DEFAULT NULL,
            notes text,
            session_type varchar(20) DEFAULT 'single',
            session_count int(11) DEFAULT 1,
            sessions_remaining int(11) DEFAULT 1,
            group_players text,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY booking_number (booking_number),
            KEY trainer_id (trainer_id),
            KEY parent_id (parent_id),
            KEY player_id (player_id),
            KEY session_date (session_date),
            KEY status (status),
            KEY recurring_id (recurring_id)
        ) $charset_collate;";
        dbDelta($sql);
        
        // Package credits table (prepaid sessions with trainers)
        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ptp_package_credits (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            parent_id bigint(20) UNSIGNED NOT NULL,
            trainer_id bigint(20) UNSIGNED NOT NULL,
            package_type varchar(50) DEFAULT 'single',
            total_credits int(11) NOT NULL DEFAULT 1,
            remaining int(11) NOT NULL DEFAULT 1,
            price_per_session decimal(10,2) DEFAULT 0,
            total_paid decimal(10,2) DEFAULT 0,
            order_id bigint(20) UNSIGNED DEFAULT NULL,
            payment_intent_id varchar(255) DEFAULT '',
            expires_at datetime DEFAULT NULL,
            status enum('active','expired','exhausted','refunded') DEFAULT 'active',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY parent_id (parent_id),
            KEY trainer_id (trainer_id),
            KEY status (status),
            KEY expires_at (expires_at)
        ) $charset_collate;";
        dbDelta($sql);
        
        // Recurring bookings table
        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ptp_recurring_bookings (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            parent_id bigint(20) UNSIGNED NOT NULL,
            trainer_id bigint(20) UNSIGNED NOT NULL,
            player_id bigint(20) UNSIGNED NOT NULL,
            frequency enum('weekly','biweekly','monthly') DEFAULT 'weekly',
            total_sessions int(11) DEFAULT 8,
            sessions_created int(11) DEFAULT 0,
            sessions_completed int(11) DEFAULT 0,
            day_of_week tinyint(1) DEFAULT NULL,
            preferred_time time DEFAULT NULL,
            location varchar(255) DEFAULT '',
            status enum('active','paused','completed','cancelled') DEFAULT 'active',
            next_booking_date date DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY parent_id (parent_id),
            KEY trainer_id (trainer_id),
            KEY status (status)
        ) $charset_collate;";
        dbDelta($sql);
        
        // Admin-created sessions table (VA scheduling)
        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ptp_sessions (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            trainer_id bigint(20) UNSIGNED NOT NULL,
            customer_id bigint(20) UNSIGNED DEFAULT NULL,
            parent_id bigint(20) UNSIGNED DEFAULT NULL,
            player_id bigint(20) UNSIGNED DEFAULT NULL,
            player_name varchar(100) DEFAULT '',
            player_age int(11) DEFAULT NULL,
            session_date date NOT NULL,
            start_time time NOT NULL,
            end_time time NOT NULL,
            duration_minutes int(11) DEFAULT 60,
            session_status enum('requested','confirmed','scheduled','completed','cancelled','no_show') DEFAULT 'scheduled',
            payment_status enum('unpaid','pending','paid','refunded') DEFAULT 'unpaid',
            session_type enum('1on1','small_group','group') DEFAULT '1on1',
            location_text varchar(255) DEFAULT '',
            price decimal(10,2) DEFAULT 0,
            trainer_payout decimal(10,2) DEFAULT 0,
            internal_notes text,
            booking_id bigint(20) UNSIGNED DEFAULT NULL,
            created_by bigint(20) UNSIGNED DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY trainer_id (trainer_id),
            KEY customer_id (customer_id),
            KEY session_date (session_date),
            KEY session_status (session_status),
            KEY booking_id (booking_id)
        ) $charset_collate;";
        dbDelta($sql);
        
        // Messages table
        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ptp_messages (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            conversation_id bigint(20) UNSIGNED NOT NULL,
            sender_id bigint(20) UNSIGNED NOT NULL,
            message text NOT NULL,
            is_read tinyint(1) DEFAULT 0,
            read_at datetime DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY conversation_id (conversation_id),
            KEY sender_id (sender_id),
            KEY is_read (is_read)
        ) $charset_collate;";
        dbDelta($sql);
        
        // Conversations table
        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ptp_conversations (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            trainer_id bigint(20) UNSIGNED NOT NULL,
            parent_id bigint(20) UNSIGNED NOT NULL,
            last_message_id bigint(20) UNSIGNED DEFAULT NULL,
            last_message_at datetime DEFAULT NULL,
            trainer_unread_count int(11) DEFAULT 0,
            parent_unread_count int(11) DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY trainer_parent (trainer_id, parent_id),
            KEY trainer_id (trainer_id),
            KEY parent_id (parent_id)
        ) $charset_collate;";
        dbDelta($sql);
        
        // Reviews table
        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ptp_reviews (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            booking_id bigint(20) UNSIGNED NOT NULL,
            trainer_id bigint(20) UNSIGNED NOT NULL,
            parent_id bigint(20) UNSIGNED NOT NULL,
            rating tinyint(1) NOT NULL,
            review text,
            is_published tinyint(1) DEFAULT 1,
            trainer_response text,
            response_at datetime DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY booking_id (booking_id),
            KEY trainer_id (trainer_id),
            KEY parent_id (parent_id),
            KEY rating (rating)
        ) $charset_collate;";
        dbDelta($sql);
        
        // Payouts table
        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ptp_payouts (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            trainer_id bigint(20) UNSIGNED NOT NULL,
            amount decimal(10,2) NOT NULL,
            status enum('pending','processing','completed','failed') DEFAULT 'pending',
            method varchar(50) DEFAULT 'venmo',
            payout_method varchar(50) DEFAULT 'venmo',
            payout_reference varchar(255) DEFAULT '',
            transaction_id varchar(255) DEFAULT '',
            notes text,
            processed_at datetime DEFAULT NULL,
            completed_at datetime DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            created_by bigint(20) UNSIGNED DEFAULT NULL,
            PRIMARY KEY (id),
            KEY trainer_id (trainer_id),
            KEY status (status)
        ) $charset_collate;";
        dbDelta($sql);
        
        // Payout items (links payouts to bookings)
        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ptp_payout_items (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            payout_id bigint(20) UNSIGNED NOT NULL,
            booking_id bigint(20) UNSIGNED NOT NULL,
            amount decimal(10,2) NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY payout_id (payout_id),
            KEY booking_id (booking_id)
        ) $charset_collate;";
        dbDelta($sql);
        
        // Trainer applications table
        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ptp_applications (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id bigint(20) UNSIGNED DEFAULT NULL,
            trainer_id bigint(20) UNSIGNED DEFAULT NULL,
            email varchar(255) NOT NULL,
            name varchar(100) NOT NULL,
            phone varchar(20) DEFAULT '',
            password_hash varchar(255) DEFAULT '',
            location varchar(255) DEFAULT '',
            college varchar(255) DEFAULT '',
            team varchar(255) DEFAULT '',
            playing_level varchar(50) DEFAULT '',
            position varchar(100) DEFAULT '',
            experience_years int(11) DEFAULT 0,
            specialties text,
            instagram varchar(100) DEFAULT '',
            headline varchar(255) DEFAULT '',
            bio text,
            hourly_rate decimal(10,2) DEFAULT 0,
            travel_radius int(11) DEFAULT 15,
            contractor_agreement_signed tinyint(1) DEFAULT 0,
            contractor_agreement_signed_at datetime DEFAULT NULL,
            contractor_agreement_ip varchar(45) DEFAULT '',
            status enum('pending','approved','rejected') DEFAULT 'pending',
            admin_notes text,
            rejection_reason text,
            reviewed_by bigint(20) UNSIGNED DEFAULT NULL,
            reviewed_at datetime DEFAULT NULL,
            approved_at datetime DEFAULT NULL,
            rejected_at datetime DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY trainer_id (trainer_id),
            KEY email (email),
            KEY status (status)
        ) $charset_collate;";
        dbDelta($sql);
        
        // Notifications table
        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ptp_notifications (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id bigint(20) UNSIGNED NOT NULL,
            type varchar(50) NOT NULL,
            title varchar(255) NOT NULL,
            message text,
            data text,
            is_read tinyint(1) DEFAULT 0,
            read_at datetime DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY type (type),
            KEY is_read (is_read)
        ) $charset_collate;";
        dbDelta($sql);
        
        // Activity log
        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ptp_activity_log (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id bigint(20) UNSIGNED DEFAULT NULL,
            action varchar(100) NOT NULL,
            object_type varchar(50) DEFAULT '',
            object_id bigint(20) UNSIGNED DEFAULT NULL,
            details text,
            ip_address varchar(45) DEFAULT '',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY action (action),
            KEY object_type (object_type, object_id)
        ) $charset_collate;";
        dbDelta($sql);
        
        // v48: Leads table for booking wizard lead capture
        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ptp_leads (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            trainer_id bigint(20) UNSIGNED DEFAULT NULL,
            name varchar(100) DEFAULT '',
            email varchar(255) NOT NULL,
            phone varchar(20) DEFAULT '',
            message text,
            source varchar(50) DEFAULT 'booking_wizard',
            status enum('new','contacted','converted','closed') DEFAULT 'new',
            notes text,
            contacted_at datetime DEFAULT NULL,
            converted_at datetime DEFAULT NULL,
            booking_id bigint(20) UNSIGNED DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY trainer_id (trainer_id),
            KEY email (email),
            KEY status (status),
            KEY source (source)
        ) $charset_collate;";
        dbDelta($sql);
        
        // Escrow table - holds payment info until session confirmed
        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ptp_escrow (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            booking_id bigint(20) UNSIGNED NOT NULL,
            trainer_id bigint(20) UNSIGNED NOT NULL,
            parent_id bigint(20) UNSIGNED NOT NULL,
            payment_intent_id varchar(255) NOT NULL,
            total_amount decimal(10,2) NOT NULL,
            platform_fee decimal(10,2) NOT NULL,
            trainer_amount decimal(10,2) NOT NULL,
            status enum('holding','session_complete','confirmed','disputed','released','refunded') DEFAULT 'holding',
            session_date date DEFAULT NULL,
            session_time time DEFAULT NULL,
            trainer_completed_at datetime DEFAULT NULL,
            parent_confirmed_at datetime DEFAULT NULL,
            auto_confirmed tinyint(1) DEFAULT 0,
            release_eligible_at datetime DEFAULT NULL,
            released_at datetime DEFAULT NULL,
            stripe_transfer_id varchar(255) DEFAULT '',
            release_method varchar(50) DEFAULT '',
            release_notes text,
            disputed_at datetime DEFAULT NULL,
            dispute_reason text,
            dispute_resolution varchar(50) DEFAULT '',
            dispute_resolved_at datetime DEFAULT NULL,
            dispute_resolved_by bigint(20) UNSIGNED DEFAULT NULL,
            resolution_notes text,
            refund_amount decimal(10,2) DEFAULT 0,
            refunded_at datetime DEFAULT NULL,
            parent_rating tinyint(1) DEFAULT NULL,
            parent_feedback text,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY booking_id (booking_id),
            KEY trainer_id (trainer_id),
            KEY parent_id (parent_id),
            KEY status (status),
            KEY release_eligible_at (release_eligible_at)
        ) $charset_collate;";
        dbDelta($sql);
        
        // Escrow log table - tracks all escrow events
        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ptp_escrow_log (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            escrow_id bigint(20) UNSIGNED NOT NULL,
            event_type varchar(50) NOT NULL,
            message text,
            user_id bigint(20) UNSIGNED DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY escrow_id (escrow_id),
            KEY event_type (event_type)
        ) $charset_collate;";
        dbDelta($sql);
        
        // FCM tokens table - for push notifications
        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ptp_fcm_tokens (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id bigint(20) UNSIGNED NOT NULL,
            token varchar(500) NOT NULL,
            device_type varchar(20) DEFAULT 'unknown',
            device_name varchar(100) DEFAULT '',
            app_version varchar(20) DEFAULT '',
            is_active tinyint(1) DEFAULT 1,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY token (token(255)),
            KEY user_id (user_id),
            KEY is_active (is_active)
        ) $charset_collate;";
        dbDelta($sql);
        
        // Push notification log
        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ptp_push_log (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            title varchar(255) NOT NULL,
            message text,
            audience varchar(50) DEFAULT 'all',
            data text,
            sent_count int(11) DEFAULT 0,
            failed_count int(11) DEFAULT 0,
            created_by bigint(20) UNSIGNED DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY audience (audience),
            KEY created_at (created_at)
        ) $charset_collate;";
        dbDelta($sql);
        
        // Favorites table for saved trainers
        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ptp_favorites (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id bigint(20) UNSIGNED NOT NULL,
            trainer_id bigint(20) UNSIGNED NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY user_trainer (user_id, trainer_id),
            KEY user_id (user_id),
            KEY trainer_id (trainer_id)
        ) $charset_collate;";
        dbDelta($sql);
        
        // Session notes from trainers
        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ptp_session_notes (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            booking_id bigint(20) UNSIGNED NOT NULL,
            player_id bigint(20) UNSIGNED DEFAULT NULL,
            trainer_id bigint(20) UNSIGNED NOT NULL,
            notes text,
            skills_covered text,
            progress_rating int(11) DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY booking_id (booking_id),
            KEY player_id (player_id),
            KEY trainer_id (trainer_id)
        ) $charset_collate;";
        dbDelta($sql);
        
        // Bundles table for combined camp + training purchases
        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ptp_bundles (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            bundle_code varchar(32) NOT NULL,
            user_id bigint(20) UNSIGNED DEFAULT NULL,
            session_id varchar(100) DEFAULT NULL,
            status enum('active','partial','completed','cancelled','abandoned') DEFAULT 'active',
            trainer_id bigint(20) UNSIGNED DEFAULT NULL,
            training_package varchar(50) DEFAULT NULL,
            training_sessions int(11) DEFAULT 1,
            training_amount decimal(10,2) DEFAULT 0.00,
            training_date date DEFAULT NULL,
            training_time time DEFAULT NULL,
            training_location text DEFAULT NULL,
            camp_order_id bigint(20) UNSIGNED DEFAULT NULL,
            camp_amount decimal(10,2) DEFAULT 0.00,
            discount_percent decimal(5,2) DEFAULT 15.00,
            discount_amount decimal(10,2) DEFAULT 0.00,
            total_amount decimal(10,2) DEFAULT 0.00,
            payment_intent_id varchar(255) DEFAULT NULL,
            stripe_session_id varchar(255) DEFAULT NULL,
            expires_at datetime DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY bundle_code (bundle_code),
            KEY user_id (user_id),
            KEY session_id (session_id),
            KEY trainer_id (trainer_id),
            KEY status (status),
            KEY created_at (created_at)
        ) $charset_collate;";
        dbDelta($sql);
        
        // Referral codes table
        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ptp_referral_codes (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            code varchar(20) NOT NULL,
            user_id bigint(20) UNSIGNED DEFAULT NULL,
            email varchar(255) DEFAULT '',
            discount_amount decimal(10,2) DEFAULT 25.00,
            discount_type enum('fixed','percent') DEFAULT 'fixed',
            uses_remaining int(11) DEFAULT 10,
            times_used int(11) DEFAULT 0,
            expires_at datetime DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY code (code),
            KEY user_id (user_id),
            KEY email (email)
        ) $charset_collate;";
        dbDelta($sql);
        
        // Calendar connections for Google Calendar sync
        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ptp_calendar_connections (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id bigint(20) UNSIGNED NOT NULL,
            provider varchar(50) DEFAULT 'google',
            access_token text,
            refresh_token text,
            token_expires datetime DEFAULT NULL,
            calendar_id varchar(255) DEFAULT NULL,
            sync_enabled tinyint(1) DEFAULT 1,
            email varchar(255) DEFAULT NULL,
            ics_token varchar(64) DEFAULT NULL,
            last_sync datetime DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY user_provider (user_id, provider),
            KEY user_id (user_id),
            KEY provider (provider),
            KEY ics_token (ics_token)
        ) $charset_collate;";
        dbDelta($sql);
        
        // Stripe Products table - trainings created in Stripe
        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ptp_stripe_products (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            stripe_product_id varchar(255) NOT NULL,
            trainer_id bigint(20) UNSIGNED NOT NULL,
            name varchar(255) NOT NULL,
            description text,
            price_cents int(11) DEFAULT NULL,
            active tinyint(1) DEFAULT 1,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY stripe_product_id (stripe_product_id),
            KEY trainer_id (trainer_id),
            KEY active (active)
        ) $charset_collate;";
        dbDelta($sql);

        // v148: Native Session table (WooCommerce-independent)
        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ptp_native_sessions (
            session_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            session_key VARCHAR(64) NOT NULL,
            user_id BIGINT UNSIGNED DEFAULT 0,
            session_data LONGTEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME,
            UNIQUE KEY session_key (session_key),
            KEY user_id (user_id),
            KEY expires_at (expires_at)
        ) $charset_collate;";
        dbDelta($sql);

        // v148: Native Cart Items table (WooCommerce-independent)
        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ptp_native_cart_items (
            cart_item_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            session_key VARCHAR(64) NOT NULL,
            item_type VARCHAR(20) NOT NULL,
            item_id BIGINT UNSIGNED NOT NULL,
            quantity INT UNSIGNED DEFAULT 1,
            price DECIMAL(10,2) NOT NULL,
            metadata JSON,
            added_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            KEY session_key (session_key),
            KEY item_type (item_type)
        ) $charset_collate;";
        dbDelta($sql);

        // v148: Native Orders table (WooCommerce-independent)
        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ptp_native_orders (
            order_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            order_number VARCHAR(32) NOT NULL,
            user_id BIGINT UNSIGNED DEFAULT 0,
            parent_id BIGINT UNSIGNED DEFAULT 0,
            status VARCHAR(20) DEFAULT 'pending',
            subtotal DECIMAL(10,2) DEFAULT 0,
            discount DECIMAL(10,2) DEFAULT 0,
            tax DECIMAL(10,2) DEFAULT 0,
            total DECIMAL(10,2) DEFAULT 0,
            payment_method VARCHAR(50),
            payment_status VARCHAR(20) DEFAULT 'pending',
            stripe_payment_intent VARCHAR(100),
            stripe_charge_id VARCHAR(100),
            billing_first_name VARCHAR(100),
            billing_last_name VARCHAR(100),
            billing_email VARCHAR(200),
            billing_phone VARCHAR(50),
            billing_address TEXT,
            notes TEXT,
            ip_address VARCHAR(45),
            user_agent TEXT,
            referral_code VARCHAR(50),
            metadata JSON,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            completed_at DATETIME,
            UNIQUE KEY order_number (order_number),
            KEY user_id (user_id),
            KEY parent_id (parent_id),
            KEY status (status),
            KEY payment_status (payment_status),
            KEY created_at (created_at)
        ) $charset_collate;";
        dbDelta($sql);

        // v148: Native Order Items table
        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ptp_native_order_items (
            item_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            order_id BIGINT UNSIGNED NOT NULL,
            item_type VARCHAR(20) NOT NULL,
            item_reference_id BIGINT UNSIGNED,
            name VARCHAR(255) NOT NULL,
            quantity INT UNSIGNED DEFAULT 1,
            unit_price DECIMAL(10,2) NOT NULL,
            total DECIMAL(10,2) NOT NULL,
            metadata JSON,
            KEY order_id (order_id),
            KEY item_type (item_type)
        ) $charset_collate;";
        dbDelta($sql);

        // v148: Native Order Meta table
        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ptp_native_order_meta (
            meta_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            order_id BIGINT UNSIGNED NOT NULL,
            meta_key VARCHAR(255) NOT NULL,
            meta_value LONGTEXT,
            KEY order_id (order_id),
            KEY order_key (order_id, meta_key(191))
        ) $charset_collate;";
        dbDelta($sql);

        // v148: Native Order Item Meta table
        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ptp_native_order_item_meta (
            meta_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            item_id BIGINT UNSIGNED NOT NULL,
            meta_key VARCHAR(255) NOT NULL,
            meta_value LONGTEXT,
            KEY item_id (item_id),
            KEY meta_key (meta_key(191))
        ) $charset_collate;";
        dbDelta($sql);
    }

    public static function drop_tables() {
        global $wpdb;
        
        $tables = array(
            'ptp_trainers',
            'ptp_parents',
            'ptp_players',
            'ptp_availability',
            'ptp_availability_exceptions',
            'ptp_bookings',
            'ptp_recurring_bookings',
            'ptp_sessions',
            'ptp_messages',
            'ptp_conversations',
            'ptp_reviews',
            'ptp_payouts',
            'ptp_payout_items',
            'ptp_applications',
            'ptp_notifications',
            'ptp_activity_log',
            'ptp_escrow',
            'ptp_escrow_log',
            'ptp_fcm_tokens',
            'ptp_push_log',
            'ptp_favorites',
            'ptp_session_notes',
            'ptp_referral_codes',
            'ptp_bundles',
            'ptp_stripe_products',
        );
        
        foreach ($tables as $table) {
            $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}{$table}");
        }
    }
    
    /**
     * Repair broken table schemas - called on plugin activation
     */
    public static function repair_tables() {
        global $wpdb;
        
        // ============ REPAIR ptp_parents TABLE ============
        $parents_table = $wpdb->prefix . 'ptp_parents';
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$parents_table'");
        
        if ($table_exists) {
            $columns = array(
                'user_id' => "bigint(20) UNSIGNED NOT NULL",
                'first_name' => "varchar(50) DEFAULT ''",
                'last_name' => "varchar(50) DEFAULT ''",
                'display_name' => "varchar(100) NOT NULL DEFAULT ''",
                'email' => "varchar(255) DEFAULT ''",
                'phone' => "varchar(20) DEFAULT ''",
                'location' => "varchar(255) DEFAULT ''",
                'latitude' => "decimal(10,8) DEFAULT NULL",
                'longitude' => "decimal(11,8) DEFAULT NULL",
                'emergency_name' => "varchar(100) DEFAULT ''",
                'emergency_phone' => "varchar(20) DEFAULT ''",
                'emergency_relation' => "varchar(50) DEFAULT ''",
                'medical_info' => "text DEFAULT NULL",
                'total_sessions' => "int(11) DEFAULT 0",
                'total_spent' => "decimal(10,2) DEFAULT 0.00",
                'notification_email' => "tinyint(1) DEFAULT 1",
                'notification_sms' => "tinyint(1) DEFAULT 1",
                'created_at' => "datetime DEFAULT CURRENT_TIMESTAMP",
            );
            
            foreach ($columns as $column => $definition) {
                $col_exists = $wpdb->get_var("SHOW COLUMNS FROM $parents_table LIKE '$column'");
                if (!$col_exists) {
                    $wpdb->query("ALTER TABLE $parents_table ADD COLUMN $column $definition");
                    error_log("PTP Repair: Added $column to ptp_parents");
                }
            }
        }
        
        // ============ REPAIR ptp_players TABLE ============
        $players_table = $wpdb->prefix . 'ptp_players';
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$players_table'");
        
        if ($table_exists) {
            $columns = array(
                'parent_id' => "bigint(20) UNSIGNED NOT NULL",
                'first_name' => "varchar(50) DEFAULT ''",
                'last_name' => "varchar(50) DEFAULT ''",
                'name' => "varchar(100) NOT NULL DEFAULT ''",
                'dob' => "date DEFAULT NULL",
                'age' => "int(11) DEFAULT NULL",
                'shirt_size' => "varchar(10) DEFAULT ''",
                'team' => "varchar(100) DEFAULT ''",
                'skill_level' => "varchar(20) DEFAULT 'beginner'",
                'position' => "varchar(100) DEFAULT ''",
                'goals' => "text",
                'notes' => "text",
                'is_active' => "tinyint(1) DEFAULT 1",
                'created_at' => "datetime DEFAULT CURRENT_TIMESTAMP",
            );
            
            foreach ($columns as $column => $definition) {
                $col_exists = $wpdb->get_var("SHOW COLUMNS FROM $players_table LIKE '$column'");
                if (!$col_exists) {
                    $wpdb->query("ALTER TABLE $players_table ADD COLUMN $column $definition");
                    error_log("PTP Repair: Added $column to ptp_players");
                }
            }
        }
        
        // ============ REPAIR ptp_bookings TABLE ============
        $bookings_table = $wpdb->prefix . 'ptp_bookings';
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$bookings_table'");
        
        if ($table_exists) {
            $columns = array(
                'booking_number' => "varchar(20) NOT NULL DEFAULT ''",
                'trainer_id' => "bigint(20) UNSIGNED NOT NULL DEFAULT 0",
                'parent_id' => "bigint(20) UNSIGNED NOT NULL DEFAULT 0",
                'player_id' => "bigint(20) UNSIGNED NOT NULL DEFAULT 0",
                'session_date' => "date DEFAULT NULL",
                'start_time' => "time DEFAULT NULL",
                'end_time' => "time DEFAULT NULL",
                'duration_minutes' => "int(11) DEFAULT 60",
                'location' => "varchar(255) DEFAULT ''",
                'location_notes' => "text",
                'hourly_rate' => "decimal(10,2) DEFAULT 0",
                'total_amount' => "decimal(10,2) DEFAULT 0",
                'platform_fee' => "decimal(10,2) DEFAULT 0",
                'trainer_payout' => "decimal(10,2) DEFAULT 0",
                'status' => "varchar(20) DEFAULT 'pending'",
                'payment_status' => "varchar(20) DEFAULT 'pending'",
                'notes' => "text",
                'created_at' => "datetime DEFAULT CURRENT_TIMESTAMP",
            );
            
            foreach ($columns as $column => $definition) {
                $col_exists = $wpdb->get_var("SHOW COLUMNS FROM $bookings_table LIKE '$column'");
                if (!$col_exists) {
                    $wpdb->query("ALTER TABLE $bookings_table ADD COLUMN $column $definition");
                    error_log("PTP Repair: Added $column to ptp_bookings");
                }
            }
        }
        
        // ============ REPAIR ptp_trainers TABLE ============
        $trainers_table = $wpdb->prefix . 'ptp_trainers';
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$trainers_table'");
        
        if ($table_exists) {
            $columns = array(
                'user_id' => "bigint(20) UNSIGNED NOT NULL DEFAULT 0",
                'display_name' => "varchar(100) NOT NULL DEFAULT ''",
                'slug' => "varchar(100) NOT NULL DEFAULT ''",
                'hourly_rate' => "decimal(10,2) DEFAULT 70",
                'cover_photo_url' => "varchar(500) DEFAULT ''",
            );
            
            foreach ($columns as $column => $definition) {
                $col_exists = $wpdb->get_var("SHOW COLUMNS FROM $trainers_table LIKE '$column'");
                if (!$col_exists) {
                    $wpdb->query("ALTER TABLE $trainers_table ADD COLUMN $column $definition");
                    error_log("PTP Repair: Added $column to ptp_trainers");
                }
            }
        }
    }
    
    /**
     * Quick repair - run on every critical operation
     * Adds ALL missing columns to critical tables
     */
    public static function quick_repair() {
        static $repaired = false;
        if ($repaired) return;
        $repaired = true;
        
        global $wpdb;
        
        // ============ FIX ptp_bookings TABLE ============
        $bookings_table = $wpdb->prefix . 'ptp_bookings';
        if ($wpdb->get_var("SHOW TABLES LIKE '$bookings_table'")) {
            $columns_needed = array(
                'booking_number' => "varchar(20) NOT NULL DEFAULT ''",
                'trainer_id' => "bigint(20) UNSIGNED NOT NULL DEFAULT 0",
                'parent_id' => "bigint(20) UNSIGNED NOT NULL DEFAULT 0",
                'player_id' => "bigint(20) UNSIGNED NOT NULL DEFAULT 0",
                'session_date' => "date DEFAULT NULL",
                'start_time' => "time DEFAULT NULL",
                'end_time' => "time DEFAULT NULL",
                'duration_minutes' => "int(11) DEFAULT 60",
                'location' => "varchar(255) DEFAULT ''",
                'hourly_rate' => "decimal(10,2) DEFAULT 0",
                'total_amount' => "decimal(10,2) DEFAULT 0",
                'platform_fee' => "decimal(10,2) DEFAULT 0",
                'trainer_payout' => "decimal(10,2) DEFAULT 0",
                'trainer_earnings' => "decimal(10,2) DEFAULT 0",
                'status' => "varchar(20) DEFAULT 'confirmed'",
                'payment_status' => "varchar(20) DEFAULT 'paid'",
                'payment_intent_id' => "varchar(255) DEFAULT ''",
                'escrow_id' => "bigint(20) UNSIGNED DEFAULT NULL",
                'escrow_status' => "varchar(30) DEFAULT NULL",
                'funds_held' => "tinyint(1) DEFAULT 0",
                'payout_status' => "varchar(20) DEFAULT NULL",
                'payout_at' => "datetime DEFAULT NULL",
                'completed_at' => "datetime DEFAULT NULL",
                'paid_at' => "datetime DEFAULT NULL",
                'notes' => "text",
                'session_type' => "varchar(20) DEFAULT 'single'",
                'session_count' => "int(11) DEFAULT 1",
                'sessions_remaining' => "int(11) DEFAULT 1",
                'group_players' => "text",
                'package_credit_id' => "bigint(20) UNSIGNED DEFAULT NULL",
                'guest_email' => "varchar(255) DEFAULT ''",
                'guest_phone' => "varchar(20) DEFAULT ''",
                'created_at' => "datetime DEFAULT CURRENT_TIMESTAMP",
            );
            
            foreach ($columns_needed as $col => $def) {
                $exists = $wpdb->get_var("SHOW COLUMNS FROM $bookings_table LIKE '$col'");
                if (!$exists) {
                    $wpdb->query("ALTER TABLE $bookings_table ADD COLUMN $col $def");
                    error_log("PTP Quick Repair: Added $col to ptp_bookings");
                }
            }
        } else {
            // Table doesn't exist - create it
            $charset = $wpdb->get_charset_collate();
            $wpdb->query("CREATE TABLE $bookings_table (
                id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                booking_number varchar(20) NOT NULL DEFAULT '',
                trainer_id bigint(20) UNSIGNED NOT NULL DEFAULT 0,
                parent_id bigint(20) UNSIGNED NOT NULL DEFAULT 0,
                player_id bigint(20) UNSIGNED NOT NULL DEFAULT 0,
                session_date date DEFAULT NULL,
                start_time time DEFAULT NULL,
                end_time time DEFAULT NULL,
                duration_minutes int(11) DEFAULT 60,
                location varchar(255) DEFAULT '',
                hourly_rate decimal(10,2) DEFAULT 0,
                total_amount decimal(10,2) DEFAULT 0,
                platform_fee decimal(10,2) DEFAULT 0,
                trainer_payout decimal(10,2) DEFAULT 0,
                status varchar(20) DEFAULT 'confirmed',
                payment_status varchar(20) DEFAULT 'paid',
                notes text,
                created_at datetime DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id)
            ) $charset");
            error_log("PTP Quick Repair: Created ptp_bookings table");
        }
        
        // ============ FIX ptp_parents TABLE ============
        $parents_table = $wpdb->prefix . 'ptp_parents';
        if ($wpdb->get_var("SHOW TABLES LIKE '$parents_table'")) {
            $columns_needed = array(
                'user_id' => "bigint(20) UNSIGNED NOT NULL DEFAULT 0",
                'display_name' => "varchar(100) NOT NULL DEFAULT ''",
                'email' => "varchar(255) DEFAULT ''",
                'phone' => "varchar(20) DEFAULT ''",
                'first_name' => "varchar(50) DEFAULT ''",
                'last_name' => "varchar(50) DEFAULT ''",
            );
            
            foreach ($columns_needed as $col => $def) {
                $exists = $wpdb->get_var("SHOW COLUMNS FROM $parents_table LIKE '$col'");
                if (!$exists) {
                    $wpdb->query("ALTER TABLE $parents_table ADD COLUMN $col $def");
                    error_log("PTP Quick Repair: Added $col to ptp_parents");
                }
            }
        }
        
        // ============ FIX ptp_players TABLE ============
        $players_table = $wpdb->prefix . 'ptp_players';
        if ($wpdb->get_var("SHOW TABLES LIKE '$players_table'")) {
            $columns_needed = array(
                'parent_id' => "bigint(20) UNSIGNED NOT NULL DEFAULT 0",
                'name' => "varchar(100) NOT NULL DEFAULT ''",
                'age' => "int(11) DEFAULT NULL",
                'skill_level' => "varchar(20) DEFAULT 'beginner'",
                'position' => "varchar(100) DEFAULT ''",
                'goals' => "text",
            );
            
            foreach ($columns_needed as $col => $def) {
                $exists = $wpdb->get_var("SHOW COLUMNS FROM $players_table LIKE '$col'");
                if (!$exists) {
                    $wpdb->query("ALTER TABLE $players_table ADD COLUMN $col $def");
                    error_log("PTP Quick Repair: Added $col to ptp_players");
                }
            }
        }
        
        // ============ FIX ptp_trainers TABLE ============
        $trainers_table = $wpdb->prefix . 'ptp_trainers';
        if ($wpdb->get_var("SHOW TABLES LIKE '$trainers_table'")) {
            $columns_needed = array(
                'email' => "varchar(255) DEFAULT ''",
                'trainer_faqs' => "longtext DEFAULT NULL",
                'sort_order' => "int(11) DEFAULT 0",
                'coaching_why' => "text DEFAULT NULL",
                'training_philosophy' => "text DEFAULT NULL",
                'training_policy' => "text DEFAULT NULL",
            );
            
            foreach ($columns_needed as $col => $def) {
                $exists = $wpdb->get_var("SHOW COLUMNS FROM $trainers_table LIKE '$col'");
                if (!$exists) {
                    $wpdb->query("ALTER TABLE $trainers_table ADD COLUMN $col $def");
                    error_log("PTP Quick Repair: Added $col to ptp_trainers");
                }
            }
            
            // Add index for sort_order if missing
            $index_exists = $wpdb->get_var("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = '$trainers_table' AND index_name = 'idx_sort_featured'");
            if (!$index_exists) {
                $wpdb->query("ALTER TABLE $trainers_table ADD INDEX idx_sort_featured (is_featured DESC, sort_order ASC)");
                error_log("PTP Quick Repair: Added idx_sort_featured index to ptp_trainers");
            }
        }
        
        // ============ FIX ptp_applications TABLE ============
        $applications_table = $wpdb->prefix . 'ptp_applications';
        if ($wpdb->get_var("SHOW TABLES LIKE '$applications_table'")) {
            $columns_needed = array(
                'password_hash' => "varchar(255) DEFAULT ''",
                'contractor_agreement_signed' => "tinyint(1) DEFAULT 0",
                'contractor_agreement_signed_at' => "datetime DEFAULT NULL",
                'contractor_agreement_ip' => "varchar(45) DEFAULT ''",
            );
            
            foreach ($columns_needed as $col => $def) {
                $exists = $wpdb->get_var("SHOW COLUMNS FROM $applications_table LIKE '$col'");
                if (!$exists) {
                    $wpdb->query("ALTER TABLE $applications_table ADD COLUMN $col $def");
                    error_log("PTP Quick Repair: Added $col to ptp_applications");
                }
            }
        }
        
        // ============ FIX ptp_bundles TABLE ============
        $bundles_table = $wpdb->prefix . 'ptp_bundles';
        if ($wpdb->get_var("SHOW TABLES LIKE '$bundles_table'")) {
            $columns_needed = array(
                'bundle_code' => "varchar(32) NOT NULL",
                'user_id' => "bigint(20) UNSIGNED DEFAULT NULL",
                'session_id' => "varchar(100) DEFAULT NULL",
                'status' => "varchar(20) DEFAULT 'active'",
                'trainer_id' => "bigint(20) UNSIGNED DEFAULT NULL",
                'training_package' => "varchar(50) DEFAULT NULL",
                'training_sessions' => "int(11) DEFAULT 1",
                'training_amount' => "decimal(10,2) DEFAULT 0.00",
                'training_date' => "date DEFAULT NULL",
                'training_time' => "time DEFAULT NULL",
                'training_location' => "text DEFAULT NULL",
                'booking_id' => "bigint(20) UNSIGNED DEFAULT NULL",
                'camp_order_id' => "bigint(20) UNSIGNED DEFAULT NULL",
                'camp_product_id' => "bigint(20) UNSIGNED DEFAULT NULL",
                'camp_amount' => "decimal(10,2) DEFAULT 0.00",
                'discount_percent' => "decimal(5,2) DEFAULT 15.00",
                'discount_amount' => "decimal(10,2) DEFAULT 0.00",
                'total_amount' => "decimal(10,2) DEFAULT 0.00",
                'payment_intent_id' => "varchar(255) DEFAULT NULL",
                'stripe_session_id' => "varchar(255) DEFAULT NULL",
                'expires_at' => "datetime DEFAULT NULL",
                'created_at' => "datetime DEFAULT CURRENT_TIMESTAMP",
                'updated_at' => "datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
            );
            
            foreach ($columns_needed as $col => $def) {
                $exists = $wpdb->get_var("SHOW COLUMNS FROM $bundles_table LIKE '$col'");
                if (!$exists) {
                    $wpdb->query("ALTER TABLE $bundles_table ADD COLUMN $col $def");
                    error_log("PTP Quick Repair: Added $col to ptp_bundles");
                }
            }
        }
        
        // v118.1: Backfill email addresses from WordPress users for existing records
        self::backfill_emails();
    }
    
    /**
     * Backfill email addresses for trainers and parents from WordPress users
     * v118.1: Only updates records that have empty email but have a valid user_id
     */
    public static function backfill_emails() {
        global $wpdb;
        
        // Backfill trainer emails
        $trainers_table = $wpdb->prefix . 'ptp_trainers';
        if ($wpdb->get_var("SHOW TABLES LIKE '$trainers_table'")) {
            $email_col = $wpdb->get_var("SHOW COLUMNS FROM $trainers_table LIKE 'email'");
            if ($email_col) {
                $trainers_without_email = $wpdb->get_results(
                    "SELECT id, user_id FROM $trainers_table WHERE (email IS NULL OR email = '') AND user_id > 0"
                );
                
                foreach ($trainers_without_email as $trainer) {
                    $user = get_userdata($trainer->user_id);
                    if ($user && $user->user_email) {
                        $wpdb->update(
                            $trainers_table,
                            array('email' => $user->user_email),
                            array('id' => $trainer->id)
                        );
                        error_log("PTP Backfill: Set email for trainer #{$trainer->id}");
                    }
                }
            }
        }
        
        // Backfill parent emails
        $parents_table = $wpdb->prefix . 'ptp_parents';
        if ($wpdb->get_var("SHOW TABLES LIKE '$parents_table'")) {
            $email_col = $wpdb->get_var("SHOW COLUMNS FROM $parents_table LIKE 'email'");
            if ($email_col) {
                $parents_without_email = $wpdb->get_results(
                    "SELECT id, user_id FROM $parents_table WHERE (email IS NULL OR email = '') AND user_id > 0"
                );
                
                foreach ($parents_without_email as $parent) {
                    $user = get_userdata($parent->user_id);
                    if ($user && $user->user_email) {
                        $wpdb->update(
                            $parents_table,
                            array('email' => $user->user_email),
                            array('id' => $parent->id)
                        );
                        error_log("PTP Backfill: Set email for parent #{$parent->id}");
                    }
                }
            }
        }
    }
    
    /**
     * Add performance indexes for scaling
     * Safe to run multiple times - checks for existing indexes
     */
    public static function add_performance_indexes() {
        global $wpdb;
        
        $indexes_added = 0;
        
        // Helper to safely add index
        $add_index = function($table, $index_name, $columns) use ($wpdb, &$indexes_added) {
            $full_table = $wpdb->prefix . $table;
            
            // Check if table exists
            if ($wpdb->get_var("SHOW TABLES LIKE '$full_table'") !== $full_table) {
                return;
            }
            
            // Check if index exists
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM information_schema.statistics 
                 WHERE table_schema = DATABASE() 
                 AND table_name = %s 
                 AND index_name = %s",
                $full_table,
                $index_name
            ));
            
            if (!$exists) {
                $result = $wpdb->query("ALTER TABLE $full_table ADD INDEX $index_name ($columns)");
                if ($result !== false) {
                    $indexes_added++;
                    error_log("PTP: Added index $index_name to $table");
                }
            }
        };
        
        // Trainers table indexes
        $add_index('ptp_trainers', 'idx_trainer_status_location', 'status, latitude, longitude');
        $add_index('ptp_trainers', 'idx_trainer_active_rating', 'status, average_rating DESC');
        $add_index('ptp_trainers', 'idx_trainer_slug', 'slug');
        $add_index('ptp_trainers', 'idx_trainer_email', 'email');
        
        // Bookings table indexes
        $add_index('ptp_bookings', 'idx_booking_trainer_date', 'trainer_id, session_date');
        $add_index('ptp_bookings', 'idx_booking_parent', 'parent_id, created_at DESC');
        $add_index('ptp_bookings', 'idx_booking_status_date', 'status, session_date');
        $add_index('ptp_bookings', 'idx_booking_payment', 'payment_status, payout_status');
        
        // Availability indexes
        $add_index('ptp_availability', 'idx_avail_trainer_day', 'trainer_id, day_of_week');
        $add_index('ptp_availability_exceptions', 'idx_avail_exc_trainer_date', 'trainer_id, exception_date');
        
        // Messages indexes
        $add_index('ptp_messages', 'idx_msg_conversation', 'conversation_id, created_at DESC');
        $add_index('ptp_conversations', 'idx_conv_trainer', 'trainer_id, last_message_at DESC');
        $add_index('ptp_conversations', 'idx_conv_parent', 'parent_id, last_message_at DESC');
        
        // Reviews indexes
        $add_index('ptp_reviews', 'idx_review_trainer', 'trainer_id, created_at DESC');
        
        // Players indexes
        $add_index('ptp_players', 'idx_player_parent_active', 'parent_id, is_active');
        
        // Payouts indexes
        $add_index('ptp_payouts', 'idx_payout_trainer_status', 'trainer_id, status');
        
        // v130.3: Additional enterprise indexes
        $add_index('ptp_bookings', 'idx_booking_created_at', 'created_at');
        $add_index('ptp_bookings', 'idx_booking_stripe', 'stripe_payment_id');
        $add_index('ptp_trainers', 'idx_trainer_stripe_account', 'stripe_account_id');
        $add_index('ptp_trainers', 'idx_trainer_featured_sort', 'is_featured, sort_order, average_rating DESC');
        $add_index('ptp_parents', 'idx_parent_created', 'created_at');
        $add_index('ptp_bookings', 'idx_booking_payout_pending', 'payout_status, trainer_id');
        
        return $indexes_added;
    }
    
    /**
     * Run on plugin activation - ensures tables and indexes exist
     * v130.3: Called automatically on activation
     */
    public static function activate() {
        self::create_tables();
        self::add_performance_indexes();
        
        // Set default options if not exists
        if (get_option('ptp_platform_fee') === false) {
            update_option('ptp_platform_fee', 20);
        }
        
        // Flush rewrite rules
        flush_rewrite_rules();
    }
}
