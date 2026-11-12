<?php
require_once __DIR__ . '/includes/config.php';
$pageTitle = 'Cookie Policy — Web3edu';
require_once __DIR__ . '/includes/header.php';
$lastUpdated = 'May 30, 2026';
?>

<div class="page-header" style="text-align:center">
    <div class="container" data-reveal>
        <div class="section-label" style="margin:0 auto 20px">Legal</div>
        <h1 class="page-header-title"><span class="gradient-text">Cookie Policy</span></h1>
        <p style="color:var(--text-muted);max-width:560px;margin:0 auto 16px;font-size:0.95rem;line-height:1.7">
            This policy explains what cookies are, how we use them, and how you can manage your preferences.
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
            <a href="<?= SITE_URL ?>/terms-of-service.php"
               style="font-size:0.78rem;color:var(--text-muted);text-decoration:none;
                      background:var(--bg-card);border:1px solid var(--border);
                      padding:5px 14px;border-radius:999px;transition:all 0.2s"
               onmouseover="this.style.color='var(--primary-bright)';this.style.borderColor='var(--border-hov)'"
               onmouseout="this.style.color='var(--text-muted)';this.style.borderColor='var(--border)'">Terms of Service</a>
            <span style="font-size:0.78rem;font-weight:700;color:var(--primary-bright);
                         background:rgba(139,92,246,0.12);border:1px solid rgba(139,92,246,0.25);
                         padding:5px 14px;border-radius:999px">Cookie Policy</span>
        </div>
    </div>
</div>

