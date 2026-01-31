<?php
/**
 * PTP WooCommerce Integration
 * Connects camps/clinics from WooCommerce to trainers for commission payouts
 */

defined('ABSPATH') || exit;

class PTP_WooCommerce {
    
    public static function init() {
        if (!class_exists('WooCommerce')) {
            return;
        }
        
        // Add trainer assignment to products
        add_action('woocommerce_product_options_general_product_data', array(__CLASS__, 'add_trainer_field'));
        add_action('woocommerce_process_product_meta', array(__CLASS__, 'save_trainer_field'));
        
        // Track orders for trainer payouts
        add_action('woocommerce_order_status_completed', array(__CLASS__, 'process_completed_order'));
        add_action('woocommerce_order_status_processing', array(__CLASS__, 'process_completed_order'));
        
        // Add camp/clinic info to order emails
        add_action('woocommerce_email_after_order_table', array(__CLASS__, 'add_camp_info_to_email'), 10, 4);
        
        // Admin columns for camp orders
        add_filter('manage_edit-shop_order_columns', array(__CLASS__, 'add_order_columns'));
        add_action('manage_shop_order_posts_custom_column', array(__CLASS__, 'render_order_columns'), 10, 2);
        
        // Dashboard widget for camp sales
        add_action('wp_dashboard_setup', array(__CLASS__, 'add_dashboard_widget'));
        
        // Shortcode for displaying camps
        add_shortcode('ptp_camps', array(__CLASS__, 'camps_shortcode'));
        add_shortcode('ptp_clinics', array(__CLASS__, 'clinics_shortcode'));
    }
    
