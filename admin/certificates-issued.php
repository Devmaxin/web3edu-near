<?php
$adminTitle = 'Issued Certificates';
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/../includes/certificates.php';

$db = getDB();
$action = $_GET['action'] ?? 'list';
$id = intval($_GET['id'] ?? 0);
$success = ''; $errors = [];

// ── Actions ──────────────────────────────────────────────────────────────────
if ($action === 'revoke' && $id && $_SERVER['REQUEST_METHOD'] === 'POST') {
    revokeCertificate($id, trim($_POST['reason'] ?? 'Revoked by admin'), $_SESSION['admin_id'] ?? null);
    header('Location: ' . SITE_URL . '/admin/certificates-issued.php?revoked=1'); exit;
}
if ($action === 'unrevoke' && $id) {
    unrevokeCertificate($id);
    header('Location: ' . SITE_URL . '/admin/certificates-issued.php?restored=1'); exit;
}
if ($action === 'reissue' && $id) {
    $newCode = reissueCertificate($id, $_SESSION['admin_id'] ?? null);
    header('Location: ' . SITE_URL . '/admin/certificates-issued.php?reissued=' . ($newCode ? '1' : '0')); exit;
}
if ($action === 'regenerate' && $id) {
    regenerateCertificate($id);
    header('Location: ' . SITE_URL . '/admin/certificates-issued.php?regenerated=1'); exit;
}
if ($action === 'delete' && $id) {
    deleteCertificatePermanently($id);
    header('Location: ' . SITE_URL . '/admin/certificates-issued.php?deleted=1'); exit;
}

// ── Manual issuance ──────────────────────────────────────────────────────────
if ($action === 'issue' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $userId = intval($_POST['user_id'] ?? 0);
    $typeId = intval($_POST['certificate_type_id'] ?? 0);
    $templateId = intval($_POST['template_id'] ?? 0);
    $title = trim($_POST['title'] ?? '');

    if (!$userId) $errors[] = 'Choose a user.';
    if (!$typeId) $errors[] = 'Choose a certificate type.';
    if (!$templateId) $errors[] = 'Choose a template.';
    if (!$title) $errors[] = 'Enter an achievement title.';

    if (empty($errors)) {
        $code = issueManualCertificate($userId, $typeId, $templateId, $title);
        if ($code) {
            header('Location: ' . SITE_URL . '/admin/certificates-issued.php?issued=' . urlencode($code)); exit;
        }
        $errors[] = 'Could not issue certificate — check the template is active.';
    }
}

// ── List + filters ───────────────────────────────────────────────────────────
$q = trim($_GET['q'] ?? '');
$statusFilter = $_GET['status'] ?? 'all'; // all | issued | revoked | expired
$typeFilter = intval($_GET['type'] ?? 0);

$where = ['1=1'];
$params = [];
if ($q !== '') {
    $where[] = "(c.certificate_code LIKE ? OR u.username LIKE ? OR u.email LIKE ? OR c.achievement_title LIKE ?)";
    array_push($params, "%$q%", "%$q%", "%$q%", "%$q%");
}
if ($statusFilter === 'issued') $where[] = "c.is_revoked = 0";
if ($statusFilter === 'revoked') $where[] = "c.is_revoked = 1";
if ($statusFilter === 'expired') $where[] = "c.expires_at IS NOT NULL AND c.expires_at < NOW() AND c.is_revoked = 0";
if ($typeFilter) { $where[] = "c.certificate_type_id = ?"; $params[] = $typeFilter; }

$sql = "
    SELECT c.*, u.username, u.email, ct.name AS template_name, cty.label AS type_label, cty.icon AS type_icon
    FROM certificates c
    INNER JOIN users u ON u.id = c.user_id
    LEFT JOIN certificate_templates ct ON ct.id = c.template_id
    LEFT JOIN certificate_types cty ON cty.id = c.certificate_type_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY c.issued_at DESC
    LIMIT 200
";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$certs = $stmt->fetchAll();

$stats = getCertificateStats();
$types = getCertificateTypes(false);
$templates = $db->query("SELECT id, name, certificate_type_id FROM certificate_templates WHERE deleted_at IS NULL AND is_active=1 ORDER BY name ASC")->fetchAll();
?>

