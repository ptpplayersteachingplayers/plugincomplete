<?php
/**
 * PTP Camps Bridge
 * 
 * Integrates PTP Training Platform with the separate PTP Camps plugin.
 * Add this file to: /ptp-training-platform/includes/class-ptp-camps-bridge.php
 * Then require it in ptp-training-platform.php after other includes.
 * 
 * @version 1.0.0
 * @since PTP Training Platform v146
 */

defined('ABSPATH') || exit;

class PTP_Camps_Bridge {
    
    private static $instance = null;
    private static $camps_active = null;
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        add_action('plugins_loaded', array($this, 'init'), 15);
    }
    
    /**
     * Check if PTP Camps plugin is active
     */
    public static function is_camps_active() {
        if (self::$camps_active === null) {
            self::$camps_active = class_exists('PTP_Camps') || 
                                  defined('PTP_CAMPS_VERSION') ||
                                  function_exists('ptp_camps');
        }
        return self::$camps_active;
    }
    
    /**
     * Initialize integration
     */
    public function init() {
        if (!self::is_camps_active()) {
            return;
        }
        
        error_log('[PTP Training Platform] PTP Camps plugin detected - enabling integration');
        
        // Share Stripe settings with PTP Camps
        add_filter('ptp_camps_stripe_secret_key', array($this, 'share_stripe_secret_key'));
        add_filter('ptp_camps_stripe_publishable_key', array($this, 'share_stripe_publishable_key'));
        add_filter('ptp_camps_stripe_webhook_secret', array($this, 'share_stripe_webhook_secret'));
        
        // Add camps section to parent dashboard
        add_action('ptp_parent_dashboard_sections', array($this, 'add_camps_dashboard_section'), 25);
        
        // Add camps to unified analytics
        add_filter('ptp_analytics_total_revenue', array($this, 'add_camps_revenue'));
        add_filter('ptp_analytics_total_orders', array($this, 'add_camps_orders'));
        add_action('ptp_admin_analytics_cards', array($this, 'add_camps_analytics_card'), 15);
        
        // Merge camp referral system with training referrals
        add_filter('ptp_referral_sources', array($this, 'add_camps_referral_source'));
        
        // Add camp orders to user history
        add_filter('ptp_user_order_history', array($this, 'add_camps_order_history'), 10, 2);
        
        // Share email sending infrastructure
        add_filter('ptp_camps_email_from', array($this, 'share_email_from'));
        add_filter('ptp_camps_email_from_name', array($this, 'share_email_from_name'));
    }
    
    /**
     * Share Stripe secret key
     */
    public function share_stripe_secret_key($key) {
        if (!empty($key)) {
            return $key;
        }
        
        $settings = get_option('ptp_settings', array());
        $mode = $settings['stripe_mode'] ?? 'test';
        
        if ($mode === 'live') {
            return $settings['stripe_live_secret_key'] ?? '';
        }
        return $settings['stripe_test_secret_key'] ?? '';
    }
    
    /**
     * Share Stripe publishable key
     */
    public function share_stripe_publishable_key($key) {
        if (!empty($key)) {
            return $key;
        }
        
        $settings = get_option('ptp_settings', array());
        $mode = $settings['stripe_mode'] ?? 'test';
        
        if ($mode === 'live') {
            return $settings['stripe_live_publishable_key'] ?? '';
        }
        return $settings['stripe_test_publishable_key'] ?? '';
    }
    
    /**
     * Share Stripe webhook secret
     */
    public function share_stripe_webhook_secret($secret) {
        if (!empty($secret)) {
            return $secret;
        }
        
        $settings = get_option('ptp_settings', array());
        return $settings['stripe_webhook_secret'] ?? '';
    }
    
    /**
     * Add camps section to parent dashboard
     */
    public function add_camps_dashboard_section() {
        if (!is_user_logged_in() || !class_exists('PTP_Camps_Orders')) {
            return;
        }
        
        $user = wp_get_current_user();
        $orders = PTP_Camps_Orders::get_user_orders($user->ID, $user->user_email, 5);
        
        if (empty($orders)) {
            return;
        }
        ?>
        <div class="ptp-dashboard-section ptp-camps-section" style="margin-top: 30px;">
            <h3 style="margin-bottom: 15px; font-size: 18px;">⚽ Camp Registrations</h3>
            
            <div class="ptp-camps-orders">
                <?php foreach ($orders as $order): ?>
                    <?php $full_order = PTP_Camps_Orders::get_order($order->id); ?>
                    <div class="ptp-camp-order" style="background: #f8f9fa; padding: 15px; border-radius: 8px; margin-bottom: 12px; border-left: 4px solid #FCB900;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                            <span style="font-weight: 600;">#<?php echo esc_html($order->order_number); ?></span>
                            <span class="ptp-status" style="background: <?php echo $order->status === 'completed' ? '#D1FAE5' : '#FEF3C7'; ?>; color: <?php echo $order->status === 'completed' ? '#065F46' : '#92400E'; ?>; padding: 4px 10px; border-radius: 4px; font-size: 12px; font-weight: 600; text-transform: uppercase;">
                                <?php echo ucfirst($order->status); ?>
                            </span>
                        </div>
                        
                        <?php if ($full_order && !empty($full_order->items)): ?>
                            <?php foreach ($full_order->items as $item): ?>
                                <div style="margin: 8px 0; padding-left: 12px; border-left: 2px solid #e5e7eb;">
                                    <strong><?php echo esc_html($item->camper_first_name . ' ' . $item->camper_last_name); ?></strong>
                                    <br>
                                    <span style="color: #666; font-size: 14px;">
                                        <?php echo esc_html($item->camp_name); ?>
                                        <?php if ($item->camp_dates): ?>
                                            • <?php echo esc_html($item->camp_dates); ?>
                                        <?php endif; ?>
                                    </span>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
            
            <p style="margin-top: 15px;">
                <a href="<?php echo esc_url(home_url('/camps/')); ?>" style="color: #FCB900; text-decoration: none; font-weight: 500;">
                    Browse All Camps →
                </a>
            </p>
        </div>
        <?php
    }
    
    /**
     * Add camps revenue to total analytics
     */
    public function add_camps_revenue($revenue) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'ptp_camp_orders';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
            return $revenue;
        }
        
        $camps_revenue = $wpdb->get_var("
            SELECT COALESCE(SUM(total_amount - COALESCE(refund_amount, 0)), 0)
            FROM {$table}
            WHERE status = 'completed'
        ");
        
        return $revenue + floatval($camps_revenue);
    }
    
    /**
     * Add camps orders to total count
     */
    public function add_camps_orders($count) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'ptp_camp_orders';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
            return $count;
        }
        
        $camps_orders = $wpdb->get_var("
            SELECT COUNT(*)
            FROM {$table}
            WHERE status = 'completed'
        ");
        
        return $count + intval($camps_orders);
    }
    
    /**
     * Add camps analytics card to admin dashboard
     */
    public function add_camps_analytics_card() {
        global $wpdb;
        
        $table = $wpdb->prefix . 'ptp_camp_orders';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
            return;
        }
        
        $month_start = date('Y-m-01');
        
        $stats = $wpdb->get_row($wpdb->prepare("
            SELECT 
                COUNT(*) as orders,
                COALESCE(SUM(total_amount), 0) as revenue
            FROM {$table}
            WHERE status = 'completed' AND created_at >= %s
        ", $month_start));
        
        $campers = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*)
            FROM {$wpdb->prefix}ptp_camp_order_items oi
            INNER JOIN {$table} o ON oi.order_id = o.id
            WHERE o.status = 'completed' AND o.created_at >= %s
        ", $month_start));
        ?>
        <div class="ptp-analytics-card" style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
            <h4 style="margin: 0 0 10px; color: #666; font-size: 14px;">⚽ Camps (This Month)</h4>
            <div style="font-size: 28px; font-weight: 700; color: #0A0A0A;">$<?php echo number_format($stats->revenue, 0); ?></div>
            <div style="font-size: 14px; color: #666; margin-top: 5px;">
                <?php echo intval($stats->orders); ?> orders • <?php echo intval($campers); ?> campers
            </div>
        </div>
        <?php
    }
    
    /**
     * Add camps as referral source
     */
    public function add_camps_referral_source($sources) {
        $sources['camps'] = 'Camp Registration';
        return $sources;
    }
    
    /**
     * Add camps orders to user order history
     */
    public function add_camps_order_history($orders, $user_id) {
        if (!class_exists('PTP_Camps_Orders')) {
            return $orders;
        }
        
        $user = get_user_by('id', $user_id);
        if (!$user) {
            return $orders;
        }
        
        $camp_orders = PTP_Camps_Orders::get_user_orders($user_id, $user->user_email);
        
        foreach ($camp_orders as $order) {
            $orders[] = array(
                'id' => 'camp_' . $order->id,
                'type' => 'camp',
                'order_number' => $order->order_number,
                'total' => $order->total_amount,
                'status' => $order->status,
                'date' => $order->created_at,
                'items' => $order->item_count . ' camper(s)',
            );
        }
        
        // Sort by date
        usort($orders, function($a, $b) {
            return strtotime($b['date']) - strtotime($a['date']);
        });
        
        return $orders;
    }
    
    /**
     * Share email from address
     */
    public function share_email_from($from) {
        if (!empty($from)) {
            return $from;
        }
        
        $settings = get_option('ptp_settings', array());
        return $settings['email_from'] ?? 'camps@ptpsummercamps.com';
    }
    
    /**
     * Share email from name
     */
    public function share_email_from_name($name) {
        if (!empty($name)) {
            return $name;
        }
        
        $settings = get_option('ptp_settings', array());
        return $settings['email_from_name'] ?? 'PTP Soccer Camps';
    }
    
    /**
     * Get combined stats for dashboard widget
     */
    public static function get_combined_stats() {
        global $wpdb;
        
        $stats = array(
            'camps_orders' => 0,
            'camps_revenue' => 0,
            'camps_campers' => 0,
        );
        
        $table = $wpdb->prefix . 'ptp_camp_orders';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
            return $stats;
        }
        
        $data = $wpdb->get_row("
            SELECT 
                COUNT(*) as orders,
                COALESCE(SUM(total_amount), 0) as revenue
            FROM {$table}
            WHERE status = 'completed'
        ");
        
        $stats['camps_orders'] = intval($data->orders);
        $stats['camps_revenue'] = floatval($data->revenue);
        
        $stats['camps_campers'] = intval($wpdb->get_var("
            SELECT COUNT(*)
            FROM {$wpdb->prefix}ptp_camp_order_items oi
            INNER JOIN {$table} o ON oi.order_id = o.id
            WHERE o.status = 'completed'
        "));
        
        return $stats;
    }
}

// Initialize
PTP_Camps_Bridge::instance();
