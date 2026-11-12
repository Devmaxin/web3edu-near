<?php
require_once __DIR__ . '/includes/config.php';
$pageTitle = 'Contact Us';

// NOTE: PHP server-side form processing removed.
// Form is now handled entirely via JavaScript Fetch API (AJAX) to Formspree.
// No POST processing needed here — submission never hits the server.

require_once __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <div class="container" data-reveal>
        <div class="section-label">Get in Touch</div>
        <h1 class="page-header-title">Contact <span class="gradient-text">Web3edu</span></h1>
        <p class="page-header-subtitle">Questions, partnerships, or feedback — we'd love to hear from you.</p>
    </div>
</div>

<div class="section-sm">
    <div class="container">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:48px;max-width:1000px;margin:0 auto" class="contact-grid">

            <!-- Contact Info -->
            <div data-reveal>
                <h2 style="font-size:1.4rem;font-weight:700;margin-bottom:24px">Let's Talk</h2>
                <?php
                $contacts = [
                    ['<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:middle;flex-shrink:0"><rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>','Email','web3eduofficial@gmail.com','mailto:web3eduofficial@gmail.com'],
                    ['<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:middle;flex-shrink:0"><path d="M4 4l16 16"/><path d="M20 4 4 20"/></svg>','Twitter','@web3eduofficial','https://twitter.com/web3eduofficial'],
                  /*  ['<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:middle;flex-shrink:0"><circle cx="9" cy="12" r="1"/><circle cx="15" cy="12" r="1"/><path d="M7.5 7.2C8.4 6.5 10 6 12 6s3.6.5 4.5 1.2"/><path d="M16.5 16.8c-.9.7-2.5 1.2-4.5 1.2s-3.6-.5-4.5-1.2"/><path d="M20 4H4a2 2 0 0 0-2 2v14l4-4h14a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2z"/></svg>','Discord','Join our server','#'], */
                    ['<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:middle;flex-shrink:0"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 2 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>','Telegram','not yet','#'],
                ];
                foreach ($contacts as [$icon, $label, $value, $href]):
                ?>
                <a href="<?= $href ?>" style="display:flex;align-items:center;gap:16px;padding:16px;background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius);margin-bottom:10px;transition:all 0.2s"
                   onmouseover="this.style.borderColor='var(--border-hov)'" onmouseout="this.style.borderColor='var(--border)'">
                    <div style="width:44px;height:44px;border-radius:10px;background:rgba(139,92,246,0.08);display:flex;align-items:center;justify-content:center;flex-shrink:0;color:var(--primary-bright)"><?= $icon ?></div>
                    <div>
                        <div style="font-size:0.75rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.06em;margin-bottom:2px"><?= $label ?></div>
                        <div style="font-weight:600;font-size:0.9rem;color:var(--primary-bright)"><?= $value ?></div>
                    </div>
                </a>
                <?php endforeach; ?>

                <div style="margin-top:32px;padding:24px;background:rgba(168,85,247,0.06);border:1px solid rgba(168,85,247,0.2);border-radius:var(--radius-lg)">
                    <h3 style="font-size:1rem;font-weight:700;margin-bottom:8px">Response Time</h3>
                    <p style="color:var(--text-muted);font-size:0.875rem">We typically respond within 24-48 hours on business days. For urgent premium support, mention it in your message.</p>
                </div>
            </div>

            <!-- Contact Form -->
            <div data-reveal>

                <!-- ============================================================
                     FORMSPREE FEEDBACK BANNERS
                     Hidden by default; toggled via JS after AJAX response.
                     ============================================================ -->

                <!-- Success banner (hidden until form submits successfully) -->
                <div id="contact-success" style="display:none;text-align:center;padding:60px;background:rgba(11,121,88,0.05);border:1px solid rgba(11,121,88,0.2);border-radius:var(--radius-lg);margin-bottom:20px">
                    <div style="font-size:3rem;margin-bottom:16px">
                        <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color:#0B7958"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                    </div>
                    <h3 style="font-size:1.3rem;font-weight:700;margin-bottom:8px">Message Sent!</h3>
                    <p style="color:var(--text-muted)">✅ Your message has been sent successfully. We'll get back to you soon.</p>
                </div>

                <!-- Error banner (hidden until a submission failure occurs) -->
                <div id="contact-error" style="display:none;background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.3);border-radius:var(--radius);padding:14px;margin-bottom:20px">
                    <div style="color:#fca5a5;font-size:0.85rem">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:middle;flex-shrink:0"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                        ❌ Unable to send your message. Please try again later.
                    </div>
                </div>

                <!-- Validation error banner (hidden until client-side validation fires) -->
                <div id="contact-validation" style="display:none;background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.3);border-radius:var(--radius);padding:14px;margin-bottom:20px">
                    <div id="contact-validation-msg" style="color:#fca5a5;font-size:0.85rem"></div>
                </div>

                <div style="background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-lg);padding:32px">
                    <h2 style="font-size:1.1rem;font-weight:700;margin-bottom:24px">Send a Message</h2>

                    <!-- ============================================================
                         CONTACT FORM
                         action / method removed — submission handled via JS Fetch.
                         id="contactForm" used as the JS hook.
                         ============================================================ -->
                    <form id="contactForm">
                        <div class="grid-2" style="gap:16px">
                            <div class="form-group" style="margin-bottom:0">
                                <label class="form-label">Your Name</label>
                                <input type="text" name="name" id="contact-name" class="form-input" placeholder="Satoshi" required>
                            </div>
                            <div class="form-group" style="margin-bottom:0">
                                <label class="form-label">Email</label>
                                <input type="email" name="email" id="contact-email" class="form-input" placeholder="you@example.com" required>
                            </div>
                        </div>
                        <div class="form-group" style="margin-top:16px">
                            <label class="form-label">Subject</label>
                            <select name="subject" id="contact-subject" class="form-select" required>
                                <option value="">Select a subject...</option>
                                <option>General Inquiry</option>
                                <option>Premium Support</option>
                                <option>Technical Issue</option>
                                <option>Partnership</option>
                                <option>Content Suggestion</option>
                                <option>Other</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Message</label>
                            <textarea name="message" id="contact-message" class="form-textarea" placeholder="Tell us how we can help..." rows="6" required></textarea>
                        </div>
                        <button type="submit" id="contact-submit" class="btn-primary" style="width:100%;justify-content:center;padding:13px">
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:middle;flex-shrink:0"><path d="M22 13V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v12c0 1.1.9 2 2 2h9"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/><path d="M19 16v6"/><path d="M16 19l3 3 3-3"/></svg>
                            <span id="contact-btn-text">Send Message</span>
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
@media (max-width:768px) {
    .contact-grid { grid-template-columns: 1fr !important; }
}
</style>