<?php if (isset($_GET['revoked'])): ?><div class="admin-alert admin-alert-success">Certificate revoked.</div><?php endif; ?>
<?php if (isset($_GET['restored'])): ?><div class="admin-alert admin-alert-success">Certificate restored.</div><?php endif; ?>
<?php if (isset($_GET['reissued'])): ?><div class="admin-alert admin-alert-<?= $_GET['reissued']==='1'?'success':'error' ?>"><?= $_GET['reissued']==='1' ? 'Certificate reissued with a new code.' : 'Reissue failed.' ?></div><?php endif; ?>
<?php if (isset($_GET['regenerated'])): ?><div class="admin-alert admin-alert-success">Certificate regenerated.</div><?php endif; ?>
<?php if (isset($_GET['deleted'])): ?><div class="admin-alert admin-alert-success">Certificate deleted.</div><?php endif; ?>
<?php if (isset($_GET['issued'])): ?><div class="admin-alert admin-alert-success">Certificate issued: <strong><?= sanitize($_GET['issued']) ?></strong></div><?php endif; ?>
<?php if (!empty($errors)): ?><div class="admin-alert admin-alert-error"><?php foreach ($errors as $e): ?><div><?= sanitize($e) ?></div><?php endforeach; ?></div><?php endif; ?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:12px">
    <h1 class="admin-page-title" style="margin:0">Issued Certificates</h1>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
        <a href="<?= SITE_URL ?>/admin/certificate-templates.php" class="btn-ghost btn-sm">Templates</a>
        <a href="<?= SITE_URL ?>/admin/certificate-rules.php" class="btn-ghost btn-sm">Rules</a>
        <button class="btn-primary btn-sm" onclick="document.getElementById('manualIssuePanel').style.display='block';this.style.display='none'">+ Issue Manually</button>
    </div>
</div>

<div class="admin-stats-grid">
    <div class="admin-stat-card"><span class="value"><?= $stats['total_issued'] ?></span><span class="label">Total Issued</span></div>
    <div class="admin-stat-card"><span class="value"><?= $stats['total_active'] ?></span><span class="label">Active</span></div>
    <div class="admin-stat-card"><span class="value"><?= $stats['total_revoked'] ?></span><span class="label">Revoked</span></div>
    <div class="admin-stat-card"><span class="value"><?= $stats['total_expired'] ?></span><span class="label">Expired</span></div>
    <div class="admin-stat-card"><span class="value"><?= $stats['total_verified'] ?></span><span class="label">Verifications</span></div>
    <div class="admin-stat-card"><span class="value"><?= $stats['issued_this_month'] ?></span><span class="label">This Month</span></div>
</div>

