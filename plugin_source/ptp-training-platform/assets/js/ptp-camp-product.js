/**
 * PTP Camp Product Template - JavaScript
 */
(function(){
  'use strict';
  
  document.addEventListener('DOMContentLoaded', function(){
    var wrapper = document.getElementById('ptp-camp-wrapper');
    if (!wrapper) return;
    
    var basePrice = parseFloat(wrapper.dataset.basePrice) || 399;
    var selectedWeeks = [];
    
    // HERO VIDEO SOUND TOGGLE
    var heroVideo = document.getElementById('heroVideo');
    var heroSoundToggle = document.getElementById('heroSoundToggle');
    if (heroVideo && heroSoundToggle) {
      heroVideo.muted = true;
      heroVideo.play().catch(function(){});
      heroSoundToggle.addEventListener('click', function(e){
        e.preventDefault();
        heroVideo.muted = !heroVideo.muted;
        this.classList.toggle('unmuted', !heroVideo.muted);
      });
    }
    
    // VIDEO REELS PLAY/PAUSE
    var currentlyPlaying = null;
    document.querySelectorAll('.reel-play').forEach(function(btn){
      btn.addEventListener('click', function(e){
        e.preventDefault();
        var videoId = this.dataset.videoId;
        var video = document.getElementById(videoId);
        var reel = this.closest('.reel');
        
        if (reel.classList.contains('playing')) {
          video.pause();
          reel.classList.remove('playing');
          currentlyPlaying = null;
        } else {
          if (currentlyPlaying && currentlyPlaying !== video) {
            currentlyPlaying.pause();
            currentlyPlaying.closest('.reel-wrap').parentElement.classList.remove('playing');
          }
          video.play().catch(function(){});
          reel.classList.add('playing');
          currentlyPlaying = video;
        }
      });
    });
    
    // FAQ ACCORDION
    var faqList = document.getElementById('faqList');
    if (faqList) {
      faqList.addEventListener('click', function(e){
        var btn = e.target.closest('.faq-q');
        if (!btn) return;
        
        var item = btn.closest('.faq-item');
        var wasOpen = item.classList.contains('open');
        
        faqList.querySelectorAll('.faq-item').forEach(function(i){
          i.classList.remove('open');
          var q = i.querySelector('.faq-q');
          if (q) q.setAttribute('aria-expanded', 'false');
        });
        
        if (!wasOpen) {
          item.classList.add('open');
          btn.setAttribute('aria-expanded', 'true');
        }
      });
    }
    
    // MULTI-WEEK SELECTION & PRICING
    var weekSelector = document.getElementById('weekSelector');
    if (weekSelector) {
      var checkboxes = weekSelector.querySelectorAll('input[name="selected_weeks[]"]');
      var weekCountEl = document.getElementById('weekCount');
      var totalPriceEl = document.getElementById('totalPrice');
      var stickyPriceEl = document.getElementById('stickyPrice');
      
      function updateSelection() {
        selectedWeeks = [];
        var subtotal = 0;
        checkboxes.forEach(function(cb){
          if (cb.checked) {
            selectedWeeks.push(cb.value);
            subtotal += parseFloat(cb.dataset.price) || basePrice;
          }
        });
        
        var count = selectedWeeks.length || 1;
        // 2 weeks = 10% off, 3+ weeks = 20% off
        var discountPercent = count >= 3 ? 0.20 : (count >= 2 ? 0.10 : 0);
        var total = Math.round(subtotal * (1 - discountPercent));
        
        if (weekCountEl) weekCountEl.textContent = count;
        if (totalPriceEl) totalPriceEl.textContent = total.toLocaleString();
        if (stickyPriceEl) stickyPriceEl.textContent = '$' + total.toLocaleString();
      }
      
      checkboxes.forEach(function(cb){ 
        cb.addEventListener('change', updateSelection); 
      });
      updateSelection();
    }
    
    // STICKY CTA BAR (mobile only)
    var hero = document.querySelector('.hero-two-col');
    var stickyFooter = document.getElementById('stickyCta');
    
    function updateSticky() {
      if (!stickyFooter || !hero) return;
      if (window.innerWidth >= 768) {
        stickyFooter.classList.remove('visible');
        return;
      }
      var heroBottom = hero.getBoundingClientRect().bottom;
      stickyFooter.classList.toggle('visible', heroBottom < 50);
    }
    
    window.addEventListener('scroll', updateSticky, {passive: true});
    window.addEventListener('resize', updateSticky);
    updateSticky();
    
    // CTA BUTTONS - Scroll to pricing
    function goToCheckout(e) {
      if (e) e.preventDefault();
      var pricingSection = document.getElementById('pricing');
      if (pricingSection) {
        var headerHeight = 56 + 60;
        var targetPosition = pricingSection.getBoundingClientRect().top + window.pageYOffset - headerHeight;
        window.scrollTo({ top: targetPosition, behavior: 'smooth' });
      }
    }
    
    ['heroCta', 'finalCta', 'stickyBtn'].forEach(function(id){
      var btn = document.getElementById(id);
      if (btn) btn.addEventListener('click', goToCheckout);
    });
    
    // CHECKOUT BUTTON - Redirect with selected camps
    var checkoutBtn = document.getElementById('checkoutBtn');
    if (checkoutBtn) {
      checkoutBtn.addEventListener('click', function(e){
        e.preventDefault();
        if (selectedWeeks.length === 0) {
          alert('Please select at least one camp week.');
          return;
        }
        // Get checkout URL from data attribute or default
        var checkoutUrl = wrapper.dataset.checkoutUrl || '/camp-checkout/';
        window.location.href = checkoutUrl + '?camps=' + selectedWeeks.join(',');
      });
    }
    
    // SMOOTH SCROLL for anchor links
    document.querySelectorAll('a[href^="#"]').forEach(function(anchor){
      anchor.addEventListener('click', function(e){
        e.preventDefault();
        var targetId = this.getAttribute('href');
        var target = document.querySelector(targetId);
        if (target) {
          var headerHeight = 56 + 60;
          var targetPosition = target.getBoundingClientRect().top + window.pageYOffset - headerHeight;
          window.scrollTo({ top: targetPosition, behavior: 'smooth' });
        }
      });
    });
    
  });
})();
