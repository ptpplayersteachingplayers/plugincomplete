<?php
/**
 * PTP WooCommerce Cart Enhancement v1.0.0
 * 
 * Enhances WooCommerce cart with professional training upsell
 * that matches PTP design system and flows correctly to bundle checkout.
 * 
 * @since 60.2.0
 */

defined('ABSPATH') || exit;

class PTP_WooCommerce_Cart_Enhancement {
    
    private static $instance = null;
    
    const BUNDLE_DISCOUNT = 15;
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        // Hook into WooCommerce mini cart
        add_action('woocommerce_mini_cart_contents', array($this, 'add_training_upsell_to_mini_cart'), 50);
        add_action('woocommerce_widget_shopping_cart_before_buttons', array($this, 'add_training_upsell_before_buttons'));
        
        // Hook into WooCommerce cart page
        add_action('woocommerce_cart_collaterals', array($this, 'add_training_upsell_to_cart_page'), 5);
        add_action('woocommerce_before_cart_totals', array($this, 'add_training_upsell_above_totals'));
        
        // Add fragment for AJAX cart updates
        add_filter('woocommerce_add_to_cart_fragments', array($this, 'cart_fragments'));
        
        // Fix redirect after add to cart
        add_filter('woocommerce_add_to_cart_redirect', array($this, 'fix_add_to_cart_redirect'), 20, 2);
        
        // Add inline styles
        add_action('wp_head', array($this, 'output_styles'));
        
