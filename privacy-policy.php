<?php
require_once __DIR__ . '/includes/config.php';
$pageTitle = 'Privacy Policy — Web3edu';
require_once __DIR__ . '/includes/header.php';
$lastUpdated = 'May 30, 2026';
?>

<div class="page-header" style="text-align:center">
    <div class="container" data-reveal>
        <div class="section-label" style="margin:0 auto 20px">Legal</div>
        <h1 class="page-header-title"><span class="gradient-text">Privacy Policy</span></h1>
        <p style="color:var(--text-muted);max-width:560px;margin:0 auto 16px;font-size:0.95rem;line-height:1.7">
            We care about your data. This policy explains what we collect, why, and how we keep it safe.
        </p>
        <span style="font-size:0.78rem;color:var(--text-muted);background:var(--bg-card);border:1px solid var(--border);
                     padding:5px 14px;border-radius:999px;display:inline-block">
            Last updated: <?= $lastUpdated ?>
        </span>
        <!-- Legal page cross-nav -->
        <div style="display:flex;align-items:center;justify-content:center;gap:10px;margin-top:24px;flex-wrap:wrap">
            <span style="font-size:0.78rem;font-weight:700;color:var(--primary-bright);
                         background:rgba(139,92,246,0.12);border:1px solid rgba(139,92,246,0.25);
                         padding:5px 14px;border-radius:999px">Privacy Policy</span>
            <a href="<?= SITE_URL ?>/terms-of-service.php"
               style="font-size:0.78rem;color:var(--text-muted);text-decoration:none;
                      background:var(--bg-card);border:1px solid var(--border);
                      padding:5px 14px;border-radius:999px;transition:all 0.2s"
               onmouseover="this.style.color='var(--primary-bright)';this.style.borderColor='var(--border-hov)'"
               onmouseout="this.style.color='var(--text-muted)';this.style.borderColor='var(--border)'">Terms of Service</a>
            <a href="<?= SITE_URL ?>/cookie-policy.php"
               style="font-size:0.78rem;color:var(--text-muted);text-decoration:none;
                      background:var(--bg-card);border:1px solid var(--border);
                      padding:5px 14px;border-radius:999px;transition:all 0.2s"
               onmouseover="this.style.color='var(--primary-bright)';this.style.borderColor='var(--border-hov)'"
               onmouseout="this.style.color='var(--text-muted)';this.style.borderColor='var(--border)'">Cookie Policy</a>
        </div>
    </div>
</div>

