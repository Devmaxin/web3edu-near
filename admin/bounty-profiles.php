<?php
/**
 * Admin — Bounty Profiles (feature 2). Admins can search every user who has
 * completed a Bounty Profile, view their wallets/bank/social info, and edit
 * any field — same backend (saveBountyProfile()) the user-facing
 * bounty-profile.php page uses, gated through canEditBountyProfile().
 */
$adminTitle = 'Bounty Profiles';
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/../includes/bounty_auth.php';
require_once __DIR__ . '/../includes/countries.php';

$db = getDB();
$id = (int)($_GET['id'] ?? 0);
$search = trim($_GET['search'] ?? '');
$errors = []; $success = '';

if ($id && $_SERVER['REQUEST_METHOD'] === 'POST' && canEditBountyProfile(0, $id)) {
    requireCsrf();
    $stmt = $db->prepare("SELECT id FROM bounty_profiles WHERE user_id = ?");
    $stmt->execute([$id]);
    if ($stmt->fetch()) {
        if (saveBountyProfile($id, $_POST)) {
            $success = 'Profile updated.';
        } else {
            $errors[] = 'Display Name, Country, and Twitter/X are required.';
        }
    }
}

if ($id) {
    $viewStmt = $db->prepare("SELECT bp.*, u.username, u.email FROM bounty_profiles bp INNER JOIN users u ON u.id = bp.user_id WHERE bp.user_id = ?");
    $viewStmt->execute([$id]);
    $profile = $viewStmt->fetch();
    if (!$profile) { header('Location: ' . SITE_URL . '/admin/bounty-profiles.php'); exit; }
    $v = fn($field) => sanitize((string)($profile[$field] ?? ''));
} else {
    $profiles = listBountyProfilesAdmin($search);
}
?>

<?php if ($id): ?>
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:12px">
        <div>
            <a href="<?= SITE_URL ?>/admin/bounty-profiles.php" class="btn-ghost btn-sm" style="margin-bottom:8px;display:inline-flex"><?= lucide('arrow-left', ['size' => 13]) ?> Back to Bounty Profiles</a>
            <h1 class="admin-page-title" style="margin:0;display:flex;align-items:center;gap:8px"><?= lucide('user', ['size' => 18]) ?> <?= $v('display_name') ?: sanitize($profile['username']) ?></h1>
        </div>
    </div>

    <?php if (!empty($errors)): ?><div class="admin-alert admin-alert-error"><?php foreach ($errors as $e): ?><div><?= sanitize($e) ?></div><?php endforeach; ?></div><?php endif; ?>
    <?php if ($success): ?><div class="admin-alert admin-alert-success"><?= sanitize($success) ?></div><?php endif; ?>

    <div style="background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-lg);padding:24px;max-width:680px">
        <form method="POST">
            <?= csrfField() ?>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px">
                <div class="form-group"><label class="form-label">Web3Edu Account</label><input type="text" class="form-input" value="<?= sanitize($profile['username']) ?> (<?= sanitize($profile['email']) ?>)" disabled></div>
                <div class="form-group"><label class="form-label">Last Updated</label><input type="text" class="form-input" value="<?= date('M j, Y g:ia', strtotime($profile['updated_at'])) ?>" disabled></div>
            </div>

            <h2 style="font-size:0.9rem;font-weight:700;margin:20px 0 12px">Basic Information</h2>
            <div class="form-group"><label class="form-label">Display Name</label><input type="text" name="display_name" class="form-input" maxlength="100" value="<?= $v('display_name') ?>" required></div>
            <div class="form-group"><label class="form-label">Bio</label><textarea name="bio" class="form-input" rows="2" maxlength="500"><?= $v('bio') ?></textarea></div>
            <div class="form-group">
                <label class="form-label">Country</label>
                <select name="country" id="apCountry" class="form-select" required>
                    <?php foreach (bountyCountryList() as $c): ?><option value="<?= sanitize($c) ?>" <?= $profile['country'] === $c ? 'selected' : '' ?>><?= sanitize($c) ?></option><?php endforeach; ?>
                </select>
            </div>

            <h2 style="font-size:0.9rem;font-weight:700;margin:20px 0 12px"><?= lucide('at-sign', ['size' => 14]) ?> Social Links</h2>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                <div class="form-group"><label class="form-label">Twitter / X</label><input type="text" name="twitter" class="form-input" value="<?= $v('twitter') ?>" required></div>
                <div class="form-group"><label class="form-label">Discord</label><input type="text" name="discord" class="form-input" value="<?= $v('discord') ?>"></div>
                <div class="form-group"><label class="form-label">Telegram</label><input type="text" name="telegram" class="form-input" value="<?= $v('telegram') ?>"></div>
                <div class="form-group"><label class="form-label">GitHub</label><input type="text" name="github" class="form-input" value="<?= $v('github') ?>"></div>
                <div class="form-group" style="grid-column:1/-1"><label class="form-label">Portfolio Website</label><input type="text" name="portfolio_url" class="form-input" value="<?= $v('portfolio_url') ?>"></div>
            </div>

            <h2 style="font-size:0.9rem;font-weight:700;margin:20px 0 12px"><?= lucide('wallet', ['size' => 14]) ?> Wallets</h2>
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px">
                <div class="form-group"><label class="form-label">Solana</label><input type="text" name="solana_wallet" class="form-input" value="<?= $v('solana_wallet') ?>"></div>
                <div class="form-group"><label class="form-label">Ethereum</label><input type="text" name="ethereum_wallet" class="form-input" value="<?= $v('ethereum_wallet') ?>"></div>
                <div class="form-group"><label class="form-label">TON</label><input type="text" name="ton_wallet" class="form-input" value="<?= $v('ton_wallet') ?>"></div>
            </div>

            <div id="apBankSection" style="<?= $profile['country'] === 'Nigeria' ? '' : 'display:none' ?>">
                <h2 style="font-size:0.9rem;font-weight:700;margin:20px 0 12px"><?= lucide('credit-card', ['size' => 14]) ?> Bank (Nigeria)</h2>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                    <div class="form-group"><label class="form-label">Bank Name</label><input type="text" name="bank_name" class="form-input" value="<?= $v('bank_name') ?>"></div>
                    <div class="form-group"><label class="form-label">Account Number</label><input type="text" name="account_number" class="form-input" value="<?= $v('account_number') ?>"></div>
                </div>
            </div>

            <button type="submit" class="btn-primary" style="width:100%;margin-top:12px">Save Changes</button>
        </form>
    </div>

    <script>
    (function() {
        var c = document.getElementById('apCountry'), b = document.getElementById('apBankSection');
        if (!c || !b) return;
        c.addEventListener('change', function() { b.style.display = (c.value === 'Nigeria') ? '' : 'none'; });
    })();
    </script>