        // Register shortcode for cart page
        add_shortcode('ptp_cart_training_upsell', array($this, 'shortcode_training_upsell'));
    }
    
    /**
     * Check if cart has camps/clinics
     */
    private function cart_has_camps() {
        if (!function_exists('WC') || !WC()->cart) {
            return false;
        }
        
        foreach (WC()->cart->get_cart() as $cart_item) {
            $product = $cart_item['data'];
            $title = strtolower($product->get_name());
            $cats = wp_get_post_terms($cart_item['product_id'], 'product_cat', array('fields' => 'slugs'));
            
            if (strpos($title, 'camp') !== false || 
                strpos($title, 'clinic') !== false ||
                in_array('camps', $cats) ||
                in_array('clinics', $cats)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Get cart subtotal
     */
    private function get_cart_subtotal() {
        if (!function_exists('WC') || !WC()->cart) {
            return 0;
        }
        return floatval(WC()->cart->get_subtotal());
    }
    
    /**
     * Add training upsell to mini cart contents
     */
    public function add_training_upsell_to_mini_cart() {
        if (!$this->cart_has_camps()) {
            return;
        }
        
        $subtotal = $this->get_cart_subtotal();
        $potential_savings = round($subtotal * (self::BUNDLE_DISCOUNT / 100), 0);
        
        echo $this->render_training_upsell_card($potential_savings, 'mini');
    }
    
    /**
     * Add training upsell before mini cart buttons
     */
    public function add_training_upsell_before_buttons() {
        if (!$this->cart_has_camps()) {
            return;
        }
        
        $subtotal = $this->get_cart_subtotal();
        $potential_savings = round($subtotal * (self::BUNDLE_DISCOUNT / 100), 0);
        
        echo $this->render_training_upsell_banner($potential_savings);
    }
    
    /**
     * Add training upsell to cart page
     */
    public function add_training_upsell_to_cart_page() {
        if (!$this->cart_has_camps()) {
            return;
        }
        
        $subtotal = $this->get_cart_subtotal();
        $potential_savings = round($subtotal * (self::BUNDLE_DISCOUNT / 100), 0);
        
        echo $this->render_training_upsell_card($potential_savings, 'page');
    }
    
    /**
     * Add training upsell above cart totals
     */
    public function add_training_upsell_above_totals() {
        if (!$this->cart_has_camps()) {
            return;
        }
        
        $subtotal = $this->get_cart_subtotal();
        $potential_savings = round($subtotal * (self::BUNDLE_DISCOUNT / 100), 0);
        
        echo $this->render_training_upsell_inline($potential_savings);
    }
    
    /**
     * Render training upsell card (for mini cart and cart page)
     */
    private function render_training_upsell_card($potential_savings, $context = 'mini') {
        $trainers_url = home_url('/find-trainers/?bundle=1');
        
        ob_start();
        ?>
        <div class="ptp-cart-upsell ptp-cart-upsell--<?php echo esc_attr($context); ?>">
            <div class="ptp-cart-upsell__badge">
                <span class="ptp-cart-upsell__badge-icon">🎁</span>
                <span class="ptp-cart-upsell__badge-text">BUNDLE DEAL</span>
            </div>
            
            <div class="ptp-cart-upsell__content">
                <h4 class="ptp-cart-upsell__title">Add Private Training</h4>
                <p class="ptp-cart-upsell__desc">
                    Get <strong><?php echo self::BUNDLE_DISCOUNT; ?>% off</strong> your entire order when you combine camp with 1-on-1 training
                </p>
                
                <div class="ptp-cart-upsell__savings">
                    <span class="ptp-cart-upsell__savings-label">Potential savings:</span>
                    <span class="ptp-cart-upsell__savings-amount">$<?php echo number_format($potential_savings); ?>+</span>
                </div>
                
                <a href="<?php echo esc_url($trainers_url); ?>" class="ptp-cart-upsell__cta">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/>
                    </svg>
                    Find Your Trainer
                </a>
                
                <div class="ptp-cart-upsell__features">
                    <span>✓ MLS & D1 Athletes</span>
                    <span>✓ Flexible Scheduling</span>
                    <span>✓ 5:1 Player Ratio</span>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Render inline training upsell banner
     */
    private function render_training_upsell_banner($potential_savings) {
        $trainers_url = home_url('/find-trainers/?bundle=1');
        
        ob_start();
        ?>
        <div class="ptp-cart-banner">
            <div class="ptp-cart-banner__inner">
                <div class="ptp-cart-banner__icon">⚡</div>
                <div class="ptp-cart-banner__text">
                    <strong>Save <?php echo self::BUNDLE_DISCOUNT; ?>%</strong> — Add training to unlock bundle discount
                </div>
                <a href="<?php echo esc_url($trainers_url); ?>" class="ptp-cart-banner__btn">
                    Add Training
                </a>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Render inline upsell (above totals)
     */
    private function render_training_upsell_inline($potential_savings) {
        $trainers_url = home_url('/find-trainers/?bundle=1');
        
        ob_start();
        ?>
        <div class="ptp-cart-inline-upsell">
            <div class="ptp-cart-inline-upsell__header">
                <span class="ptp-cart-inline-upsell__icon">🎁</span>
                <span class="ptp-cart-inline-upsell__title">UNLOCK <?php echo self::BUNDLE_DISCOUNT; ?>% BUNDLE DISCOUNT</span>
            </div>
            <p class="ptp-cart-inline-upsell__text">
                Add private training with an MLS or D1 athlete and save <strong>$<?php echo number_format($potential_savings); ?>+</strong> on your order.
            </p>
            <a href="<?php echo esc_url($trainers_url); ?>" class="ptp-cart-inline-upsell__btn">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/>
                </svg>
                Browse Trainers & Save <?php echo self::BUNDLE_DISCOUNT; ?>%
            </a>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Cart fragments for AJAX updates
     */
    public function cart_fragments($fragments) {
        if ($this->cart_has_camps()) {
            $subtotal = $this->get_cart_subtotal();
            $potential_savings = round($subtotal * (self::BUNDLE_DISCOUNT / 100), 0);
            
            $fragments['.ptp-cart-upsell--mini'] = $this->render_training_upsell_card($potential_savings, 'mini');
            $fragments['.ptp-cart-banner'] = $this->render_training_upsell_banner($potential_savings);
        }
        
        return $fragments;
    }
    
    /**
     * Fix add to cart redirect
     */
    public function fix_add_to_cart_redirect($url, $product) {
        // If coming from bundle flow, go to cart
        if (isset($_GET['ptp_bundle']) || isset($_GET['bundle'])) {
            return wc_get_cart_url();
        }
        
        // If AJAX add to cart, don't redirect
        if (wp_doing_ajax()) {
            return $url;
        }
        
        return $url;
    }
    
    /**
     * Shortcode for manual placement
     */
    public function shortcode_training_upsell($atts) {
        if (!$this->cart_has_camps()) {
            return '';
        }
        
        $subtotal = $this->get_cart_subtotal();
        $potential_savings = round($subtotal * (self::BUNDLE_DISCOUNT / 100), 0);
        
        return $this->render_training_upsell_card($potential_savings, 'shortcode');
    }
    
    /**
     * Output styles
     */
    public function output_styles() {
        if (!is_cart() && !is_checkout()) {
            // Still output for mini cart which can appear anywhere
        }
        ?>
        <style id="ptp-cart-enhancement-styles">
        /* PTP Cart Upsell Card */
        .ptp-cart-upsell {
            background: linear-gradient(135deg, #0A0A0A 0%, #1a1a1a 100%);
            border: 2px solid #FCB900;
            padding: 0;
            margin: 16px 0;
            overflow: hidden;
            font-family: 'Inter', -apple-system, sans-serif;
        }
        
        .ptp-cart-upsell__badge {
            background: #FCB900;
            padding: 8px 16px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .ptp-cart-upsell__badge-icon {
            font-size: 16px;
        }
        
        .ptp-cart-upsell__badge-text {
            font-family: 'Oswald', sans-serif;
            font-size: 12px;
            font-weight: 700;
            color: #0A0A0A;
            letter-spacing: 1px;
        }
        
        .ptp-cart-upsell__content {
            padding: 20px;
            color: #fff;
        }
        
        .ptp-cart-upsell__title {
            font-family: 'Oswald', sans-serif;
            font-size: 18px;
            font-weight: 600;
            margin: 0 0 8px;
            text-transform: uppercase;
            color: #fff;
        }
        
        .ptp-cart-upsell__desc {
            font-size: 14px;
            color: rgba(255,255,255,0.8);
            margin: 0 0 16px;
            line-height: 1.5;
        }
        
        .ptp-cart-upsell__desc strong {
            color: #FCB900;
        }
        
        .ptp-cart-upsell__savings {
            background: rgba(252,185,0,0.15);
            border: 1px solid rgba(252,185,0,0.3);
            padding: 12px 16px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
        }
        
        .ptp-cart-upsell__savings-label {
            font-size: 13px;
            color: rgba(255,255,255,0.7);
        }
        
        .ptp-cart-upsell__savings-amount {
            font-family: 'Oswald', sans-serif;
            font-size: 24px;
            font-weight: 700;
            color: #FCB900;
        }
        
        .ptp-cart-upsell__cta {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            width: 100%;
            padding: 14px 20px;
            background: #FCB900;
            color: #0A0A0A;
            font-family: 'Oswald', sans-serif;
            font-size: 15px;
            font-weight: 700;
            text-transform: uppercase;
            text-decoration: none;
            border: none;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .ptp-cart-upsell__cta:hover {
            background: #e5a800;
            color: #0A0A0A;
            transform: translateY(-1px);
        }
        
        .ptp-cart-upsell__cta svg {
            flex-shrink: 0;
        }
        
        .ptp-cart-upsell__features {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 14px;
            padding-top: 14px;
            border-top: 1px solid rgba(255,255,255,0.1);
        }
        
        .ptp-cart-upsell__features span {
            font-size: 11px;
            color: rgba(255,255,255,0.6);
        }
        
        /* Mini cart specific */
        .ptp-cart-upsell--mini {
            margin: 12px 0 0;
        }
        
        .ptp-cart-upsell--mini .ptp-cart-upsell__content {
            padding: 16px;
        }
        
        .ptp-cart-upsell--mini .ptp-cart-upsell__title {
            font-size: 15px;
        }
        
        .ptp-cart-upsell--mini .ptp-cart-upsell__desc {
            font-size: 13px;
            margin-bottom: 12px;
        }
        
        .ptp-cart-upsell--mini .ptp-cart-upsell__savings {
            padding: 10px 12px;
            margin-bottom: 12px;
        }
        
        .ptp-cart-upsell--mini .ptp-cart-upsell__savings-amount {
            font-size: 20px;
        }
        
        .ptp-cart-upsell--mini .ptp-cart-upsell__cta {
            padding: 12px 16px;
            font-size: 13px;
        }
        
        .ptp-cart-upsell--mini .ptp-cart-upsell__features {
            display: none;
        }
        
        /* Banner style */
        .ptp-cart-banner {
            background: linear-gradient(90deg, #059669 0%, #047857 100%);
            padding: 12px 16px;
            margin: 12px 0;
        }
        
        .ptp-cart-banner__inner {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }
        
        .ptp-cart-banner__icon {
            font-size: 18px;
        }
        
        .ptp-cart-banner__text {
            flex: 1;
            font-size: 13px;
            color: #fff;
            min-width: 150px;
        }
        
        .ptp-cart-banner__text strong {
            color: #FCB900;
        }
        
        .ptp-cart-banner__btn {
            background: #FCB900;
            color: #0A0A0A;
            padding: 8px 16px;
            font-family: 'Oswald', sans-serif;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            text-decoration: none;
            white-space: nowrap;
        }
        
        .ptp-cart-banner__btn:hover {
            background: #e5a800;
            color: #0A0A0A;
        }
        
        /* Inline upsell (above totals) */
        .ptp-cart-inline-upsell {
            background: #FFFBEB;
            border: 2px solid #FCB900;
            padding: 20px;
            margin-bottom: 24px;
        }
        
        .ptp-cart-inline-upsell__header {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 10px;
        }
        
        .ptp-cart-inline-upsell__icon {
            font-size: 20px;
        }
        
        .ptp-cart-inline-upsell__title {
            font-family: 'Oswald', sans-serif;
            font-size: 14px;
            font-weight: 700;
            color: #92400E;
            letter-spacing: 0.5px;
        }
        
        .ptp-cart-inline-upsell__text {
            font-size: 14px;
            color: #78350F;
            margin: 0 0 16px;
            line-height: 1.5;
        }
        
        .ptp-cart-inline-upsell__text strong {
            color: #0A0A0A;
        }
        
        .ptp-cart-inline-upsell__btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: #0A0A0A;
            color: #FCB900;
            padding: 12px 24px;
            font-family: 'Oswald', sans-serif;
            font-size: 14px;
            font-weight: 600;
            text-transform: uppercase;
            text-decoration: none;
            transition: all 0.2s;
        }
        
        .ptp-cart-inline-upsell__btn:hover {
            background: #1a1a1a;
            color: #FCB900;
        }
        
        /* Hide default WooCommerce cross-sells if we're showing ours */
        .ptp-cart-upsell + .cross-sells {
            display: none;
        }
        </style>
        <?php
    }
}

// Initialize
function ptp_woocommerce_cart_enhancement() {
    return PTP_WooCommerce_Cart_Enhancement::instance();
}

add_action('plugins_loaded', function() {
    if (class_exists('WooCommerce')) {
        ptp_woocommerce_cart_enhancement();
    }
});
