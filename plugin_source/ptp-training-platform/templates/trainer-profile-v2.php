<?php
/**
 * Trainer Profile v85.4 - Full data, site header, Google Maps
 */
defined('ABSPATH') || exit;

global $wpdb;
$google_maps_key = get_option('ptp_google_maps_api_key', '');

// Get trainer
$slug = get_query_var('trainer_slug');
if (!$slug) {
    $uri = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
    $parts = explode('/', $uri);
    $slug = end($parts);
}

$trainer = $wpdb->get_row($wpdb->prepare(
    "SELECT * FROM {$wpdb->prefix}ptp_trainers WHERE slug = %s AND status = 'active'",
    sanitize_title($slug)
));

if (!$trainer) {
    wp_redirect(home_url('/find-trainers/'));
    exit;
}

// Get reviews with parent/player names
$reviews = $wpdb->get_results($wpdb->prepare(
    "SELECT r.*, 
            COALESCE(u.display_name, 'Parent') as reviewer_name
     FROM {$wpdb->prefix}ptp_reviews r
     LEFT JOIN {$wpdb->users} u ON r.user_id = u.ID
     WHERE r.trainer_id = %d 
     ORDER BY r.created_at DESC 
     LIMIT 20",
    $trainer->id
));

// Stats
$avg_rating = floatval($trainer->average_rating ?: 5.0);
$review_count = intval($trainer->review_count ?: count($reviews));
$total_sessions = intval($trainer->total_sessions ?: 0);
$response_rate = intval($trainer->responsiveness_score ?: 100);
$return_rate = intval($trainer->return_rate ?: 0);

// Labels
$level_labels = array('pro'=>'MLS PRO','college_d1'=>'NCAA D1','college_d2'=>'NCAA D2','college_d3'=>'NCAA D3','academy'=>'ACADEMY','semi_pro'=>'SEMI-PRO');
$level = $level_labels[$trainer->playing_level] ?? strtoupper($trainer->playing_level ?: 'PRO');

// Data
$rate = intval($trainer->hourly_rate ?: 60);
$photo = $trainer->photo_url ?: 'https://ui-avatars.com/api/?name=' . urlencode($trainer->display_name) . '&size=400&background=FCB900&color=0A0A0A&bold=true';
$bio = $trainer->bio ?: 'Professional soccer trainer dedicated to helping players reach their full potential.';
$headline = $trainer->headline ?: '';
$specialties = $trainer->specialties ? array_filter(array_map('trim', explode(',', $trainer->specialties))) : array();
$city = $trainer->city ?: '';
$state = $trainer->state ?: '';
$location = trim($city . ($city && $state ? ', ' : '') . $state) ?: 'Philadelphia Area';
$lat = floatval($trainer->latitude ?: 39.9526);
$lng = floatval($trainer->longitude ?: -75.1652);
$travel_radius = intval($trainer->travel_radius ?: 15);
$college = $trainer->college ?: '';
$team = $trainer->team ?: '';
$position = $trainer->position ?: '';
$experience = intval($trainer->experience_years ?: 0);
$instagram = $trainer->instagram ?: '';
$intro_video = $trainer->intro_video_url ?: '';
$gallery = $trainer->gallery ? json_decode($trainer->gallery, true) : array();
$training_locations = $trainer->training_locations ? json_decode($trainer->training_locations, true) : array();
$is_verified = intval($trainer->is_verified ?: 0);
$is_featured = intval($trainer->is_featured ?: 0);
$is_supercoach = intval($trainer->is_supercoach ?: 0);
$safesport = intval($trainer->safesport_verified ?: 0);
$background_check = intval($trainer->background_verified ?: 0);

// Available times (mock for now - would come from availability table)
$available_slots = array(
    array('day' => 'Tomorrow', 'time' => '4:00 PM'),
    array('day' => 'Thursday', 'time' => '5:30 PM'),
    array('day' => 'Saturday', 'time' => '10:00 AM'),
    array('day' => 'Sunday', 'time' => '2:00 PM')
);