<div class="section-sm">
    <div class="container">
        <div style="max-width:760px;margin:0 auto">

            <!-- What is a cookie -->
            <div data-reveal style="margin-bottom:24px;background:var(--bg-card);border:1px solid var(--border);
                                    border-radius:var(--radius-lg);overflow:hidden">
                <div style="display:flex;align-items:center;gap:14px;padding:18px 24px;
                            border-bottom:1px solid var(--border);
                            background:linear-gradient(90deg,rgba(139,92,246,0.06),transparent)">
                    <div style="width:36px;height:36px;border-radius:10px;background:rgba(139,92,246,0.12);
                                border:1px solid var(--border);display:flex;align-items:center;justify-content:center;
                                flex-shrink:0;color:var(--primary-bright)">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                    </div>
                    <h2 style="font-size:1rem;font-weight:700;margin:0">1. What Are Cookies?</h2>
                </div>
                <div style="padding:24px;color:var(--text-muted);font-size:0.92rem;line-height:1.8">
                    <p>Cookies are small text files that a website stores on your browser or device when you visit. They are widely used to make websites work efficiently and to provide information to website owners.</p>
                    <p>Cookies themselves do not contain personally identifiable information, but the data we store using them may be linked to personal information you provide us.</p>
                </div>
            </div>

            <!-- Cookie types table -->
            <div data-reveal style="margin-bottom:24px;background:var(--bg-card);border:1px solid var(--border);
                                    border-radius:var(--radius-lg);overflow:hidden">
                <div style="display:flex;align-items:center;gap:14px;padding:18px 24px;
                            border-bottom:1px solid var(--border);
                            background:linear-gradient(90deg,rgba(139,92,246,0.06),transparent)">
                    <div style="width:36px;height:36px;border-radius:10px;background:rgba(139,92,246,0.12);
                                border:1px solid var(--border);display:flex;align-items:center;justify-content:center;
                                flex-shrink:0;color:var(--primary-bright)">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="21" x2="9" y2="9"/></svg>
                    </div>
                    <h2 style="font-size:1rem;font-weight:700;margin:0">2. Cookies We Use</h2>
                </div>
                <div style="padding:24px">
                    <!-- Cookie table -->
                    <?php
                    $cookies = [
                        [
                            'name'     => 'web3edu_session',
                            'type'     => 'Essential',
                            'type_cls' => 'green',
                            'purpose'  => 'Keeps you logged in during your visit. Stores your session ID so you don\'t need to log in on every page.',
                            'duration' => 'Session (cleared when browser closes)',
                            'party'    => 'First-party',
                        ],
                        [
                            'name'     => 'web3edu_remember',
                            'type'     => 'Essential',
                            'type_cls' => 'green',
                            'purpose'  => 'Remembers your login for 30 days if you tick "Remember me" during login.',
                            'duration' => '30 days',
                            'party'    => 'First-party',
                        ],
                        [
                            'name'     => 'PHPSESSID',
                            'type'     => 'Essential',
                            'type_cls' => 'green',
                            'purpose'  => 'Standard PHP session identifier, used internally to maintain state across requests.',
                            'duration' => 'Session',
                            'party'    => 'First-party',
                        ],
                        [
                            'name'     => 'web3edu_flash',
                            'type'     => 'Functional',
                            'type_cls' => 'blue',
                            'purpose'  => 'Stores one-time notification messages (e.g. "Login successful") to display after a page redirect.',
                            'duration' => 'Cleared immediately after display',
                            'party'    => 'First-party',
                        ],
                        [
                            'name'     => 'g_state / g_csrf_token',
                            'type'     => 'Third-party',
                            'type_cls' => 'purple',
                            'purpose'  => 'Set by Google when you use "Sign in with Google". Used by Google to protect the OAuth flow.',
                            'duration' => 'Session',
                            'party'    => 'Third-party (Google)',
                        ],
                        [
                            'name'     => '__paystack_*',
                            'type'     => 'Third-party',
                            'type_cls' => 'purple',
                            'purpose'  => 'Set by Paystack during the payment flow to protect against fraud and maintain payment session state.',
                            'duration' => 'Session',
                            'party'    => 'Third-party (Paystack)',
                        ],
                    ];
                    ?>
                    <div class="cookie-table-wrap">
                    <table class="cookie-table" style="border-collapse:collapse;font-size:0.85rem">
                        <thead>
                            <tr style="background:rgba(139,92,246,0.08)">
                                <th style="padding:12px 14px;text-align:left;font-weight:700;color:var(--text);border-bottom:1px solid var(--border);white-space:nowrap;min-width:160px">Cookie Name</th>
                                <th style="padding:12px 14px;text-align:left;font-weight:700;color:var(--text);border-bottom:1px solid var(--border);white-space:nowrap;min-width:110px">Type</th>
                                <th style="padding:12px 14px;text-align:left;font-weight:700;color:var(--text);border-bottom:1px solid var(--border);min-width:220px">Purpose</th>
                                <th style="padding:12px 14px;text-align:left;font-weight:700;color:var(--text);border-bottom:1px solid var(--border);white-space:nowrap;min-width:160px">Duration</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($cookies as $c):
                                $colors = ['green'=>'rgba(11,121,88,0.12)','blue'=>'rgba(59,130,246,0.12)','purple'=>'rgba(139,92,246,0.12)'];
                                $textColors = ['green'=>'#0B7958','blue'=>'#93c5fd','purple'=>'var(--primary-bright)'];
                            ?>
                            <tr style="border-bottom:1px solid var(--border)">
                                <td data-label="Cookie Name" style="padding:12px 14px;font-family:var(--font-mono);font-size:0.8rem;color:var(--text);white-space:nowrap"><?= htmlspecialchars($c['name']) ?></td>
                                <td data-label="Type" style="padding:12px 14px">
                                    <span style="background:<?= $colors[$c['type_cls']] ?>;color:<?= $textColors[$c['type_cls']] ?>;
                                                 padding:3px 10px;border-radius:999px;font-size:0.75rem;font-weight:600;white-space:nowrap">
                                        <?= $c['type'] ?>
                                    </span>
                                </td>
                                <td data-label="Purpose" style="padding:12px 14px;color:var(--text-muted);line-height:1.6"><?= $c['purpose'] ?></td>
                                <td data-label="Duration" style="padding:12px 14px;color:var(--text-muted);font-size:0.82rem;white-space:nowrap"><?= $c['duration'] ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>

                    <!-- Legend -->
                    <div style="margin-top:16px;display:flex;flex-wrap:wrap;gap:12px">
                        <?php
                        $legend = [
                            ['Essential',    'rgba(11,121,88,0.12)',  '#0B7958',  'Required for the site to work. Cannot be disabled.'],
                            ['Functional',   'rgba(59,130,246,0.12)', '#93c5fd',  'Enhances your experience but not strictly required.'],
                            ['Third-party',  'rgba(139,92,246,0.12)', 'var(--primary-bright)', 'Set by external services we integrate with.'],
                        ];
                        foreach ($legend as [$label, $bg, $color, $desc]): ?>
                        <div style="display:flex;align-items:center;gap:8px;font-size:0.78rem;color:var(--text-muted)">
                            <span style="background:<?= $bg ?>;color:<?= $color ?>;padding:2px 9px;border-radius:999px;font-size:0.72rem;font-weight:600;flex-shrink:0"><?= $label ?></span>
                            <?= $desc ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- Managing cookies -->
            <div data-reveal style="margin-bottom:24px;background:var(--bg-card);border:1px solid var(--border);
                                    border-radius:var(--radius-lg);overflow:hidden">
                <div style="display:flex;align-items:center;gap:14px;padding:18px 24px;
                            border-bottom:1px solid var(--border);
                            background:linear-gradient(90deg,rgba(139,92,246,0.06),transparent)">
                    <div style="width:36px;height:36px;border-radius:10px;background:rgba(139,92,246,0.12);
                                border:1px solid var(--border);display:flex;align-items:center;justify-content:center;
                                flex-shrink:0;color:var(--primary-bright)">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.07 4.93a10 10 0 0 1 0 14.14M4.93 4.93a10 10 0 0 0 0 14.14"/></svg>
                    </div>
                    <h2 style="font-size:1rem;font-weight:700;margin:0">3. Managing Your Cookie Preferences</h2>
                </div>
                <div style="padding:24px;color:var(--text-muted);font-size:0.92rem;line-height:1.8">
                    <p>You can control and manage cookies in several ways:</p>

                    <div style="display:grid;gap:14px;margin-top:16px">
                        <?php
                        $browsers = [
                            ['Chrome',  'https://support.google.com/chrome/answer/95647'],
                            ['Firefox', 'https://support.mozilla.org/en-US/kb/enable-and-disable-cookies-website-preferences'],
                            ['Safari',  'https://support.apple.com/guide/safari/manage-cookies-sfri11471/mac'],
                            ['Edge',    'https://support.microsoft.com/en-us/microsoft-edge/delete-cookies-in-microsoft-edge-63947406-40ac-c3b8-57b9-2a946a29ae09'],
                        ];
                        foreach ($browsers as [$name, $url]): ?>
                        <div style="display:flex;align-items:center;gap:12px;padding:14px 18px;
                                    background:var(--bg-card2);border:1px solid var(--border);border-radius:var(--radius)">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--primary-bright)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
                            <div style="flex:1;font-weight:600;color:var(--text)"><?= $name ?></div>
                            <a href="<?= $url ?>" target="_blank" rel="noopener"
                               style="color:var(--primary-bright);font-size:0.82rem">
                                View guide ↗
                            </a>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <div style="margin-top:20px;padding:16px 18px;background:rgba(239,68,68,0.06);
                                border:1px solid rgba(239,68,68,0.2);border-radius:var(--radius)">
                        <p style="margin:0;color:var(--text-muted)">
                            <strong style="color:var(--text)">⚠ Important:</strong>
                            Disabling essential cookies (especially session cookies) will prevent Web3edu from working correctly —
                            you will not be able to log in or access your account.
                        </p>
                    </div>
                </div>
            </div>

            <!-- Third-party cookies -->
            <div data-reveal style="margin-bottom:24px;background:var(--bg-card);border:1px solid var(--border);
                                    border-radius:var(--radius-lg);overflow:hidden">
                <div style="display:flex;align-items:center;gap:14px;padding:18px 24px;
                            border-bottom:1px solid var(--border);
                            background:linear-gradient(90deg,rgba(139,92,246,0.06),transparent)">
                    <div style="width:36px;height:36px;border-radius:10px;background:rgba(139,92,246,0.12);
                                border:1px solid var(--border);display:flex;align-items:center;justify-content:center;
                                flex-shrink:0;color:var(--primary-bright)">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                    </div>
                    <h2 style="font-size:1rem;font-weight:700;margin:0">4. Third-Party Services</h2>
                </div>
                <div style="padding:24px;color:var(--text-muted);font-size:0.92rem;line-height:1.8">
                    <p>We integrate with the following third-party services that may set their own cookies. We have no control over these cookies — please review each provider's own cookie/privacy policy:</p>
                    <ul>
                        <li><strong>Google (Sign-In)</strong> — <a href="https://policies.google.com/privacy" target="_blank" rel="noopener" style="color:var(--primary-bright)">Google Privacy Policy</a></li>
                        <li><strong>Paystack (Payments)</strong> — <a href="https://paystack.com/privacy" target="_blank" rel="noopener" style="color:var(--primary-bright)">Paystack Privacy Policy</a></li>
                    </ul>
                    <p>We do <strong>not</strong> use any advertising, tracking, or analytics cookies.</p>
                </div>
            </div>

            <!-- Changes -->
            <div data-reveal style="margin-bottom:32px;background:var(--bg-card);border:1px solid var(--border);
                                    border-radius:var(--radius-lg);overflow:hidden">
                <div style="display:flex;align-items:center;gap:14px;padding:18px 24px;
                            border-bottom:1px solid var(--border);
                            background:linear-gradient(90deg,rgba(139,92,246,0.06),transparent)">
                    <div style="width:36px;height:36px;border-radius:10px;background:rgba(139,92,246,0.12);
                                border:1px solid var(--border);display:flex;align-items:center;justify-content:center;
                                flex-shrink:0;color:var(--primary-bright)">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                    </div>
                    <h2 style="font-size:1rem;font-weight:700;margin:0">5. Changes to This Policy</h2>
                </div>
                <div style="padding:24px;color:var(--text-muted);font-size:0.92rem;line-height:1.8">
                    <p>We may update this Cookie Policy from time to time to reflect changes in technology, legislation, or our services. The "Last updated" date at the top will always reflect the most recent version. We recommend reviewing this page periodically.</p>
                </div>
            </div>

            <!-- Contact CTA -->
            <div data-reveal style="text-align:center;padding:40px 24px;
                                    background:linear-gradient(135deg,rgba(139,92,246,0.06),rgba(168,85,247,0.04));
                                    border:1px solid var(--border);border-radius:var(--radius-lg)">
                <h3 style="font-size:1.2rem;font-weight:700;margin-bottom:10px">Questions about cookies?</h3>
                <p style="color:var(--text-muted);font-size:0.9rem;margin-bottom:20px">
                    Contact us at <a href="mailto:web3eduofficial@gmail.com" style="color:var(--primary-bright)">web3eduofficial@gmail.com</a>
                </p>
                <div style="display:flex;gap:12px;justify-content:center;flex-wrap:wrap">
                    <a href="<?= SITE_URL ?>/privacy-policy.php" class="btn-ghost btn-sm">Privacy Policy</a>
                    <a href="<?= SITE_URL ?>/terms-of-service.php" class="btn-ghost btn-sm">Terms of Service</a>
                    <a href="<?= SITE_URL ?>/contact.php" class="btn-upgrade btn-sm">Contact Us</a>
                </div>
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

/*
 * Cookie table — horizontal scroll
 *
 * Key fix: width:100% was removed from the table inline style.
 * When width:100% is set, the browser shrinks columns to fit the container
 * instead of letting the table overflow and scroll.
 * Without it, the min-width values on <th> elements force the table
 * to its natural wide width, which overflows the wrapper and triggers scroll.
 *
 * The negative-margin bleed pulls the wrapper flush to the card edges
 * so the full card width (not the inner padded width) is the scroll viewport.
 */
.cookie-table-wrap {
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    margin-left:  -24px;
    margin-right: -24px;
    padding-left:  24px;
    padding-right: 24px;
}
.cookie-table {
    /* No width:100% — column min-widths drive the table width */
    border-collapse: collapse;
}
</style>

<?php require_once __DIR__ . '/includes/footer.php'; ?>