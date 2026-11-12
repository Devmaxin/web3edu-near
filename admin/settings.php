<?php
$adminTitle = 'Site Settings';
require_once __DIR__ . '/header.php';

$db     = getDB();
$errors = [];
$saved  = false;
$testResult = null;

// ── Send Test Email (config-level — tests From/Reply-To/API key directly) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_config_test') {
    requireCsrf();
    $testEmail = trim($_POST['test_email'] ?? '');
    if ($testEmail && filter_var($testEmail, FILTER_VALIDATE_EMAIL)) {
        $testResult = sendConfigTestEmail($testEmail);
    } else {
        $testResult = ['success' => false, 'error' => 'Enter a valid email address.'];
    }
}

// ── Handle POST ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    requireCsrf();

    // --- Premium price validation ---
    $rawKobo    = trim($_POST['premium_price_kobo']    ?? '');
    $rawDisplay = trim($_POST['premium_price_display'] ?? '');
    $siteName   = trim($_POST['site_name']             ?? '');
    $tagline    = trim($_POST['site_tagline']           ?? '');

    // Accept either a kobo value (e.g. 990000) or a naira value (e.g. 9900)
    // We'll treat any value ≥ 100 as kobo, anything < 100 as invalid
    if (!ctype_digit($rawKobo) || $rawKobo === '') {
        $errors[] = 'Premium price (kobo) must be a whole number.';
    } else {
        $koboInt = (int)$rawKobo;
        if ($koboInt < 100) {
            $errors[] = 'Minimum price is ₦1 (100 kobo). Please enter a valid amount.';
        } elseif ($koboInt > 100_000_000) { // ₦1,000,000 max
            $errors[] = 'Price cannot exceed ₦1,000,000 (100000000 kobo).';
        }
    }

    if ($rawDisplay === '') {
        // Auto-generate display from kobo
        if (empty($errors)) {
            $naira      = $koboInt / 100;
            $rawDisplay = '₦' . number_format($naira, $naira == floor($naira) ? 0 : 2);
        }
    }

    if ($siteName === '') $errors[] = 'Site name cannot be empty.';

    // --- Bounty Marketplace settings (independent of the premium/site-info validation above) ---
    $bountyFeeKobo     = trim($_POST['bounty_listing_fee_kobo'] ?? '');
    $bountyCurrency    = trim($_POST['bounty_listing_fee_currency'] ?? 'NGN') ?: 'NGN';
    $bountyFeeEnabled  = isset($_POST['bounty_listing_fee_enabled']) ? '1' : '0';
    $bountyCryptoOn    = isset($_POST['bounty_crypto_enabled']) ? '1' : '0';
    $bountyPaystackOn  = isset($_POST['bounty_paystack_enabled']) ? '1' : '0';
    $bountySolana      = trim($_POST['bounty_solana_wallet'] ?? '');
    $bountyEthereum    = trim($_POST['bounty_ethereum_wallet'] ?? '');
    $bountyTon         = trim($_POST['bounty_ton_wallet'] ?? '');
    $bountySolanaAmt   = trim($_POST['bounty_solana_suggested_amount'] ?? '');
    $bountyEthereumAmt = trim($_POST['bounty_ethereum_suggested_amount'] ?? '');
    $bountyTonAmt      = trim($_POST['bounty_ton_suggested_amount'] ?? '');

    if ($bountyFeeKobo !== '' && (!ctype_digit($bountyFeeKobo) || (int)$bountyFeeKobo < 0)) {
        $errors[] = 'Listing fee must be a whole number (in the smallest currency unit, e.g. kobo).';
    }

    // --- Email Settings ---
    $emailFromName    = trim($_POST['email_from_name'] ?? '');
    $emailFromAddress = trim($_POST['email_from_address'] ?? '');
    $emailReplyTo     = trim($_POST['email_reply_to'] ?? '');
    $primaryAdminEmail = trim($_POST['primary_admin_notification_email'] ?? '');
    $resendApiKey     = trim($_POST['resend_api_key'] ?? '');

    if ($emailFromAddress !== '' && !filter_var($emailFromAddress, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'From Email must be a valid email address.';
    }
    if ($emailReplyTo !== '' && !filter_var($emailReplyTo, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Reply-To Email must be a valid email address.';
    }
    if ($primaryAdminEmail !== '' && !filter_var($primaryAdminEmail, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Primary Admin Notification Email must be a valid email address.';
    }

    // --- Notification Preferences (one checkbox per event in the catalog) ---
    $notifPrefs = [];
    foreach (notificationEventCatalog() as $category) {
        foreach ($category['events'] as $eventKey => $event) {
            $notifPrefs[$eventKey] = isset($_POST['notif_pref'][$eventKey]) ? '1' : '0';
        }
    }

    if (empty($errors)) {
        $upsert = $db->prepare("
            INSERT INTO site_settings (setting_key, setting_val)
            VALUES (?, ?)
            ON DUPLICATE KEY UPDATE setting_val = VALUES(setting_val), updated_at = NOW()
        ");

        $upsert->execute(['premium_price_kobo',    (string)$koboInt]);
        $upsert->execute(['premium_price_display',  $rawDisplay]);
        $upsert->execute(['site_name',              $siteName]);
        $upsert->execute(['site_tagline',           $tagline]);

        if ($bountyFeeKobo !== '') $upsert->execute(['bounty_listing_fee_kobo', (string)(int)$bountyFeeKobo]);
        $upsert->execute(['bounty_listing_fee_currency', $bountyCurrency]);
        $upsert->execute(['bounty_listing_fee_enabled',  $bountyFeeEnabled]);
        $upsert->execute(['bounty_crypto_enabled',       $bountyCryptoOn]);
        $upsert->execute(['bounty_paystack_enabled',     $bountyPaystackOn]);
        $upsert->execute(['bounty_solana_wallet',        $bountySolana]);
        $upsert->execute(['bounty_ethereum_wallet',      $bountyEthereum]);
        $upsert->execute(['bounty_ton_wallet',           $bountyTon]);
        $upsert->execute(['bounty_solana_suggested_amount',   $bountySolanaAmt]);
        $upsert->execute(['bounty_ethereum_suggested_amount', $bountyEthereumAmt]);
        $upsert->execute(['bounty_ton_suggested_amount',      $bountyTonAmt]);

        // Email Settings — blank From/Reply-To/API key fields are left
        // untouched (not overwritten with empty) so the constant-based
        // fallback in EmailService.php keeps working until an admin
        // deliberately sets one.
        if ($emailFromName !== '')     $upsert->execute(['email_from_name', $emailFromName]);
        if ($emailFromAddress !== '')  $upsert->execute(['email_from_address', $emailFromAddress]);
        $upsert->execute(['email_reply_to', $emailReplyTo]); // blank is a valid "no reply-to" state
        $upsert->execute(['primary_admin_notification_email', $primaryAdminEmail]); // blank is valid — falls back to admins table only
        if ($resendApiKey !== '')      $upsert->execute(['resend_api_key', $resendApiKey]);

        // Notification Preferences
        foreach ($notifPrefs as $eventKey => $enabled) {
            $upsert->execute([notificationPreferenceSettingKey($eventKey), $enabled]);
        }

        $saved = true;
    }
}

// ── Load current settings ─────────────────────────────────────
$allSettings = [];
try {
    $rows = $db->query("SELECT setting_key, setting_val, label, description FROM site_settings ORDER BY id")->fetchAll();
    foreach ($rows as $r) {
        $allSettings[$r['setting_key']] = $r;
    }
} catch (\Throwable $e) {
    $errors[] = 'Settings table not found. Please run migration_settings.sql first.';
}

// Fallback defaults for display when table is empty / pre-migration
$premiumKobo    = (int)($allSettings['premium_price_kobo']['setting_val']    ?? PREMIUM_PRICE);
$premiumDisplay = $allSettings['premium_price_display']['setting_val']        ?? PREMIUM_PRICE_DISPLAY;
$siteName       = $allSettings['site_name']['setting_val']                    ?? SITE_NAME;
$siteTagline    = $allSettings['site_tagline']['setting_val']                 ?? SITE_TAGLINE;

// Derived naira for display
$premiumNaira   = $premiumKobo / 100;

// Bounty Marketplace settings
$bountyFeeKoboVal   = (int)($allSettings['bounty_listing_fee_kobo']['setting_val']      ?? 1000000);
$bountyCurrencyVal  = $allSettings['bounty_listing_fee_currency']['setting_val']         ?? 'NGN';
$bountyFeeOnVal     = ($allSettings['bounty_listing_fee_enabled']['setting_val']         ?? '1') === '1';
$bountyCryptoOnVal  = ($allSettings['bounty_crypto_enabled']['setting_val']              ?? '1') === '1';
$bountyPaystackOnVal= ($allSettings['bounty_paystack_enabled']['setting_val']            ?? '1') === '1';
$bountySolanaVal    = $allSettings['bounty_solana_wallet']['setting_val']                ?? '';
$bountyEthereumVal  = $allSettings['bounty_ethereum_wallet']['setting_val']              ?? '';
$bountyTonVal       = $allSettings['bounty_ton_wallet']['setting_val']                   ?? '';
$bountySolanaAmtVal   = $allSettings['bounty_solana_suggested_amount']['setting_val']    ?? '';
$bountyEthereumAmtVal = $allSettings['bounty_ethereum_suggested_amount']['setting_val']  ?? '';
$bountyTonAmtVal      = $allSettings['bounty_ton_suggested_amount']['setting_val']       ?? '';
$bountyFeeNairaVal  = $bountyFeeKoboVal / 100;

// Email Settings — falls back to the same constants EmailService.php uses,
// so the form shows what's actually active even before any admin edit.
$emailFromNameVal    = $allSettings['email_from_name']['setting_val']    ?? EMAIL_FROM_NAME;
$emailFromAddressVal = $allSettings['email_from_address']['setting_val'] ?? EMAIL_FROM_ADDRESS;
$emailReplyToVal     = $allSettings['email_reply_to']['setting_val']     ?? '';
$primaryAdminEmailVal = $allSettings['primary_admin_notification_email']['setting_val'] ?? '';
// SECURITY: never load the actual Resend API key (DB override or the
// RESEND_API_KEY constant) into a variable that gets rendered into the
// page — only whether one is currently configured, so the settings page
// can say so without ever putting the live key in HTML. See the
// "Resend API Key" field below.
$resendApiKeyConfigured = (($allSettings['resend_api_key']['setting_val'] ?? '') !== '') || RESEND_API_KEY !== '';

// Notification Preferences — default enabled ('1') for any event not yet saved.
$notifPrefVals = [];
foreach (notificationEventCatalog() as $catKey => $category) {
    foreach ($category['events'] as $eventKey => $event) {
        $notifPrefVals[$eventKey] = ($allSettings[notificationPreferenceSettingKey($eventKey)]['setting_val'] ?? '1') === '1';
    }
}
?>

<h1 class="admin-page-title">
    <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none"
         stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
         style="display:inline-block;vertical-align:middle;margin-right:8px;flex-shrink:0">
        <circle cx="12" cy="12" r="3"/><path d="M19.07 4.93a10 10 0 0 1 0 14.14M4.93 4.93a10 10 0 0 0 0 14.14"/>
        <path d="M22 12a10 10 0 0 1-10 10A10 10 0 0 1 2 12a10 10 0 0 1 10-10 10 10 0 0 1 10 10z"/>
    </svg>
    Site Settings
</h1>

<?php if ($testResult): ?>
<div style="background:<?= $testResult['success'] ? 'rgba(11,121,88,0.1);border:1px solid rgba(11,121,88,0.3);color:#0B7958' : 'rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.3);color:#fca5a5' ?>;
     border-radius:var(--radius);padding:14px 18px;margin-bottom:24px;font-size:0.9rem">
    <?= $testResult['success'] ? 'Test email sent successfully.' : 'Test email failed: ' . sanitize($testResult['error'] ?? 'Unknown error') ?>
</div>
<?php endif; ?>

<?php if ($saved): ?>
<div style="background:rgba(11,121,88,0.1);border:1px solid rgba(11,121,88,0.3);color:#0B7958;
     border-radius:var(--radius);padding:14px 18px;margin-bottom:24px;display:flex;align-items:center;gap:10px;font-size:0.9rem">
    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none"
         stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0">
        <polyline points="20 6 9 17 4 12"/>
    </svg>
    Settings saved successfully.
</div>
<?php endif; ?>

<?php if (!empty($errors)): ?>
<div style="background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.3);color:#fca5a5;
     border-radius:var(--radius);padding:14px 18px;margin-bottom:24px;font-size:0.9rem">
    <?php foreach ($errors as $e): ?>
    <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px">
        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="2" style="flex-shrink:0"><circle cx="12" cy="12" r="10"/>
            <line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
        </svg>
        <?= sanitize($e) ?>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<form method="POST">
<?= csrfField() ?>
    <input type="hidden" name="save_settings" value="1">

    <div style="display:grid;gap:24px;max-width:800px">

        <!-- ── PREMIUM PRICING CARD ───────────────────────── -->
        <div style="background:var(--bg-card);border:1.5px solid var(--border);border-radius:var(--radius-lg);overflow:hidden">

            <!-- Card header -->
            <div style="padding:20px 24px;border-bottom:1px solid var(--border);
                        background:linear-gradient(90deg,rgba(168,85,247,0.08),transparent);
                        display:flex;align-items:center;gap:12px">
                <div style="width:38px;height:38px;border-radius:10px;
                            background:linear-gradient(135deg,var(--primary),var(--pink));
                            display:flex;align-items:center;justify-content:center;flex-shrink:0">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24"
                         fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="12" y1="1" x2="12" y2="23"/>
                        <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>
                    </svg>
                </div>
                <div>
                    <div style="font-weight:700;font-size:1rem">Premium Membership Price</div>
                    <div style="color:var(--text-muted);font-size:0.8rem;margin-top:2px">
                        Controls the amount charged on the upgrade page via Paystack.
                    </div>
                </div>

                <!-- Live badge showing current price -->
                <div style="margin-left:auto;text-align:right;flex-shrink:0">
                    <div style="font-size:0.68rem;text-transform:uppercase;letter-spacing:0.08em;color:var(--text-muted);margin-bottom:3px">Current Price</div>
                    <div style="font-family:var(--font-head);font-size:1.5rem;font-weight:900;
                                background:linear-gradient(135deg,var(--primary-bright),var(--pink));
                                -webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text">
                        <?= sanitize($premiumDisplay) ?>
                    </div>
                </div>
            </div>

            <!-- Card body -->
            <div style="padding:24px;display:grid;gap:20px">

                <!-- Kobo input -->
                <div>
                    <label style="display:block;font-size:0.82rem;font-weight:600;margin-bottom:8px;color:var(--text)">
                        Amount in Kobo
                        <span style="font-weight:400;color:var(--text-muted)">(100 kobo = ₦1)</span>
                    </label>
                    <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap">
                        <input
                            type="number"
                            name="premium_price_kobo"
                            id="koboInput"
                            value="<?= (int)$premiumKobo ?>"
                            min="100"
                            max="100000000"
                            step="100"
                            required
                            style="flex:1;min-width:160px;padding:11px 16px;
                                   background:var(--bg-input);border:1.5px solid var(--border);
                                   border-radius:var(--radius);color:var(--text);font-size:0.95rem;
                                   font-family:var(--font-mono)"
                            oninput="syncNaira(this.value)"
                        >
                        <div style="padding:11px 18px;background:rgba(139,92,246,0.08);
                                    border:1.5px solid var(--border);border-radius:var(--radius);
                                    font-family:var(--font-mono);font-size:0.95rem;white-space:nowrap;
                                    color:var(--primary-bright)">
                            = <span id="nairaPreview">₦<?= number_format($premiumNaira, $premiumNaira == floor($premiumNaira) ? 0 : 2) ?></span>
                        </div>
                    </div>
                    <div style="font-size:0.76rem;color:var(--text-muted);margin-top:6px">
                        Examples: 990000 = ₦9,900 · 500000 = ₦5,000 · 100000 = ₦1,000
                    </div>
                </div>

                <!-- Display string override -->
                <div>
                    <label style="display:block;font-size:0.82rem;font-weight:600;margin-bottom:8px;color:var(--text)">
                        Display Text Override
                        <span style="font-weight:400;color:var(--text-muted)">(optional — auto-generated if left blank)</span>
                    </label>
                    <input
                        type="text"
                        name="premium_price_display"
                        id="displayInput"
                        value="<?= sanitize($premiumDisplay) ?>"
                        placeholder="e.g. ₦9,900"
                        maxlength="30"
                        style="width:100%;padding:11px 16px;
                               background:var(--bg-input);border:1.5px solid var(--border);
                               border-radius:var(--radius);color:var(--text);font-size:0.95rem"
                    >
                    <div style="font-size:0.76rem;color:var(--text-muted);margin-top:6px">
                        Shown on buttons and pricing cards. Leave blank to auto-format from kobo above.
                    </div>
                </div>

                <!-- Quick presets -->
                <div>
                    <div style="font-size:0.78rem;font-weight:600;color:var(--text-muted);margin-bottom:10px;text-transform:uppercase;letter-spacing:0.06em">
                        Quick Presets
                    </div>
                    <div style="display:flex;gap:8px;flex-wrap:wrap">
                        <?php
                        $presets = [
                            ['label'=>'₦1,000','kobo'=>100000,'display'=>'₦1,000'],
                            ['label'=>'₦2,500','kobo'=>250000,'display'=>'₦2,500'],
                            ['label'=>'₦5,000','kobo'=>500000,'display'=>'₦5,000'],
                            ['label'=>'₦9,900','kobo'=>990000,'display'=>'₦9,900'],
                            ['label'=>'₦15,000','kobo'=>1500000,'display'=>'₦15,000'],
                            ['label'=>'₦25,000','kobo'=>2500000,'display'=>'₦25,000'],
                        ];
                        foreach ($presets as $p): ?>
                        <button type="button"
                                onclick="setPreset(<?= $p['kobo'] ?>,'<?= $p['display'] ?>')"
                                style="padding:7px 14px;border-radius:8px;font-size:0.8rem;font-weight:600;
                                       background:var(--glow-soft);border:1.5px solid var(--border);
                                       color:var(--text-muted);cursor:pointer;transition:all 0.2s;
                                       <?= $p['kobo'] === $premiumKobo ? 'border-color:var(--primary);color:var(--primary-bright);background:rgba(139,92,246,0.1)' : '' ?>">
                            <?= $p['label'] ?>
                        </button>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── SITE INFO CARD ─────────────────────────────── -->
        <div style="background:var(--bg-card);border:1.5px solid var(--border);border-radius:var(--radius-lg);overflow:hidden">
            <div style="padding:20px 24px;border-bottom:1px solid var(--border);
                        display:flex;align-items:center;gap:12px">
                <div style="width:38px;height:38px;border-radius:10px;
                            background:rgba(139,92,246,0.12);border:1.5px solid var(--border);
                            display:flex;align-items:center;justify-content:center;flex-shrink:0">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24"
                         fill="none" stroke="var(--primary-bright)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="10"/>
                        <line x1="2" y1="12" x2="22" y2="12"/>
                        <path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>
                    </svg>
                </div>
                <div>
                    <div style="font-weight:700;font-size:1rem">Site Information</div>
                    <div style="color:var(--text-muted);font-size:0.8rem;margin-top:2px">
                        Brand name and tagline shown across the platform.
                    </div>
                </div>
            </div>
            <div style="padding:24px;display:grid;gap:20px">
                <div>
                    <label style="display:block;font-size:0.82rem;font-weight:600;margin-bottom:8px;color:var(--text)">
                        Site Name
                    </label>
                    <input
                        type="text"
                        name="site_name"
                        value="<?= sanitize($siteName) ?>"
                        maxlength="80"
                        required
                        style="width:100%;padding:11px 16px;
                               background:var(--bg-input);border:1.5px solid var(--border);
                               border-radius:var(--radius);color:var(--text);font-size:0.95rem"
                    >
                </div>
                <div>
                    <label style="display:block;font-size:0.82rem;font-weight:600;margin-bottom:8px;color:var(--text)">
                        Site Tagline
                    </label>
                    <input
                        type="text"
                        name="site_tagline"
                        value="<?= sanitize($siteTagline) ?>"
                        maxlength="160"
                        style="width:100%;padding:11px 16px;
                               background:var(--bg-input);border:1.5px solid var(--border);
                               border-radius:var(--radius);color:var(--text);font-size:0.95rem"
                    >
                </div>
            </div>
        </div>

        <!-- ── EMAIL SETTINGS CARD ────────────────────────── -->
        <div style="background:var(--bg-card);border:1.5px solid var(--border);border-radius:var(--radius-lg);overflow:hidden">
            <div style="padding:20px 24px;border-bottom:1px solid var(--border);
                        display:flex;align-items:center;gap:12px">
                <div style="width:38px;height:38px;border-radius:10px;
                            background:rgba(139,92,246,0.12);border:1.5px solid var(--border);
                            display:flex;align-items:center;justify-content:center;flex-shrink:0">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24"
                         fill="none" stroke="var(--primary-bright)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/>
                        <polyline points="22,6 12,13 2,6"/>
                    </svg>
                </div>
                <div>
                    <div style="font-weight:700;font-size:1rem">Email Settings</div>
                    <div style="color:var(--text-muted);font-size:0.8rem;margin-top:2px">
                        Sender identity and the Resend API key used for every outgoing email.
                    </div>
                </div>
            </div>
            <div style="padding:24px;display:grid;gap:20px">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                    <div>
                        <label style="display:block;font-size:0.82rem;font-weight:600;margin-bottom:8px;color:var(--text)">From Name</label>
                        <input type="text" name="email_from_name" value="<?= sanitize($emailFromNameVal) ?>" maxlength="80" placeholder="Web3Edu"
                               style="width:100%;padding:11px 16px;background:var(--bg-input);border:1.5px solid var(--border);
                                      border-radius:var(--radius);color:var(--text);font-size:0.95rem">
                    </div>
                    <div>
                        <label style="display:block;font-size:0.82rem;font-weight:600;margin-bottom:8px;color:var(--text)">From Email</label>
                        <input type="email" name="email_from_address" value="<?= sanitize($emailFromAddressVal) ?>" placeholder="noreply@web3education.xyz"
                               style="width:100%;padding:11px 16px;background:var(--bg-input);border:1.5px solid var(--border);
                                      border-radius:var(--radius);color:var(--text);font-size:0.95rem;font-family:var(--font-mono)">
                    </div>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                    <div>
                        <label style="display:block;font-size:0.82rem;font-weight:600;margin-bottom:8px;color:var(--text)">
                            Reply-To Email <span style="font-weight:400;color:var(--text-muted)">(optional)</span>
                        </label>
                        <input type="email" name="email_reply_to" value="<?= sanitize($emailReplyToVal) ?>" placeholder="support@web3education.xyz"
                               style="width:100%;padding:11px 16px;background:var(--bg-input);border:1.5px solid var(--border);
                                      border-radius:var(--radius);color:var(--text);font-size:0.95rem;font-family:var(--font-mono)">
                    </div>
                    <div>
                        <label style="display:block;font-size:0.82rem;font-weight:600;margin-bottom:8px;color:var(--text)">Primary Admin Notification Email</label>
                        <input type="email" name="primary_admin_notification_email" value="<?= sanitize($primaryAdminEmailVal) ?>" placeholder="admin@web3education.xyz"
                               style="width:100%;padding:11px 16px;background:var(--bg-input);border:1.5px solid var(--border);
                                      border-radius:var(--radius);color:var(--text);font-size:0.95rem;font-family:var(--font-mono)">
                        <div style="font-size:0.76rem;color:var(--text-muted);margin-top:6px">
                            Added to every admin account's email for admin-facing notifications — doesn't replace them.
                        </div>
                    </div>
                </div>
                <div>
                    <label style="display:block;font-size:0.82rem;font-weight:600;margin-bottom:8px;color:var(--text)">Resend API Key</label>
                    <input type="password" name="resend_api_key" value="" autocomplete="new-password"
                           placeholder="<?= $resendApiKeyConfigured ? 'Key configured — leave blank to keep it' : 're_...' ?>"
                           style="width:100%;padding:11px 16px;background:var(--bg-input);border:1.5px solid var(--border);
                                  border-radius:var(--radius);color:var(--text);font-size:0.9rem;font-family:var(--font-mono)">
                    <div style="font-size:0.76rem;color:var(--text-muted);margin-top:6px">
                        <?= $resendApiKeyConfigured
                            ? 'A key is currently configured and hidden for security. Enter a value here only to replace it — leave blank to keep the current one.'
                            : 'No key configured yet — email sending will fail until one is set.' ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── NOTIFICATION PREFERENCES CARD ─────────────── -->
        <div style="background:var(--bg-card);border:1.5px solid var(--border);border-radius:var(--radius-lg);overflow:hidden">
            <div style="padding:20px 24px;border-bottom:1px solid var(--border);
                        display:flex;align-items:center;gap:12px">
                <div style="width:38px;height:38px;border-radius:10px;
                            background:rgba(139,92,246,0.12);border:1.5px solid var(--border);
                            display:flex;align-items:center;justify-content:center;flex-shrink:0">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24"
                         fill="none" stroke="var(--primary-bright)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/>
                    </svg>
                </div>
                <div>
                    <div style="font-weight:700;font-size:1rem">Notification Preferences</div>
                    <div style="color:var(--text-muted);font-size:0.8rem;margin-top:2px">
                        Turns off <em>email</em> for an event — the in-app notification (bell/history) is unaffected.
                    </div>
                </div>
            </div>
            <div style="padding:24px;display:grid;gap:24px">
                <?php foreach (notificationEventCatalog() as $category): ?>
                <div>
                    <div style="font-size:0.78rem;font-weight:600;color:var(--text-muted);margin-bottom:10px;text-transform:uppercase;letter-spacing:0.06em">
                        <?= sanitize($category['label']) ?>
                    </div>
                    <div style="display:grid;gap:10px">
                        <?php foreach ($category['events'] as $eventKey => $event): ?>
                        <label style="display:flex;align-items:center;gap:10px;font-size:0.88rem;cursor:pointer">
                            <input type="checkbox" name="notif_pref[<?= sanitize($eventKey) ?>]" value="1" <?= $notifPrefVals[$eventKey] ? 'checked' : '' ?>>
                            <?= sanitize($event['label']) ?>
                            <?php if (($event['wired'] ?? true) === false): ?>
                                <span style="font-size:0.7rem;color:var(--text-muted);font-weight:400">(not yet triggered — reserved for a future phase)</span>
                            <?php endif; ?>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- ── BOUNTY MARKETPLACE SETTINGS CARD ──────────── -->
        <div style="background:var(--bg-card);border:1.5px solid var(--border);border-radius:var(--radius-lg);overflow:hidden">
            <div style="padding:20px 24px;border-bottom:1px solid var(--border);
                        background:linear-gradient(90deg,rgba(168,85,247,0.08),transparent);
                        display:flex;align-items:center;gap:12px">
                <div style="width:38px;height:38px;border-radius:10px;
                            background:linear-gradient(135deg,var(--primary),var(--pink));
                            display:flex;align-items:center;justify-content:center;flex-shrink:0">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24"
                         fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>
                    </svg>
                </div>
                <div>
                    <div style="font-weight:700;font-size:1rem">Bounty Marketplace Settings</div>
                    <div style="color:var(--text-muted);font-size:0.8rem;margin-top:2px">
                        Listing fee, payment methods, and receiving wallets for Hosted Bounties.
                    </div>
                </div>
            </div>

            <div style="padding:24px;display:grid;gap:20px">

                <!-- Listing fee amount + currency -->
                <div style="display:grid;grid-template-columns:2fr 1fr;gap:12px">
                    <div>
                        <label style="display:block;font-size:0.82rem;font-weight:600;margin-bottom:8px;color:var(--text)">
                            Hosted Listing Fee Amount <span style="font-weight:400;color:var(--text-muted)">(smallest unit, e.g. kobo)</span>
                        </label>
                        <input type="number" name="bounty_listing_fee_kobo" value="<?= (int)$bountyFeeKoboVal ?>" min="0" step="100"
                               style="width:100%;padding:11px 16px;background:var(--bg-input);border:1.5px solid var(--border);
                                      border-radius:var(--radius);color:var(--text);font-size:0.95rem;font-family:var(--font-mono)">
                        <div style="font-size:0.76rem;color:var(--text-muted);margin-top:6px">
                            = <?= sanitize($bountyCurrencyVal) ?> <?= number_format($bountyFeeNairaVal, $bountyFeeNairaVal == floor($bountyFeeNairaVal) ? 0 : 2) ?> per Hosted Bounty. Set to 0 to make every host fee-exempt.
                        </div>
                    </div>
                    <div>
                        <label style="display:block;font-size:0.82rem;font-weight:600;margin-bottom:8px;color:var(--text)">
                            Currency
                        </label>
                        <input type="text" name="bounty_listing_fee_currency" value="<?= sanitize($bountyCurrencyVal) ?>" maxlength="10"
                               placeholder="NGN"
                               style="width:100%;padding:11px 16px;background:var(--bg-input);border:1.5px solid var(--border);
                                      border-radius:var(--radius);color:var(--text);font-size:0.95rem">
                    </div>
                </div>

                <!-- Enable/disable toggles -->
                <div style="display:grid;gap:12px">
                    <label style="display:flex;align-items:center;gap:10px;font-size:0.88rem;cursor:pointer">
                        <input type="checkbox" name="bounty_listing_fee_enabled" value="1" <?= $bountyFeeOnVal ? 'checked' : '' ?>>
                        Enable Listing Fee <span style="color:var(--text-muted);font-weight:400">— when off, every Hosted Bounty skips payment and goes straight to Pending Admin Review after approval</span>
                    </label>
                    <label style="display:flex;align-items:center;gap:10px;font-size:0.88rem;cursor:pointer">
                        <input type="checkbox" name="bounty_paystack_enabled" value="1" <?= $bountyPaystackOnVal ? 'checked' : '' ?>>
                        Enable Paystack Payments
                    </label>
                    <label style="display:flex;align-items:center;gap:10px;font-size:0.88rem;cursor:pointer">
                        <input type="checkbox" name="bounty_crypto_enabled" value="1" <?= $bountyCryptoOnVal ? 'checked' : '' ?>>
                        Enable Crypto Payments
                    </label>
                </div>

                <!-- Crypto Payment Settings -->
                <div>
                    <div style="font-size:0.78rem;font-weight:600;color:var(--text-muted);margin-bottom:10px;text-transform:uppercase;letter-spacing:0.06em">
                        Crypto Payment Settings
                    </div>
                    <div style="display:grid;gap:16px">

                        <div style="border:1px solid var(--border);border-radius:var(--radius);padding:14px 16px">
                            <div style="font-weight:600;font-size:0.85rem;margin-bottom:10px">TON</div>
                            <div style="display:grid;grid-template-columns:2fr 1fr;gap:12px">
                                <div>
                                    <label style="display:block;font-size:0.76rem;margin-bottom:6px;color:var(--text-muted)">Wallet Address</label>
                                    <input type="text" name="bounty_ton_wallet" value="<?= sanitize($bountyTonVal) ?>" placeholder="EQxxxxxxxxxxxxxxxx"
                                           style="width:100%;padding:10px 14px;background:var(--bg-input);border:1.5px solid var(--border);
                                                  border-radius:var(--radius);color:var(--text);font-size:0.88rem;font-family:var(--font-mono)">
                                </div>
                                <div>
                                    <label style="display:block;font-size:0.76rem;margin-bottom:6px;color:var(--text-muted)">Suggested Amount</label>
                                    <input type="text" name="bounty_ton_suggested_amount" value="<?= sanitize($bountyTonAmtVal) ?>" placeholder="2 TON"
                                           style="width:100%;padding:10px 14px;background:var(--bg-input);border:1.5px solid var(--border);
                                                  border-radius:var(--radius);color:var(--text);font-size:0.88rem">
                                </div>
                            </div>
                        </div>

                        <div style="border:1px solid var(--border);border-radius:var(--radius);padding:14px 16px">
                            <div style="font-weight:600;font-size:0.85rem;margin-bottom:10px">Solana</div>
                            <div style="display:grid;grid-template-columns:2fr 1fr;gap:12px">
                                <div>
                                    <label style="display:block;font-size:0.76rem;margin-bottom:6px;color:var(--text-muted)">Wallet Address</label>
                                    <input type="text" name="bounty_solana_wallet" value="<?= sanitize($bountySolanaVal) ?>" placeholder="Solana wallet address"
                                           style="width:100%;padding:10px 14px;background:var(--bg-input);border:1.5px solid var(--border);
                                                  border-radius:var(--radius);color:var(--text);font-size:0.88rem;font-family:var(--font-mono)">
                                </div>
                                <div>
                                    <label style="display:block;font-size:0.76rem;margin-bottom:6px;color:var(--text-muted)">Suggested Amount</label>
                                    <input type="text" name="bounty_solana_suggested_amount" value="<?= sanitize($bountySolanaAmtVal) ?>" placeholder="3 SOL"
                                           style="width:100%;padding:10px 14px;background:var(--bg-input);border:1.5px solid var(--border);
                                                  border-radius:var(--radius);color:var(--text);font-size:0.88rem">
                                </div>
                            </div>
                        </div>

                        <div style="border:1px solid var(--border);border-radius:var(--radius);padding:14px 16px">
                            <div style="font-weight:600;font-size:0.85rem;margin-bottom:10px">Ethereum</div>
                            <div style="display:grid;grid-template-columns:2fr 1fr;gap:12px">
                                <div>
                                    <label style="display:block;font-size:0.76rem;margin-bottom:6px;color:var(--text-muted)">Wallet Address</label>
                                    <input type="text" name="bounty_ethereum_wallet" value="<?= sanitize($bountyEthereumVal) ?>" placeholder="0x...."
                                           style="width:100%;padding:10px 14px;background:var(--bg-input);border:1.5px solid var(--border);
                                                  border-radius:var(--radius);color:var(--text);font-size:0.88rem;font-family:var(--font-mono)">
                                </div>
                                <div>
                                    <label style="display:block;font-size:0.76rem;margin-bottom:6px;color:var(--text-muted)">Suggested Amount</label>
                                    <input type="text" name="bounty_ethereum_suggested_amount" value="<?= sanitize($bountyEthereumAmtVal) ?>" placeholder="0.015 ETH"
                                           style="width:100%;padding:10px 14px;background:var(--bg-input);border:1.5px solid var(--border);
                                                  border-radius:var(--radius);color:var(--text);font-size:0.88rem">
                                </div>
                            </div>
                        </div>

                    </div>
                </div>
            </div>
        </div>

        <!-- ── SAVE BUTTON ───────────────────────────────── -->
        <div style="display:flex;gap:14px;align-items:center;flex-wrap:wrap">
            <button type="submit" class="btn-upgrade btn-lg" style="min-width:180px;justify-content:center">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"
                     fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="20 6 9 17 4 12"/>
                </svg>
                Save Settings
            </button>
            <a href="<?= SITE_URL ?>/upgrade.php" target="_blank" class="btn-ghost btn-sm">
                Preview Upgrade Page ↗
            </a>
            <a href="<?= SITE_URL ?>/admin/bounty-payments.php" class="btn-ghost btn-sm">
                Listing Payments ↗
            </a>
        </div>
    </div>
</form>

<!-- ── SEND TEST EMAIL (config-level) ──────────────────────── -->
<!-- Separate small form/POST action, same pattern as admin/notifications.php's
     manual-send panel — tests From/Reply-To/API key directly rather than any
     one stored template (that per-template test already exists on
     admin/email-templates.php and is untouched). -->
<div style="background:var(--bg-card);border:1.5px solid var(--border);border-radius:var(--radius-lg);
            overflow:hidden;max-width:800px;margin-top:24px">
    <div style="padding:20px 24px;border-bottom:1px solid var(--border)">
        <div style="font-weight:700;font-size:1rem">Send Test Email</div>
        <div style="color:var(--text-muted);font-size:0.8rem;margin-top:2px">
            Sends a real email using the current configuration above — save any changes first.
        </div>
    </div>
    <div style="padding:24px">
        <form method="POST" style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end">
        <?= csrfField() ?>
            <input type="hidden" name="action" value="send_config_test">
            <div style="flex:1;min-width:220px">
                <label style="display:block;font-size:0.82rem;font-weight:600;margin-bottom:8px;color:var(--text)">Send to</label>
                <input type="email" name="test_email" required placeholder="you@example.com"
                       style="width:100%;padding:11px 16px;background:var(--bg-input);border:1.5px solid var(--border);
                              border-radius:var(--radius);color:var(--text);font-size:0.95rem">
            </div>
            <button type="submit" class="btn-secondary">Send Test Email</button>
        </form>
    </div>
</div>

<script>
function syncNaira(koboVal) {
    const kobo   = parseInt(koboVal, 10);
    const el     = document.getElementById('nairaPreview');
    const dispEl = document.getElementById('displayInput');
    if (isNaN(kobo) || kobo < 100) {
        el.textContent = '—';
        return;
    }
    const naira     = kobo / 100;
    const formatted = '₦' + naira.toLocaleString('en-NG', {minimumFractionDigits: naira % 1 ? 2 : 0, maximumFractionDigits: 2});
    el.textContent  = formatted;
    // Auto-fill display if it was auto-generated or empty
    if (!dispEl.dataset.manual) dispEl.value = formatted;
}

// Mark display as manually edited so we stop auto-filling
document.getElementById('displayInput').addEventListener('input', function() {
    this.dataset.manual = '1';
});

function setPreset(kobo, display) {
    document.getElementById('koboInput').value  = kobo;
    document.getElementById('displayInput').value = display;
    delete document.getElementById('displayInput').dataset.manual;
    syncNaira(kobo);

    // Highlight active preset button
    document.querySelectorAll('[onclick^="setPreset"]').forEach(btn => {
        btn.style.borderColor   = 'var(--border)';
        btn.style.color         = 'var(--text-muted)';
        btn.style.background    = 'var(--glow-soft)';
    });
    event.target.style.borderColor = 'var(--primary)';
    event.target.style.color       = 'var(--primary-bright)';
    event.target.style.background  = 'rgba(139,92,246,0.1)';
}
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
