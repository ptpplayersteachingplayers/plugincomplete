<?php
/**
 * PTP WooCommerce Order Integration - v71
 * Handles order metadata, thank you page customization, and order details display
 * 
 * @since 71.0.0
 */

defined('ABSPATH') || exit;

class PTP_WooCommerce_Orders_V71 {
    
    private static $instance = null;
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        // Order metadata
        add_action('woocommerce_checkout_create_order_line_item', array($this, 'save_order_item_meta'), 10, 4);
        add_action('woocommerce_checkout_order_processed', array($this, 'process_order_meta'), 10, 3);
        
        // Thank you page
        add_action('woocommerce_thankyou', array($this, 'thank_you_page_content'), 5);
        add_filter('woocommerce_thankyou_order_received_text', array($this, 'custom_thank_you_text'), 10, 2);
        
        // Order emails - comprehensive styling
        add_action('woocommerce_email_styles', array($this, 'add_email_styles'), 10);
        add_action('woocommerce_email_header', array($this, 'email_header_logo'), 10, 2);
        add_action('woocommerce_email_after_order_table', array($this, 'add_event_details_to_email'), 10, 4);
        add_filter('woocommerce_email_order_items_args', array($this, 'email_order_items_args'), 10, 1);
        
        // Order details page
        add_action('woocommerce_order_details_after_order_table', array($this, 'display_event_details'), 10);
        
        // Admin order page
        add_action('woocommerce_admin_order_data_after_billing_address', array($this, 'admin_order_meta'), 10);
        add_action('add_meta_boxes', array($this, 'add_order_meta_boxes'));
        
        // Cart item data
        add_filter('woocommerce_get_item_data', array($this, 'display_cart_item_data'), 10, 2);
        add_action('woocommerce_checkout_create_order_line_item', array($this, 'save_cart_item_meta'), 10, 4);
        