<!-- ============================================================
     FORMSPREE AJAX INTEGRATION
     - Submits via Fetch API (no page refresh, no redirect)
     - Validates required fields client-side before sending
     - Shows loading state on submit button during request
     - Displays success or error banner inline after response
     ============================================================ -->
<script>
(function () {

    // ── Formspree endpoint ───────────────────────────────────────────────────
    // Replace YOUR_FORMSPREE_ENDPOINT with your actual form ID, e.g.:
    //   https://formspree.io/f/xpznwkab
    var FORMSPREE_ENDPOINT = 'https://formspree.io/f/mvzjgdvq';

    // ── DOM references ───────────────────────────────────────────────────────
    var form       = document.getElementById('contactForm');
    var btnSubmit  = document.getElementById('contact-submit');
    var btnText    = document.getElementById('contact-btn-text');
    var divSuccess = document.getElementById('contact-success');
    var divError   = document.getElementById('contact-error');
    var divVal     = document.getElementById('contact-validation');
    var divValMsg  = document.getElementById('contact-validation-msg');

    if (!form) return; // guard: only run on contact page

    // ── Helper: show / hide feedback banners ─────────────────────────────────
    function hideBanners() {
        divSuccess.style.display = 'none';
        divError.style.display   = 'none';
        divVal.style.display     = 'none';
    }

    function showBanner(el) {
        hideBanners();
        el.style.display = 'block';
        // Smooth scroll to banner so user sees feedback
        el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    // ── Helper: loading state on the submit button ────────────────────────────
    function setLoading(loading) {
        btnSubmit.disabled = loading;
        btnText.textContent = loading ? 'Sending…' : 'Send Message';
    }

    // ── Client-side validation ────────────────────────────────────────────────
    // Mirrors the original PHP validation logic so users get instant feedback.
    function validate(name, email, subject, message) {
        if (!name || name.length < 2) return 'Please enter your name (at least 2 characters).';
        // Basic email format check
        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) return 'Enter a valid email address.';
        if (!subject) return 'Please select a subject.';
        if (!message || message.length < 20) return 'Message must be at least 20 characters.';
        return null; // null = valid
    }

    // ── Form submit handler ───────────────────────────────────────────────────
    form.addEventListener('submit', function (e) {
        // Prevent default browser form submission (no page refresh, no redirect)
        e.preventDefault();

        hideBanners();

        // Collect field values
        var name    = document.getElementById('contact-name').value.trim();
        var email   = document.getElementById('contact-email').value.trim();
        var subject = document.getElementById('contact-subject').value;
        var message = document.getElementById('contact-message').value.trim();

        // ── Client-side validation ────────────────────────────────────────────
        var validationError = validate(name, email, subject, message);
        if (validationError) {
            divValMsg.innerHTML =
                '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:middle;flex-shrink:0"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg> '
                + validationError;
            showBanner(divVal);
            return;
        }

        // ── Loading state: disable button, update text ────────────────────────
        setLoading(true);

        // ── AJAX submission to Formspree ──────────────────────────────────────
        // Using Fetch API with Accept: application/json so Formspree returns
        // JSON instead of redirecting to its own thank-you page.
        fetch(FORMSPREE_ENDPOINT, {
            method: 'POST',
            headers: {
                'Accept': 'application/json'
                // Content-Type is NOT set manually; FormData sets it with boundary
            },
            body: new FormData(form) // includes all named fields: name, email, subject, message
        })
        .then(function (response) {
            // ── Success: HTTP 200 with ok:true in JSON body ───────────────────
            if (response.ok) {
                // Show success banner
                showBanner(divSuccess);
                // Reset all form fields
                form.reset();
            } else {
                // ── Formspree returned a non-2xx status (e.g. 422, 429, 5xx) ──
                showBanner(divError);
            }
        })
        .catch(function () {
            // ── Network error (offline, CORS, timeout, etc.) ──────────────────
            showBanner(divError);
        })
        .finally(function () {
            // ── Always restore submit button regardless of outcome ────────────
            setLoading(false);
        });
    });

})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>