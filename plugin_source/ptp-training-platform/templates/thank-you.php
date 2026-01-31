<?php
/**
 * Thank You / Confirmation v87.5 - Enhanced with full order details
 */
defined('ABSPATH') || exit;

global $wpdb;
$order_id = isset($_GET['order_id']) ? intval($_GET['order_id']) : 0;
$booking_id = isset($_GET['booking_id']) ? intval($_GET['booking_id']) : 0;

$booking = null;
$order = null;
$order_items = array();
$has_camp = false;
$has_training = false;

// Get booking info
if ($booking_id) {
    $booking = $wpdb->get_row($wpdb->prepare(
        "SELECT b.*, t.display_name as trainer_name, t.photo_url as trainer_photo, t.slug as trainer_slug
         FROM {$wpdb->prefix}ptp_bookings b
         LEFT JOIN {$wpdb->prefix}ptp_trainers t ON b.trainer_id = t.id
         WHERE b.id = %d", $booking_id
    ));
    if ($booking) $has_training = true;
}

// Get WooCommerce order info
if ($order_id && function_exists('wc_get_order')) {
    $order = wc_get_order($order_id);
    if ($order) {
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            $pid = $product ? $product->get_id() : 0;
            $is_camp = get_post_meta($pid, '_ptp_is_camp', true) === 'yes';
            if (!$is_camp && $product) {
                $name = strtolower($product->get_name());
                $is_camp = strpos($name, 'camp') !== false || strpos($name, 'clinic') !== false;
            }
            
            if ($is_camp) $has_camp = true;
            
            $order_items[] = array(
                'name' => $item->get_name(),
                'type' => $is_camp ? 'camp' : 'product',
                'qty' => $item->get_quantity(),
                'total' => $item->get_total(),
                'date' => get_post_meta($pid, '_ptp_start_date', true),
                'end_date' => get_post_meta($pid, '_ptp_end_date', true),
                'location' => get_post_meta($pid, '_ptp_location_name', true),
                'address' => get_post_meta($pid, '_ptp_location_address', true),
            );
        }
    }
}

// Get parent email
$parent_email = '';
if ($order) {
    $parent_email = $order->get_billing_email();
} elseif ($booking) {
    $parent_email = $booking->parent_email ?? '';
}

