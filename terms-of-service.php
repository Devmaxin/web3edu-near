<?php
require_once __DIR__ . '/includes/config.php';
$pageTitle = 'Terms of Service — Web3edu';
require_once __DIR__ . '/includes/header.php';
$lastUpdated = 'May 30, 2026';
?>

<div class="page-header" style="text-align:center">
    <div class="container" data-reveal>
        <div class="section-label" style="margin:0 auto 20px">Legal</div>
        <h1 class="page-header-title"><span class="gradient-text">Terms of Service</span></h1>
        <p style="color:var(--text-muted);max-width:580px;margin:0 auto 16px;font-size:0.95rem;line-height:1.7">
            Please read these terms carefully before using Web3edu. By accessing the platform you agree to be bound by them.
        </p>
        <span style="font-size:0.78rem;color:var(--text-muted);background:var(--bg-card);border:1px solid var(--border);
                     padding:5px 14px;border-radius:999px;display:inline-block">
            Last updated: <?= $lastUpdated ?>
        </span>
        <!-- Legal page cross-nav -->
        <div style="display:flex;align-items:center;justify-content:center;gap:10px;margin-top:24px;flex-wrap:wrap">
            <a href="<?= SITE_URL ?>/privacy-policy.php"
               style="font-size:0.78rem;color:var(--text-muted);text-decoration:none;
                      background:var(--bg-card);border:1px solid var(--border);
                      padding:5px 14px;border-radius:999px;transition:all 0.2s"
               onmouseover="this.style.color='var(--primary-bright)';this.style.borderColor='var(--border-hov)'"
               onmouseout="this.style.color='var(--text-muted)';this.style.borderColor='var(--border)'">Privacy Policy</a>
            <span style="font-size:0.78rem;font-weight:700;color:var(--primary-bright);
                         background:rgba(139,92,246,0.12);border:1px solid rgba(139,92,246,0.25);
                         padding:5px 14px;border-radius:999px">Terms of Service</span>
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

            <!-- Acceptance banner -->
            <div data-reveal style="background:rgba(139,92,246,0.08);border:1.5px solid rgba(139,92,246,0.25);
                                    border-radius:var(--radius-lg);padding:20px 24px;margin-bottom:32px;
                                    display:flex;gap:14px;align-items:flex-start">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none"
                     stroke="var(--primary-bright)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                     style="flex-shrink:0;margin-top:2px">
                    <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/>
                    <line x1="12" y1="16" x2="12.01" y2="16"/>
                </svg>
                <p style="margin:0;font-size:0.9rem;line-height:1.7;color:var(--text-muted)">
                    By registering an account or using any part of Web3edu, you confirm that you have read, understood, and agree to these Terms of Service and our
                    <a href="<?= SITE_URL ?>/privacy-policy.php" style="color:var(--primary-bright)">Privacy Policy</a>.
                    If you do not agree, please do not use the platform.
                </p>
            </div>

            <?php
            $sections = [
                [
                    'icon'  => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>',
                    'title' => '1. About Web3edu',
                    'body'  => '<p>Web3edu is an online education platform providing structured guides, lessons, and resources on blockchain technology, decentralised finance (DeFi), NFTs, and Web3 development. We are based in Nigeria and our platform is available globally.</p>',
                ],
                [
                    'icon'  => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>',
                    'title' => '2. Eligibility & Account Registration',
                    'body'  => '<p>To use Web3edu you must:</p>
                    <ul>
                        <li>Be at least <strong>18 years old</strong>, or the age of majority in your jurisdiction.</li>
                        <li>Provide accurate, truthful information when creating your account.</li>
                        <li>Keep your login credentials confidential and not share them with others.</li>
                    </ul>
                    <p>You are responsible for all activity that occurs under your account. Notify us immediately at <a href="mailto:web3eduofficial@gmail.com" style="color:var(--primary-bright)">web3eduofficial@gmail.com</a> if you suspect unauthorised access.</p>
                    <p>We reserve the right to refuse registration or suspend accounts at our discretion.</p>',
                ],
                [
                    'icon'  => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5v-15A2.5 2.5 0 0 1 6.5 2H20v20H6.5a2.5 2.5 0 0 1 0-5H20"/></svg>',
                    'title' => '3. Free & Premium Content',
                    'body'  => '<p><strong>Free tier:</strong> Registered users have access to a selection of free lessons and blog posts.</p>
                    <p><strong>Premium membership:</strong> Premium is offered as a recurring <strong>Monthly</strong> or <strong>Yearly</strong> subscription (pricing set by the administrator and displayed on the <a href="' . SITE_URL . '/upgrade.php" style="color:var(--primary-bright)">Upgrade page</a>). Premium access includes:</p>
                    <ul>
                        <li>All Premium-locked lessons and guides.</li>
                        <li>Exclusive airdrop guides.</li>
                        <li>Priority access to new content.</li>
                        <li>A Premium badge on your profile.</li>
                    </ul>
                    <p>Subscriptions renew automatically at the end of each billing cycle until cancelled. You may cancel anytime from your dashboard — you keep access until the end of the period you already paid for. Premium access is tied to your individual account and is non-transferable.</p>',
                ],
                [
                    'icon'  => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>',
                    'title' => '4. Payments & Refunds',
                    'body'  => '<p>All payments are processed securely by <strong>Paystack</strong>. By making a payment you agree to Paystack\'s <a href="https://paystack.com/terms" target="_blank" rel="noopener" style="color:var(--primary-bright)">Terms of Service</a>.</p>
                    <p><strong>Refund policy:</strong> Because Premium access is granted instantly upon successful payment, all sales are <strong>final</strong>. We do not offer refunds except where required by applicable law or where there is a demonstrable technical failure on our part. To raise a payment dispute, contact <a href="mailto:web3eduofficial@gmail.com" style="color:var(--primary-bright)">web3eduofficial@gmail.com</a> within 7 days of payment.</p>',
                ],
                [
                    'icon'  => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>',
                    'title' => '5. Acceptable Use',
                    'body'  => '<p>You agree <strong>not</strong> to:</p>
                    <ul>
                        <li>Share, resell, or distribute Premium content to non-Premium users.</li>
                        <li>Scrape, copy, or reproduce Web3edu content without written permission.</li>
                        <li>Use the platform for any unlawful purpose, including fraud or money laundering.</li>
                        <li>Attempt to gain unauthorised access to any part of the platform or its servers.</li>
                        <li>Post harmful, abusive, defamatory, or misleading content.</li>
                        <li>Impersonate other users or Web3edu staff.</li>
                        <li>Interfere with the platform\'s availability or security.</li>
                    </ul>
                    <p>Violation of these rules may result in immediate account suspension without refund.</p>',
                ],
                [
                    'icon'  => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="21" x2="9" y2="9"/></svg>',
                    'title' => '6. Intellectual Property',
                    'body'  => '<p>All content on Web3edu — including lessons, videos, articles, graphics, and code examples — is the intellectual property of Web3edu or its content creators and is protected by copyright.</p>
                    <p>You are granted a limited, non-exclusive, non-transferable licence to access and use the content for your own personal, non-commercial learning. You may not reproduce, publish, or create derivative works from our content without prior written permission.</p>',
                ],
                [
                    'icon'  => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
                    'title' => '7. Educational Disclaimer',
                    'body'  => '<p>Web3edu content is provided for <strong>educational purposes only</strong>. Nothing on the platform constitutes financial, investment, legal, or tax advice.</p>
                    <p>Cryptocurrency and DeFi investments carry significant risk. Past performance is not indicative of future results. Always conduct your own research (DYOR) and consult a qualified professional before making any financial decision. Web3edu is not responsible for any losses arising from actions taken based on our content.</p>',
                ],
                [
                    'icon'  => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/></svg>',
                    'title' => '8. Limitation of Liability',
                    'body'  => '<p>To the fullest extent permitted by law, Web3edu and its team shall not be liable for:</p>
                    <ul>
                        <li>Any indirect, incidental, or consequential damages arising from your use of the platform.</li>
                        <li>Loss of profits, data, or business opportunities.</li>
                        <li>Downtime or errors caused by third-party services (hosting, Paystack, Google, etc.).</li>
                    </ul>
                    <p>Our total liability to you for any claim shall not exceed the amount you paid us in the 12 months preceding the claim.</p>',
                ],
                [
                    'icon'  => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>',
                    'title' => '9. Changes to These Terms',
                    'body'  => '<p>We reserve the right to update these Terms at any time. When we do, we will update the "Last updated" date. For material changes, we will notify you by email or a notice on the platform. Your continued use of Web3edu after changes constitutes acceptance of the new terms.</p>',
                ],
                [
                    'icon'  => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>',
                    'title' => '10. Governing Law',
                    'body'  => '<p>These Terms are governed by and construed in accordance with the laws of the <strong>Federal Republic of Nigeria</strong>. Any disputes arising from these Terms shall be subject to the exclusive jurisdiction of the Nigerian courts.</p>',
                ],
            ];

            foreach ($sections as $s): ?>
            <div data-reveal style="margin-bottom:24px;background:var(--bg-card);border:1px solid var(--border);
                                    border-radius:var(--radius-lg);overflow:hidden">
                <div style="display:flex;align-items:center;gap:14px;padding:18px 24px;
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
            <div data-reveal style="text-align:center;padding:40px 24px;
                                    background:linear-gradient(135deg,rgba(139,92,246,0.06),rgba(168,85,247,0.04));
                                    border:1px solid var(--border);border-radius:var(--radius-lg)">
                <h3 style="font-size:1.2rem;font-weight:700;margin-bottom:10px">Have questions about these terms?</h3>
                <p style="color:var(--text-muted);font-size:0.9rem;margin-bottom:20px">
                    Contact us at <a href="mailto:web3eduofficial@gmail.com" style="color:var(--primary-bright)">web3eduofficial@gmail.com</a> or use the form below.
                </p>
                <a href="<?= SITE_URL ?>/contact.php" class="btn-upgrade btn-sm">Contact Us</a>
            </div>

        </div>
    </div>
</div>

<style>
[data-reveal] ul { padding-left: 20px; margin: 12px 0; }
[data-reveal] ul li { margin-bottom: 8px; }
[data-reveal] p { margin-bottom: 14px; }
[data-reveal] p:last-child { margin-bottom: 0; }
[data-reveal] strong { color: var(--text); }
</style>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
