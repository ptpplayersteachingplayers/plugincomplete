/**
 * PTP Scroll Fix v135.2
 * 
 * JavaScript that forces scroll to work.
 * 
 * ROOT CAUSE: scroll-behavior: smooth + hidden tab state
 * When document.visibilityState === "hidden", browsers skip smooth scroll
 * animations and the scroll simply doesn't happen.
 * 
 * FIX: Always use behavior: 'instant' or set scrollTop directly
 * 
 * v135.2: More aggressive desktop scroll enforcement
 */

(function() {
    'use strict';
    
    // v135.2: IMMEDIATE desktop scroll fix - runs before anything else
    var isDesktop = window.innerWidth >= 1025;
    if (isDesktop) {
        document.documentElement.style.cssText += 'overflow-y: scroll !important; overflow-x: hidden !important; height: auto !important; position: static !important;';
        if (document.body) {
            document.body.style.cssText += 'overflow-y: auto !important; overflow-x: hidden !important; height: auto !important; position: static !important;';
        }
    }
    
    // Classes that commonly block scroll (including Astra + Flavor specific)
    var blockingClasses = [
        // Generic
        'no-scroll',
        'modal-open', 
        'overflow-hidden',
        'menu-open',
        'ptp-modal-open',
        'fixed-body',
        'body-fixed',
        'scroll-lock',
        'scroll-locked',
        'noscroll',
        // Astra specific
        'ast-main-header-nav-open',
        'ast-mobile-popup-open',
        'ast-header-break-point-active',
        // Flavor specific
        'flavor-menu-open',
        'flavor-nav-open',
        'flavor-modal-open'
    ];
    
    /**
     * Fix scroll-behavior CSS - this is the main culprit
     */
    function fixScrollBehavior() {
        var html = document.documentElement;
        
        // Remove scroll-behavior: smooth which breaks when tab is hidden
        html.style.setProperty('scroll-behavior', 'auto', 'important');
        
        // Also remove from body just in case
        if (document.body) {
            document.body.style.setProperty('scroll-behavior', 'auto', 'important');
        }
    }
    
    /**
     * Safe scroll function that works even when tab is hidden
     */
    function safeScrollTo(x, y) {
        // Method 1: Set scrollTop directly (most reliable)
        document.documentElement.scrollTop = y;
        document.body.scrollTop = y; // For Safari
        
        // Method 2: Use behavior: 'instant' as fallback
        try {
            window.scrollTo({ top: y, left: x, behavior: 'instant' });
        } catch(e) {
            window.scrollTo(x, y);
        }
    }
    
    /**
     * Force scroll to work - AGGRESSIVE VERSION
     */
    function forceScroll() {
        var html = document.documentElement;
        var body = document.body;
        
        // CRITICAL: Fix scroll-behavior first
        fixScrollBehavior();
        
        // Remove blocking classes from both html and body
        blockingClasses.forEach(function(cls) {
            html.classList.remove(cls);
            body.classList.remove(cls);
        });
        
        // DESKTOP ONLY: Remove menu-open class if no mobile menu is actually visible
        if (window.innerWidth >= 1025) {
            var mobileMenuVisible = document.querySelector('.ptp-mobile-nav.open, .ptp-mobile-menu.open, .ast-mobile-popup-drawer.active');
            if (!mobileMenuVisible) {
                body.classList.remove('menu-open');
                body.classList.remove('ast-main-header-nav-open');
                body.classList.remove('ast-mobile-popup-open');
            }
        }
        
        // Force styles on html
        html.style.setProperty('overflow-y', 'scroll', 'important');
        html.style.setProperty('overflow-x', 'hidden', 'important');
        html.style.setProperty('height', 'auto', 'important');
        html.style.setProperty('position', 'static', 'important');
        html.style.setProperty('scroll-behavior', 'auto', 'important');
        
        // Force styles on body
        body.style.setProperty('overflow-y', 'auto', 'important');
        body.style.setProperty('overflow-x', 'hidden', 'important');
        body.style.setProperty('overflow', 'visible', 'important'); // Reset shorthand too
        body.style.setProperty('height', 'auto', 'important');
        body.style.setProperty('position', 'static', 'important');
        body.style.setProperty('top', 'auto', 'important');
        body.style.setProperty('left', 'auto', 'important');
        body.style.setProperty('right', 'auto', 'important');
        body.style.setProperty('width', '100%', 'important');
        body.style.setProperty('scroll-behavior', 'auto', 'important');
        
        // Fix Astra's #page container if it exists
        var page = document.getElementById('page');
        if (page) {
            page.style.setProperty('overflow', 'visible', 'important');
            page.style.setProperty('height', 'auto', 'important');
            page.style.setProperty('position', 'static', 'important');
        }
        
        // Fix Astra's site-content
        var siteContent = document.querySelector('.site-content');
        if (siteContent) {
            siteContent.style.setProperty('overflow', 'visible', 'important');
        }
        
        // Fix Astra's ast-container
        var astContainers = document.querySelectorAll('.ast-container, .ast-separate-container');
        astContainers.forEach(function(el) {
            el.style.setProperty('overflow', 'visible', 'important');
        });
        
        // Fix Flavor theme wrappers - THIS IS CRITICAL
        var flavorWrappers = document.querySelectorAll('.flavor-wrapper, #flavor-wrapper, .flavor-content');
        flavorWrappers.forEach(function(el) {
            el.style.setProperty('overflow', 'visible', 'important');
            el.style.setProperty('overflow-y', 'visible', 'important');
            el.style.setProperty('height', 'auto', 'important');
            el.style.setProperty('max-height', 'none', 'important');
            el.style.setProperty('min-height', '100vh', 'important');
            el.style.setProperty('position', 'relative', 'important');
            el.style.setProperty('transform', 'none', 'important');
        });
        
        // DESKTOP: Also check for any element that might be blocking scroll
        if (window.innerWidth >= 1025) {
            // Check for any direct children of body with height:100vh and overflow:hidden
            var bodyChildren = body.children;
            for (var i = 0; i < bodyChildren.length; i++) {
                var child = bodyChildren[i];
                if (child.tagName !== 'SCRIPT' && child.tagName !== 'STYLE') {
                    var computed = window.getComputedStyle(child);
                    // If a wrapper has height 100vh and overflow hidden, fix it
                    if ((computed.height === window.innerHeight + 'px' || computed.minHeight === '100vh') && 
                        (computed.overflow === 'hidden' || computed.overflowY === 'hidden')) {
                        child.style.setProperty('overflow', 'visible', 'important');
                        child.style.setProperty('overflow-y', 'visible', 'important');
                        child.style.setProperty('height', 'auto', 'important');
                        console.log('[PTP] Fixed scroll-blocking wrapper:', child.className || child.id);
                    }
                }
            }
        }
    }
    
    // Run immediately
    fixScrollBehavior();
    forceScroll();
    
    // Run on DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() {
            fixScrollBehavior();
            forceScroll();
        });
    }
    
    // Run on window load
    window.addEventListener('load', function() {
        fixScrollBehavior();
        forceScroll();
        setTimeout(forceScroll, 100);
        setTimeout(forceScroll, 300);
        setTimeout(forceScroll, 500);
        setTimeout(forceScroll, 1000);
    });
    
    // Run periodically for first 5 seconds
    var checks = 0;
    var interval = setInterval(function() {
        fixScrollBehavior();
        forceScroll();
        checks++;
        if (checks >= 10) {
            clearInterval(interval);
        }
    }, 500);
    
    // DESKTOP SPECIFIC: More aggressive monitoring
    // On desktop, keep checking scroll state periodically
    if (window.innerWidth >= 1025) {
        // Check every 2 seconds for first 30 seconds (in case late-loading scripts block scroll)
        var desktopChecks = 0;
        var desktopInterval = setInterval(function() {
            var computed = window.getComputedStyle(document.body);
            var hasOpenModal = document.querySelector('.ptp-modal-overlay.open, .ptp-bottom-sheet-overlay.open, .modal.show, .ptp-mini-cart.active, [class*="modal"].open');
            var hasOpenMenu = document.body.classList.contains('menu-open') || 
                             document.body.classList.contains('ast-main-header-nav-open');
            
            // If scroll is blocked but nothing is open, fix it
            if ((computed.overflowY === 'hidden' || computed.overflow === 'hidden') && !hasOpenModal && !hasOpenMenu) {
                console.log('[PTP] Desktop scroll still blocked - forcing');
                forceScroll();
            }
            
            // v135: ALWAYS force overflow-y: auto on desktop body regardless
            document.body.style.setProperty('overflow-y', 'auto', 'important');
            document.documentElement.style.setProperty('overflow-y', 'scroll', 'important');
            
            desktopChecks++;
            if (desktopChecks >= 15) {
                clearInterval(desktopInterval);
            }
        }, 2000);
        
        // v135: Continuous desktop scroll enforcement - run every 5 seconds forever
        setInterval(function() {
            if (window.innerWidth >= 1025) {
                var hasOpenModal = document.querySelector('.ptp-modal-overlay.open, .ptp-bottom-sheet-overlay.open, .modal.show, .ptp-mini-cart.active');
                if (!hasOpenModal) {
                    document.body.style.setProperty('overflow-y', 'auto', 'important');
                    document.documentElement.style.setProperty('overflow-y', 'scroll', 'important');
                }
            }
        }, 5000);
        
        // Also run on resize (in case switching from mobile layout)
        window.addEventListener('resize', function() {
            if (window.innerWidth >= 1025) {
                setTimeout(forceScroll, 100);
            }
        });
    }
    
    // Watch for style changes that might re-add scroll-behavior: smooth OR overflow: hidden
    if (typeof MutationObserver !== 'undefined') {
        var observer = new MutationObserver(function(mutations) {
            mutations.forEach(function(mutation) {
                if (mutation.attributeName === 'style' || mutation.attributeName === 'class') {
                    var target = mutation.target;
                    
                    // Check if scroll-behavior was changed back to smooth
                    var computedStyle = window.getComputedStyle(document.documentElement);
                    if (computedStyle.scrollBehavior === 'smooth') {
                        fixScrollBehavior();
                    }
                    
                    // CRITICAL: Check if body overflow was set to hidden (by modals, menus, etc.)
                    if (target === document.body || target === document.documentElement) {
                        var style = target.style;
                        var computed = window.getComputedStyle(target);
                        
                        // If overflow-y is hidden and no modal is actually open, force it back
                        if (computed.overflowY === 'hidden' || style.overflow === 'hidden' || style.overflowY === 'hidden') {
                            // Check if a modal is actually open - if not, force scroll back
                            var hasOpenModal = document.querySelector('.ptp-modal-overlay.open, .ptp-bottom-sheet-overlay.open, .modal.show, .ptp-mini-cart.active, [class*="modal"].open');
                            var hasOpenMenu = document.body.classList.contains('menu-open') || 
                                             document.body.classList.contains('ast-main-header-nav-open') ||
                                             document.body.classList.contains('ast-mobile-popup-open');
                            
                            if (!hasOpenModal && !hasOpenMenu) {
                                // No modal open, but overflow is hidden - something went wrong
                                console.log('[PTP] Scroll blocked without open modal - forcing scroll back');
                                forceScroll();
                            }
                        }
                    }
                }
            });
        });
        
        // Observe both html and body for changes
        observer.observe(document.documentElement, { attributes: true, attributeFilter: ['style', 'class'] });
        if (document.body) {
            observer.observe(document.body, { attributes: true, attributeFilter: ['style', 'class'] });
        }
    }
    
    // Override native scrollTo to always use instant behavior
    var originalScrollTo = window.scrollTo;
    window.scrollTo = function(x, y) {
        if (typeof x === 'object') {
            // Called with options object
            x.behavior = 'instant';
            return originalScrollTo.call(window, x);
        } else {
            // Called with x, y coordinates
            return originalScrollTo.call(window, { top: y, left: x, behavior: 'instant' });
        }
    };
    
    // Also override scroll() which is an alias
    window.scroll = window.scrollTo;
    
    // Override scrollBy too
    var originalScrollBy = window.scrollBy;
    window.scrollBy = function(x, y) {
        if (typeof x === 'object') {
            x.behavior = 'instant';
            return originalScrollBy.call(window, x);
        } else {
            return originalScrollBy.call(window, { top: y, left: x, behavior: 'instant' });
        }
    };
    
    // Expose utilities globally
    window.ptpForceScroll = forceScroll;
    window.ptpSafeScrollTo = safeScrollTo;
    window.ptpFixScrollBehavior = fixScrollBehavior;
    
    // Diagnostic function - call from console: ptpScrollDiag()
    window.ptpScrollDiag = function() {
        console.log('=== PTP SCROLL DIAGNOSTIC ===');
        console.log('Window width:', window.innerWidth);
        console.log('Is desktop:', window.innerWidth >= 1025);
        
        var html = document.documentElement;
        var body = document.body;
        
        console.log('\n--- HTML Element ---');
        console.log('Classes:', html.className);
        console.log('Style overflow:', html.style.overflow);
        console.log('Style overflow-y:', html.style.overflowY);
        console.log('Computed overflow:', window.getComputedStyle(html).overflow);
        console.log('Computed overflow-y:', window.getComputedStyle(html).overflowY);
        
        console.log('\n--- BODY Element ---');
        console.log('Classes:', body.className);
        console.log('Style overflow:', body.style.overflow);
        console.log('Style overflow-y:', body.style.overflowY);
        console.log('Computed overflow:', window.getComputedStyle(body).overflow);
        console.log('Computed overflow-y:', window.getComputedStyle(body).overflowY);
        console.log('Computed height:', window.getComputedStyle(body).height);
        console.log('Computed position:', window.getComputedStyle(body).position);
        
        console.log('\n--- Potential Blockers ---');
        var blockers = [];
        var allElements = document.querySelectorAll('body > *:not(script):not(style)');
        allElements.forEach(function(el) {
            var computed = window.getComputedStyle(el);
            if (computed.overflow === 'hidden' || computed.overflowY === 'hidden') {
                blockers.push({
                    element: el,
                    tag: el.tagName,
                    id: el.id,
                    class: el.className,
                    overflow: computed.overflow,
                    overflowY: computed.overflowY,
                    height: computed.height
                });
            }
        });
        
        if (blockers.length) {
            console.log('Found potential scroll blockers:');
            blockers.forEach(function(b) {
                console.log('  -', b.tag, b.id || b.class, '| overflow:', b.overflow, '| height:', b.height);
            });
        } else {
            console.log('No obvious blockers found in direct body children');
        }
        
        console.log('\n--- Document Scroll Info ---');
        console.log('documentElement.scrollHeight:', html.scrollHeight);
        console.log('body.scrollHeight:', body.scrollHeight);
        console.log('window.innerHeight:', window.innerHeight);
        console.log('Can scroll:', html.scrollHeight > window.innerHeight);
        
        console.log('\n--- Fix Status ---');
        console.log('To force scroll: ptpForceScroll()');
        console.log('=============================');
    };
    
    // Log that we're active
    console.log('[PTP] Scroll fix v135.2 loaded - scroll-behavior: smooth disabled, desktop scroll enforced');
    
})();