get_header();
?>
<style>
.ptp-thanks{--gold:#FCB900;--black:#0A0A0A;--gray:#F5F5F5;--green:#22C55E;--blue:#3B82F6;--radius:16px;font-family:Inter,-apple-system,sans-serif;background:linear-gradient(180deg,var(--black) 0%,#1a1a1a 100%);min-height:80vh;padding:40px 16px 80px}
.ptp-thanks h1,.ptp-thanks h2,.ptp-thanks h3{font-family:Oswald,sans-serif;font-weight:700;text-transform:uppercase;margin:0}
.ptp-thanks a{color:inherit;text-decoration:none}
.ptp-thanks-wrap{max-width:600px;margin:0 auto}
.ptp-thanks-hero{text-align:center;margin-bottom:32px}
.ptp-thanks-check{width:80px;height:80px;background:var(--green);border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 24px;animation:pop .5s ease}
.ptp-thanks-check svg{width:40px;height:40px;stroke:#fff;stroke-width:3}
@keyframes pop{0%{transform:scale(0)}50%{transform:scale(1.2)}100%{transform:scale(1)}}
.ptp-thanks h1{font-size:clamp(28px,6vw,40px);color:#fff;margin-bottom:8px}
.ptp-thanks h1 em{color:var(--gold);font-style:normal}
.ptp-thanks-sub{color:rgba(255,255,255,.6);font-size:15px}
.ptp-thanks-sub strong{color:#fff}

.ptp-card{background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.1);border-radius:var(--radius);padding:20px;margin-bottom:16px}
.ptp-card-title{font-size:11px;text-transform:uppercase;letter-spacing:.1em;color:rgba(255,255,255,.4);margin-bottom:16px;display:flex;align-items:center;gap:8px}
.ptp-card-title svg{width:16px;height:16px;stroke:var(--gold)}

.ptp-order-item{display:flex;gap:14px;padding:14px 0;border-bottom:1px solid rgba(255,255,255,.08)}
.ptp-order-item:last-child{border:none}
.ptp-order-badge{font-size:9px;font-weight:700;text-transform:uppercase;padding:3px 8px;border-radius:4px;display:inline-block}
.ptp-order-badge.camp{background:rgba(252,185,0,.2);color:var(--gold)}
.ptp-order-badge.training{background:rgba(34,197,94,.2);color:var(--green)}
.ptp-order-name{font-size:14px;font-weight:500;color:#fff;margin:4px 0}
.ptp-order-meta{font-size:12px;color:rgba(255,255,255,.5)}
.ptp-order-price{font-family:Oswald,sans-serif;font-size:16px;font-weight:600;color:#fff;margin-left:auto;white-space:nowrap}

.ptp-trainer{display:flex;gap:14px;align-items:center;padding-bottom:16px;margin-bottom:16px;border-bottom:1px solid rgba(255,255,255,.1)}
.ptp-trainer-img{width:56px;height:56px;border-radius:50%;border:3px solid var(--gold);overflow:hidden}
.ptp-trainer-img img{width:100%;height:100%;object-fit:cover}
.ptp-trainer-name{font-family:Oswald,sans-serif;font-size:16px;font-weight:600;text-transform:uppercase;color:#fff}
.ptp-trainer-type{font-size:12px;color:var(--gold)}

.ptp-detail{display:flex;justify-content:space-between;font-size:14px;margin-bottom:10px}
.ptp-detail:last-child{margin-bottom:0}
.ptp-detail-label{color:rgba(255,255,255,.5)}
.ptp-detail-value{color:#fff;font-weight:500;text-align:right}

.ptp-steps{counter-reset:step}
.ptp-step{display:flex;gap:14px;padding:14px 0;border-bottom:1px solid rgba(255,255,255,.08)}
.ptp-step:last-child{border:none}
.ptp-step-num{width:28px;height:28px;background:var(--gold);color:var(--black);border-radius:50%;display:flex;align-items:center;justify-content:center;font-family:Oswald,sans-serif;font-size:14px;font-weight:700;flex-shrink:0}
.ptp-step-text{flex:1}
.ptp-step-title{font-weight:600;color:#fff;margin-bottom:2px}
.ptp-step-desc{font-size:13px;color:rgba(255,255,255,.6)}

.ptp-btns{display:flex;flex-direction:column;gap:12px;margin-top:24px}
.ptp-btn{display:flex;align-items:center;justify-content:center;gap:8px;padding:16px 28px;font-family:Oswald,sans-serif;font-size:15px;font-weight:600;text-transform:uppercase;text-align:center;border-radius:10px;transition:.2s;border:none;cursor:pointer}
.ptp-btn.gold{background:var(--gold);color:var(--black)}
.ptp-btn.gold:hover{transform:translateY(-2px);box-shadow:0 8px 24px rgba(252,185,0,.3)}
.ptp-btn.outline{border:2px solid rgba(255,255,255,.3);color:#fff;background:transparent}
.ptp-btn.outline:hover{border-color:var(--gold);color:var(--gold)}
.ptp-btn.blue{background:var(--blue);color:#fff}
.ptp-btn svg{width:18px;height:18px}

.ptp-cal-dropdown{position:relative;display:inline-block;width:100%}
.ptp-cal-menu{display:none;position:absolute;bottom:100%;left:0;right:0;background:#1a1a1a;border:1px solid rgba(255,255,255,.2);border-radius:10px;padding:8px;margin-bottom:8px;z-index:10}
.ptp-cal-menu.open{display:block}
.ptp-cal-opt{display:flex;align-items:center;gap:10px;padding:10px 12px;border-radius:6px;color:#fff;font-size:14px;cursor:pointer}
.ptp-cal-opt:hover{background:rgba(255,255,255,.1)}

.ptp-referral{background:linear-gradient(135deg,var(--gold) 0%,#FFD54F 100%);border-radius:var(--radius);padding:20px;text-align:center;color:var(--black);margin-top:24px}
.ptp-referral h3{font-size:18px;margin-bottom:8px}
.ptp-referral p{font-size:13px;margin-bottom:12px;opacity:.8}
.ptp-referral-code{background:var(--black);color:var(--gold);padding:12px 20px;border-radius:8px;font-family:monospace;font-size:18px;font-weight:700;letter-spacing:2px;display:inline-block}

.ptp-share{display:flex;gap:12px;justify-content:center;margin-top:24px;padding-top:24px;border-top:1px solid rgba(255,255,255,.1)}
.ptp-share-btn{width:44px;height:44px;border-radius:50%;background:rgba(255,255,255,.1);display:flex;align-items:center;justify-content:center;transition:.2s}
.ptp-share-btn:hover{background:var(--gold);color:var(--black)}
.ptp-share-btn svg{width:20px;height:20px}

@media(max-width:599px){
    .ptp-thanks{padding:24px 12px 60px}
    .ptp-card{padding:16px}
    .ptp-btns{gap:10px}
    .ptp-btn{padding:14px 20px;font-size:14px}
}
</style>

<div class="ptp-thanks">
<div class="ptp-thanks-wrap">
    <div class="ptp-thanks-hero">
        <div class="ptp-thanks-check"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><polyline points="20 6 9 17 4 12"/></svg></div>
        <h1>YOU'RE <em>ALL SET!</em></h1>
        <p class="ptp-thanks-sub">Confirmation sent to <strong><?php echo esc_html($parent_email ?: 'your email'); ?></strong></p>
    </div>
    
    <?php if (!empty($order_items)): ?>
    <div class="ptp-card">
        <p class="ptp-card-title">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 2L3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 01-8 0"/></svg>
            Your Order
        </p>
        <?php foreach ($order_items as $item): ?>
        <div class="ptp-order-item">
            <div>
                <span class="ptp-order-badge <?php echo $item['type']; ?>"><?php echo $item['type'] === 'camp' ? 'Camp' : 'Item'; ?></span>
                <div class="ptp-order-name"><?php echo esc_html($item['name']); ?></div>
                <?php if ($item['date']): ?>
                <div class="ptp-order-meta">
                    📅 <?php echo date('M j', strtotime($item['date'])); ?><?php if ($item['end_date']): ?> - <?php echo date('M j, Y', strtotime($item['end_date'])); ?><?php endif; ?>
                    <?php if ($item['location']): ?> · 📍 <?php echo esc_html($item['location']); ?><?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
            <div class="ptp-order-price">$<?php echo number_format($item['total'], 2); ?></div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
    
    <?php if ($booking): ?>
    <div class="ptp-card">
        <p class="ptp-card-title">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            Training Session
        </p>
        <div class="ptp-trainer">
            <div class="ptp-trainer-img">
                <img src="<?php echo esc_url($booking->trainer_photo ?: 'https://ui-avatars.com/api/?name='.urlencode($booking->trainer_name).'&size=112&background=FCB900&color=0A0A0A&bold=true'); ?>" alt="">
            </div>
            <div>
                <div class="ptp-trainer-name"><?php echo esc_html($booking->trainer_name); ?></div>
                <div class="ptp-trainer-type">Private Training</div>
            </div>
        </div>
        <?php if (!empty($booking->session_date)): ?>
        <div class="ptp-detail"><span class="ptp-detail-label">Date</span><span class="ptp-detail-value"><?php echo date('l, F j, Y', strtotime($booking->session_date)); ?></span></div>
        <?php else: ?>
        <div class="ptp-detail"><span class="ptp-detail-label">Date</span><span class="ptp-detail-value" style="color:var(--gold);">To be scheduled with trainer</span></div>
        <?php endif; ?>
        <?php if (!empty($booking->session_time)): ?><div class="ptp-detail"><span class="ptp-detail-label">Time</span><span class="ptp-detail-value"><?php echo date('g:i A', strtotime($booking->session_time)); ?> EST</span></div><?php endif; ?>
        <?php if (!empty($booking->location)): ?><div class="ptp-detail"><span class="ptp-detail-label">Location</span><span class="ptp-detail-value"><?php echo esc_html($booking->location); ?></span></div><?php endif; ?>
        <div class="ptp-detail"><span class="ptp-detail-label">Confirmation #</span><span class="ptp-detail-value">PTP-<?php echo $booking->id; ?></span></div>
    </div>
    <?php endif; ?>
    
    <?php if ($order): ?>
    <div class="ptp-card">
        <p class="ptp-card-title">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
            Payment Details
        </p>
        <div class="ptp-detail"><span class="ptp-detail-label">Order #</span><span class="ptp-detail-value"><?php echo $order->get_id(); ?></span></div>
        <div class="ptp-detail"><span class="ptp-detail-label">Date</span><span class="ptp-detail-value"><?php echo $order->get_date_created()->format('M j, Y g:i A'); ?></span></div>
        <div class="ptp-detail" style="padding-top:10px;margin-top:10px;border-top:1px solid rgba(255,255,255,.1);font-size:16px;"><span class="ptp-detail-label" style="font-weight:600">Total Paid</span><span class="ptp-detail-value" style="color:var(--gold);font-family:Oswald;font-size:20px">$<?php echo number_format($order->get_total(), 2); ?></span></div>
    </div>
    <?php endif; ?>
    
    <!-- Next Steps -->
    <div class="ptp-card">
        <p class="ptp-card-title">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
            What's Next
        </p>
        <div class="ptp-steps">
            <div class="ptp-step">
                <div class="ptp-step-num">1</div>
                <div class="ptp-step-text">
                    <div class="ptp-step-title">Check Your Email</div>
                    <div class="ptp-step-desc">Confirmation with all details sent to your inbox</div>
                </div>
            </div>
            <?php if ($has_camp): ?>
            <div class="ptp-step">
                <div class="ptp-step-num">2</div>
                <div class="ptp-step-text">
                    <div class="ptp-step-title">Receive Camp Packet</div>
                    <div class="ptp-step-desc">1 week before camp: schedule, what to bring, coach assignments</div>
                </div>
            </div>
            <div class="ptp-step">
                <div class="ptp-step-num">3</div>
                <div class="ptp-step-text">
                    <div class="ptp-step-title">Day 1 Check-In</div>
                    <div class="ptp-step-desc">Arrive 15 min early. Bring water, cleats, shin guards!</div>
                </div>
            </div>
            <?php endif; ?>
            <?php if ($has_training): ?>
            <div class="ptp-step">
                <div class="ptp-step-num"><?php echo $has_camp ? '4' : '2'; ?></div>
                <div class="ptp-step-text">
                    <div class="ptp-step-title">Trainer Will Reach Out</div>
                    <div class="ptp-step-desc">Your trainer will text/call to confirm location details</div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <div class="ptp-btns">
        <?php 
        $cal_title = 'PTP Soccer';
        $cal_start = '';
        $cal_location = '';
        
        if ($has_camp && !empty($order_items)) {
            foreach ($order_items as $item) {
                if ($item['type'] === 'camp' && $item['date']) {
                    $cal_title = $item['name'];
                    $cal_start = $item['date'];
                    $cal_location = $item['address'] ?: $item['location'];
                    break;
                }
            }
        } elseif ($booking) {
            $cal_title = 'PTP Training with ' . $booking->trainer_name;
            $cal_start = $booking->session_date;
            $cal_location = $booking->location;
        }
        
        if ($cal_start):
            $gcal_url = 'https://calendar.google.com/calendar/render?action=TEMPLATE&text=' . urlencode($cal_title) . '&dates=' . date('Ymd', strtotime($cal_start)) . '/' . date('Ymd', strtotime($cal_start . ' +1 day')) . '&location=' . urlencode($cal_location);
        ?>
        <a href="<?php echo esc_url($gcal_url); ?>" target="_blank" class="ptp-btn blue">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
            Add to Calendar
        </a>
        <?php endif; ?>
        
        <a href="<?php echo home_url('/my-training/'); ?>" class="ptp-btn gold">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
            View My Dashboard
        </a>
        <a href="<?php echo home_url('/find-trainers/'); ?>" class="ptp-btn outline">Book Another Session</a>
    </div>
    
    <!-- Referral Program -->
    <div class="ptp-referral">
        <h3>🎁 Give $25, Get $25</h3>
        <p>Share your code with friends. When they register, you both save!</p>
        <?php $ref_code = strtoupper(substr(md5($parent_email . 'ptp'), 0, 8)); ?>
        <div class="ptp-referral-code"><?php echo $ref_code; ?></div>
    </div>
    
    <div class="ptp-share">
        <a href="https://www.facebook.com/sharer/sharer.php?u=<?php echo urlencode(home_url()); ?>" target="_blank" class="ptp-share-btn" title="Share on Facebook">
            <svg viewBox="0 0 24 24" fill="currentColor"><path d="M18 2h-3a5 5 0 00-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 011-1h3z"/></svg>
        </a>
        <a href="sms:?body=Check%20out%20PTP%20Soccer!%20Use%20my%20code%20<?php echo $ref_code; ?>%20for%20$25%20off" class="ptp-share-btn" title="Share via Text">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>
        </a>
    </div>
</div>
</div>
<?php get_footer(); ?>