<div class="section-sm">
    <div class="container">
        <div style="max-width:760px;margin:0 auto">

            <?php
            $sections = [
                [
                    'icon' => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>',
                    'title' => '1. Who We Are',
                    'body'  => '<p>Web3edu ("<strong>we</strong>", "<strong>us</strong>", "<strong>our</strong>") is an online education platform focused on blockchain, DeFi, NFTs, and Web3 development. Our website is accessible at <strong>' . SITE_URL . '</strong>.</p>
                    <p>If you have any questions about this Privacy Policy, you can contact us at <a href="mailto:web3eduofficial@gmail.com" style="color:var(--primary-bright)">web3eduofficial@gmail.com</a>.</p>',
                ],
                [
                    'icon' => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>',
                    'title' => '2. Information We Collect',
                    'body'  => '<p>We collect information in two ways:</p>
                    <p><strong>Information you provide directly:</strong></p>
                    <ul>
                        <li><strong>Account data</strong> — name, email address, username, and password when you register.</li>
                        <li><strong>Profile data</strong> — wallet address, bio, avatar selection, and social links you optionally add.</li>
                        <li><strong>Payment data</strong> — when you upgrade to Premium, payment is processed by <strong>Paystack</strong>. We store only a transaction reference and your membership status — we never see or store your card details.</li>
                        <li><strong>Communications</strong> — messages you send us via contact forms or email.</li>
                    </ul>
                    <p><strong>Information collected automatically:</strong></p>
                    <ul>
                        <li><strong>Log data</strong> — IP address, browser type, pages visited, and timestamps.</li>
                        <li><strong>Cookies</strong> — session cookies to keep you logged in, and preference cookies. See our <a href="' . SITE_URL . '/cookie-policy.php" style="color:var(--primary-bright)">Cookie Policy</a> for details.</li>
                        <li><strong>Google Sign-In</strong> — if you sign in with Google, we receive your name, email, and profile picture from Google. We do not access any other Google account data.</li>
                    </ul>',
                ],
                [
                    'icon' => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>',
                    'title' => '3. How We Use Your Information',
                    'body'  => '<p>We use the information we collect to:</p>
                    <ul>
                        <li>Create and manage your account.</li>
                        <li>Provide access to lessons, airdrops, and Premium content.</li>
                        <li>Process payments and send receipts.</li>
                        <li>Respond to your support requests and questions.</li>
                        <li>Send important service emails (account confirmation, password reset, payment receipts). We do <strong>not</strong> send marketing emails without your consent.</li>
                        <li>Improve the platform through aggregate, anonymised analytics.</li>
                        <li>Detect and prevent fraud or abuse.</li>
                    </ul>',
                ],
                [
                    'icon' => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
                    'title' => '4. Sharing Your Information',
                    'body'  => '<p>We do <strong>not</strong> sell or rent your personal data. We only share it in these limited circumstances:</p>
                    <ul>
                        <li><strong>Paystack</strong> — our payment processor. Subject to <a href="https://paystack.com/privacy" target="_blank" rel="noopener" style="color:var(--primary-bright)">Paystack\'s Privacy Policy</a>.</li>
                        <li><strong>Google</strong> — if you use Google Sign-In, subject to <a href="https://policies.google.com/privacy" target="_blank" rel="noopener" style="color:var(--primary-bright)">Google\'s Privacy Policy</a>.</li>
                        <li><strong>Legal requirements</strong> — if required by law, court order, or to protect the rights and safety of our users.</li>
                        <li><strong>Business transfers</strong> — if Web3edu is acquired or merges, your data may transfer to the new entity, which will be bound by this policy.</li>
                    </ul>',
                ],
                [
                    'icon' => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>',
                    'title' => '5. Data Security',
                    'body'  => '<p>We implement industry-standard security measures including:</p>
                    <ul>
                        <li>HTTPS/SSL encryption on all pages.</li>
                        <li>Hashed and salted passwords (we never store plain-text passwords).</li>
                        <li>Restricted database access with least-privilege principles.</li>
                        <li>Regular security reviews.</li>
                    </ul>
                    <p>No system is 100% secure. If you suspect your account has been compromised, please contact us immediately at <a href="mailto:web3eduofficial@gmail.com" style="color:var(--primary-bright)">web3eduofficial@gmail.com</a>.</p>',
                ],
                [
                    'icon' => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>',
                    'title' => '6. Your Rights',
                    'body'  => '<p>You have the right to:</p>
                    <ul>
                        <li><strong>Access</strong> — request a copy of the personal data we hold about you.</li>
                        <li><strong>Correction</strong> — update inaccurate information via your profile settings.</li>
                        <li><strong>Deletion</strong> — request that we delete your account and personal data. Email us at <a href="mailto:web3eduofficial@gmail.com" style="color:var(--primary-bright)">web3eduofficial@gmail.com</a>.</li>
                        <li><strong>Portability</strong> — request your data in a portable format.</li>
                        <li><strong>Objection</strong> — object to certain processing of your data.</li>
                    </ul>
                    <p>To exercise any of these rights, contact <a href="mailto:web3eduofficial@gmail.com" style="color:var(--primary-bright)">web3eduofficial@gmail.com</a>. We will respond within 30 days.</p>',
                ],
                [
                    'icon' => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>',
                    'title' => '7. Data Retention',
                    'body'  => '<p>We retain your personal data for as long as your account is active. If you delete your account:</p>
                    <ul>
                        <li>Your profile and personal data are deleted within 30 days.</li>
                        <li>Payment records may be retained for up to 7 years for legal and tax compliance.</li>
                        <li>Anonymised, aggregate analytics data may be retained indefinitely.</li>
                    </ul>',
                ],
                [
                    'icon' => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>',
                    'title' => '8. International Users',
                    'body'  => '<p>Web3edu is operated from Nigeria. If you access our platform from another country, your data may be transferred to and processed in Nigeria. By using Web3edu, you consent to this transfer.</p>
                    <p>We comply with applicable data protection laws including the Nigeria Data Protection Regulation (NDPR).</p>',
                ],
                [
                    'icon' => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>',
                    'title' => '9. Changes to This Policy',
                    'body'  => '<p>We may update this Privacy Policy from time to time. When we do, we will update the "Last updated" date at the top of this page. For significant changes, we will notify you by email or a prominent notice on the platform. Continued use of Web3edu after changes means you accept the updated policy.</p>',
                ],
            ];

            foreach ($sections as $i => $s): ?>
            <div data-reveal style="margin-bottom:32px;background:var(--bg-card);border:1px solid var(--border);
                                    border-radius:var(--radius-lg);overflow:hidden">
                <div style="display:flex;align-items:center;gap:14px;padding:20px 24px;
                            border-bottom:1px solid var(--border);
                            background:linear-gradient(90deg,rgba(139,92,246,0.06),transparent)">
                    <div style="width:36px;height:36px;border-radius:10px;background:rgba(139,92,246,0.12);
                                border:1px solid var(--border);display:flex;align-items:center;justify-content:center;
                                flex-shrink:0;color:var(--primary-bright)">
                        <?= $s['icon'] ?>
                    </div>
                    <h2 style="font-size:1rem;font-weight:700;margin:0"><?= $s['title'] ?></h2>
                </div>
                <div style="padding:24px;color:var(--text-muted);font-size:0.92rem;line-height:1.8">
                    <?= $s['body'] ?>
                </div>
            </div>
            <?php endforeach; ?>

            <!-- Contact CTA -->
            <div data-reveal style="text-align:center;padding:40px 24px;background:var(--bg-card);
                                    border:1px solid var(--border);border-radius:var(--radius-lg);
                                    background:linear-gradient(135deg,rgba(139,92,246,0.06),rgba(168,85,247,0.04))">
                <h3 style="font-size:1.2rem;font-weight:700;margin-bottom:10px">Questions about your privacy?</h3>
                <p style="color:var(--text-muted);font-size:0.9rem;margin-bottom:20px">
                    We're happy to help. Reach out to our team any time.
                </p>
                <a href="<?= SITE_URL ?>/contact.php" class="btn-upgrade btn-sm">Contact Us</a>
            </div>

        </div>
    </div>
</div>

<style>
.page-header-title + p + span { margin-top: 4px; }
article ul { padding-left: 20px; margin: 12px 0; }
article ul li { margin-bottom: 8px; }
[data-reveal] ul { padding-left: 20px; margin: 12px 0; }
[data-reveal] ul li { margin-bottom: 8px; }
[data-reveal] p { margin-bottom: 14px; }
[data-reveal] p:last-child { margin-bottom: 0; }
[data-reveal] strong { color: var(--text); }
</style>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