<!-- ── Manual issuance panel ── -->
<div id="manualIssuePanel" style="display:none;background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-lg);padding:24px;margin-bottom:24px;max-width:680px">
    <h2 style="font-size:0.9rem;font-weight:700;margin-bottom:16px">Issue a Certificate Manually</h2>
    <form method="POST" action="?action=issue">
        <div class="form-group">
            <label class="form-label">User (username or email)</label>
            <input type="text" id="userSearch" class="form-input" placeholder="Start typing a username or email…" autocomplete="off" oninput="searchUsers(this.value)">
            <input type="hidden" name="user_id" id="userIdField">
            <div id="userResults" style="margin-top:6px;font-size:0.8rem"></div>
        </div>
        <div class="form-group">
            <label class="form-label">Certificate Type</label>
            <select name="certificate_type_id" id="manualType" class="form-select" required onchange="filterManualTemplates()">
                <option value="">— choose —</option>
                <?php foreach ($types as $ty): ?>
                <option value="<?= $ty['id'] ?>"><?= $ty['icon'] ?> <?= sanitize($ty['label']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label class="form-label">Template</label>
            <select name="template_id" id="manualTemplate" class="form-select" required>
                <?php foreach ($templates as $tp): ?>
                <option value="<?= $tp['id'] ?>" data-type="<?= $tp['certificate_type_id'] ?>"><?= sanitize($tp['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label class="form-label">Achievement Title</label>
            <input type="text" name="title" class="form-input" placeholder="e.g. Web3Edu Hackathon 2026 — 1st Place" required>
        </div>
        <div style="display:flex;gap:10px">
            <button type="submit" class="btn-primary" style="flex:1">Issue Certificate</button>
            <button type="button" class="btn-ghost" onclick="document.getElementById('manualIssuePanel').style.display='none'">Cancel</button>
        </div>
    </form>
</div>

<!-- ── Filters ── -->
<form method="GET" style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px;align-items:center">
    <input type="text" name="q" class="form-input" style="max-width:280px" placeholder="Search code, user, title…" value="<?= sanitize($q) ?>">
    <select name="status" class="form-select" style="max-width:160px">
        <?php foreach (['all'=>'All statuses','issued'=>'Active','revoked'=>'Revoked','expired'=>'Expired'] as $k=>$v): ?>
        <option value="<?= $k ?>" <?= $statusFilter===$k?'selected':'' ?>><?= $v ?></option>
        <?php endforeach; ?>
    </select>
    <select name="type" class="form-select" style="max-width:200px">
        <option value="0">All types</option>
        <?php foreach ($types as $ty): ?>
        <option value="<?= $ty['id'] ?>" <?= $typeFilter===(int)$ty['id']?'selected':'' ?>><?= $ty['icon'] ?> <?= sanitize($ty['label']) ?></option>
        <?php endforeach; ?>
    </select>
    <button type="submit" class="btn-ghost btn-sm">Filter</button>
</form>

<div class="admin-table-wrap">
<table class="admin-table">
<thead><tr><th>Code</th><th>User</th><th>Title</th><th>Type</th><th>Issued</th><th>Status</th><th>Actions</th></tr></thead>
<tbody>
<?php foreach ($certs as $c):
    $isExpired = !empty($c['expires_at']) && strtotime($c['expires_at']) < time();
?>
<tr>
    <td style="font-family:var(--font-mono);font-size:0.76rem"><?= sanitize($c['certificate_code']) ?></td>
    <td>
        <div style="font-weight:600"><?= sanitize($c['username'] ?? '—') ?></div>
        <div style="font-size:0.72rem;color:var(--text-muted)"><?= sanitize($c['email'] ?? '') ?></div>
    </td>
    <td style="font-size:0.8rem;max-width:220px"><?= sanitize($c['achievement_title'] ?? $c['template_name'] ?? '') ?></td>
    <td style="font-size:0.78rem;color:var(--text-muted)"><?= ($c['type_icon'] ?? '') . ' ' . sanitize($c['type_label'] ?? '—') ?></td>
    <td style="font-size:0.78rem"><?= date('M j, Y', strtotime($c['issued_at'])) ?></td>
    <td style="text-align:center">
        <?php if ($c['is_revoked']): ?>
            <span style="padding:3px 8px;border-radius:20px;font-size:0.7rem;font-weight:700;background:rgba(239,68,68,0.12);color:#f87171">Revoked</span>
        <?php elseif ($isExpired): ?>
            <span style="padding:3px 8px;border-radius:20px;font-size:0.7rem;font-weight:700;background:rgba(245,158,11,0.12);color:#fbbf24">Expired</span>
        <?php else: ?>
            <span style="padding:3px 8px;border-radius:20px;font-size:0.7rem;font-weight:700;background:rgba(34,197,94,0.12);color:#22c55e">Active</span>
        <?php endif; ?>
    </td>
    <td>
        <div style="display:flex;gap:5px;flex-wrap:wrap">
            <a href="<?= SITE_URL ?>/certificate/verify.php?id=<?= urlencode($c['certificate_code']) ?>" target="_blank" class="btn-ghost btn-sm">View</a>
            <a href="?action=regenerate&id=<?= $c['id'] ?>" class="btn-ghost btn-sm">Regenerate</a>
            <a href="?action=reissue&id=<?= $c['id'] ?>" class="btn-ghost btn-sm" onclick="return confirm('Reissue with a new certificate code? The old code will be revoked.')">Reissue</a>
            <?php if ($c['is_revoked']): ?>
                <a href="?action=unrevoke&id=<?= $c['id'] ?>" class="btn-ghost btn-sm" style="color:#22c55e">Restore</a>
            <?php else: ?>
                <button type="button" class="btn-danger btn-sm" onclick="revokePrompt(<?= $c['id'] ?>)">Revoke</button>
            <?php endif; ?>
            <a href="?action=delete&id=<?= $c['id'] ?>" class="btn-danger btn-sm" onclick="return confirm('Permanently delete this certificate? This cannot be undone.')">Delete</a>
        </div>
    </td>
</tr>
<?php endforeach; ?>
<?php if (empty($certs)): ?><tr><td colspan="7" class="admin-empty">No certificates match your filters.</td></tr><?php endif; ?>
</tbody>
</table>
</div>
<p style="font-size:0.75rem;color:var(--text-muted);margin-top:10px">Showing up to 200 most recent results. Narrow your search to find older certificates.</p>

<!-- Hidden revoke form -->
<form method="POST" id="revokeForm" style="display:none">
    <input type="text" name="reason" id="revokeReason">
</form>

<script>
function revokePrompt(id) {
    const reason = prompt('Reason for revoking (shown to admins only):', 'Revoked by admin');
    if (reason === null) return;
    const f = document.getElementById('revokeForm');
    f.action = '?action=revoke&id=' + id;
    document.getElementById('revokeReason').value = reason;
    f.submit();
}

let userSearchTimer;
function searchUsers(q) {
    clearTimeout(userSearchTimer);
    document.getElementById('userIdField').value = '';
    if (q.trim().length < 2) { document.getElementById('userResults').innerHTML = ''; return; }
    userSearchTimer = setTimeout(() => {
        fetch('<?= SITE_URL ?>/admin/ajax-user-search.php?q=' + encodeURIComponent(q))
            .then(r => r.json())
            .then(list => {
                const box = document.getElementById('userResults');
                if (!list.length) { box.innerHTML = '<span style="color:var(--text-muted)">No matches.</span>'; return; }
                box.innerHTML = list.map(u =>
                    `<div style="padding:5px 8px;border-radius:6px;cursor:pointer;background:var(--bg-card2);margin-bottom:3px" onclick="pickUser(${u.id}, '${u.username.replace(/'/g,"")}')">${u.username} — ${u.email}</div>`
                ).join('');
            })
            .catch(() => {});
    }, 250);
}
function pickUser(id, username) {
    document.getElementById('userIdField').value = id;
    document.getElementById('userSearch').value = username;
    document.getElementById('userResults').innerHTML = '<span style="color:#22c55e">Selected ✓</span>';
}
function filterManualTemplates() {
    const typeId = document.getElementById('manualType').value;
    document.querySelectorAll('#manualTemplate option').forEach(o => {
        o.hidden = typeId && o.dataset.type && o.dataset.type !== typeId;
    });
}
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
