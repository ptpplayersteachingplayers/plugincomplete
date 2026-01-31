<?php
/**
 * PTP Camp Orders Admin
 * 
 * Admin interface for managing camp orders without WooCommerce
 * 
 * @version 146.0.0
 */

defined('ABSPATH') || exit;

class PTP_Camp_Orders_Admin {
    
    private static $instance = null;
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        add_action('admin_menu', array($this, 'add_menu_pages'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
        
        // AJAX handlers
        add_action('wp_ajax_ptp_admin_get_camp_order', array($this, 'ajax_get_order'));
        add_action('wp_ajax_ptp_admin_update_camp_order', array($this, 'ajax_update_order'));
        add_action('wp_ajax_ptp_admin_refund_camp_order', array($this, 'ajax_refund_order'));
        add_action('wp_ajax_ptp_admin_sync_stripe_products', array($this, 'ajax_sync_products'));
        add_action('wp_ajax_ptp_admin_export_camp_orders', array($this, 'ajax_export_orders'));
    }
    
    /**
     * Add admin menu pages
     */
    public function add_menu_pages() {
        add_submenu_page(
            'ptp-dashboard',
            'Camp Orders',
            'Camp Orders',
            'manage_options',
            'ptp-camp-orders',
            array($this, 'render_orders_page')
        );
        
        add_submenu_page(
            'ptp-dashboard',
            'Camp Products',
            'Camp Products',
            'manage_options',
            'ptp-camp-products',
            array($this, 'render_products_page')
        );
    }
    
    /**
     * Enqueue admin scripts
     */
    public function enqueue_scripts($hook) {
        if (strpos($hook, 'ptp-camp') === false) {
            return;
        }
        
        wp_enqueue_style('ptp-admin-camp', PTP_PLUGIN_URL . 'assets/css/admin-camp.css', array(), PTP_VERSION);
        wp_enqueue_script('ptp-admin-camp', PTP_PLUGIN_URL . 'assets/js/admin-camp.js', array('jquery'), PTP_VERSION, true);
        
        wp_localize_script('ptp-admin-camp', 'ptpAdminCamp', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('ptp_admin_nonce'),
        ));
    }
    