$checkout_url = home_url('/ptp-checkout/') . '?trainer_id=' . $trainer->id;
$first_name = explode(' ', $trainer->display_name)[0];

get_header();
?>
<style>
.ptp-profile{--gold:#FCB900;--black:#0A0A0A;--gray:#F5F5F5;--gray-dark:#525252;--green:#22C55E;--radius:16px;font-family:Inter,-apple-system,sans-serif;background:var(--gray);min-height:80vh}
.ptp-profile h1,.ptp-profile h2,.ptp-profile h3{font-family:Oswald,sans-serif;font-weight:700;text-transform:uppercase;line-height:1.1;margin:0}
.ptp-profile a{color:inherit;text-decoration:none}
.ptp-back{display:inline-flex;align-items:center;gap:6px;padding:16px 20px;color:var(--gray-dark);font-size:13px;font-weight:500}
.ptp-back:hover{color:var(--black)}
.ptp-back svg{width:18px;height:18px}
.ptp-hero{background:var(--black);padding:0 20px 100px;text-align:center}
.ptp-hero-photo{width:140px;height:140px;border-radius:50%;border:4px solid var(--gold);overflow:hidden;margin:0 auto 16px;box-shadow:0 12px 40px rgba(0,0,0,.4)}
.ptp-hero-photo img{width:100%;height:100%;object-fit:cover}
.ptp-hero-badges{display:flex;justify-content:center;gap:8px;margin-bottom:12px}
.ptp-badge{display:inline-flex;align-items:center;gap:5px;padding:6px 12px;border-radius:100px;font-size:11px;font-weight:600;text-transform:uppercase}
.ptp-badge-gold{background:var(--gold);color:var(--black)}
.ptp-badge-green{background:rgba(34,197,94,.15);color:var(--green)}
.ptp-badge-outline{background:transparent;border:1px solid rgba(255,255,255,.3);color:#fff}
.ptp-badge svg{width:12px;height:12px}
.ptp-hero-name{font-size:clamp(28px,6vw,40px);color:#fff;margin-bottom:4px}
.ptp-hero-headline{color:rgba(255,255,255,.6);font-size:14px;margin-bottom:6px}
.ptp-hero-loc{color:rgba(255,255,255,.5);font-size:13px;display:flex;align-items:center;justify-content:center;gap:6px;margin-bottom:20px}
.ptp-hero-stats{display:flex;justify-content:center;gap:28px;flex-wrap:wrap}
.ptp-stat{text-align:center}
.ptp-stat-value{font-family:Oswald,sans-serif;font-size:26px;font-weight:700;color:var(--gold);line-height:1}
.ptp-stat-label{font-size:10px;text-transform:uppercase;letter-spacing:.08em;color:rgba(255,255,255,.4);margin-top:4px}
.ptp-main{max-width:1000px;margin:-60px auto 0;padding:0 20px 60px;position:relative}
.ptp-grid{display:grid;grid-template-columns:1fr;gap:20px}
@media(min-width:768px){.ptp-grid{grid-template-columns:1.4fr 1fr}}
.ptp-card{background:#fff;border-radius:var(--radius);padding:24px;box-shadow:0 4px 20px rgba(0,0,0,.06)}
.ptp-card-title{font-size:14px;margin-bottom:16px;padding-bottom:12px;border-bottom:2px solid var(--gray);display:flex;align-items:center;gap:8px}
.ptp-card-title svg{width:18px;height:18px;stroke:var(--gold)}
.ptp-bio{font-size:14px;color:var(--gray-dark);line-height:1.8}
.ptp-video{margin-top:16px;border-radius:12px;overflow:hidden;aspect-ratio:16/9;background:#000}
.ptp-video iframe{width:100%;height:100%;border:none}
.ptp-specs{display:flex;flex-wrap:wrap;gap:8px;margin-top:16px}
.ptp-spec{background:var(--gray);color:var(--gray-dark);font-size:12px;font-weight:500;padding:8px 14px;border-radius:100px}
.ptp-details{margin-top:16px}
.ptp-detail{display:flex;justify-content:space-between;padding:10px 0;border-bottom:1px solid #f0f0f0;font-size:13px}
.ptp-detail:last-child{border-bottom:none}
.ptp-detail-label{color:var(--gray-dark)}
.ptp-detail-value{font-weight:500}
.ptp-socials{display:flex;gap:10px;margin-top:16px}
.ptp-social{width:40px;height:40px;background:var(--gray);border-radius:10px;display:flex;align-items:center;justify-content:center;transition:.2s}
.ptp-social:hover{background:var(--gold)}
.ptp-social svg{width:18px;height:18px}
.ptp-map{height:180px;border-radius:12px;overflow:hidden;margin-top:16px;background:#e5e5e5}
#trainerMap{width:100%;height:100%}
.ptp-locations{margin-top:16px}
.ptp-location{display:flex;align-items:center;gap:10px;padding:10px;background:var(--gray);border-radius:10px;margin-bottom:8px;font-size:13px}
.ptp-location svg{width:16px;height:16px;stroke:var(--gold);flex-shrink:0}
.ptp-review{padding:16px 0;border-bottom:1px solid #f0f0f0}
.ptp-review:last-child{border-bottom:none}
.ptp-review-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:8px}
.ptp-review-author{font-weight:600;font-size:14px}
.ptp-review-stars{color:var(--gold);font-size:13px}
.ptp-review-text{font-size:13px;color:var(--gray-dark);line-height:1.7}
.ptp-review-date{font-size:11px;color:#999;margin-top:6px}
.ptp-empty{text-align:center;padding:24px;color:var(--gray-dark);font-size:13px}
.ptp-book{position:sticky;top:100px}
.ptp-book-price{text-align:center;margin-bottom:20px}
.ptp-book-rate{font-family:Oswald,sans-serif;font-size:38px;font-weight:700}
.ptp-book-rate small{font-size:16px;color:var(--gray-dark);font-weight:400}
.ptp-book-save{font-size:12px;color:var(--green);margin-top:4px}
.ptp-slots{margin-bottom:20px}
.ptp-slots-title{font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.1em;color:var(--gray-dark);margin-bottom:10px}
.ptp-slots-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px}
.ptp-slot{padding:12px;background:var(--gray);border:2px solid transparent;border-radius:10px;text-align:center;cursor:pointer;transition:.2s}
.ptp-slot:hover,.ptp-slot.active{border-color:var(--gold);background:#fff}
.ptp-slot-day{font-family:Oswald,sans-serif;font-size:12px;font-weight:600;text-transform:uppercase}
.ptp-slot-time{font-size:11px;color:var(--gray-dark)}
.ptp-book-btn{display:block;width:100%;padding:18px;background:var(--gold);color:var(--black);font-family:Oswald,sans-serif;font-size:15px;font-weight:600;text-transform:uppercase;text-align:center;border:none;cursor:pointer;transition:.2s;border-radius:12px}
.ptp-book-btn:hover{background:#E5A800;transform:translateY(-2px);box-shadow:0 8px 24px rgba(252,185,0,.35)}
.ptp-book-note{text-align:center;font-size:11px;color:var(--gray-dark);margin-top:12px}
.ptp-book-note svg{width:14px;height:14px;vertical-align:middle;margin-right:4px}
.ptp-trust{display:flex;flex-wrap:wrap;gap:8px;margin-top:16px;padding-top:16px;border-top:1px solid #f0f0f0}
.ptp-trust-item{display:flex;align-items:center;gap:6px;font-size:11px;color:var(--gray-dark)}
.ptp-trust-item svg{width:14px;height:14px;stroke:var(--green)}
.ptp-sticky{position:fixed;bottom:0;left:0;right:0;z-index:999;background:var(--black);padding:12px 20px;padding-bottom:calc(12px + env(safe-area-inset-bottom,0px));display:flex;align-items:center;justify-content:space-between;gap:14px;border-top:3px solid var(--gold)}
@media(min-width:768px){.ptp-sticky{display:none}}
.ptp-sticky-info{color:#fff}
.ptp-sticky-name{font-family:Oswald,sans-serif;font-size:14px;font-weight:600;text-transform:uppercase}
.ptp-sticky-rate{font-size:12px;color:rgba(255,255,255,.6)}
.ptp-sticky-btn{background:var(--gold);color:var(--black);font-family:Oswald,sans-serif;font-size:13px;font-weight:600;text-transform:uppercase;padding:14px 24px;border-radius:10px}
</style>

<div class="ptp-profile">
<a href="<?php echo home_url('/find-trainers/'); ?>" class="ptp-back">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
    Back to Trainers
</a>

<section class="ptp-hero">
    <div class="ptp-hero-photo"><img src="<?php echo esc_url($photo); ?>" alt="<?php echo esc_attr($trainer->display_name); ?>"></div>
    
    <div class="ptp-hero-badges">
        <span class="ptp-badge ptp-badge-gold"><?php echo esc_html($level); ?></span>
        <?php if($is_verified || $safesport): ?><span class="ptp-badge ptp-badge-green"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>Verified</span><?php endif; ?>
        <?php if($is_supercoach): ?><span class="ptp-badge ptp-badge-outline">⭐ Supercoach</span><?php endif; ?>
        <?php if($is_featured): ?><span class="ptp-badge ptp-badge-outline">Featured</span><?php endif; ?>
    </div>
    
    <h1 class="ptp-hero-name"><?php echo esc_html($trainer->display_name); ?></h1>
    <?php if($headline): ?><p class="ptp-hero-headline"><?php echo esc_html($headline); ?></p><?php endif; ?>
    <p class="ptp-hero-loc"><svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg><?php echo esc_html($location); ?></p>
    
    <div class="ptp-hero-stats">
        <div class="ptp-stat"><div class="ptp-stat-value">$<?php echo $rate; ?></div><div class="ptp-stat-label">Per Hour</div></div>
        <div class="ptp-stat"><div class="ptp-stat-value">★ <?php echo number_format($avg_rating, 1); ?></div><div class="ptp-stat-label"><?php echo $review_count; ?> Reviews</div></div>
        <div class="ptp-stat"><div class="ptp-stat-value"><?php echo $total_sessions; ?>+</div><div class="ptp-stat-label">Sessions</div></div>
        <div class="ptp-stat"><div class="ptp-stat-value"><?php echo $response_rate; ?>%</div><div class="ptp-stat-label">Response</div></div>
        <?php if($return_rate > 0): ?><div class="ptp-stat"><div class="ptp-stat-value"><?php echo $return_rate; ?>%</div><div class="ptp-stat-label">Return Rate</div></div><?php endif; ?>
    </div>
</section>

<main class="ptp-main">
    <div class="ptp-grid">
        <div>
            <!-- About -->
            <div class="ptp-card">
                <h3 class="ptp-card-title"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>ABOUT <?php echo esc_html(strtoupper($first_name)); ?></h3>
                <p class="ptp-bio"><?php echo nl2br(esc_html($bio)); ?></p>
                
                <?php if($intro_video): ?>
                <div class="ptp-video">
                    <iframe src="<?php echo esc_url($intro_video); ?>" allowfullscreen loading="lazy"></iframe>
                </div>
                <?php endif; ?>
                
                <?php if(!empty($specialties)): ?>
                <div class="ptp-specs">
                    <?php foreach($specialties as $s): ?><span class="ptp-spec"><?php echo esc_html($s); ?></span><?php endforeach; ?>
                </div>
                <?php endif; ?>
                
                <div class="ptp-details">
                    <?php if($college): ?><div class="ptp-detail"><span class="ptp-detail-label">College</span><span class="ptp-detail-value"><?php echo esc_html($college); ?></span></div><?php endif; ?>
                    <?php if($team): ?><div class="ptp-detail"><span class="ptp-detail-label">Team</span><span class="ptp-detail-value"><?php echo esc_html($team); ?></span></div><?php endif; ?>
                    <?php if($position): ?><div class="ptp-detail"><span class="ptp-detail-label">Position</span><span class="ptp-detail-value"><?php echo esc_html($position); ?></span></div><?php endif; ?>
                    <?php if($experience): ?><div class="ptp-detail"><span class="ptp-detail-label">Experience</span><span class="ptp-detail-value"><?php echo $experience; ?>+ years</span></div><?php endif; ?>
                    <div class="ptp-detail"><span class="ptp-detail-label">Travel Radius</span><span class="ptp-detail-value"><?php echo $travel_radius; ?> miles</span></div>
                </div>
                
                <?php if($instagram): ?>
                <div class="ptp-socials">
                    <a href="https://instagram.com/<?php echo esc_attr($instagram); ?>" target="_blank" class="ptp-social" title="Instagram"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="2" width="20" height="20" rx="5"/><path d="M16 11.37A4 4 0 1 1 12.63 8 4 4 0 0 1 16 11.37z"/><line x1="17.5" y1="6.5" x2="17.51" y2="6.5"/></svg></a>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- Training Area -->
            <?php if($google_maps_key): ?>
            <div class="ptp-card" style="margin-top:20px;">
                <h3 class="ptp-card-title"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>TRAINING AREA</h3>
                <div class="ptp-map" id="trainerMap"></div>
                <?php if(!empty($training_locations)): ?>
                <div class="ptp-locations">
                    <?php foreach($training_locations as $loc): ?>
                    <div class="ptp-location"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg><?php echo esc_html(is_array($loc) ? ($loc['name'] ?? $loc['address'] ?? '') : $loc); ?></div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            
            <!-- Reviews -->
            <div class="ptp-card" style="margin-top:20px;">
                <h3 class="ptp-card-title"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>REVIEWS (<?php echo $review_count; ?>)</h3>
                <?php if(!empty($reviews)): ?>
                    <?php foreach($reviews as $r): ?>
                    <div class="ptp-review">
                        <div class="ptp-review-header">
                            <span class="ptp-review-author"><?php echo esc_html($r->reviewer_name ?: 'Parent'); ?></span>
                            <span class="ptp-review-stars"><?php echo str_repeat('★', intval($r->rating)); ?><?php echo str_repeat('☆', 5 - intval($r->rating)); ?></span>
                        </div>
                        <p class="ptp-review-text"><?php echo esc_html($r->review_text ?: 'Great session!'); ?></p>
                        <p class="ptp-review-date"><?php echo date('M j, Y', strtotime($r->created_at)); ?></p>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p class="ptp-empty">No reviews yet. Be the first!</p>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Booking Sidebar -->
        <div>
            <div class="ptp-card ptp-book">
                <div class="ptp-book-price">
                    <div class="ptp-book-rate">$<?php echo $rate; ?><small>/hr</small></div>
                    <p class="ptp-book-save">💰 Save 15% with 5-pack</p>
                </div>
                
                <div class="ptp-slots">
                    <p class="ptp-slots-title">Next Available</p>
                    <div class="ptp-slots-grid">
                        <?php foreach($available_slots as $i => $slot): ?>
                        <div class="ptp-slot<?php echo $i===0?' active':''; ?>">
                            <div class="ptp-slot-day"><?php echo esc_html($slot['day']); ?></div>
                            <div class="ptp-slot-time"><?php echo esc_html($slot['time']); ?></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <a href="<?php echo esc_url($checkout_url); ?>" class="ptp-book-btn">Book Session →</a>
                <p class="ptp-book-note"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>Free cancellation up to 24hrs</p>
                
                <div class="ptp-trust">
                    <?php if($safesport): ?><span class="ptp-trust-item"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>SafeSport</span><?php endif; ?>
                    <?php if($background_check): ?><span class="ptp-trust-item"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>Background Check</span><?php endif; ?>
                    <span class="ptp-trust-item"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>Secure Payment</span>
                </div>
            </div>
        </div>
    </div>
</main>

<!-- Mobile Sticky -->
<div class="ptp-sticky">
    <div class="ptp-sticky-info">
        <div class="ptp-sticky-name"><?php echo esc_html($trainer->display_name); ?></div>
        <div class="ptp-sticky-rate">$<?php echo $rate; ?>/hr · ★ <?php echo number_format($avg_rating, 1); ?></div>
    </div>
    <a href="<?php echo esc_url($checkout_url); ?>" class="ptp-sticky-btn">Book</a>
</div>
</div>

<?php if($google_maps_key): ?>
<script>
function initTrainerMap(){
    var pos={lat:<?php echo $lat; ?>,lng:<?php echo $lng; ?>};
    var map=new google.maps.Map(document.getElementById('trainerMap'),{zoom:11,center:pos,disableDefaultUI:true,zoomControl:true,styles:[
        {elementType:'geometry',stylers:[{color:'#1a1a1a'}]},
        {elementType:'labels.text.stroke',stylers:[{color:'#0A0A0A'}]},
        {elementType:'labels.text.fill',stylers:[{color:'#9CA3AF'}]},
        {featureType:'administrative',elementType:'geometry',stylers:[{visibility:'off'}]},
        {featureType:'administrative.locality',elementType:'labels.text.fill',stylers:[{color:'#E5E7EB'}]},
        {featureType:'poi',stylers:[{visibility:'off'}]},
        {featureType:'road',elementType:'geometry',stylers:[{color:'#2d2d2d'}]},
        {featureType:'road',elementType:'geometry.stroke',stylers:[{color:'#1a1a1a'}]},
        {featureType:'road',elementType:'labels.text.fill',stylers:[{color:'#9CA3AF'}]},
        {featureType:'road.highway',elementType:'geometry',stylers:[{color:'#3d3d3d'}]},
        {featureType:'road.highway',elementType:'geometry.stroke',stylers:[{color:'#1a1a1a'}]},
        {featureType:'road.highway',elementType:'labels.text.fill',stylers:[{color:'#F3F4F6'}]},
        {featureType:'transit',stylers:[{visibility:'off'}]},
        {featureType:'water',elementType:'geometry',stylers:[{color:'#0d1117'}]},
        {featureType:'water',elementType:'labels.text.fill',stylers:[{color:'#4B5563'}]}
    ]});
    new google.maps.Circle({strokeColor:'#FCB900',strokeOpacity:.8,strokeWeight:2,fillColor:'#FCB900',fillOpacity:.15,map:map,center:pos,radius:<?php echo $travel_radius * 1609; ?>});
    new google.maps.Marker({position:pos,map:map,icon:{path:google.maps.SymbolPath.CIRCLE,fillColor:'#FCB900',fillOpacity:1,strokeColor:'#0A0A0A',strokeWeight:2,scale:10}});
}
if(typeof google!=='undefined'&&google.maps)initTrainerMap();
else{var s=document.createElement('script');s.src='https://maps.googleapis.com/maps/api/js?key=<?php echo esc_attr($google_maps_key); ?>&callback=initTrainerMap';s.async=true;document.head.appendChild(s);}
</script>
<?php endif; ?>
<script>
document.querySelectorAll('.ptp-slot').forEach(function(s){s.addEventListener('click',function(){document.querySelectorAll('.ptp-slot').forEach(function(x){x.classList.remove('active');});s.classList.add('active');});});
</script>
<?php get_footer(); ?>