        // REST API for order data
        add_action('rest_api_init', array($this, 'register_rest_routes'));
    }
    
    /**
     * Add custom CSS to WooCommerce emails
     */
    public function add_email_styles($css) {
        $css .= '
            /* PTP Email Styles */
            #wrapper {
                background-color: #f7f7f7 !important;
                padding: 40px 20px !important;
            }
            #template_container {
                background-color: #ffffff !important;
                border: none !important;
                border-radius: 12px !important;
                box-shadow: 0 2px 8px rgba(0,0,0,0.08) !important;
            }
            #template_header {
                background-color: #0A0A0A !important;
                border-radius: 12px 12px 0 0 !important;
                border-bottom: 4px solid #FCB900 !important;
            }
            #template_header h1 {
                color: #FCB900 !important;
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif !important;
                font-size: 24px !important;
                font-weight: 700 !important;
                text-transform: uppercase !important;
                letter-spacing: 1px !important;
            }
            #template_body {
                padding: 32px !important;
            }
            #template_footer {
                border-top: 1px solid #E5E7EB !important;
            }
            #template_footer #credit {
                color: #9CA3AF !important;
                font-size: 12px !important;
            }
            h2 {
                color: #0A0A0A !important;
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif !important;
                font-size: 18px !important;
                font-weight: 700 !important;
                border-bottom: 3px solid #FCB900 !important;
                padding-bottom: 10px !important;
                margin-bottom: 20px !important;
            }
            .td {
                color: #374151 !important;
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif !important;
            }
            .order_item {
                border-bottom: 1px solid #E5E7EB !important;
            }
            .order_item td {
                padding: 16px 12px !important;
            }
            table.td {
                border: 1px solid #E5E7EB !important;
                border-radius: 8px !important;
            }
            table.td th {
                background: #F9FAFB !important;
                color: #374151 !important;
                font-weight: 600 !important;
                text-transform: uppercase !important;
                font-size: 12px !important;
                letter-spacing: 0.5px !important;
                border-bottom: 2px solid #E5E7EB !important;
            }
            .address {
                background: #F9FAFB !important;
                border: 1px solid #E5E7EB !important;
                border-radius: 8px !important;
                padding: 16px !important;
            }
            a {
                color: #FCB900 !important;
                font-weight: 600 !important;
            }
        ';
        return $css;
    }
    
    /**
     * Customize email header with PTP logo
     */
    public function email_header_logo($email_heading, $email = null) {
        // PTP logo can be added here if needed
        // For now, we let WooCommerce handle it but our CSS styles the header
    }
    
    /**
     * Customize order items display in email
     */
    public function email_order_items_args($args) {
        $args['show_image'] = true;
        $args['image_size'] = array(64, 64);
        return $args;
    }
    
    /**
     * Save order item metadata from cart
     */
    public function save_order_item_meta($item, $cart_item_key, $values, $order) {
        // Player information
        if (!empty($values['player_name'])) {
            $item->add_meta_data('Player Name', sanitize_text_field($values['player_name']), true);
        }
        
        if (!empty($values['player_age'])) {
            $item->add_meta_data('Player Age', intval($values['player_age']), true);
        }
        
        if (!empty($values['player_position'])) {
            $item->add_meta_data('Player Position', sanitize_text_field($values['player_position']), true);
        }
        
        if (!empty($values['player_team'])) {
            $item->add_meta_data('Player Team', sanitize_text_field($values['player_team']), true);
        }
        
        // Event details
        if (!empty($values['event_date'])) {
            $item->add_meta_data('Event Date', sanitize_text_field($values['event_date']), true);
        }
        
        if (!empty($values['event_time'])) {
            $item->add_meta_data('Event Time', sanitize_text_field($values['event_time']), true);
        }
        
        if (!empty($values['event_location'])) {
            $item->add_meta_data('Event Location', sanitize_text_field($values['event_location']), true);
        }
        
        if (!empty($values['event_address'])) {
            $item->add_meta_data('Event Address', sanitize_text_field($values['event_address']), true);
        }
        
        // Trainer info (for private sessions)
        if (!empty($values['trainer_id'])) {
            $item->add_meta_data('_trainer_id', intval($values['trainer_id']), true);
            
            $trainer = $this->get_trainer_data($values['trainer_id']);
            if ($trainer) {
                $item->add_meta_data('Trainer', $trainer['name'], true);
            }
        }
        
        // Session type
        if (!empty($values['session_type'])) {
            $item->add_meta_data('Session Type', sanitize_text_field($values['session_type']), true);
        }
        
        // Camp/clinic specific
        if (!empty($values['camp_id'])) {
            $item->add_meta_data('_camp_id', intval($values['camp_id']), true);
        }
        
        // Booking ID for private training
        if (!empty($values['booking_id'])) {
            $item->add_meta_data('_booking_id', intval($values['booking_id']), true);
        }
    }
    
    /**
     * Process order metadata after checkout
     */
    public function process_order_meta($order_id, $posted_data, $order) {
        // Get all items and process
        foreach ($order->get_items() as $item_id => $item) {
            $product_id = $item->get_product_id();
            
            // Check if this is a camp/clinic product
            $camp_data = $this->get_camp_data_from_product($product_id);
            if ($camp_data) {
                $this->process_camp_registration($order_id, $item, $camp_data);
            }
            
            // Check if this is a private training product
            $trainer_id = $item->get_meta('_trainer_id');
            if ($trainer_id) {
                $this->process_training_booking($order_id, $item, $trainer_id);
            }
        }
        
        // Create parent record if not exists
        $this->maybe_create_parent_record($order);
        
        // Send notifications
        $this->send_order_notifications($order);
    }
    
    /**
     * Get camp data from product
     */
    private function get_camp_data_from_product($product_id) {
        // Check for camp meta
        $camp_date = get_post_meta($product_id, '_camp_date', true);
        $camp_time = get_post_meta($product_id, '_camp_time', true);
        $camp_location = get_post_meta($product_id, '_camp_location', true);
        $camp_address = get_post_meta($product_id, '_camp_address', true);
        
        if ($camp_date) {
            return array(
                'date' => $camp_date,
                'time' => $camp_time,
                'location' => $camp_location,
                'address' => $camp_address,
                'product_id' => $product_id
            );
        }
        
        // Try to parse from product title
        $product = wc_get_product($product_id);
        if ($product) {
            $title = $product->get_name();
            // Parse date from title if present
            if (preg_match('/(\w+ \d{1,2})/', $title, $matches)) {
                return array(
                    'date' => $matches[1],
                    'time' => $camp_time,
                    'location' => $camp_location,
                    'address' => $camp_address,
                    'product_id' => $product_id
                );
            }
        }
        
        return null;
    }
    
    /**
     * Process camp/clinic registration
     */
    private function process_camp_registration($order_id, $item, $camp_data) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'ptp_registrations';
        
        // Check if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
            return;
        }
        
        $wpdb->insert($table, array(
            'order_id' => $order_id,
            'order_item_id' => $item->get_id(),
            'product_id' => $item->get_product_id(),
            'player_name' => $item->get_meta('Player Name'),
            'player_age' => $item->get_meta('Player Age'),
            'event_date' => $camp_data['date'],
            'event_time' => $camp_data['time'],
            'event_location' => $camp_data['location'],
            'status' => 'confirmed',
            'created_at' => current_time('mysql')
        ));
    }
    
    /**
     * Process private training booking
     */
    private function process_training_booking($order_id, $item, $trainer_id) {
        global $wpdb;
        
        $booking_id = $item->get_meta('_booking_id');
        if (!$booking_id) return;
        
        // Update booking status
        $wpdb->update(
            $wpdb->prefix . 'ptp_bookings',
            array(
                'status' => 'confirmed',
                'order_id' => $order_id,
                'paid_at' => current_time('mysql')
            ),
            array('id' => $booking_id)
        );
        
        // Create payout record for trainer
        if (class_exists('PTP_Trainer_Payouts')) {
            PTP_Trainer_Payouts::create_payout_record($booking_id, $order_id);
        }
    }
    
    /**
     * Maybe create parent record
     */
    private function maybe_create_parent_record($order) {
        if (!class_exists('PTP_Parent')) return;
        
        $user_id = $order->get_user_id();
        if (!$user_id) return;
        
        $existing = PTP_Parent::get_by_user_id($user_id);
        if ($existing) return;
        
        // Create parent record
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'ptp_parents',
            array(
                'user_id' => $user_id,
                'first_name' => $order->get_billing_first_name(),
                'last_name' => $order->get_billing_last_name(),
                'email' => $order->get_billing_email(),
                'phone' => $order->get_billing_phone(),
                'created_at' => current_time('mysql')
            )
        );
    }
    
    /**
     * Send order notifications
     */
    private function send_order_notifications($order) {
        // SMS notification if enabled
        if (class_exists('PTP_SMS_V71')) {
            $phone = $order->get_billing_phone();
            if ($phone) {
                $message = sprintf(
                    "Thanks for your PTP order #%d! Check your email for details. Questions? Reply to this text.",
                    $order->get_id()
                );
                PTP_SMS_V71::send($phone, $message);
            }
        }
        
        // Notify trainers for private sessions
        foreach ($order->get_items() as $item) {
            $trainer_id = $item->get_meta('_trainer_id');
            if ($trainer_id) {
                $this->notify_trainer_of_booking($trainer_id, $order, $item);
            }
        }
    }
    
    /**
     * Notify trainer of new booking
     */
    private function notify_trainer_of_booking($trainer_id, $order, $item) {
        $trainer = $this->get_trainer_data($trainer_id);
        if (!$trainer || empty($trainer['phone'])) return;
        
        if (class_exists('PTP_SMS_V71')) {
            $message = sprintf(
                "New booking! %s booked a session on %s at %s. Check your dashboard for details.",
                $order->get_billing_first_name(),
                $item->get_meta('Event Date'),
                $item->get_meta('Event Time')
            );
            PTP_SMS_V71::send($trainer['phone'], $message);
        }
    }
    
    /**
     * Get trainer data
     */
    private function get_trainer_data($trainer_id) {
        global $wpdb;
        
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_trainers WHERE id = %d",
            $trainer_id
        ));
        
        if (!$trainer) return null;
        
        return array(
            'id' => $trainer->id,
            'name' => $trainer->first_name . ' ' . $trainer->last_name,
            'email' => $trainer->email,
            'phone' => $trainer->phone
        );
    }
    
    /**
     * Custom thank you page content
     */
    public function thank_you_page_content($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) return;
        
        // Use the same comprehensive display as order details page
        $this->display_event_details($order);
    }
    
    /**
     * Render event details on thank you page
     */
    private function render_event_details($events) {
        ?>
        <div class="ptp-order-events">
            <h2 class="ptp-events-title">Camp/Clinic Details</h2>
            
            <?php foreach ($events as $event): ?>
                <div class="ptp-event-card">
                    <h3 class="ptp-event-name"><?php echo esc_html($event['name']); ?></h3>
                    
                    <?php if ($event['date']): ?>
                        <p class="ptp-event-datetime">
                            
                            <?php 
                            $date = $event['date'];
                            if (strtotime($date)) {
                                $date = date_i18n('l, F j, Y', strtotime($date));
                            }
                            echo esc_html($date);
                            ?>
                        </p>
                    <?php endif; ?>
                    
                    <?php if ($event['time']): ?>
                        <p class="ptp-event-time">
                            
                            <?php echo esc_html($event['time']); ?>
                        </p>
                    <?php endif; ?>
                    
                    <?php if ($event['location']): ?>
                        <p class="ptp-event-location">
                            
                            <?php echo esc_html($event['location']); ?>
                            <?php if ($event['address']): ?>
                                <br><small><?php echo esc_html($event['address']); ?></small>
                            <?php endif; ?>
                        </p>
                    <?php endif; ?>
                    
                    <?php if ($event['player']): ?>
                        <p class="ptp-event-player">
                            
                            Player: <?php echo esc_html($event['player']); ?>
                        </p>
                    <?php endif; ?>
                    
                    <?php if ($event['trainer']): ?>
                        <p class="ptp-event-trainer">
                            
                            Trainer: <?php echo esc_html($event['trainer']); ?>
                        </p>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
        
        <style>
            .ptp-order-events {
                margin: 30px 0;
                padding: 0;
            }
            
            .ptp-events-title {
                font-family: 'Oswald', sans-serif;
                font-size: 24px;
                font-weight: 600;
                margin: 0 0 20px;
                padding-bottom: 10px;
                border-bottom: 2px solid #0A0A0A;
            }
            
            .ptp-event-card {
                background: #fff;
                border: 2px solid #0A0A0A;
                padding: 20px;
                margin-bottom: 16px;
            }
            
            .ptp-event-name {
                font-family: 'Oswald', sans-serif;
                font-size: 18px;
                font-weight: 600;
                margin: 0 0 16px;
                padding-bottom: 12px;
                border-bottom: 1px solid #E5E5E5;
            }
            
            .ptp-event-card p {
                margin: 0 0 10px;
                font-family: 'Inter', sans-serif;
                font-size: 15px;
                display: flex;
                align-items: flex-start;
                gap: 10px;
            }
            
            .ptp-event-card p:last-child {
                margin-bottom: 0;
            }
            
            .ptp-icon {
                flex-shrink: 0;
            }
            
            .ptp-event-card small {
                color: #666;
            }
            
            @media (max-width: 767px) {
                .ptp-event-card {
                    padding: 16px;
                }
                
                .ptp-events-title {
                    font-size: 20px;
                }
            }
        </style>
        <?php
    }
    
    /**
     * Custom thank you text
     */
    public function custom_thank_you_text($text, $order) {
        if (!$order) return $text;
        
        $first_name = $order->get_billing_first_name();
        
        return sprintf(
            '<strong>Thank you %s!</strong> Your registration is confirmed. You\'ll receive a confirmation email shortly with all the details.',
            esc_html($first_name)
        );
    }
    
    /**
     * Add event details to order emails
     */
    public function add_event_details_to_email($order, $sent_to_admin, $plain_text, $email) {
        // Get camper information
        $campers = $order->get_meta('_ptp_campers');
        
        // Get emergency contact
        $emergency_name = $order->get_meta('_ptp_emergency_name');
        $emergency_phone = $order->get_meta('_ptp_emergency_phone');
        $emergency_relationship = $order->get_meta('_ptp_emergency_relationship');
        
        // Get medical info
        $medical_info = $order->get_meta('_ptp_medical_info');
        
        // Get waiver info
        $waiver_signed = $order->get_meta('_ptp_waiver_signed');
        $waiver_date = $order->get_meta('_ptp_waiver_date');
        
        // Get add-ons (v102)
        $before_after_care = $order->get_meta('_before_after_care');
        $care_amount = $order->get_meta('_before_after_care_amount');
        $upgrade_pack = $order->get_meta('_upgrade_pack');
        $upgrade_camp_names = $order->get_meta('_upgrade_camp_names');
        
        // Get event details from items
        $events = array();
        $has_camp = false;
        $has_training = false;
        
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            $product_id = $product ? $product->get_id() : 0;
            
            // Check product categories
            if ($product_id) {
                $cats = wp_get_post_terms($product_id, 'product_cat', array('fields' => 'slugs'));
                if (array_intersect($cats, array('camps', 'clinics', 'camp', 'clinic'))) {
                    $has_camp = true;
                }
            }
            
            // Check for trainer ID (training session)
            $trainer_id = $item->get_meta('_trainer_id');
            if ($trainer_id) {
                $has_training = true;
            }
            
            $event_date = $item->get_meta('Event Date');
            if ($event_date || $trainer_id) {
                $events[] = array(
                    'name' => $item->get_name(),
                    'date' => $event_date,
                    'time' => $item->get_meta('Event Time'),
                    'location' => $item->get_meta('Event Location'),
                    'player' => $item->get_meta('Player Name'),
                    'trainer' => $item->get_meta('Trainer'),
                );
            }
        }
        
        // Check if we have any PTP data
        $has_ptp_data = !empty($campers) || !empty($emergency_name) || !empty($events);
        if (!$has_ptp_data) return;
        
        if ($plain_text) {
            // Plain text email format
            echo "\n\n";
            echo "========================================\n";
            echo "CAMP & TRAINING DETAILS\n";
            echo "========================================\n\n";
            
            // Events first
            if (!empty($events)) {
                foreach ($events as $event) {
                    echo strtoupper($event['name']) . "\n";
                    echo str_repeat('-', strlen($event['name'])) . "\n";
                    if ($event['date']) echo "Date: " . $event['date'] . "\n";
                    if ($event['time']) echo "Time: " . $event['time'] . " EST\n";
                    if ($event['location']) echo "Location: " . $event['location'] . "\n";
                    if ($event['trainer']) echo "Trainer: " . $event['trainer'] . "\n";
                    if ($event['player']) echo "Player: " . $event['player'] . "\n";
                    echo "\n";
                }
            }
            
            // Campers
            if (!empty($campers) && is_array($campers)) {
                echo "REGISTERED PLAYERS\n";
                echo "------------------\n";
                $num = 1;
                foreach ($campers as $camper) {
                    $name = trim(($camper['full_name'] ?? '') ?: (($camper['first_name'] ?? '') . ' ' . ($camper['last_name'] ?? '')));
                    echo "Player {$num}: {$name}";
                    if (!empty($camper['age'])) echo " ({$camper['age']} years old)";
                    echo "\n";
                    if (!empty($camper['skill_level'])) echo "   Level: " . ucfirst($camper['skill_level']) . "\n";
                    if (!empty($camper['team'])) echo "   Team: {$camper['team']}\n";
                    if (!empty($camper['shirt_size'])) echo "   Shirt Size: {$camper['shirt_size']}\n";
                    $num++;
                }
                echo "\n";
            }
            
            // Add-Ons (v102)
            if ($before_after_care === 'yes' || $upgrade_pack) {
                echo "YOUR ADD-ONS\n";
                echo "------------\n";
                if ($before_after_care === 'yes') {
                    echo "✓ Before & After Care (Drop-off: 8AM, Pick-up: 5PM)\n";
                }
                if ($upgrade_pack) {
                    $upgrade_labels = array(
                        '2pack' => '2-Camp Pack',
                        '3pack' => '3-Camp Pack',
                        'allaccess' => 'All-Access Pass',
                    );
                    echo "✓ " . ($upgrade_labels[$upgrade_pack] ?? $upgrade_pack) . "\n";
                    if ($upgrade_camp_names) {
                        echo "   Additional camps: {$upgrade_camp_names}\n";
                    }
                }
                echo "\n";
            }
            
            // Emergency Contact
            if ($emergency_name || $emergency_phone) {
                echo "EMERGENCY CONTACT\n";
                echo "-----------------\n";
                if ($emergency_name) {
                    echo "Name: {$emergency_name}";
                    if ($emergency_relationship) echo " ({$emergency_relationship})";
                    echo "\n";
                }
                if ($emergency_phone) echo "Phone: {$emergency_phone}\n";
                echo "\n";
            }
            
            // What to Bring
            if ($has_camp) {
                echo "WHAT TO BRING\n";
                echo "-------------\n";
                echo "- Soccer cleats and shin guards\n";
                echo "- Water bottle (labeled)\n";
                echo "- Soccer ball (if you have one)\n";
                echo "- Positive attitude!\n\n";
            }
            
            // Waiver
            if ($waiver_signed === 'yes') {
                echo "WAIVER: Signed";
                if ($waiver_date) echo " on " . date('M j, Y', strtotime($waiver_date));
                echo "\n";
            }
            
        } else {
            // HTML email format - Professional design
            ?>
            <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="margin: 32px 0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;">
                
                <?php if (!empty($events)): ?>
                <?php foreach ($events as $event): ?>
                <!-- Event Card -->
                <tr>
                    <td style="padding-bottom: 24px;">
                        <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="background: #FFFBEB; border: 2px solid #FCB900; border-radius: 12px; overflow: hidden;">
                            <tr>
                                <td style="padding: 20px 24px; background: #FCB900;">
                                    <h3 style="margin: 0; font-size: 18px; font-weight: 700; color: #0A0A0A; text-transform: uppercase; letter-spacing: 0.5px;"><?php echo esc_html($event['name']); ?></h3>
                                </td>
                            </tr>
                            <tr>
                                <td style="padding: 20px 24px;">
                                    <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
                                        <?php if ($event['date']): ?>
                                        <tr>
                                            <td style="padding: 8px 0; border-bottom: 1px solid #FDE68A;">
                                                <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
                                                    <tr>
                                                        <td width="28" valign="top" style="padding-right: 12px;">
                                                            <img src="https://ptpsummercamps.com/wp-content/uploads/2025/01/icon-calendar.png" width="20" height="20" alt="" style="display: block;">
                                                        </td>
                                                        <td style="font-size: 15px; color: #374151;"><?php echo esc_html($event['date']); ?></td>
                                                    </tr>
                                                </table>
                                            </td>
                                        </tr>
                                        <?php endif; ?>
                                        <?php if ($event['time']): ?>
                                        <tr>
                                            <td style="padding: 8px 0; border-bottom: 1px solid #FDE68A;">
                                                <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
                                                    <tr>
                                                        <td width="28" valign="top" style="padding-right: 12px;">
                                                            <img src="https://ptpsummercamps.com/wp-content/uploads/2025/01/icon-clock.png" width="20" height="20" alt="" style="display: block;">
                                                        </td>
                                                        <td style="font-size: 15px; color: #374151;"><?php echo esc_html($event['time']); ?> EST</td>
                                                    </tr>
                                                </table>
                                            </td>
                                        </tr>
                                        <?php endif; ?>
                                        <?php if ($event['location']): ?>
                                        <tr>
                                            <td style="padding: 8px 0; border-bottom: 1px solid #FDE68A;">
                                                <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
                                                    <tr>
                                                        <td width="28" valign="top" style="padding-right: 12px;">
                                                            <img src="https://ptpsummercamps.com/wp-content/uploads/2025/01/icon-location.png" width="20" height="20" alt="" style="display: block;">
                                                        </td>
                                                        <td style="font-size: 15px; color: #374151;"><?php echo esc_html($event['location']); ?></td>
                                                    </tr>
                                                </table>
                                            </td>
                                        </tr>
                                        <?php endif; ?>
                                        <?php if ($event['trainer']): ?>
                                        <tr>
                                            <td style="padding: 8px 0;">
                                                <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
                                                    <tr>
                                                        <td width="28" valign="top" style="padding-right: 12px;">
                                                            <img src="https://ptpsummercamps.com/wp-content/uploads/2025/01/icon-trainer.png" width="20" height="20" alt="" style="display: block;">
                                                        </td>
                                                        <td style="font-size: 15px; color: #374151;">with <strong><?php echo esc_html($event['trainer']); ?></strong></td>
                                                    </tr>
                                                </table>
                                            </td>
                                        </tr>
                                        <?php endif; ?>
                                    </table>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
                
                <?php if ($before_after_care === 'yes' || $upgrade_pack): ?>
                <!-- Add-Ons -->
                <tr>
                    <td style="padding-bottom: 24px;">
                        <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="background: #EFF6FF; border: 1px solid #BFDBFE; border-radius: 12px; overflow: hidden;">
                            <tr>
                                <td style="padding: 16px 24px; background: #DBEAFE; border-bottom: 1px solid #BFDBFE;">
                                    <h3 style="margin: 0; font-size: 14px; font-weight: 700; color: #1E40AF; text-transform: uppercase; letter-spacing: 0.5px;">Your Add-Ons</h3>
                                </td>
                            </tr>
                            <tr>
                                <td style="padding: 16px 24px;">
                                    <?php if ($before_after_care === 'yes'): ?>
                                    <p style="margin: 0 0 12px; font-size: 14px; color: #1E3A8A;">
                                        <strong style="color: #1E40AF;">✓ Before & After Care</strong><br>
                                        <span style="color: #3B82F6;">Drop-off: 8:00 AM · Pick-up: 5:00 PM</span>
                                    </p>
                                    <?php endif; ?>
                                    <?php if ($upgrade_pack): 
                                        $upgrade_labels = array(
                                            '2pack' => '2-Camp Pack',
                                            '3pack' => '3-Camp Pack',
                                            'allaccess' => 'All-Access Pass',
                                        );
                                        $upgrade_label = $upgrade_labels[$upgrade_pack] ?? $upgrade_pack;
                                    ?>
                                    <p style="margin: 0; font-size: 14px; color: #1E3A8A;">
                                        <strong style="color: #1E40AF;">✓ <?php echo esc_html($upgrade_label); ?></strong>
                                        <?php if ($upgrade_camp_names): ?>
                                        <br><span style="color: #3B82F6;">Additional camps: <?php echo esc_html($upgrade_camp_names); ?></span>
                                        <?php endif; ?>
                                    </p>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
                <?php endif; ?>
                
                <?php if (!empty($campers) && is_array($campers)): ?>
                <!-- Registered Players -->
                <tr>
                    <td style="padding-bottom: 24px;">
                        <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="background: #ffffff; border: 1px solid #E5E7EB; border-radius: 12px; overflow: hidden;">
                            <tr>
                                <td style="padding: 16px 24px; background: #F9FAFB; border-bottom: 1px solid #E5E7EB;">
                                    <h3 style="margin: 0; font-size: 14px; font-weight: 700; color: #0A0A0A; text-transform: uppercase; letter-spacing: 0.5px;">Registered Players</h3>
                                </td>
                            </tr>
                            <?php $num = 1; foreach ($campers as $camper): 
                                $name = trim(($camper['full_name'] ?? '') ?: (($camper['first_name'] ?? '') . ' ' . ($camper['last_name'] ?? '')));
                            ?>
                            <tr>
                                <td style="padding: 16px 24px; <?php echo $num < count($campers) ? 'border-bottom: 1px solid #E5E7EB;' : ''; ?>">
                                    <p style="margin: 0 0 6px; font-size: 16px; font-weight: 600; color: #111;">
                                        <?php echo esc_html($name); ?>
                                        <?php if (!empty($camper['age'])): ?>
                                        <span style="font-weight: 400; color: #6B7280;">(<?php echo intval($camper['age']); ?> years old)</span>
                                        <?php endif; ?>
                                    </p>
                                    <table role="presentation" cellspacing="0" cellpadding="0" border="0">
                                        <tr>
                                            <?php if (!empty($camper['skill_level'])): ?>
                                            <td style="padding-right: 20px;">
                                                <span style="font-size: 13px; color: #6B7280;">Level:</span>
                                                <span style="font-size: 13px; color: #374151; font-weight: 500;"><?php echo ucfirst(esc_html($camper['skill_level'])); ?></span>
                                            </td>
                                            <?php endif; ?>
                                            <?php if (!empty($camper['team'])): ?>
                                            <td style="padding-right: 20px;">
                                                <span style="font-size: 13px; color: #6B7280;">Team:</span>
                                                <span style="font-size: 13px; color: #374151; font-weight: 500;"><?php echo esc_html($camper['team']); ?></span>
                                            </td>
                                            <?php endif; ?>
                                            <?php if (!empty($camper['shirt_size'])): ?>
                                            <td>
                                                <span style="font-size: 13px; color: #6B7280;">Shirt Size:</span>
                                                <span style="font-size: 13px; color: #374151; font-weight: 500;"><?php echo esc_html($camper['shirt_size']); ?></span>
                                            </td>
                                            <?php endif; ?>
                                        </tr>
                                    </table>
                                </td>
                            </tr>
                            <?php $num++; endforeach; ?>
                        </table>
                    </td>
                </tr>
                <?php endif; ?>
                
                <?php if ($has_camp): ?>
                <!-- What to Bring -->
                <tr>
                    <td style="padding-bottom: 24px;">
                        <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="background: #F0FDF4; border: 1px solid #BBF7D0; border-radius: 12px; overflow: hidden;">
                            <tr>
                                <td style="padding: 20px 24px;">
                                    <h3 style="margin: 0 0 12px; font-size: 14px; font-weight: 700; color: #166534; text-transform: uppercase; letter-spacing: 0.5px;">What to Bring</h3>
                                    <table role="presentation" cellspacing="0" cellpadding="0" border="0">
                                        <tr><td style="padding: 4px 0; font-size: 14px; color: #166534;">&#10003; Soccer cleats and shin guards</td></tr>
                                        <tr><td style="padding: 4px 0; font-size: 14px; color: #166534;">&#10003; Water bottle (labeled with name)</td></tr>
                                        <tr><td style="padding: 4px 0; font-size: 14px; color: #166534;">&#10003; Soccer ball (if you have one)</td></tr>
                                        <tr><td style="padding: 4px 0; font-size: 14px; color: #166534;">&#10003; Positive attitude!</td></tr>
                                    </table>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
                <?php endif; ?>
                
                <?php if ($emergency_name || $emergency_phone): ?>
                <!-- Emergency Contact -->
                <tr>
                    <td style="padding-bottom: 24px;">
                        <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="background: #FEF2F2; border: 1px solid #FECACA; border-radius: 12px; overflow: hidden;">
                            <tr>
                                <td style="padding: 16px 24px;">
                                    <h3 style="margin: 0 0 8px; font-size: 14px; font-weight: 700; color: #991B1B; text-transform: uppercase; letter-spacing: 0.5px;">Emergency Contact</h3>
                                    <p style="margin: 0; font-size: 14px; color: #374151;">
                                        <?php if ($emergency_name): ?>
                                        <strong><?php echo esc_html($emergency_name); ?></strong>
                                        <?php if ($emergency_relationship): ?> (<?php echo esc_html($emergency_relationship); ?>)<?php endif; ?>
                                        <?php endif; ?>
                                        <?php if ($emergency_phone): ?><br><?php echo esc_html($emergency_phone); ?><?php endif; ?>
                                    </p>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
                <?php endif; ?>
                
                <?php if ($waiver_signed === 'yes'): ?>
                <!-- Waiver Status -->
                <tr>
                    <td>
                        <p style="margin: 0; font-size: 13px; color: #10B981; font-weight: 500;">
                            &#10003; Waiver signed<?php if ($waiver_date): ?> on <?php echo date('M j, Y', strtotime($waiver_date)); ?><?php endif; ?>
                        </p>
                    </td>
                </tr>
                <?php endif; ?>
                
            </table>
            <?php
        }
    }
    
    /**
     * Display event details on order details page (thank you page, my account)
     */
    public function display_event_details($order) {
        // Get camper information
        $campers = $order->get_meta('_ptp_campers');
        
        // Get emergency contact
        $emergency_name = $order->get_meta('_ptp_emergency_name');
        $emergency_phone = $order->get_meta('_ptp_emergency_phone');
        $emergency_relationship = $order->get_meta('_ptp_emergency_relationship');
        
        // Get medical info
        $medical_info = $order->get_meta('_ptp_medical_info');
        
        // Get waiver info
        $waiver_signed = $order->get_meta('_ptp_waiver_signed');
        $waiver_date = $order->get_meta('_ptp_waiver_date');
        
        // Get events from items
        $events = array();
        foreach ($order->get_items() as $item) {
            $event_date = $item->get_meta('Event Date');
            $camp_data = $this->get_camp_data_from_product($item->get_product_id());
            
            if ($event_date || $camp_data) {
                $events[] = array(
                    'name' => $item->get_name(),
                    'date' => $event_date ?: ($camp_data ? $camp_data['date'] : ''),
                    'time' => $item->get_meta('Event Time') ?: ($camp_data ? $camp_data['time'] : ''),
                    'location' => $item->get_meta('Event Location') ?: ($camp_data ? $camp_data['location'] : ''),
                    'address' => $item->get_meta('Event Address') ?: ($camp_data ? $camp_data['address'] : ''),
                    'player' => $item->get_meta('Player Name'),
                    'trainer' => $item->get_meta('Trainer')
                );
            }
        }
        
        // Check if we have any data to display
        $has_data = !empty($campers) || !empty($events) || !empty($emergency_name);
        if (!$has_data) return;
        
        ?>
        <section class="ptp-order-details" style="margin: 30px 0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;">
            <h2 style="font-size: 20px; font-weight: bold; margin: 0 0 20px; padding-bottom: 12px; border-bottom: 3px solid #FCB900; color: #0A0A0A;">
                 Camp & Training Details
            </h2>
            
            <?php if (!empty($campers) && is_array($campers)): ?>
            <div style="margin-bottom: 20px; border: 1px solid #FCD34D; border-radius: 8px; overflow: hidden;">
                <div style="padding: 12px 16px; background: #FCB900; font-weight: bold; color: #0A0A0A;">
                     Registered Players
                </div>
                <?php $num = 1; foreach ($campers as $camper): 
                    $name = trim(($camper['first_name'] ?? '') . ' ' . ($camper['last_name'] ?? ''));
                ?>
                <div style="padding: 12px 16px; background: #FFFBEB; border-bottom: 1px solid #FCD34D;">
                    <strong>Player <?php echo $num; ?>:</strong> <?php echo esc_html($name); ?>
                    <?php if (!empty($camper['age'])): ?>
                        <span style="color: #666;"> (<?php echo intval($camper['age']); ?> years old)</span>
                    <?php endif; ?>
                    <?php if (!empty($camper['skill_level'])): ?>
                        <br><span style="color: #666; font-size: 14px;">Skill Level: <?php echo esc_html(ucfirst($camper['skill_level'])); ?></span>
                    <?php endif; ?>
                </div>
                <?php $num++; endforeach; ?>
            </div>
            <?php endif; ?>
            
            <?php if (!empty($events)): ?>
            <div style="margin-bottom: 20px; border: 1px solid #e5e5e5; border-radius: 8px; overflow: hidden;">
                <div style="padding: 12px 16px; background: #0A0A0A; font-weight: bold; color: #FCB900;">
                     Event Schedule
                </div>
                <?php foreach ($events as $event): ?>
                <div style="padding: 16px; background: #f9f9f9; border-bottom: 1px solid #e5e5e5;">
                    <strong style="font-size: 16px; color: #0A0A0A;"><?php echo esc_html($event['name']); ?></strong>
                    <div style="margin-top: 10px; display: grid; gap: 6px;">
                        <div> <strong>Date:</strong> <?php echo esc_html($event['date']); ?></div>
                        <?php if ($event['time']): ?>
                        <div> <strong>Time:</strong> <?php echo esc_html($event['time']); ?></div>
                        <?php endif; ?>
                        <?php if ($event['location']): ?>
                        <div> <strong>Location:</strong> <?php echo esc_html($event['location']); ?></div>
                        <?php endif; ?>
                        <?php if ($event['address']): ?>
                        <div style="margin-left: 24px; color: #666;"><?php echo esc_html($event['address']); ?></div>
                        <?php endif; ?>
                        <?php if ($event['player']): ?>
                        <div> <strong>Player:</strong> <?php echo esc_html($event['player']); ?></div>
                        <?php endif; ?>
                        <?php if ($event['trainer']): ?>
                        <div>🏃 <strong>Trainer:</strong> <?php echo esc_html($event['trainer']); ?></div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            
            <?php if ($emergency_name || $emergency_phone): ?>
            <div style="margin-bottom: 20px; border: 1px solid #ffc107; border-radius: 8px; overflow: hidden;">
                <div style="padding: 12px 16px; background: #ffc107; font-weight: bold; color: #0A0A0A;">
                     Emergency Contact
                </div>
                <div style="padding: 16px; background: #fff3cd;">
                    <?php if ($emergency_name): ?>
                        <strong><?php echo esc_html($emergency_name); ?></strong>
                        <?php if ($emergency_relationship): ?>
                            <span style="color: #666;"> (<?php echo esc_html($emergency_relationship); ?>)</span>
                        <?php endif; ?>
                        <br>
                    <?php endif; ?>
                    <?php if ($emergency_phone): ?>
                        📞 <a href="tel:<?php echo esc_attr($emergency_phone); ?>" style="color: #0A0A0A; font-weight: 600;"><?php echo esc_html($emergency_phone); ?></a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <?php if (!empty($medical_info)): ?>
            <div style="margin-bottom: 20px; border: 1px solid #dc3545; border-radius: 8px; overflow: hidden;">
                <div style="padding: 12px 16px; background: #dc3545; font-weight: bold; color: #fff;">
                    ⚕️ Medical Information / Allergies
                </div>
                <div style="padding: 16px; background: #f8d7da;">
                    <?php echo nl2br(esc_html($medical_info)); ?>
                </div>
            </div>
            <?php endif; ?>
            
            <?php if ($waiver_signed === 'yes'): ?>
            <div style="margin-bottom: 20px; border: 1px solid #28a745; border-radius: 8px; overflow: hidden;">
                <div style="padding: 12px 16px; background: #28a745; font-weight: bold; color: #fff;">
                    ✅ Waiver & Agreements
                </div>
                <div style="padding: 16px; background: #d4edda;">
                    <strong style="color: #155724;">Liability Waiver Signed</strong>
                    <?php if ($waiver_date): ?>
                        <br><span style="color: #666; font-size: 14px;">Date: <?php echo esc_html(date('F j, Y \a\t g:i A', strtotime($waiver_date))); ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
            
        </section>
        <?php
    }
    
    /**
     * Admin order meta display
     */
    public function admin_order_meta($order) {
        $events = array();
        
        // Display photo consent status
        $photo_consent = $order->get_meta('_photo_consent');
        if ($photo_consent !== '') {
            $consent_text = $photo_consent === 'yes' ? '✅ Yes' : '❌ No';
            $consent_color = $photo_consent === 'yes' ? '#22c55e' : '#ef4444';
            echo '<p style="margin-bottom:10px;"><strong>📸 Photo/Media Consent:</strong> <span style="color:' . $consent_color . ';font-weight:600;">' . $consent_text . '</span></p>';
        }
        
        foreach ($order->get_items() as $item) {
            $player = $item->get_meta('Player Name');
            $trainer_id = $item->get_meta('_trainer_id');
            $booking_id = $item->get_meta('_booking_id');
            
            if ($player || $trainer_id || $booking_id) {
                echo '<p><strong>PTP Details:</strong></p>';
                
                if ($player) {
                    echo '<p>Player: ' . esc_html($player) . '</p>';
                }
                
                if ($trainer_id) {
                    $trainer = $this->get_trainer_data($trainer_id);
                    if ($trainer) {
                        echo '<p>Trainer: ' . esc_html($trainer['name']) . '</p>';
                    }
                }
                
                if ($booking_id) {
                    echo '<p>Booking ID: #' . esc_html($booking_id) . '</p>';
                }
                
                break;
            }
        }
    }
    
    /**
     * Add admin meta boxes
     */
    public function add_order_meta_boxes() {
        // Classic post type orders
        add_meta_box(
            'ptp_order_details',
            ' PTP Camp & Training Details',
            array($this, 'render_order_meta_box'),
            'shop_order',
            'normal',
            'high'
        );
        
        // HPOS compatibility (WooCommerce 8.0+)
        add_meta_box(
            'ptp_order_details',
            ' PTP Camp & Training Details',
            array($this, 'render_order_meta_box'),
            'woocommerce_page_wc-orders',
            'normal',
            'high'
        );
    }
    
    /**
     * Render order meta box
     */
    public function render_order_meta_box($post_or_order) {
        $order = $post_or_order instanceof WP_Post ? wc_get_order($post_or_order->ID) : $post_or_order;
        if (!$order) return;
        
        $has_ptp_data = false;
        
        // ========================================
        // NEW CHECKOUT FLOW DATA (v113+)
        // ========================================
        
        // Player/Camper Name
        $player_name = $order->get_meta('_player_name');
        if ($player_name) {
            $has_ptp_data = true;
            echo '<div style="margin-bottom: 15px;">';
            echo '<h4 style="margin: 0 0 10px; color: #0A0A0A; font-size: 14px; text-transform: uppercase;">⚽ Player Information</h4>';
            echo '<div style="padding: 12px; background: #f9f9f9; border-left: 3px solid #FCB900;">';
            echo '<strong>' . esc_html($player_name) . '</strong>';
            
            // Get shirt size from line items
            foreach ($order->get_items() as $item) {
                $shirt = $item->get_meta('T-Shirt Size');
                $age = $item->get_meta('Player Age');
                if ($shirt) echo '<br>👕 T-Shirt: ' . esc_html($shirt);
                if ($age) echo '<br>🎂 Age: ' . esc_html($age);
                break;
            }
            echo '</div></div>';
        }
        
        // Photo Consent (NEW)
        $photo_consent = $order->get_meta('_photo_consent');
        if ($photo_consent !== '') {
            $has_ptp_data = true;
            $consent_yes = ($photo_consent === 'yes');
            echo '<div style="margin-bottom: 15px;">';
            echo '<h4 style="margin: 0 0 10px; color: #0A0A0A; font-size: 14px; text-transform: uppercase;">📸 Photo/Media Consent</h4>';
            echo '<div style="padding: 12px; background: ' . ($consent_yes ? '#d4edda' : '#f8d7da') . '; border-left: 3px solid ' . ($consent_yes ? '#28a745' : '#dc3545') . ';">';
            echo '<strong style="color: ' . ($consent_yes ? '#155724' : '#721c24') . ';">' . ($consent_yes ? '✅ CONSENT GIVEN' : '❌ NO CONSENT') . '</strong>';
            echo '<br><span style="font-size:11px;color:#666;">Parent ' . ($consent_yes ? 'agreed' : 'did NOT agree') . ' to photos/videos for promotional use</span>';
            echo '</div></div>';
        }
        
        // Emergency Contact (new format)
        $emergency_contact = $order->get_meta('_emergency_contact');
        if ($emergency_contact) {
            $has_ptp_data = true;
            echo '<div style="margin-bottom: 15px;">';
            echo '<h4 style="margin: 0 0 10px; color: #0A0A0A; font-size: 14px; text-transform: uppercase;">🚨 Emergency Contact</h4>';
            echo '<div style="padding: 12px; background: #fff3cd; border-left: 3px solid #ffc107;">';
            echo '<strong>' . esc_html($emergency_contact) . '</strong>';
            echo '</div></div>';
        }
        
        // Medical Info (new format)
        $medical_info = $order->get_meta('_medical_info');
        if ($medical_info) {
            $has_ptp_data = true;
            echo '<div style="margin-bottom: 15px;">';
            echo '<h4 style="margin: 0 0 10px; color: #0A0A0A; font-size: 14px; text-transform: uppercase;">⚕️ Medical/Allergies</h4>';
            echo '<div style="padding: 12px; background: #f8d7da; border-left: 3px solid #dc3545;">';
            echo '<p style="margin: 0; white-space: pre-wrap;">' . esc_html($medical_info) . '</p>';
            echo '</div></div>';
        }
        
        // Waiver (new format)
        $waiver_accepted = $order->get_meta('_waiver_accepted');
        $waiver_at = $order->get_meta('_waiver_accepted_at');
        if ($waiver_accepted === 'yes') {
            $has_ptp_data = true;
            echo '<div style="margin-bottom: 15px;">';
            echo '<h4 style="margin: 0 0 10px; color: #0A0A0A; font-size: 14px; text-transform: uppercase;">📝 Waiver</h4>';
            echo '<div style="padding: 12px; background: #d4edda; border-left: 3px solid #28a745;">';
            echo '<strong style="color: #155724;">✅ Waiver Accepted</strong>';
            if ($waiver_at) {
                echo '<br><span style="color: #666; font-size: 12px;">Date: ' . esc_html(date('M j, Y g:i A', strtotime($waiver_at))) . '</span>';
            }
            echo '</div></div>';
        }
        
        // Bundle/Upgrade Info
        $bundle_code = $order->get_meta('_bundle_code');
        $bundle_discount = $order->get_meta('_bundle_discount');
        $upgrade_pack = $order->get_meta('_upgrade_pack');
        $upgrade_camps = $order->get_meta('_upgrade_camp_names');
        
        if ($bundle_code || $upgrade_pack) {
            $has_ptp_data = true;
            echo '<div style="margin-bottom: 15px;">';
            echo '<h4 style="margin: 0 0 10px; color: #0A0A0A; font-size: 14px; text-transform: uppercase;">🎁 Bundles & Upgrades</h4>';
            echo '<div style="padding: 12px; background: #e7f3ff; border-left: 3px solid #0073aa;">';
            if ($bundle_code) {
                echo 'Bundle Code: <strong>' . esc_html($bundle_code) . '</strong>';
                if ($bundle_discount) echo ' (Saved $' . number_format($bundle_discount, 2) . ')';
                echo '<br>';
            }
            if ($upgrade_pack) {
                $labels = array('2pack' => '2-Camp Pack', '3pack' => '3-Camp Pack', 'allaccess' => 'All-Access Pass');
                echo 'Upgrade: <strong>' . esc_html($labels[$upgrade_pack] ?? $upgrade_pack) . '</strong><br>';
            }
            if ($upgrade_camps) {
                echo 'Additional Camps: ' . esc_html($upgrade_camps);
            }
            echo '</div></div>';
        }
        
        // Before/After Care
        $care = $order->get_meta('_before_after_care');
        $care_amount = $order->get_meta('_before_after_care_amount');
        if ($care === 'yes') {
            $has_ptp_data = true;
            echo '<div style="margin-bottom: 15px;">';
            echo '<h4 style="margin: 0 0 10px; color: #0A0A0A; font-size: 14px; text-transform: uppercase;">🕐 Before & After Care</h4>';
            echo '<div style="padding: 12px; background: #f0f0f0; border-left: 3px solid #666;">';
            echo '<strong>✅ Enrolled</strong>';
            if ($care_amount) echo ' ($' . number_format($care_amount, 2) . ')';
            echo '</div></div>';
        }
        
        // ========================================
        // LEGACY CHECKOUT FLOW DATA
        // ========================================
        
        // Display Camper Information (legacy)
        $campers = $order->get_meta('_ptp_campers');
        if (!empty($campers) && is_array($campers)) {
            $has_ptp_data = true;
            echo '<div style="margin-bottom: 20px;">';
            echo '<h4 style="margin: 0 0 10px; color: #0A0A0A; font-size: 14px; text-transform: uppercase;"> Camper/Player Information</h4>';
            
            $camper_num = 1;
            foreach ($campers as $camper) {
                echo '<div style="margin-bottom: 12px; padding: 12px; background: #f9f9f9; border-left: 3px solid #FCB900;">';
                echo '<strong>Camper ' . $camper_num . ':</strong> ';
                echo esc_html(($camper['first_name'] ?? '') . ' ' . ($camper['last_name'] ?? ''));
                
                if (!empty($camper['age'])) {
                    echo ' <span style="color: #666;">(' . intval($camper['age']) . ' years old)</span>';
                }
                
                if (!empty($camper['skill_level'])) {
                    echo '<br><span style="color: #666; font-size: 12px;">Skill Level: ' . esc_html(ucfirst($camper['skill_level'])) . '</span>';
                }
                
                echo '</div>';
                $camper_num++;
            }
            echo '</div>';
        }
        
        // Display Emergency Contact (legacy)
        $emergency_name = $order->get_meta('_ptp_emergency_name');
        $emergency_phone = $order->get_meta('_ptp_emergency_phone');
        $emergency_relationship = $order->get_meta('_ptp_emergency_relationship');
        
        if ($emergency_name || $emergency_phone) {
            $has_ptp_data = true;
            echo '<div style="margin-bottom: 20px;">';
            echo '<h4 style="margin: 0 0 10px; color: #0A0A0A; font-size: 14px; text-transform: uppercase;"> Emergency Contact</h4>';
            echo '<div style="padding: 12px; background: #fff3cd; border-left: 3px solid #ffc107;">';
            
            if ($emergency_name) {
                echo '<strong>' . esc_html($emergency_name) . '</strong>';
                if ($emergency_relationship) {
                    echo ' <span style="color: #666;">(' . esc_html($emergency_relationship) . ')</span>';
                }
                echo '<br>';
            }
            
            if ($emergency_phone) {
                echo '📞 <a href="tel:' . esc_attr($emergency_phone) . '">' . esc_html($emergency_phone) . '</a>';
            }
            
            echo '</div></div>';
        }
        
        // Display Medical Info (legacy)
        $medical_info_legacy = $order->get_meta('_ptp_medical_info');
        if (!empty($medical_info_legacy) && empty($medical_info)) {
            $has_ptp_data = true;
            echo '<div style="margin-bottom: 20px;">';
            echo '<h4 style="margin: 0 0 10px; color: #0A0A0A; font-size: 14px; text-transform: uppercase;">⚕️ Medical Information</h4>';
            echo '<div style="padding: 12px; background: #f8d7da; border-left: 3px solid #dc3545;">';
            echo '<p style="margin: 0; white-space: pre-wrap;">' . esc_html($medical_info_legacy) . '</p>';
            echo '</div></div>';
        }
        
        // Display Waiver Status (legacy)
        $waiver_signed = $order->get_meta('_ptp_waiver_signed');
        $waiver_signature = $order->get_meta('_ptp_waiver_signature');
        $waiver_date = $order->get_meta('_ptp_waiver_date');
        
        if ($waiver_signed === 'yes' && $waiver_accepted !== 'yes') {
            $has_ptp_data = true;
            echo '<div style="margin-bottom: 20px;">';
            echo '<h4 style="margin: 0 0 10px; color: #0A0A0A; font-size: 14px; text-transform: uppercase;">✅ Waiver & Agreements</h4>';
            echo '<div style="padding: 12px; background: #d4edda; border-left: 3px solid #28a745;">';
            echo '<strong style="color: #155724;">Waiver Signed</strong>';
            
            if ($waiver_signature) {
                echo '<br>Signature: <em>' . esc_html($waiver_signature) . '</em>';
            }
            
            if ($waiver_date) {
                echo '<br><span style="color: #666; font-size: 12px;">Date: ' . esc_html(date('M j, Y g:i A', strtotime($waiver_date))) . '</span>';
            }
            
            echo '</div></div>';
        }
        
        // Display per-item data (original functionality)
        $has_item_data = false;
        foreach ($order->get_items() as $item) {
            $player = $item->get_meta('Player Name');
            $event_date = $item->get_meta('Event Date');
            $trainer = $item->get_meta('Trainer');
            
            if ($player || $event_date || $trainer) {
                if (!$has_item_data) {
                    echo '<div style="margin-bottom: 20px;">';
                    echo '<h4 style="margin: 0 0 10px; color: #0A0A0A; font-size: 14px; text-transform: uppercase;">📦 Item Details</h4>';
                    $has_item_data = true;
                    $has_ptp_data = true;
                }
                
                echo '<div style="margin-bottom: 10px; padding: 10px; background: #f1f1f1; border-left: 3px solid #0A0A0A;">';
                echo '<strong>' . esc_html($item->get_name()) . '</strong><br>';
                
                if ($player) {
                    echo ' ' . esc_html($player) . '<br>';
                }
                
                if ($event_date) {
                    echo ' ' . esc_html($event_date) . '<br>';
                }
                
                if ($trainer) {
                    echo '🏃 ' . esc_html($trainer) . '<br>';
                }
                
                echo '</div>';
            }
        }
        
        if ($has_item_data) {
            echo '</div>';
        }
        
        // Display Add-Ons (v102)
        $before_after_care = $order->get_meta('_before_after_care');
        $care_amount = $order->get_meta('_before_after_care_amount');
        $upgrade_pack = $order->get_meta('_upgrade_pack');
        $upgrade_amount = $order->get_meta('_upgrade_amount');
        $upgrade_camp_names = $order->get_meta('_upgrade_camp_names');
        
        if ($before_after_care === 'yes' || $upgrade_pack) {
            $has_ptp_data = true;
            echo '<div style="margin-bottom: 20px;">';
            echo '<h4 style="margin: 0 0 10px; color: #0A0A0A; font-size: 14px; text-transform: uppercase;">🎯 Add-Ons</h4>';
            
            if ($before_after_care === 'yes') {
                echo '<div style="margin-bottom: 10px; padding: 12px; background: #dbeafe; border-left: 3px solid #2563eb;">';
                echo '<strong style="color: #1e40af;">✓ Before & After Care</strong>';
                if ($care_amount > 0) {
                    echo ' <span style="color: #666;">($' . number_format($care_amount, 2) . ')</span>';
                }
                echo '<br><span style="color: #3b82f6; font-size: 12px;">Drop-off: 8:00 AM · Pick-up: 5:00 PM</span>';
                echo '</div>';
            }
            
            if ($upgrade_pack) {
                $upgrade_labels = array(
                    '2pack' => '2-Camp Pack (+1 Camp)',
                    '3pack' => '3-Camp Pack (+2 Camps)',
                    'allaccess' => 'All-Access Pass',
                );
                $upgrade_label = $upgrade_labels[$upgrade_pack] ?? $upgrade_pack;
                
                echo '<div style="padding: 12px; background: #fef3c7; border-left: 3px solid #f59e0b;">';
                echo '<strong style="color: #92400e;">✓ ' . esc_html($upgrade_label) . '</strong>';
                if ($upgrade_amount > 0) {
                    echo ' <span style="color: #666;">($' . number_format($upgrade_amount, 2) . ')</span>';
                }
                if ($upgrade_camp_names) {
                    echo '<br><span style="color: #b45309; font-size: 12px;">Additional camps: ' . esc_html($upgrade_camp_names) . '</span>';
                }
                echo '</div>';
            }
            
            echo '</div>';
        }
        
        if (!$has_ptp_data) {
            echo '<p style="color: #999; font-style: italic;">No PTP event data for this order</p>';
        }
    }
    
    /**
     * Display cart item data
     */
    public function display_cart_item_data($item_data, $cart_item) {
        $fields = array(
            'player_name' => 'Player',
            'player_age' => 'Age',
            'event_date' => 'Date',
            'event_time' => 'Time',
            'event_location' => 'Location'
        );
        
        foreach ($fields as $key => $label) {
            if (!empty($cart_item[$key])) {
                $item_data[] = array(
                    'key' => $label,
                    'value' => sanitize_text_field($cart_item[$key])
                );
            }
        }
        
        return $item_data;
    }
    
    /**
     * Save cart item meta to order
     */
    public function save_cart_item_meta($item, $cart_item_key, $values, $order) {
        // This is handled by save_order_item_meta
    }
    
    /**
     * Register REST API routes
     */
    public function register_rest_routes() {
        register_rest_route('ptp/v1', '/orders/(?P<id>\d+)', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_order_details'),
            'permission_callback' => function() {
                return current_user_can('read');
            }
        ));
    }
    
    /**
     * Get order details via REST
     */
    public function get_order_details($request) {
        $order_id = $request['id'];
        $order = wc_get_order($order_id);
        
        if (!$order) {
            return new WP_Error('not_found', 'Order not found', array('status' => 404));
        }
        
        // Check permission
        if ($order->get_user_id() !== get_current_user_id() && !current_user_can('manage_options')) {
            return new WP_Error('forbidden', 'Access denied', array('status' => 403));
        }
        
        $items = array();
        foreach ($order->get_items() as $item) {
            $items[] = array(
                'name' => $item->get_name(),
                'quantity' => $item->get_quantity(),
                'total' => $item->get_total(),
                'player_name' => $item->get_meta('Player Name'),
                'event_date' => $item->get_meta('Event Date'),
                'event_time' => $item->get_meta('Event Time'),
                'event_location' => $item->get_meta('Event Location'),
                'trainer' => $item->get_meta('Trainer')
            );
        }
        
        return array(
            'id' => $order->get_id(),
            'status' => $order->get_status(),
            'total' => $order->get_total(),
            'date_created' => $order->get_date_created()->format('c'),
            'billing' => array(
                'first_name' => $order->get_billing_first_name(),
                'last_name' => $order->get_billing_last_name(),
                'email' => $order->get_billing_email(),
                'phone' => $order->get_billing_phone()
            ),
            'items' => $items
        );
    }
}

// Initialize
PTP_WooCommerce_Orders_V71::instance();
