/**
 * PTP V35 Unified JavaScript
 * Handles animations, sticky elements, and scroll interactions
 */
(function() {
    'use strict';
    
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
    
    function init() {
        initScrollAnimations();
        initStickyElements();
        initSmoothScroll();
        initVideoAutoplay();
    }
    
    function initScrollAnimations() {
        var anims = document.querySelectorAll('.v35-anim');
        if (!anims.length) return;
        
        var observer = new IntersectionObserver(function(entries) {
            entries.forEach(function(entry) {
                if (entry.isIntersecting) {
                    entry.target.classList.add('in');
                    observer.unobserve(entry.target);
                }
            });
        }, { threshold: 0.1, rootMargin: '0px 0px -20px 0px' });
        
        anims.forEach(function(el) { observer.observe(el); });
    }
    
    function initStickyElements() {
        var sticky = document.querySelector('.v35-sticky');
        var fab = document.querySelector('.v35-fab');
        if (!sticky && !fab) return;
        
        var ticking = false;
        function onScroll() {
            var scrollY = window.scrollY;
            var viewportHeight = window.innerHeight;
            var show = scrollY > viewportHeight * 0.5;
            var isDesktop = window.innerWidth >= 600;
            
            if (isDesktop) {
                if (sticky) sticky.classList.remove('show');
                if (fab) fab.classList.toggle('show', show);
            } else {
                if (sticky) sticky.classList.toggle('show', show);
                if (fab) fab.classList.remove('show');
            }
            ticking = false;
        }
        
        window.addEventListener('scroll', function() {
            if (!ticking) { requestAnimationFrame(onScroll); ticking = true; }
        }, { passive: true });
        
        onScroll();
    }
    
    function initSmoothScroll() {
        document.querySelectorAll('a[href^="#"]').forEach(function(anchor) {
            anchor.addEventListener('click', function(e) {
                var targetId = this.getAttribute('href');
                if (targetId === '#') return;
                var target = document.querySelector(targetId);
                if (target) {
                    e.preventDefault();
                    target.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            });
        });
    }
    
    function initVideoAutoplay() {
        var videos = document.querySelectorAll('video[autoplay]');
        if (!videos.length) return;
        
        var observer = new IntersectionObserver(function(entries) {
            entries.forEach(function(entry) {
                var video = entry.target;
                if (entry.isIntersecting) {
                    video.play().catch(function() {});
                } else {
                    video.pause();
                }
            });
        }, { threshold: 0.25 });
        
        videos.forEach(function(video) { observer.observe(video); });
    }
    
    window.PTPV35 = { initAnimations: initScrollAnimations, initSticky: initStickyElements };
})();
