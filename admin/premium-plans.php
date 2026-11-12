<?php
$adminTitle = 'Premium Plans';
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/../includes/subscriptions.php';

$db     = getDB();
$action = $_GET['action'] ?? 'list';
$id     = intval($_GET['id'] ?? 0);
$success = ''; $errors = [];

// ── Toggle active ────────────────────────────────────────────
if ($action === 'toggle' && $id) {
    $db->prepare("UPDATE premium_plans SET is_active = NOT is_active WHERE id = ?")->execute([$id]);
    header('Location: ' . SITE_URL . '/admin/premium-plans.php'); exit;
}

// ── Soft delete ──────────────────────────────────────────────
if ($action === 'delete' && $id) {
    $db->prepare("UPDATE premium_plans SET deleted_at = NOW(), deleted_by = ? WHERE id = ?")
       ->execute([$_SESSION['admin_id'] ?? null, $id]);
    header('Location: ' . SITE_URL . '/admin/premium-plans.php?deleted=1'); exit;
}

// ── CREATE / UPDATE ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name          = trim($_POST['name'] ?? '');
    $slug          = trim($_POST['slug'] ?? '');
    $billingCycle  = $_POST['billing_cycle'] ?? 'monthly';
    $priceNaira    = (float)($_POST['price_naira'] ?? 0);
    $description   = trim($_POST['description'] ?? '');
    $isRecommended = isset($_POST['is_recommended']) ? 1 : 0;
    $isActive      = isset($_POST['is_active']) ? 1 : 0;
    $sortOrder     = intval($_POST['sort_order'] ?? 0);
    $providerPlanCode = trim($_POST['provider_plan_code'] ?? '');
    $featuresRaw   = trim($_POST['features'] ?? '');
    $features      = array_filter(array_map('trim', explode("\n", $featuresRaw)));

    if (empty($name)) $errors[] = 'Plan name is required.';
    if (empty($slug)) $slug = strtolower(preg_replace('/[^a-z0-9-]+/', '-', strtolower($name)));
    if (!in_array($billingCycle, ['monthly', 'yearly'], true)) $errors[] = 'Invalid billing cycle.';
    if ($priceNaira <= 0) $errors[] = 'Price must be greater than zero.';

    if (empty($errors)) {
        $checkSql = $id ? "SELECT id FROM premium_plans WHERE slug=? AND id!=?" : "SELECT id FROM premium_plans WHERE slug=?";
        $checkParams = $id ? [$slug, $id] : [$slug];
        $exists = $db->prepare($checkSql); $exists->execute($checkParams);
        if ($exists->fetch()) $errors[] = 'That slug is already taken.';
    }

    if (empty($errors)) {
        $priceKobo = (int)round($priceNaira * 100);

        if ($id) {
            $db->prepare("
                UPDATE premium_plans
                SET name=?, slug=?, billing_cycle=?, price_kobo=?, description=?, is_recommended=?, is_active=?, sort_order=?, provider_plan_code=?
                WHERE id=?
            ")->execute([$name, $slug, $billingCycle, $priceKobo, $description, $isRecommended, $isActive, $sortOrder, $providerPlanCode ?: null, $id]);
            $success = 'Plan updated successfully.';
        } else {
            $db->prepare("
                INSERT INTO premium_plans (name, slug, billing_cycle, price_kobo, description, is_recommended, is_active, sort_order, provider_plan_code)
                VALUES (?,?,?,?,?,?,?,?,?)
            ")->execute([$name, $slug, $billingCycle, $priceKobo, $description, $isRecommended, $isActive, $sortOrder, $providerPlanCode ?: null]);
            $id = (int)$db->lastInsertId();
            $success = 'Plan created successfully.';
        }

        // Replace feature list wholesale (simplest correct approach for a short list)
        $db->prepare("DELETE FROM plan_features WHERE plan_id = ?")->execute([$id]);
        foreach ($features as $i => $feature) {
            $db->prepare("INSERT INTO plan_features (plan_id, feature_text, sort_order) VALUES (?,?,?)")->execute([$id, $feature, $i]);
        }
        $action = 'edit';
    }
}

// ── LOAD for edit ────────────────────────────────────────────
$plan = null; $planFeatures = [];
if ($action === 'edit' && $id) {
    $stmt = $db->prepare("SELECT * FROM premium_plans WHERE id = ?");
    $stmt->execute([$id]);
    $plan = $stmt->fetch();
    if (!$plan) { header('Location: ' . SITE_URL . '/admin/premium-plans.php'); exit; }
    $fStmt = $db->prepare("SELECT feature_text FROM plan_features WHERE plan_id = ? ORDER BY sort_order ASC");
    $fStmt->execute([$id]);
    $planFeatures = $fStmt->fetchAll(PDO::FETCH_COLUMN);
}

// ── LIST DATA ────────────────────────────────────────────────
$plans = $db->query("
    SELECT pp.*, (SELECT COUNT(*) FROM user_subscriptions WHERE plan_id=pp.id AND status='active') AS active_subs
    FROM premium_plans pp WHERE pp.deleted_at IS NULL ORDER BY pp.sort_order ASC
")->fetchAll();
?>

<?php if (isset($_GET['deleted'])): ?>
<div class="admin-alert admin-alert-success">Plan deleted.</div>
<?php endif; ?>
<?php if ($success): ?>
<div class="admin-alert admin-alert-success"><?= sanitize($success) ?></div>
<?php endif; ?>
<?php if (!empty($errors)): ?>
<div class="admin-alert admin-alert-error"><?php foreach ($errors as $e): ?><div><?= sanitize($e) ?></div><?php endforeach; ?></div>
<?php endif; ?>

<?php if ($action === 'list'): ?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:24px;flex-wrap:wrap;gap:12px">
    <h1 class="admin-page-title" style="margin:0">Premium Plans <span style="font-size:0.75rem;font-weight:400;color:var(--text-muted);margin-left:8px">(<?= count($plans) ?>)</span></h1>
    <a href="?action=edit" class="btn-primary btn-sm">+ New Plan</a>
</div>

<div style="background:rgba(139,92,246,0.07);border:1px solid rgba(139,92,246,0.2);border-radius:var(--radius);padding:12px 16px;margin-bottom:22px;font-size:0.84rem;color:var(--text-muted)">
    <strong style="color:var(--primary-bright)">No hardcoded prices:</strong> everything shown on <a href="<?= SITE_URL ?>/upgrade.php" target="_blank" style="color:var(--primary-bright)">/upgrade.php</a> comes from this table. Disabling a plan hides it from new checkouts but doesn't affect existing subscribers.
</div>

<div class="admin-table-wrap">
<table class="admin-table">
<thead><tr><th>Order</th><th>Name</th><th>Cycle</th><th>Price</th><th>Recommended</th><th>Active subs</th><th>Status</th><th>Actions</th></tr></thead>
<tbody>
<?php foreach ($plans as $p): ?>
<tr>
    <td style="text-align:center;color:var(--text-muted)"><?= (int)$p['sort_order'] ?></td>
    <td style="font-weight:600"><?= sanitize($p['name']) ?></td>
    <td><?= ucfirst($p['billing_cycle']) ?></td>
    <td style="font-family:var(--font-mono)">₦<?= number_format($p['price_kobo']/100, 0) ?></td>
    <td style="text-align:center"><?= $p['is_recommended'] ? '★' : '—' ?></td>
    <td style="text-align:center"><?= (int)$p['active_subs'] ?></td>
    <td style="text-align:center">
        <a href="?action=toggle&id=<?= $p['id'] ?>" style="padding:3px 10px;border-radius:20px;font-size:0.72rem;font-weight:700;text-decoration:none;<?= $p['is_active'] ? 'background:rgba(11,121,88,0.12);color:#0B7958' : 'background:rgba(148,148,163,0.12);color:var(--text-muted)' ?>">
            <?= $p['is_active'] ? 'Active' : 'Disabled' ?>
        </a>
    </td>
    <td>
        <div style="display:flex;gap:6px">
            <a href="?action=edit&id=<?= $p['id'] ?>" class="btn-ghost btn-sm">Edit</a>
            <a href="?action=delete&id=<?= $p['id'] ?>" class="btn-danger btn-sm" onclick="return confirm('Delete this plan? Existing subscribers keep their access but new checkouts won\'t see it.')">Delete</a>
        </div>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

<?php elseif ($action === 'edit'): ?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:24px;flex-wrap:wrap;gap:12px">
    <h1 class="admin-page-title" style="margin:0"><?= $id ? 'Edit Plan' : 'New Plan' ?></h1>
    <a href="<?= SITE_URL ?>/admin/premium-plans.php" class="btn-ghost btn-sm">← Back</a>
</div>

<div style="background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-lg);padding:28px;max-width:640px">
<form method="POST">
    <div class="form-group">
        <label class="form-label">Plan Name <span style="color:var(--danger)">*</span></label>
        <input type="text" name="name" class="form-input" value="<?= sanitize($plan['name'] ?? '') ?>" placeholder="e.g. Yearly" required>
    </div>

    <div class="form-group">
        <label class="form-label">Slug</label>
        <input type="text" name="slug" class="form-input" value="<?= sanitize($plan['slug'] ?? '') ?>" placeholder="auto-generated if blank" pattern="[a-z0-9-]+">
    </div>

    <div class="form-group">
        <label class="form-label">Billing Cycle <span style="color:var(--danger)">*</span></label>
        <select name="billing_cycle" class="form-input" required>
            <?php foreach (['monthly'=>'Monthly','yearly'=>'Yearly'] as $val => $label): ?>
                <option value="<?= $val ?>" <?= ($plan['billing_cycle'] ?? '') === $val ? 'selected' : '' ?>><?= $label ?></option>
            <?php endforeach; ?>
        </select>
        <div class="form-hint">Web3Edu only offers Monthly and Yearly Premium — both renew automatically until cancelled.</div>
    </div>

    <div class="form-group">
        <label class="form-label">Price (₦) <span style="color:var(--danger)">*</span></label>
        <input type="number" name="price_naira" class="form-input" value="<?= isset($plan['price_kobo']) ? $plan['price_kobo']/100 : '' ?>" step="0.01" min="0" required>
        <div class="form-hint">Stored in kobo internally. Never hardcoded — this is the single source of truth for pricing.</div>
    </div>

    <div class="form-group">
        <label class="form-label">Description</label>
        <textarea name="description" class="form-input" rows="2" placeholder="Shown under the plan name on the pricing page…"><?= sanitize($plan['description'] ?? '') ?></textarea>
    </div>

    <div class="form-group">
        <label class="form-label">Benefits / Features</label>
        <textarea name="features" class="form-input" rows="5" placeholder="One benefit per line, e.g.&#10;All premium lessons&#10;Unlimited AI Tutor"><?= sanitize(implode("\n", $planFeatures)) ?></textarea>
        <div class="form-hint">One feature per line — shown as a checklist on the pricing card.</div>
    </div>

    <div class="form-group">
        <label class="form-label">Provider Plan Code (Paystack)</label>
        <input type="text" name="provider_plan_code" class="form-input" value="<?= sanitize($plan['provider_plan_code'] ?? '') ?>" placeholder="PLN_xxxxxxxx (optional)">
        <div class="form-hint">Once set, Monthly/Yearly subscriptions auto-attach to Paystack recurring billing. Leave blank until the plan is created on Paystack's dashboard.</div>
    </div>

    <div class="form-group">
        <label class="form-label">Sort Order</label>
        <input type="number" name="sort_order" class="form-input" value="<?= intval($plan['sort_order'] ?? 0) ?>" min="0" style="width:120px">
    </div>

    <div class="form-group" style="display:flex;gap:20px">
        <label style="display:flex;align-items:center;gap:8px;font-size:0.85rem">
            <input type="checkbox" name="is_recommended" <?= !empty($plan['is_recommended']) ? 'checked' : '' ?>> Recommended / Most popular
        </label>
        <label style="display:flex;align-items:center;gap:8px;font-size:0.85rem">
            <input type="checkbox" name="is_active" <?= ($plan['is_active'] ?? 1) ? 'checked' : '' ?>> Active (visible on pricing page)
        </label>
    </div>

    <button type="submit" class="btn-primary"><?= $id ? 'Save Changes' : 'Create Plan' ?></button>
</form>
</div>

<?php endif; ?>

<?php require_once __DIR__ . '/footer.php'; ?>