<?php else: ?>
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:12px">
        <h1 class="admin-page-title" style="margin:0">Bounty Profiles</h1>
        <a href="<?= SITE_URL ?>/admin/bounties.php" class="btn-ghost btn-sm"><?= lucide('arrow-left', ['size' => 13]) ?> Bounties</a>
    </div>

    <form method="GET" style="margin-bottom:16px;max-width:360px">
        <input type="text" name="search" class="form-input" placeholder="Search by username, email, or display name…" value="<?= sanitize($search) ?>">
    </form>

    <div class="admin-table-wrap">
    <table class="admin-table">
    <thead><tr><th>Display Name</th><th>Account</th><th>Country</th><th>Wallets</th><th>Bank</th><th>Updated</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($profiles as $p): ?>
    <tr>
        <td style="font-weight:600"><?= sanitize($p['display_name']) ?></td>
        <td style="font-size:0.78rem;color:var(--text-muted)"><?= sanitize($p['username']) ?><br><?= sanitize($p['email']) ?></td>
        <td style="font-size:0.78rem"><?= sanitize($p['country']) ?></td>
        <td style="text-align:center;color:#F59E0B"><?= ($p['solana_wallet'] || $p['ethereum_wallet'] || $p['ton_wallet']) ? lucide('check-circle', ['size' => 14]) : '<span style="color:var(--text-muted)">—</span>' ?></td>
        <td style="text-align:center;color:#F59E0B"><?= $p['bank_name'] ? lucide('check-circle', ['size' => 14]) : '<span style="color:var(--text-muted)">—</span>' ?></td>
        <td style="font-size:0.72rem;color:var(--text-muted)"><?= date('M j, Y', strtotime($p['updated_at'])) ?></td>
        <td><a href="?id=<?= (int)$p['user_id'] ?>" class="btn-ghost btn-sm"><?= lucide('eye', ['size' => 12]) ?> View / Edit</a></td>
    </tr>
    <?php endforeach; ?>
    <?php if (empty($profiles)): ?><tr><td colspan="7" class="admin-empty"><?= lucide('inbox', ['size' => 16]) ?> No bounty profiles found.</td></tr><?php endif; ?>
    </tbody>
    </table>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/footer.php'; ?>