    /**
     * Add trainer assignment field to WooCommerce products
     */
    public static function add_trainer_field() {
        global $wpdb;
        
        // Get all active trainers
        $trainers = $wpdb->get_results("
            SELECT id, display_name FROM {$wpdb->prefix}ptp_trainers 
            WHERE status = 'active' 
            ORDER BY display_name ASC
        ");
        
        $options = array('' => 'No trainer (PTP keeps 100%)');
        foreach ($trainers as $trainer) {
            $options[$trainer->id] = $trainer->display_name;
        }
        
        echo '<div class="options_group ptp-woo-fields">';
        
        woocommerce_wp_select(array(
            'id' => '_ptp_trainer_id',
            'label' => 'Assigned Trainer',
            'description' => 'Trainer who will receive payout for this product',
            'desc_tip' => true,
            'options' => $options,
        ));
        
        woocommerce_wp_text_input(array(
            'id' => '_ptp_trainer_payout_percent',
            'label' => 'Trainer Payout %',
            'description' => 'Override payout % for this product. Training bookings use tiered: 50% first session, 75% repeat.',
            'desc_tip' => true,
            'type' => 'number',
            'custom_attributes' => array(
                'min' => '0',
                'max' => '100',
                'step' => '1',
            ),
            'placeholder' => '75',
        ));
        
        woocommerce_wp_select(array(
            'id' => '_ptp_product_type',
            'label' => 'PTP Product Type',
            'options' => array(
                '' => 'Not a PTP product',
                'camp' => 'Summer Camp',
                'clinic' => 'Clinic/Workshop',
                'training_package' => 'Training Package',
                'private_session' => 'Private Session',
            ),
        ));
        
        woocommerce_wp_text_input(array(
            'id' => '_ptp_event_date',
            'label' => 'Event Date',
            'description' => 'Date of the camp/clinic',
            'type' => 'date',
        ));
        
        woocommerce_wp_text_input(array(
            'id' => '_ptp_event_location',
            'label' => 'Event Location',
            'description' => 'Where the camp/clinic takes place',
        ));
        
        woocommerce_wp_text_input(array(
            'id' => '_ptp_age_range',
            'label' => 'Age Range',
            'placeholder' => 'e.g., 8-14',
        ));
        
        woocommerce_wp_text_input(array(
            'id' => '_ptp_skill_level',
            'label' => 'Skill Level',
            'placeholder' => 'e.g., All Levels, Advanced',
        ));
        
        echo '</div>';
    }
    
    /**
     * Save trainer field
     */
    public static function save_trainer_field($post_id) {
        $fields = array(
            '_ptp_trainer_id',
            '_ptp_trainer_payout_percent',
            '_ptp_product_type',
            '_ptp_event_date',
            '_ptp_event_location',
            '_ptp_age_range',
            '_ptp_skill_level',
        );
        
        foreach ($fields as $field) {
            if (isset($_POST[$field])) {
                update_post_meta($post_id, $field, sanitize_text_field($_POST[$field]));
            }
        }
    }
    
    /**
     * Process completed order and queue trainer payouts
     */
    public static function process_completed_order($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) return;
        
        // Check if already processed
        if ($order->get_meta('_ptp_payouts_processed')) {
            return;
        }
        
        global $wpdb;
        
        foreach ($order->get_items() as $item) {
            $product_id = $item->get_product_id();
            $trainer_id = get_post_meta($product_id, '_ptp_trainer_id', true);
            
            if (!$trainer_id) continue;
            
            $payout_percent = get_post_meta($product_id, '_ptp_trainer_payout_percent', true);
            $payout_percent = $payout_percent ? floatval($payout_percent) : 80;
            
            $line_total = $item->get_total();
            $trainer_amount = ($line_total * $payout_percent) / 100;
            
            if ($trainer_amount <= 0) continue;
            
            // Create or get pending payout for trainer
            $payout = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}ptp_payouts WHERE trainer_id = %d AND status = 'pending'",
                $trainer_id
            ));
            
            if (!$payout) {
                $wpdb->insert($wpdb->prefix . 'ptp_payouts', array(
                    'trainer_id' => $trainer_id,
                    'amount' => 0,
                    'status' => 'pending',
                    'payout_method' => 'stripe',
                ));
                $payout_id = $wpdb->insert_id;
            } else {
                $payout_id = $payout->id;
            }
            
            // Record the payout item
            $wpdb->insert($wpdb->prefix . 'ptp_woo_payout_items', array(
                'payout_id' => $payout_id,
                'order_id' => $order_id,
                'order_item_id' => $item->get_id(),
                'product_id' => $product_id,
                'amount' => $trainer_amount,
                'product_name' => $item->get_name(),
            ));
            
            // Update payout total
            $total = $wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(SUM(amount), 0) FROM {$wpdb->prefix}ptp_woo_payout_items WHERE payout_id = %d",
                $payout_id
            ));
            
            // Also add booking payout items if any
            $booking_total = $wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(SUM(amount), 0) FROM {$wpdb->prefix}ptp_payout_items WHERE payout_id = %d",
                $payout_id
            ));
            
            $wpdb->update(
                $wpdb->prefix . 'ptp_payouts',
                array('amount' => $total + $booking_total),
                array('id' => $payout_id)
            );
            
            // Update trainer earnings
            $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->prefix}ptp_trainers SET total_earnings = total_earnings + %f WHERE id = %d",
                $trainer_amount, $trainer_id
            ));
        }
        
        // Mark order as processed for payouts
        $order->update_meta_data('_ptp_payouts_processed', true);
        $order->save();
    }
    
    /**
     * Add camp info to order emails
     */
    public static function add_camp_info_to_email($order, $sent_to_admin, $plain_text, $email) {
        $camp_items = array();
        
        foreach ($order->get_items() as $item) {
            $product_id = $item->get_product_id();
            $product_type = get_post_meta($product_id, '_ptp_product_type', true);
            
            if ($product_type) {
                $camp_items[] = array(
                    'name' => $item->get_name(),
                    'type' => $product_type,
                    'date' => get_post_meta($product_id, '_ptp_event_date', true),
                    'location' => get_post_meta($product_id, '_ptp_event_location', true),
                );
            }
        }
        
        if (empty($camp_items)) return;
        
        if ($plain_text) {
            echo "\n\n=== CAMP/CLINIC DETAILS ===\n";
            foreach ($camp_items as $camp) {
                echo $camp['name'] . "\n";
                if ($camp['date']) echo "Date: " . date('l, F j, Y', strtotime($camp['date'])) . "\n";
                if ($camp['location']) echo "Location: " . $camp['location'] . "\n";
                echo "\n";
            }
        } else {
            echo '<h2 style="color: #0E0F11; font-size: 18px; margin: 30px 0 15px;">Camp/Clinic Details</h2>';
            echo '<table style="width: 100%; border-collapse: collapse;">';
            foreach ($camp_items as $camp) {
                echo '<tr>';
                echo '<td style="padding: 12px; border-bottom: 1px solid #e5e7eb;">';
                echo '<strong>' . esc_html($camp['name']) . '</strong><br>';
                if ($camp['date']) echo '<span style="color: #6b7280;">📅 ' . date('l, F j, Y', strtotime($camp['date'])) . '</span><br>';
                if ($camp['location']) echo '<span style="color: #6b7280;">📍 ' . esc_html($camp['location']) . '</span>';
                echo '</td>';
                echo '</tr>';
            }
            echo '</table>';
        }
    }
    
    /**
     * Add custom columns to orders
     */
    public static function add_order_columns($columns) {
        $new_columns = array();
        foreach ($columns as $key => $value) {
            $new_columns[$key] = $value;
            if ($key === 'order_total') {
                $new_columns['ptp_trainer'] = 'Trainer Payout';
            }
        }
        return $new_columns;
    }
    
    /**
     * Render custom order columns
     */
    public static function render_order_columns($column, $post_id) {
        if ($column === 'ptp_trainer') {
            $order = wc_get_order($post_id);
            if (!$order) return;
            
            global $wpdb;
            $payouts = array();
            
            foreach ($order->get_items() as $item) {
                $product_id = $item->get_product_id();
                $trainer_id = get_post_meta($product_id, '_ptp_trainer_id', true);
                
                if ($trainer_id) {
                    $trainer = $wpdb->get_row($wpdb->prepare(
                        "SELECT display_name FROM {$wpdb->prefix}ptp_trainers WHERE id = %d",
                        $trainer_id
                    ));
                    
                    $payout_percent = get_post_meta($product_id, '_ptp_trainer_payout_percent', true) ?: 80;
                    $amount = ($item->get_total() * $payout_percent) / 100;
                    
                    if ($trainer) {
                        $payouts[] = $trainer->display_name . ': $' . number_format($amount, 2);
                    }
                }
            }
            
            echo $payouts ? implode('<br>', $payouts) : '-';
        }
    }
    
    /**
     * Dashboard widget for camp sales
     */
    public static function add_dashboard_widget() {
        wp_add_dashboard_widget(
            'ptp_camp_sales',
            'PTP Camp & Clinic Sales',
            array(__CLASS__, 'render_dashboard_widget')
        );
    }
    
    public static function render_dashboard_widget() {
        global $wpdb;
        
        // Get this month's camp/clinic sales
        $month_start = date('Y-m-01');
        $month_end = date('Y-m-t');
        
        $stats = $wpdb->get_row("
            SELECT 
                COUNT(DISTINCT oi.order_id) as order_count,
                SUM(oi.meta_value) as total_revenue
            FROM {$wpdb->prefix}woocommerce_order_itemmeta oi
            JOIN {$wpdb->prefix}woocommerce_order_items items ON oi.order_item_id = items.order_item_id
            JOIN {$wpdb->prefix}posts orders ON items.order_id = orders.ID
            WHERE oi.meta_key = '_line_total'
            AND orders.post_status IN ('wc-completed', 'wc-processing')
            AND orders.post_date >= '{$month_start}'
            AND orders.post_date <= '{$month_end}'
        ");
        
        $pending_payouts = $wpdb->get_var("
            SELECT COALESCE(SUM(amount), 0) FROM {$wpdb->prefix}ptp_payouts WHERE status = 'pending'
        ");
        
        echo '<div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 15px;">';
        echo '<div style="text-align: center; padding: 15px; background: #f0f9ff; border-radius: 8px;">';
        echo '<div style="font-size: 24px; font-weight: 700; color: #0369a1;">' . intval($stats->order_count ?? 0) . '</div>';
        echo '<div style="font-size: 12px; color: #64748b;">Orders This Month</div>';
        echo '</div>';
        echo '<div style="text-align: center; padding: 15px; background: #f0fdf4; border-radius: 8px;">';
        echo '<div style="font-size: 24px; font-weight: 700; color: #15803d;">$' . number_format($stats->total_revenue ?? 0, 0) . '</div>';
        echo '<div style="font-size: 12px; color: #64748b;">Revenue</div>';
        echo '</div>';
        echo '<div style="text-align: center; padding: 15px; background: #fefce8; border-radius: 8px;">';
        echo '<div style="font-size: 24px; font-weight: 700; color: #a16207;">$' . number_format($pending_payouts, 0) . '</div>';
        echo '<div style="font-size: 12px; color: #64748b;">Pending Payouts</div>';
        echo '</div>';
        echo '</div>';
        echo '<p style="margin: 15px 0 0; text-align: center;">';
        echo '<a href="' . admin_url('admin.php?page=ptp-payments') . '" class="button">Manage Payouts</a>';
        echo '</p>';
    }
    
    /**
     * Camps shortcode
     */
    public static function camps_shortcode($atts) {
        $atts = shortcode_atts(array(
            'limit' => 6,
            'columns' => 3,
            'category' => '',
        ), $atts);
        
        return self::render_products_grid('camp', $atts);
    }
    
    /**
     * Clinics shortcode
     */
    public static function clinics_shortcode($atts) {
        $atts = shortcode_atts(array(
            'limit' => 6,
            'columns' => 3,
            'category' => '',
        ), $atts);
        
        return self::render_products_grid('clinic', $atts);
    }
    
    /**
     * Render products grid
     */
    private static function render_products_grid($type, $atts) {
        $args = array(
            'post_type' => 'product',
            'posts_per_page' => intval($atts['limit']),
            'post_status' => 'publish',
            'meta_query' => array(
                array(
                    'key' => '_ptp_product_type',
                    'value' => $type,
                ),
            ),
            'orderby' => 'meta_value',
            'meta_key' => '_ptp_event_date',
            'order' => 'ASC',
        );
        
        if ($atts['category']) {
            $args['tax_query'] = array(
                array(
                    'taxonomy' => 'product_cat',
                    'field' => 'slug',
                    'terms' => $atts['category'],
                ),
            );
        }
        
        $products = new WP_Query($args);
        
        if (!$products->have_posts()) {
            return '<p style="text-align: center; color: #6b7280;">No ' . $type . 's available at this time.</p>';
        }
        
        ob_start();
        ?>
        <div class="ptp-products-grid" style="display: grid; grid-template-columns: repeat(<?php echo intval($atts['columns']); ?>, 1fr); gap: 24px;">
            <?php while ($products->have_posts()): $products->the_post(); 
                $product = wc_get_product(get_the_ID());
                $event_date = get_post_meta(get_the_ID(), '_ptp_event_date', true);
                $location = get_post_meta(get_the_ID(), '_ptp_event_location', true);
                $age_range = get_post_meta(get_the_ID(), '_ptp_age_range', true);
            ?>
                <div class="ptp-product-card" style="background: #fff; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 6px rgba(0,0,0,0.05);">
                    <a href="<?php the_permalink(); ?>" style="display: block;">
                        <?php if (has_post_thumbnail()): ?>
                            <div style="aspect-ratio: 16/9; overflow: hidden;">
                                <?php the_post_thumbnail('medium', array('style' => 'width: 100%; height: 100%; object-fit: cover;')); ?>
                            </div>
                        <?php endif; ?>
                    </a>
                    <div style="padding: 20px;">
                        <h3 style="margin: 0 0 8px; font-size: 18px; font-weight: 700;">
                            <a href="<?php the_permalink(); ?>" style="color: #0E0F11; text-decoration: none;"><?php the_title(); ?></a>
                        </h3>
                        <?php if ($event_date): ?>
                            <p style="margin: 0 0 4px; font-size: 14px; color: #6b7280; display: flex; align-items: center; gap: 6px;">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                                <?php echo date('M j, Y', strtotime($event_date)); ?>
                            </p>
                        <?php endif; ?>
                        <?php if ($location): ?>
                            <p style="margin: 0 0 4px; font-size: 14px; color: #6b7280; display: flex; align-items: center; gap: 6px;">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg>
                                <?php echo esc_html($location); ?>
                            </p>
                        <?php endif; ?>
                        <?php if ($age_range): ?>
                            <p style="margin: 0 0 12px; font-size: 14px; color: #6b7280; display: flex; align-items: center; gap: 6px;">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                                Ages <?php echo esc_html($age_range); ?>
                            </p>
                        <?php endif; ?>
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 16px;">
                            <span style="font-size: 20px; font-weight: 700; color: #0E0F11;">
                                <?php echo $product->get_price_html(); ?>
                            </span>
                            <a href="<?php echo esc_url($product->add_to_cart_url()); ?>" 
                               class="button" 
                               style="background: #FCB900; color: #0E0F11; border: none; padding: 10px 20px; border-radius: 8px; font-weight: 600; text-decoration: none;">
                                Register
                            </a>
                        </div>
                    </div>
                </div>
            <?php endwhile; wp_reset_postdata(); ?>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Create WooCommerce payout items table
     */
    public static function create_table() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        
        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ptp_woo_payout_items (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            payout_id bigint(20) UNSIGNED NOT NULL,
            order_id bigint(20) UNSIGNED NOT NULL,
            order_item_id bigint(20) UNSIGNED NOT NULL,
            product_id bigint(20) UNSIGNED NOT NULL,
            product_name varchar(255) DEFAULT '',
            amount decimal(10,2) NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY payout_id (payout_id),
            KEY order_id (order_id)
        ) $charset_collate;";
        dbDelta($sql);
    }
    
    /**
     * Get WooCommerce payout items for a payout
     */
    public static function get_woo_payout_items($payout_id) {
        global $wpdb;
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_woo_payout_items WHERE payout_id = %d ORDER BY created_at DESC",
            $payout_id
        ));
    }
    
    /**
     * Get trainer's WooCommerce earnings
     */
    public static function get_trainer_woo_earnings($trainer_id) {
        global $wpdb;
        
        return $wpdb->get_row($wpdb->prepare("
            SELECT 
                COALESCE(SUM(CASE WHEN p.status = 'completed' THEN wi.amount ELSE 0 END), 0) as paid,
                COALESCE(SUM(CASE WHEN p.status = 'pending' THEN wi.amount ELSE 0 END), 0) as pending
            FROM {$wpdb->prefix}ptp_woo_payout_items wi
            JOIN {$wpdb->prefix}ptp_payouts p ON wi.payout_id = p.id
            WHERE p.trainer_id = %d
        ", $trainer_id));
    }
}