    /**
     * Render orders page
     */
    public function render_orders_page() {
        global $wpdb;
        
        // Get filters
        $status = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';
        $search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
        $page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $per_page = 20;
        $offset = ($page - 1) * $per_page;
        
        // Build query
        $where = array('1=1');
        if ($status) {
            $where[] = $wpdb->prepare("o.status = %s", $status);
        }
        if ($search) {
            $where[] = $wpdb->prepare(
                "(o.order_number LIKE %s OR o.billing_email LIKE %s OR o.billing_first_name LIKE %s OR o.billing_last_name LIKE %s)",
                '%' . $wpdb->esc_like($search) . '%',
                '%' . $wpdb->esc_like($search) . '%',
                '%' . $wpdb->esc_like($search) . '%',
                '%' . $wpdb->esc_like($search) . '%'
            );
        }
        $where_sql = implode(' AND ', $where);
        
        // Get orders
        $orders = $wpdb->get_results("
            SELECT o.*, 
                   (SELECT COUNT(*) FROM {$wpdb->prefix}ptp_camp_order_items WHERE order_id = o.id) as item_count
            FROM {$wpdb->prefix}ptp_camp_orders o
            WHERE $where_sql
            ORDER BY o.created_at DESC
            LIMIT $per_page OFFSET $offset
        ");
        
        $total = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}ptp_camp_orders o WHERE $where_sql");
        $total_pages = ceil($total / $per_page);
        
        // Get stats
        $stats = $wpdb->get_row("
            SELECT 
                COUNT(*) as total_orders,
                SUM(CASE WHEN status = 'completed' THEN total_amount ELSE 0 END) as total_revenue,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_orders,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_orders
            FROM {$wpdb->prefix}ptp_camp_orders
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        ");
        
        ?>
        <div class="wrap ptp-admin-camp">
            <h1 class="wp-heading-inline">Camp Orders</h1>
            <a href="<?php echo admin_url('admin.php?page=ptp-camp-orders&action=export'); ?>" class="page-title-action">Export CSV</a>
            <hr class="wp-header-end">
            
            <!-- Stats -->
            <div class="ptp-stats-grid">
                <div class="ptp-stat-card">
                    <span class="stat-value">$<?php echo number_format($stats->total_revenue ?? 0, 0); ?></span>
                    <span class="stat-label">Revenue (30 days)</span>
                </div>
                <div class="ptp-stat-card">
                    <span class="stat-value"><?php echo intval($stats->completed_orders ?? 0); ?></span>
                    <span class="stat-label">Completed Orders</span>
                </div>
                <div class="ptp-stat-card">
                    <span class="stat-value"><?php echo intval($stats->pending_orders ?? 0); ?></span>
                    <span class="stat-label">Pending Orders</span>
                </div>
                <div class="ptp-stat-card">
                    <span class="stat-value"><?php echo intval($stats->total_orders ?? 0); ?></span>
                    <span class="stat-label">Total Orders (30 days)</span>
                </div>
            </div>
            
            <!-- Filters -->
            <div class="ptp-filters">
                <form method="get" action="">
                    <input type="hidden" name="page" value="ptp-camp-orders">
                    
                    <select name="status">
                        <option value="">All Statuses</option>
                        <option value="pending" <?php selected($status, 'pending'); ?>>Pending</option>
                        <option value="completed" <?php selected($status, 'completed'); ?>>Completed</option>
                        <option value="cancelled" <?php selected($status, 'cancelled'); ?>>Cancelled</option>
                        <option value="refunded" <?php selected($status, 'refunded'); ?>>Refunded</option>
                    </select>
                    
                    <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="Search orders...">
                    
                    <button type="submit" class="button">Filter</button>
                    
                    <?php if ($status || $search): ?>
                        <a href="<?php echo admin_url('admin.php?page=ptp-camp-orders'); ?>" class="button">Clear</a>
                    <?php endif; ?>
                </form>
            </div>
            
            <!-- Orders Table -->
            <table class="wp-list-table widefat fixed striped ptp-orders-table">
                <thead>
                    <tr>
                        <th class="column-order">Order</th>
                        <th class="column-customer">Customer</th>
                        <th class="column-items">Items</th>
                        <th class="column-total">Total</th>
                        <th class="column-status">Status</th>
                        <th class="column-date">Date</th>
                        <th class="column-actions">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($orders)): ?>
                        <tr>
                            <td colspan="7" style="text-align: center; padding: 40px;">
                                No orders found.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($orders as $order): ?>
                            <tr data-order-id="<?php echo $order->id; ?>">
                                <td class="column-order">
                                    <strong>
                                        <a href="#" class="view-order" data-order-id="<?php echo $order->id; ?>">
                                            #<?php echo esc_html($order->order_number); ?>
                                        </a>
                                    </strong>
                                    <?php if ($order->referral_code_used): ?>
                                        <br><small style="color: #10b981;">Referral: <?php echo esc_html($order->referral_code_used); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td class="column-customer">
                                    <strong><?php echo esc_html($order->billing_first_name . ' ' . $order->billing_last_name); ?></strong>
                                    <br>
                                    <a href="mailto:<?php echo esc_attr($order->billing_email); ?>"><?php echo esc_html($order->billing_email); ?></a>
                                </td>
                                <td class="column-items">
                                    <?php echo intval($order->item_count); ?> camper<?php echo $order->item_count != 1 ? 's' : ''; ?>
                                </td>
                                <td class="column-total">
                                    <strong>$<?php echo number_format($order->total_amount, 2); ?></strong>
                                    <?php if ($order->discount_amount > 0): ?>
                                        <br><small style="color: #10b981;">-$<?php echo number_format($order->discount_amount, 2); ?> discount</small>
                                    <?php endif; ?>
                                </td>
                                <td class="column-status">
                                    <span class="status-badge status-<?php echo esc_attr($order->status); ?>">
                                        <?php echo ucfirst($order->status); ?>
                                    </span>
                                    <?php if ($order->payment_status !== $order->status): ?>
                                        <br><small>Payment: <?php echo ucfirst($order->payment_status); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td class="column-date">
                                    <?php echo date('M j, Y', strtotime($order->created_at)); ?>
                                    <br>
                                    <small><?php echo date('g:i a', strtotime($order->created_at)); ?></small>
                                </td>
                                <td class="column-actions">
                                    <a href="#" class="button button-small view-order" data-order-id="<?php echo $order->id; ?>">View</a>
                                    <?php if ($order->status === 'completed' && $order->payment_status === 'completed'): ?>
                                        <a href="#" class="button button-small refund-order" data-order-id="<?php echo $order->id; ?>">Refund</a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            
            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <div class="tablenav bottom">
                    <div class="tablenav-pages">
                        <span class="displaying-num"><?php echo $total; ?> items</span>
                        <span class="pagination-links">
                            <?php if ($page > 1): ?>
                                <a class="prev-page button" href="<?php echo add_query_arg('paged', $page - 1); ?>">‹</a>
                            <?php endif; ?>
                            <span class="paging-input">
                                <?php echo $page; ?> of <?php echo $total_pages; ?>
                            </span>
                            <?php if ($page < $total_pages): ?>
                                <a class="next-page button" href="<?php echo add_query_arg('paged', $page + 1); ?>">›</a>
                            <?php endif; ?>
                        </span>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Order Detail Modal -->
        <div id="order-detail-modal" class="ptp-modal" style="display: none;">
            <div class="ptp-modal-content">
                <span class="ptp-modal-close">&times;</span>
                <div id="order-detail-content">
                    <!-- Loaded via AJAX -->
                </div>
            </div>
        </div>
        
        <style>
            .ptp-stats-grid {
                display: grid;
                grid-template-columns: repeat(4, 1fr);
                gap: 20px;
                margin: 20px 0;
            }
            .ptp-stat-card {
                background: #fff;
                border: 1px solid #ddd;
                border-radius: 8px;
                padding: 20px;
                text-align: center;
            }
            .ptp-stat-card .stat-value {
                display: block;
                font-size: 32px;
                font-weight: 700;
                color: #1d2327;
            }
            .ptp-stat-card .stat-label {
                color: #666;
                font-size: 14px;
            }
            .ptp-filters {
                background: #fff;
                padding: 15px;
                border: 1px solid #ddd;
                border-radius: 8px;
                margin-bottom: 20px;
            }
            .ptp-filters form {
                display: flex;
                gap: 10px;
                align-items: center;
            }
            .ptp-filters select,
            .ptp-filters input[type="search"] {
                padding: 6px 12px;
            }
            .status-badge {
                display: inline-block;
                padding: 4px 10px;
                border-radius: 20px;
                font-size: 12px;
                font-weight: 500;
            }
            .status-pending { background: #fef3c7; color: #92400e; }
            .status-completed { background: #d1fae5; color: #065f46; }
            .status-cancelled { background: #f3f4f6; color: #6b7280; }
            .status-refunded { background: #fee2e2; color: #991b1b; }
            
            .ptp-modal {
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background: rgba(0,0,0,0.5);
                z-index: 100000;
                display: flex;
                align-items: center;
                justify-content: center;
            }
            .ptp-modal-content {
                background: #fff;
                border-radius: 12px;
                max-width: 800px;
                width: 90%;
                max-height: 90vh;
                overflow-y: auto;
                position: relative;
                padding: 30px;
            }
            .ptp-modal-close {
                position: absolute;
                top: 15px;
                right: 20px;
                font-size: 28px;
                cursor: pointer;
                color: #666;
            }
            
            @media (max-width: 782px) {
                .ptp-stats-grid {
                    grid-template-columns: repeat(2, 1fr);
                }
            }
        </style>
        
        <script>
        jQuery(document).ready(function($) {
            // View order
            $('.view-order').on('click', function(e) {
                e.preventDefault();
                var orderId = $(this).data('order-id');
                
                $('#order-detail-content').html('<p style="text-align:center;padding:40px;">Loading...</p>');
                $('#order-detail-modal').show();
                
                $.ajax({
                    url: ptpAdminCamp.ajaxUrl,
                    method: 'POST',
                    data: {
                        action: 'ptp_admin_get_camp_order',
                        nonce: ptpAdminCamp.nonce,
                        order_id: orderId
                    },
                    success: function(response) {
                        if (response.success) {
                            $('#order-detail-content').html(response.data.html);
                        } else {
                            $('#order-detail-content').html('<p style="color:red;">Error loading order</p>');
                        }
                    }
                });
            });
            
            // Close modal
            $('.ptp-modal-close, .ptp-modal').on('click', function(e) {
                if (e.target === this) {
                    $('#order-detail-modal').hide();
                }
            });
            
            // Refund order
            $('.refund-order').on('click', function(e) {
                e.preventDefault();
                if (!confirm('Are you sure you want to refund this order? This will issue a full refund via Stripe.')) {
                    return;
                }
                
                var orderId = $(this).data('order-id');
                var btn = $(this);
                btn.text('Processing...').prop('disabled', true);
                
                $.ajax({
                    url: ptpAdminCamp.ajaxUrl,
                    method: 'POST',
                    data: {
                        action: 'ptp_admin_refund_camp_order',
                        nonce: ptpAdminCamp.nonce,
                        order_id: orderId
                    },
                    success: function(response) {
                        if (response.success) {
                            location.reload();
                        } else {
                            alert(response.data.message || 'Refund failed');
                            btn.text('Refund').prop('disabled', false);
                        }
                    }
                });
            });
        });
        </script>
        <?php
    }
    
    /**
     * Render products page
     */
    public function render_products_page() {
        global $wpdb;
        
        $products = $wpdb->get_results("
            SELECT * FROM {$wpdb->prefix}ptp_stripe_products
            WHERE product_type IN ('camp', 'clinic')
            ORDER BY sort_order ASC, name ASC
        ");
        
        ?>
        <div class="wrap ptp-admin-camp">
            <h1 class="wp-heading-inline">Camp Products</h1>
            <button type="button" class="page-title-action" id="sync-stripe-products">Sync from Stripe</button>
            <a href="https://dashboard.stripe.com/products" target="_blank" class="page-title-action">Manage in Stripe</a>
            <hr class="wp-header-end">
            
            <p class="description">
                Camp products are managed in Stripe. Create products in your Stripe dashboard with type "camp" or "clinic" in the metadata, 
                then click "Sync from Stripe" to import them here.
            </p>
            
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>Price</th>
                        <th>Dates</th>
                        <th>Location</th>
                        <th>Capacity</th>
                        <th>Status</th>
                        <th>Stripe ID</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($products)): ?>
                        <tr>
                            <td colspan="7" style="text-align: center; padding: 40px;">
                                No products found. Create products in Stripe and click "Sync from Stripe".
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($products as $product): ?>
                            <tr>
                                <td>
                                    <strong><?php echo esc_html($product->name); ?></strong>
                                    <?php if ($product->description): ?>
                                        <br><small><?php echo esc_html(substr($product->description, 0, 100)); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>$<?php echo number_format($product->price_cents / 100, 0); ?></td>
                                <td><?php echo esc_html($product->camp_dates ?: '-'); ?></td>
                                <td><?php echo esc_html($product->camp_location ?: '-'); ?></td>
                                <td>
                                    <?php if ($product->camp_capacity): ?>
                                        <?php echo intval($product->camp_registered); ?> / <?php echo intval($product->camp_capacity); ?>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($product->active): ?>
                                        <span style="color: #10b981;">Active</span>
                                    <?php else: ?>
                                        <span style="color: #6b7280;">Inactive</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <code style="font-size: 11px;"><?php echo esc_html(substr($product->stripe_product_id, 0, 20)); ?>...</code>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            
            <div class="ptp-stripe-metadata-help" style="margin-top: 30px; background: #fff; padding: 20px; border: 1px solid #ddd; border-radius: 8px;">
                <h3>Stripe Product Metadata</h3>
                <p>When creating products in Stripe, add these metadata fields:</p>
                <table class="widefat" style="max-width: 600px;">
                    <tr><td><code>type</code></td><td>camp or clinic (required)</td></tr>
                    <tr><td><code>dates</code></td><td>e.g., "June 17-21, 2024"</td></tr>
                    <tr><td><code>location</code></td><td>e.g., "Villanova University"</td></tr>
                    <tr><td><code>time</code></td><td>e.g., "9:00 AM - 12:00 PM"</td></tr>
                    <tr><td><code>age_min</code></td><td>e.g., 6</td></tr>
                    <tr><td><code>age_max</code></td><td>e.g., 14</td></tr>
                    <tr><td><code>capacity</code></td><td>e.g., 50</td></tr>
                </table>
            </div>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            $('#sync-stripe-products').on('click', function() {
                var btn = $(this);
                btn.text('Syncing...').prop('disabled', true);
                
                $.ajax({
                    url: ptpAdminCamp.ajaxUrl,
                    method: 'POST',
                    data: {
                        action: 'ptp_admin_sync_stripe_products',
                        nonce: ptpAdminCamp.nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            alert('Synced ' + response.data.synced + ' products from Stripe');
                            location.reload();
                        } else {
                            alert(response.data.message || 'Sync failed');
                        }
                    },
                    complete: function() {
                        btn.text('Sync from Stripe').prop('disabled', false);
                    }
                });
            });
        });
        </script>
        <?php
    }
    
    /**
     * AJAX: Get order details
     */
    public function ajax_get_order() {
        check_ajax_referer('ptp_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }
        
        $order_id = intval($_POST['order_id']);
        $order = PTP_Camp_Orders::get_order($order_id);
        
        if (!$order) {
            wp_send_json_error(array('message' => 'Order not found'));
        }
        
        ob_start();
        ?>
        <h2>Order #<?php echo esc_html($order->order_number); ?></h2>
        
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
            <div>
                <h3>Customer</h3>
                <p>
                    <strong><?php echo esc_html($order->billing_first_name . ' ' . $order->billing_last_name); ?></strong><br>
                    <?php echo esc_html($order->billing_email); ?><br>
                    <?php echo esc_html($order->billing_phone); ?>
                </p>
                
                <h4>Emergency Contact</h4>
                <p>
                    <?php echo esc_html($order->emergency_name); ?> (<?php echo esc_html($order->emergency_relation); ?>)<br>
                    <?php echo esc_html($order->emergency_phone); ?>
                </p>
            </div>
            <div>
                <h3>Order Details</h3>
                <p>
                    <strong>Status:</strong> <?php echo ucfirst($order->status); ?><br>
                    <strong>Payment:</strong> <?php echo ucfirst($order->payment_status); ?><br>
                    <strong>Date:</strong> <?php echo date('M j, Y g:i a', strtotime($order->created_at)); ?><br>
                    <?php if ($order->referral_code_used): ?>
                        <strong>Referral Code:</strong> <?php echo esc_html($order->referral_code_used); ?><br>
                    <?php endif; ?>
                    <?php if ($order->referral_code_generated): ?>
                        <strong>Their Referral Code:</strong> <?php echo esc_html($order->referral_code_generated); ?>
                    <?php endif; ?>
                </p>
            </div>
        </div>
        
        <h3>Campers</h3>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th>Camper</th>
                    <th>Camp</th>
                    <th>Details</th>
                    <th>Price</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($order->items as $item): ?>
                    <tr>
                        <td>
                            <strong><?php echo esc_html($item->camper_first_name . ' ' . $item->camper_last_name); ?></strong><br>
                            <small>Age: <?php echo esc_html($item->camper_age); ?> | Size: <?php echo esc_html($item->camper_shirt_size); ?></small>
                        </td>
                        <td>
                            <?php echo esc_html($item->camp_name); ?><br>
                            <small><?php echo esc_html($item->camp_dates); ?></small>
                        </td>
                        <td>
                            <?php if ($item->care_bundle): ?><span style="background:#d1fae5;padding:2px 6px;border-radius:4px;font-size:11px;">Care Bundle</span><?php endif; ?>
                            <?php if ($item->jersey): ?><span style="background:#dbeafe;padding:2px 6px;border-radius:4px;font-size:11px;">Jersey</span><?php endif; ?>
                            <?php if ($item->is_sibling): ?><span style="background:#fef3c7;padding:2px 6px;border-radius:4px;font-size:11px;">Sibling</span><?php endif; ?>
                            <?php if ($item->medical_conditions): ?>
                                <br><small style="color:#ef4444;">Medical: <?php echo esc_html(substr($item->medical_conditions, 0, 50)); ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            $<?php echo number_format($item->final_price, 2); ?>
                            <?php if ($item->discount_amount > 0): ?>
                                <br><small style="color:#10b981;">-$<?php echo number_format($item->discount_amount, 2); ?></small>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <div style="margin-top: 20px; text-align: right;">
            <table style="margin-left: auto;">
                <tr><td>Subtotal:</td><td style="text-align:right;padding-left:20px;">$<?php echo number_format($order->subtotal, 2); ?></td></tr>
                <?php if ($order->discount_amount > 0): ?>
                    <tr style="color:#10b981;"><td>Discounts:</td><td style="text-align:right;padding-left:20px;">-$<?php echo number_format($order->discount_amount, 2); ?></td></tr>
                <?php endif; ?>
                <?php if ($order->care_bundle_total > 0): ?>
                    <tr><td>Care Bundle:</td><td style="text-align:right;padding-left:20px;">$<?php echo number_format($order->care_bundle_total, 2); ?></td></tr>
                <?php endif; ?>
                <?php if ($order->jersey_total > 0): ?>
                    <tr><td>Jerseys:</td><td style="text-align:right;padding-left:20px;">$<?php echo number_format($order->jersey_total, 2); ?></td></tr>
                <?php endif; ?>
                <tr><td>Processing Fee:</td><td style="text-align:right;padding-left:20px;">$<?php echo number_format($order->processing_fee, 2); ?></td></tr>
                <tr style="font-weight:bold;font-size:18px;"><td>Total:</td><td style="text-align:right;padding-left:20px;">$<?php echo number_format($order->total_amount, 2); ?></td></tr>
            </table>
        </div>
        
        <?php if ($order->stripe_payment_intent_id): ?>
            <p style="margin-top:20px;">
                <a href="https://dashboard.stripe.com/payments/<?php echo esc_attr($order->stripe_payment_intent_id); ?>" target="_blank" class="button">
                    View in Stripe →
                </a>
            </p>
        <?php endif; ?>
        <?php
        
        $html = ob_get_clean();
        
        wp_send_json_success(array('html' => $html));
    }
    
    /**
     * AJAX: Refund order
     */
    public function ajax_refund_order() {
        check_ajax_referer('ptp_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }
        
        $order_id = intval($_POST['order_id']);
        $order = PTP_Camp_Orders::get_order($order_id);
        
        if (!$order) {
            wp_send_json_error(array('message' => 'Order not found'));
        }
        
        if (!$order->stripe_payment_intent_id) {
            wp_send_json_error(array('message' => 'No payment intent found'));
        }
        
        // Create refund via Stripe
        if (class_exists('PTP_Stripe')) {
            $refund = PTP_Stripe::create_refund($order->stripe_payment_intent_id);
            
            if (is_wp_error($refund)) {
                wp_send_json_error(array('message' => $refund->get_error_message()));
            }
        }
        
        // Update order status
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'ptp_camp_orders',
            array(
                'status' => 'refunded',
                'payment_status' => 'refunded',
                'refund_amount' => $order->total_amount,
                'refunded_at' => current_time('mysql'),
            ),
            array('id' => $order_id)
        );
        
        // Send cancellation email
        if (class_exists('PTP_Camp_Emails')) {
            PTP_Camp_Emails::send_cancellation_email($order_id, $order->total_amount);
        }
        
        wp_send_json_success(array('message' => 'Order refunded successfully'));
    }
    
    /**
     * AJAX: Sync Stripe products
     */
    public function ajax_sync_products() {
        check_ajax_referer('ptp_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }
        
        $result = PTP_Camp_Checkout::sync_products_from_stripe();
        
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }
        
        wp_send_json_success($result);
    }
    
    /**
     * AJAX: Export orders
     */
    public function ajax_export_orders() {
        check_ajax_referer('ptp_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }
        
        global $wpdb;
        
        $orders = $wpdb->get_results("
            SELECT o.*, i.*
            FROM {$wpdb->prefix}ptp_camp_orders o
            LEFT JOIN {$wpdb->prefix}ptp_camp_order_items i ON i.order_id = o.id
            WHERE o.status = 'completed'
            ORDER BY o.created_at DESC
        ");
        
        // Build CSV
        $csv = "Order Number,Order Date,Parent Name,Parent Email,Parent Phone,Camper Name,Camper Age,Camper Shirt Size,Camp Name,Camp Dates,Care Bundle,Jersey,Price,Medical Notes\n";
        
        foreach ($orders as $row) {
            $csv .= sprintf(
                '"%s","%s","%s","%s","%s","%s","%s","%s","%s","%s","%s","%s","%s","%s"' . "\n",
                $row->order_number,
                $row->created_at,
                $row->billing_first_name . ' ' . $row->billing_last_name,
                $row->billing_email,
                $row->billing_phone,
                $row->camper_first_name . ' ' . $row->camper_last_name,
                $row->camper_age,
                $row->camper_shirt_size,
                $row->camp_name,
                $row->camp_dates,
                $row->care_bundle ? 'Yes' : 'No',
                $row->jersey ? 'Yes' : 'No',
                $row->final_price,
                str_replace('"', '""', $row->medical_conditions)
            );
        }
        
        wp_send_json_success(array('csv' => $csv));
    }
}

// Initialize
PTP_Camp_Orders_Admin::instance();
