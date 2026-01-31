<?php
/**
 * PTP Checkout Diagnostic Page
 * 
 * Add this shortcode to any page: [ptp_checkout_diagnostic]
 * Or access via: /wp-admin/admin.php?page=ptp-checkout-test (if admin menu added)
 * 
 * This shows the current cart state and checkout data without processing payment.
 */

// Register shortcode
add_shortcode('ptp_checkout_diagnostic', function() {
    if (!current_user_can('manage_options')) {
        return '<p>Admin access required</p>';
    }
    
    ob_start();
    ptp_render_checkout_diagnostic();
    return ob_get_clean();
});

function ptp_render_checkout_diagnostic() {
    ?>
    <style>
        .ptp-diag { font-family: -apple-system, sans-serif; max-width: 900px; margin: 20px auto; padding: 20px; }
        .ptp-diag-section { background: #fff; border: 2px solid #e5e7eb; margin-bottom: 20px; }
        .ptp-diag-header { background: #0a0a0a; color: #FCB900; padding: 12px 20px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; }
        .ptp-diag-body { padding: 20px; }
        .ptp-diag-row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #f3f4f6; }
        .ptp-diag-row:last-child { border-bottom: none; }
        .ptp-diag-label { color: #6b7280; }
        .ptp-diag-value { font-weight: 600; font-family: monospace; }
        .ptp-diag-success { color: #059669; }
        .ptp-diag-error { color: #dc2626; }
        .ptp-diag-warning { color: #d97706; }
        .ptp-diag-json { background: #f9fafb; padding: 15px; border-radius: 4px; font-family: monospace; font-size: 12px; white-space: pre-wrap; overflow-x: auto; max-height: 300px; }
        .ptp-diag-btn { display: inline-block; padding: 10px 20px; background: #FCB900; color: #0a0a0a; font-weight: 700; text-decoration: none; margin-right: 10px; margin-top: 10px; }
        .ptp-diag-btn:hover { background: #e5a800; }
        .ptp-diag-btn.secondary { background: #0a0a0a; color: #fff; }
    </style>
    
    <div class="ptp-diag">
        <h1>🔧 PTP Checkout Diagnostic</h1>
        <p>This page shows the current cart/checkout state for debugging.</p>
        
        <?php
        // Get cart data
        $cart_data = array();
        $cart_error = null;
        
        try {
            if (class_exists('PTP_Cart_Helper')) {
                $cart_data = PTP_Cart_Helper::get_cart_data(true); // Force refresh
            } else {
                throw new Exception('PTP_Cart_Helper class not found');
            }
        } catch (Exception $e) {
            $cart_error = $e->getMessage();
        }
        ?>
        
        <!-- Cart State Section -->
        <div class="ptp-diag-section">
            <div class="ptp-diag-header">📦 Cart State</div>
            <div class="ptp-diag-body">
                <?php if ($cart_error): ?>
                    <div class="ptp-diag-error">Error: <?php echo esc_html($cart_error); ?></div>
                <?php else: ?>
                    <div class="ptp-diag-row">
                        <span class="ptp-diag-label">Total Items</span>
                        <span class="ptp-diag-value"><?php echo esc_html($cart_data['item_count'] ?? 0); ?></span>
                    </div>
                    <div class="ptp-diag-row">
                        <span class="ptp-diag-label">Has Camps</span>
                        <span class="ptp-diag-value <?php echo ($cart_data['has_camps'] ?? false) ? 'ptp-diag-success' : ''; ?>">
                            <?php echo ($cart_data['has_camps'] ?? false) ? '✓ Yes' : '✗ No'; ?>
                        </span>
                    </div>
                    <div class="ptp-diag-row">
                        <span class="ptp-diag-label">Has Training</span>
                        <span class="ptp-diag-value <?php echo ($cart_data['has_training'] ?? false) ? 'ptp-diag-success' : ''; ?>">
                            <?php echo ($cart_data['has_training'] ?? false) ? '✓ Yes' : '✗ No'; ?>
                        </span>
                    </div>
                    <div class="ptp-diag-row">
                        <span class="ptp-diag-label">Has Bundle Discount</span>
                        <span class="ptp-diag-value <?php echo ($cart_data['has_bundle'] ?? false) ? 'ptp-diag-success' : ''; ?>">
                            <?php echo ($cart_data['has_bundle'] ?? false) ? '✓ Yes (15% off)' : '✗ No'; ?>
                        </span>
                    </div>
                    <div class="ptp-diag-row">
                        <span class="ptp-diag-label">Bundle Code</span>
                        <span class="ptp-diag-value"><?php echo esc_html($cart_data['bundle_code'] ?? 'None'); ?></span>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Pricing Section -->
        <div class="ptp-diag-section">
            <div class="ptp-diag-header">💰 Pricing</div>
            <div class="ptp-diag-body">
                <div class="ptp-diag-row">
                    <span class="ptp-diag-label">WooCommerce Subtotal</span>
                    <span class="ptp-diag-value">$<?php echo number_format($cart_data['woo_subtotal'] ?? 0, 2); ?></span>
                </div>
                <div class="ptp-diag-row">
                    <span class="ptp-diag-label">Training Subtotal</span>
                    <span class="ptp-diag-value">$<?php echo number_format($cart_data['training_subtotal'] ?? 0, 2); ?></span>
                </div>
                <div class="ptp-diag-row">
                    <span class="ptp-diag-label">Subtotal</span>
                    <span class="ptp-diag-value">$<?php echo number_format($cart_data['subtotal'] ?? 0, 2); ?></span>
                </div>
                <div class="ptp-diag-row">
                    <span class="ptp-diag-label">Bundle Discount</span>
                    <span class="ptp-diag-value ptp-diag-success">-$<?php echo number_format($cart_data['bundle_discount'] ?? 0, 2); ?></span>
                </div>
                <div class="ptp-diag-row" style="border-top: 2px solid #0a0a0a; padding-top: 12px; font-size: 18px;">
                    <span class="ptp-diag-label"><strong>Total</strong></span>
                    <span class="ptp-diag-value"><strong>$<?php echo number_format($cart_data['total'] ?? 0, 2); ?></strong></span>
                </div>
            </div>
        </div>
        
        <!-- WooCommerce Items -->
        <div class="ptp-diag-section">
            <div class="ptp-diag-header">🛒 WooCommerce Items (<?php echo count($cart_data['woo_items'] ?? []); ?>)</div>
            <div class="ptp-diag-body">
                <?php if (empty($cart_data['woo_items'])): ?>
                    <p style="color: #6b7280;">No items in WooCommerce cart</p>
                <?php else: ?>
                    <?php foreach ($cart_data['woo_items'] as $item): ?>
                    <div class="ptp-diag-row">
                        <span class="ptp-diag-label">
                            <?php echo esc_html($item['name'] ?? 'Unknown'); ?>
                            <?php if (($item['quantity'] ?? 1) > 1): ?> × <?php echo esc_html($item['quantity']); ?><?php endif; ?>
                        </span>
                        <span class="ptp-diag-value">$<?php echo number_format($item['subtotal'] ?? 0, 2); ?></span>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Training Items -->
        <div class="ptp-diag-section">
            <div class="ptp-diag-header">⚽ Training Items (<?php echo count($cart_data['training_items'] ?? []); ?>)</div>
            <div class="ptp-diag-body">
                <?php if (empty($cart_data['training_items'])): ?>
                    <p style="color: #6b7280;">No training in cart</p>
                <?php else: ?>
                    <?php foreach ($cart_data['training_items'] as $item): ?>
                    <div class="ptp-diag-row">
                        <span class="ptp-diag-label">
                            <?php echo esc_html($item['package_name'] ?? 'Training'); ?> 
                            with <?php echo esc_html($item['trainer_name'] ?? 'Trainer'); ?>
                            <?php if (!empty($item['date'])): ?><br><small><?php echo esc_html($item['date']); ?> at <?php echo esc_html($item['time']); ?></small><?php endif; ?>
                        </span>
                        <span class="ptp-diag-value">$<?php echo number_format($item['price'] ?? 0, 2); ?></span>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- System Status -->
        <div class="ptp-diag-section">
            <div class="ptp-diag-header">⚙️ System Status</div>
            <div class="ptp-diag-body">
                <div class="ptp-diag-row">
                    <span class="ptp-diag-label">PTP Version</span>
                    <span class="ptp-diag-value"><?php echo defined('PTP_VERSION') ? PTP_VERSION : 'Unknown'; ?></span>
                </div>
                <div class="ptp-diag-row">
                    <span class="ptp-diag-label">Cart Helper</span>
                    <span class="ptp-diag-value <?php echo class_exists('PTP_Cart_Helper') ? 'ptp-diag-success' : 'ptp-diag-error'; ?>">
                        <?php echo class_exists('PTP_Cart_Helper') ? '✓ Loaded' : '✗ Missing'; ?>
                    </span>
                </div>
                <div class="ptp-diag-row">
                    <span class="ptp-diag-label">Stripe</span>
                    <span class="ptp-diag-value <?php echo (class_exists('PTP_Stripe') && PTP_Stripe::is_enabled()) ? 'ptp-diag-success' : 'ptp-diag-error'; ?>">
                        <?php 
                        if (!class_exists('PTP_Stripe')) {
                            echo '✗ Class missing';
                        } elseif (!PTP_Stripe::is_enabled()) {
                            echo '✗ Not configured';
                        } else {
                            echo '✓ Configured';
                        }
                        ?>
                    </span>
                </div>
                <div class="ptp-diag-row">
                    <span class="ptp-diag-label">WooCommerce</span>
                    <span class="ptp-diag-value <?php echo function_exists('WC') ? 'ptp-diag-success' : 'ptp-diag-error'; ?>">
                        <?php echo function_exists('WC') ? '✓ Active' : '✗ Not active'; ?>
                    </span>
                </div>
                <div class="ptp-diag-row">
                    <span class="ptp-diag-label">Session ID</span>
                    <span class="ptp-diag-value"><?php echo esc_html(class_exists('PTP_Cart_Helper') ? PTP_Cart_Helper::get_session_id() : 'N/A'); ?></span>
                </div>
            </div>
        </div>
        
        <!-- Raw Data -->
        <div class="ptp-diag-section">
            <div class="ptp-diag-header">📋 Raw Cart Data (JSON)</div>
            <div class="ptp-diag-body">
                <div class="ptp-diag-json"><?php echo esc_html(json_encode($cart_data, JSON_PRETTY_PRINT)); ?></div>
            </div>
        </div>
        
        <!-- Actions -->
        <div class="ptp-diag-section">
            <div class="ptp-diag-header">🔗 Quick Links</div>
            <div class="ptp-diag-body">
                <a href="<?php echo esc_url(home_url('/ptp-cart/')); ?>" class="ptp-diag-btn">View Cart Page</a>
                <a href="<?php echo esc_url(home_url('/ptp-checkout/')); ?>" class="ptp-diag-btn">View Checkout Page</a>
                <a href="<?php echo esc_url(home_url('/find-trainers/')); ?>" class="ptp-diag-btn secondary">Find Trainers</a>
                <a href="<?php echo esc_url(home_url('/ptp-find-a-camp/')); ?>" class="ptp-diag-btn secondary">Browse Camps</a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=ptp-settings')); ?>" class="ptp-diag-btn secondary">PTP Settings</a>
            </div>
        </div>
        
        <p style="color: #6b7280; font-size: 12px; margin-top: 20px;">
            Generated at: <?php echo current_time('Y-m-d H:i:s'); ?> | 
            User: <?php echo is_user_logged_in() ? wp_get_current_user()->user_login : 'Guest'; ?>
        </p>
    </div>
    <?php
}

// DISABLED - Checkout diagnostic moved to Tools page
// add_action('admin_menu', function() {
//     add_submenu_page(
//         'ptp-dashboard',
//         'Checkout Diagnostic',
//         'Checkout Test',
//         'manage_options',
//         'ptp-checkout-test',
//         function() {
//             echo '<div class="wrap">';
//             ptp_render_checkout_diagnostic();
//             echo '</div>';
//         }
//     );
// });
