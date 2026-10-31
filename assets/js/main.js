// ============================================
// Web3edu — Main JS
// Purple brand theme + Dark/Light toggle
// ============================================

document.addEventListener('DOMContentLoaded', () => {

    // ---- THEME SYSTEM ----
    const THEME_KEY = 'web3edu_theme';
    const html = document.documentElement;

    function getStoredTheme() {
        return localStorage.getItem(THEME_KEY) ||
               (window.matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark');
    }

    function applyTheme(theme) {
        html.setAttribute('data-theme', theme);
        localStorage.setItem(THEME_KEY, theme);
        // Update toggle aria label
        const btn = document.getElementById('themeToggle');
        if (btn) btn.setAttribute('aria-label', theme === 'dark' ? 'Switch to light mode' : 'Switch to dark mode');
    }

    // Apply on load immediately (before paint)
    applyTheme(getStoredTheme());

    const toggleBtn = document.getElementById('themeToggle');
    if (toggleBtn) {
        toggleBtn.addEventListener('click', () => {
            const current = html.getAttribute('data-theme') || 'dark';
            applyTheme(current === 'dark' ? 'light' : 'dark');
        });
    }

    // ---- HEADER SCROLL ----
    const header = document.getElementById('siteHeader');
    if (header) {
        const onScroll = () => header.classList.toggle('scrolled', window.scrollY > 20);
        window.addEventListener('scroll', onScroll, { passive: true });
        onScroll();
    }

    // ---- USER DROPDOWN ----
    const trigger  = document.getElementById('userMenuTrigger');
    const dropdown = document.getElementById('userDropdown');
    if (trigger && dropdown) {
        trigger.addEventListener('click', e => {
            e.stopPropagation();
            const isOpen = dropdown.classList.toggle('open');
            trigger.setAttribute('aria-expanded', isOpen);
        });
        document.addEventListener('click', () => {
            dropdown.classList.remove('open');
            trigger?.setAttribute('aria-expanded', 'false');
        });
        dropdown.addEventListener('click', e => e.stopPropagation());
    }

    // ---- MOBILE HAMBURGER ----
    const hamburger = document.getElementById('hamburger');
    const nav       = document.getElementById('mainNav');
    if (hamburger && nav) {
        hamburger.addEventListener('click', () => {
            const open = nav.classList.toggle('open');
            hamburger.classList.toggle('active', open);
            hamburger.setAttribute('aria-expanded', open);
            const [s1, s2, s3] = hamburger.querySelectorAll('span');
            if (open) {
                s1.style.transform = 'rotate(45deg) translate(5px,5px)';
                s2.style.opacity   = '0';
                s3.style.transform = 'rotate(-45deg) translate(5px,-5px)';
            } else {
                [s1, s2, s3].forEach(s => { s.style.transform = ''; s.style.opacity = ''; });
            }
        });
        // Close nav on outside click
        document.addEventListener('click', e => {
            if (!hamburger.contains(e.target) && !nav.contains(e.target)) {
                nav.classList.remove('open');
                hamburger.classList.remove('active');
                hamburger.querySelectorAll('span').forEach(s => { s.style.transform = ''; s.style.opacity = ''; });
            }
        });
    }

    // ---- FLASH AUTO-DISMISS ----
    const flash = document.getElementById('flashMsg');
    if (flash) {
        setTimeout(() => flash.style.animation = 'slideInRight 0.3s ease reverse', 4500);
        setTimeout(() => flash.remove(), 5000);
    }

    // ---- SCROLL REVEAL ----
    const reveals = document.querySelectorAll('[data-reveal]');
    if (reveals.length && 'IntersectionObserver' in window) {
        const revealObs = new IntersectionObserver((entries) => {
            entries.forEach((entry, i) => {
                if (entry.isIntersecting) {
                    entry.target.style.opacity   = '1';
                    entry.target.style.transform = 'translateY(0)';
                    revealObs.unobserve(entry.target);
                }
            });
        }, { threshold: 0.08, rootMargin: '0px 0px -40px 0px' });

        reveals.forEach((el, i) => {
            el.style.opacity    = '0';
            el.style.transform  = 'translateY(28px)';
            el.style.transition = `opacity 0.55s ease ${i * 0.07}s, transform 0.55s ease ${i * 0.07}s`;
            revealObs.observe(el);
        });
    }

    // ---- COUNTER ANIMATION ----
    function animateCounter(el) {
        const target = parseFloat(el.dataset.count);
        const suffix = el.dataset.suffix || (el.textContent.replace(/[\d.]/g, '').trim());
        const duration = 2000;
        const startTime = performance.now();
        const isFloat = String(target).includes('.');

        const update = (now) => {
            const elapsed  = now - startTime;
            const progress = Math.min(elapsed / duration, 1);
            const ease     = 1 - Math.pow(1 - progress, 3); // cubic ease-out
            const current  = target * ease;
            el.textContent = (isFloat ? current.toFixed(1) : Math.round(current)) + suffix;
            if (progress < 1) requestAnimationFrame(update);
        };
        requestAnimationFrame(update);
    }

    // Trigger counters when visible
    const counters = document.querySelectorAll('[data-count]');
    if (counters.length && 'IntersectionObserver' in window) {
        const countObs = new IntersectionObserver(entries => {
            entries.forEach(e => {
                if (e.isIntersecting) {
                    animateCounter(e.target);
                    countObs.unobserve(e.target);
                }
            });
        }, { threshold: 0.5 });
        counters.forEach(el => countObs.observe(el));
    }

    // ---- ACTIVE NAV LINK ----
    const currentPath = window.location.pathname;
    document.querySelectorAll('.nav-link').forEach(link => {
        try {
            const linkPath = new URL(link.href, window.location.origin).pathname;
            const isHome = linkPath.endsWith('index.php') || linkPath.endsWith('/');
            if (!isHome && currentPath.includes(linkPath.split('/').pop().replace('.php',''))) {
                link.classList.add('active');
            } else if (isHome && (currentPath.endsWith('index.php') || currentPath.endsWith('/'))) {
                link.classList.add('active');
            }
        } catch {}
    });

    // ---- TAB SWITCHING ----
    document.querySelectorAll('.tab-btn[data-tab]').forEach(btn => {
        btn.addEventListener('click', () => {
            const group  = btn.closest('[data-tab-group]') || document.body;
            const target = btn.dataset.tab;
            group.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            group.querySelectorAll('[data-tab-content]').forEach(panel => {
                panel.style.display = panel.dataset.tabContent === target ? 'block' : 'none';
            });
        });
    });

    // ---- CARD HOVER GLOW (mouse follow) ----
    document.querySelectorAll('.lesson-card, .airdrop-card, .blog-card, .glass-card').forEach(card => {
        card.addEventListener('mousemove', e => {
            const rect = card.getBoundingClientRect();
            const x = ((e.clientX - rect.left) / rect.width  * 100).toFixed(1);
            const y = ((e.clientY - rect.top)  / rect.height * 100).toFixed(1);
            card.style.setProperty('--mouse-x', `${x}%`);
            card.style.setProperty('--mouse-y', `${y}%`);
        });
    });

});

// ============================================
// PAYSTACK PAYMENT
// ============================================
function initPaystackPayment(email, amount, reference, publicKey, callbackUrl) {
    if (typeof PaystackPop === 'undefined') {
        showToast('Payment system not loaded. Please refresh.', 'error');
        return;
    }
    const handler = PaystackPop.setup({
        key:      publicKey,
        email:    email,
        amount:   amount,
        currency: 'NGN',
        ref:      reference,
        metadata: { platform: 'Web3edu', type: 'premium_upgrade' },
        callback: function(response) {
            showToast('Payment successful! Activating your account...', 'success');
            setTimeout(() => {
                window.location.href = callbackUrl + '?reference=' + response.reference;
            }, 800);
        },
        onClose: function() {
            showToast('Payment window closed. No charge was made.', 'info');
        }
    });
    handler.openIframe();
}

// ============================================
// TOAST NOTIFICATIONS
// ============================================
function showToast(msg, type = 'info') {
    const existing = document.querySelector('.flash-message.toast');
    if (existing) existing.remove();

    const el = document.createElement('div');
    el.className = `flash-message flash-${type} toast`;
    el.style.cssText = 'position:fixed;top:88px;right:20px;z-index:9999';
    el.innerHTML = `<span>${msg}</span><button onclick="this.parentElement.remove()">✕</button>`;
    document.body.appendChild(el);
    setTimeout(() => el?.remove(), 5000);
}

// ============================================
// THEME: apply before DOM loads (prevent flash)
// ============================================
(function() {
    const stored = localStorage.getItem('web3edu_theme');
    const pref   = window.matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark';
    document.documentElement.setAttribute('data-theme', stored || pref);
})();
