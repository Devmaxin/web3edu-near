<?php
$adminTitle = 'Bounties';
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/../includes/bounty_auth.php';

$db = getDB();
$action = $_GET['action'] ?? 'list';
$id = intval($_GET['id'] ?? 0);
$success = ''; $errors = [];
$adminId = $_SESSION['admin_id'] ?? null;

if ($action === 'approve' && $id) {
    requireCsrfGet();
    approveBounty($id, $adminId);
    header('Location: ' . SITE_URL . '/admin/bounties.php?approved=1'); exit;
}
if ($action === 'reject' && $id && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    rejectBounty($id, trim($_POST['reason'] ?? 'Not specified'), $adminId);
    header('Location: ' . SITE_URL . '/admin/bounties.php?rejected=1'); exit;
}
if ($action === 'feature' && $id) {
    requireCsrfGet();
    $db->prepare("UPDATE bounties SET is_featured = NOT is_featured WHERE id=?")->execute([$id]);
    header('Location: ' . SITE_URL . '/admin/bounties.php'); exit;
}
if ($action === 'archive' && $id) {
    requireCsrfGet();
    archiveBountyAndNotifyParticipants($id);
    header('Location: ' . SITE_URL . '/admin/bounties.php?archived=1'); exit;
}
if ($action === 'delete' && $id && canDeleteBounty((int)($adminId ?? 0), $id)) {
    requireCsrfGet();
    $db->prepare("UPDATE bounties SET deleted_at=NOW() WHERE id=?")->execute([$id]);
    header('Location: ' . SITE_URL . '/admin/bounties.php?deleted=1'); exit;
}

// Create a bounty — Official (Web3Edu-run), Hosted (on behalf of an organization),
// or External (aggregated from elsewhere). Feature 6.
if ($action === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $bountyType = in_array($_POST['bounty_type'] ?? '', ['official', 'hosted', 'external'], true) ? $_POST['bounty_type'] : 'official';
    $title = trim($_POST['title'] ?? '');
    if (!$title) $errors[] = 'Title is required.';

    $logoPath = null;
    if (!empty($_FILES['project_logo']['name'])) {
        $logoPath = handleImageUpload('project_logo', 'bounties', $errors);
    }
    $coverPath = null;
    if (!empty($_FILES['cover_image']['name'])) {
        $coverPath = handleImageUpload('cover_image', 'bounties', $errors);
    }

    $breakdown = [];
    foreach (($_POST['prize_place'] ?? []) as $i => $place) {
        if (trim($place) !== '' && trim($_POST['prize_amount'][$i] ?? '') !== '') {
            $breakdown[] = ['place' => trim($place), 'amount' => trim($_POST['prize_amount'][$i])];
        }
    }

    $sharedFields = [
        'title' => $title, 'category_id' => (int)($_POST['category_id'] ?? 0),
        'project_name' => trim($_POST['project_name'] ?? ''), 'project_logo' => $logoPath, 'cover_image' => $coverPath,
        'description' => trim($_POST['description'] ?? ''), 'requirements' => trim($_POST['requirements'] ?? ''),
        'skills' => trim($_POST['skills'] ?? ''), 'reward_display' => trim($_POST['reward_display'] ?? ''),
        'reward_breakdown' => $breakdown, 'num_winners' => max(1, (int)($_POST['num_winners'] ?? 1)),
        'difficulty' => $_POST['difficulty'] ?? 'Intermediate', 'country' => trim($_POST['country'] ?? '') ?: null,
        'deadline' => $_POST['deadline'] ?: null, 'winner_announcement_date' => $_POST['winner_announcement_date'] ?: null,
    ];

    if ($bountyType === 'official') {
        if (empty($errors)) {
            try {
                createOfficialBounty($sharedFields + [
                    'is_featured' => isset($_POST['is_featured']), 'is_premium_only' => isset($_POST['is_premium_only']),
                ], $adminId);
                header('Location: ' . SITE_URL . '/admin/bounties.php?created=1'); exit;
            } catch (\Throwable $e) {
                error_log('[Bounty] Official bounty creation failed: ' . $e->getMessage());
                $errors[] = 'Something went wrong while saving this bounty. Please try again.';
            }
        }
    } elseif ($bountyType === 'hosted') {
        // Admin creates it on behalf of an organization, attached to a real Web3Edu
        // account that will manage it going forward via the normal Host Dashboard —
        // "should behave exactly like host-created bounties" (feature 6).
        $identifier = trim($_POST['host_account'] ?? '');
        $orgName = trim($_POST['organization_name'] ?? '');
        $contactEmail = trim($_POST['contact_email'] ?? '');
        if (!$identifier) $errors[] = 'A host account (username or email) is required.';
        if (!$orgName || !$contactEmail) $errors[] = 'Organization name and contact email are required.';

        $hostUser = null;
        if ($identifier) {
            $lookup = $db->prepare("SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1");
            $lookup->execute([$identifier, $identifier]);
            $hostUser = $lookup->fetch();
            if (!$hostUser) $errors[] = "No Web3Edu account found matching \"$identifier\".";
        }

        $hostLogoPath = null;
        if (!empty($_FILES['host_logo']['name'])) {
            $hostLogoPath = handleImageUpload('host_logo', 'bounties', $errors);
        }

        if (empty($errors)) {
            try {
                $bountyHost = createOrGetBountyHost((int)$hostUser['id'], [
                    'organization_name' => $orgName,
                    'logo_path'         => $hostLogoPath,
                    'website'           => trim($_POST['host_website'] ?? ''),
                    'contact_email'     => $contactEmail,
                    'description'       => '',
                ]);
                $bountyId = createHostedBounty((int)$hostUser['id'], (int)$bountyHost['id'], $sharedFields);
                header('Location: ' . SITE_URL . '/admin/bounties.php?created=1'); exit;
            } catch (\Throwable $e) {
                error_log('[Bounty] Admin-created hosted bounty failed: ' . $e->getMessage());
                $errors[] = 'Something went wrong while saving this bounty. Please try again.';
            }
        }
    } elseif ($bountyType === 'external') {
        if (empty($_POST['external_url'] ?? '')) $errors[] = 'External URL is required.';
        if (empty($errors)) {
            try {
                $result = createExternalBountyAsAdmin($sharedFields + [
                    'external_platform' => trim($_POST['external_platform'] ?? 'Other'),
                    'external_url'      => trim($_POST['external_url'] ?? ''),
                ], (int)$adminId);
                if (!$result['success']) {
                    $errors[] = $result['error'];
                } else {
                    header('Location: ' . SITE_URL . '/admin/bounties.php?created=1'); exit;
                }
            } catch (\Throwable $e) {
                error_log('[Bounty] Admin-created external bounty failed: ' . $e->getMessage());
                $errors[] = 'Something went wrong while saving this bounty. Please try again.';
            }
        }
    }
}

$statusFilter = $_GET['status'] ?? 'all';
$where = ['b.deleted_at IS NULL'];
if ($statusFilter !== 'all') { $where[] = "b.status = " . $db->quote($statusFilter); }
$bounties = $db->query("
    SELECT b.*, bh.organization_name AS host_name,
           (SELECT COUNT(*) FROM bounty_submissions WHERE bounty_id=b.id) AS submission_count
    FROM bounties b LEFT JOIN bounty_hosts bh ON bh.id = b.host_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY FIELD(b.status,'pending_review','awaiting_payment') DESC, b.created_at DESC
    LIMIT 200
")->fetchAll();

$stats = getBountyStats();
$categories = getBountyCategories(false);
?>

<?php if (isset($_GET['created'])): ?><div class="admin-alert admin-alert-success">Bounty created.</div><?php endif; ?>
<?php if (isset($_GET['approved'])): ?><div class="admin-alert admin-alert-success">Bounty approved.</div><?php endif; ?>
<?php if (isset($_GET['rejected'])): ?><div class="admin-alert admin-alert-success">Bounty rejected.</div><?php endif; ?>
<?php if (isset($_GET['archived'])): ?><div class="admin-alert admin-alert-success">Bounty archived.</div><?php endif; ?>
<?php if (isset($_GET['deleted'])): ?><div class="admin-alert admin-alert-success">Bounty deleted.</div><?php endif; ?>
<?php if (!empty($errors)): ?><div class="admin-alert admin-alert-error"><?php foreach ($errors as $e): ?><div><?= sanitize($e) ?></div><?php endforeach; ?></div><?php endif; ?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:12px">
    <h1 class="admin-page-title" style="margin:0">Bounty Marketplace</h1>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
        <a href="<?= SITE_URL ?>/admin/bounty-categories.php" class="btn-ghost btn-sm"><?= lucide('tag', ['size' => 13]) ?> Categories</a>
        <a href="<?= SITE_URL ?>/admin/bounty-hosts.php" class="btn-ghost btn-sm"><?= lucide('building', ['size' => 13]) ?> Host Applications</a>
        <a href="<?= SITE_URL ?>/admin/bounty-payments.php" class="btn-ghost btn-sm"><?= lucide('credit-card', ['size' => 13]) ?> Payments</a>
        <a href="<?= SITE_URL ?>/admin/bounty-profiles.php" class="btn-ghost btn-sm"><?= lucide('user', ['size' => 13]) ?> Bounty Profiles</a>
        <a href="<?= SITE_URL ?>/admin/bounty-submissions.php" class="btn-ghost btn-sm"><?= lucide('file-text', ['size' => 13]) ?> Submissions</a>
        <button class="btn-primary btn-sm" onclick="document.getElementById('createPanel').style.display='block';this.style.display='none'"><?= lucide('plus', ['size' => 13]) ?> New Bounty</button>
    </div>
</div>

<div class="admin-stats-grid">
    <div class="admin-stat-card"><span class="value"><?= $stats['total_bounties'] ?></span><span class="label"><?= lucide('briefcase', ['size' => 12]) ?> Total</span></div>
    <div class="admin-stat-card"><span class="value"><?= $stats['published'] ?></span><span class="label"><?= lucide('check-circle', ['size' => 12]) ?> Published</span></div>
    <div class="admin-stat-card"><span class="value"><?= $stats['pending_review'] ?></span><span class="label"><?= lucide('clock', ['size' => 12]) ?> Pending Review</span></div>
    <div class="admin-stat-card"><span class="value"><?= $stats['awaiting_payment'] ?></span><span class="label"><?= lucide('credit-card', ['size' => 12]) ?> Awaiting Payment</span></div>
    <div class="admin-stat-card"><span class="value"><?= $stats['total_hosts'] ?></span><span class="label"><?= lucide('building', ['size' => 12]) ?> Hosts</span></div>
    <div class="admin-stat-card"><span class="value"><?= $stats['total_submissions'] ?></span><span class="label"><?= lucide('file-text', ['size' => 12]) ?> Submissions</span></div>
</div>

<div id="createPanel" style="display:none;background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-lg);padding:24px;margin-bottom:24px;max-width:680px">
    <h2 style="font-size:0.9rem;font-weight:700;margin-bottom:16px;display:flex;align-items:center;gap:6px"><?= lucide('crown', ['size' => 16]) ?> New Bounty</h2>
    <?php
        $formMode = 'create';
        $formAction = '?action=create';
        $bounty = [];
        require __DIR__ . '/partials/bounty-form.php';
    ?>
</div>

<div style="display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap">
    <?php foreach (['all'=>'All','pending_review'=>'Pending','awaiting_payment'=>'Awaiting Payment','published'=>'Published','winners_announced'=>'Winners Announced','rejected'=>'Rejected','archived'=>'Archived'] as $s=>$label): ?>
    <a href="?status=<?= $s ?>" class="btn-ghost btn-sm" style="<?= $statusFilter===$s?'background:var(--primary-bright);color:#fff':'' ?>"><?= $label ?></a>
    <?php endforeach; ?>
</div>

<div class="admin-table-wrap">
<table class="admin-table">
<thead><tr><th>Title</th><th>Type</th><th>Host</th><th>Status</th><th>Submissions</th><th>Actions</th></tr></thead>
<tbody>
<?php foreach ($bounties as $b): ?>
<tr>
    <td style="font-weight:600"><?= sanitize($b['title']) ?> <?= $b['is_featured'] ? lucide('star', ['size' => 12]) : '' ?> <?= !empty($b['is_premium_only']) ? lucide('lock', ['size' => 12, 'title' => 'Premium Only']) : '' ?></td>
    <td style="font-size:0.78rem"><?= ucfirst($b['type']) ?></td>
    <td style="font-size:0.78rem;color:var(--text-muted)"><?= sanitize($b['host_name'] ?? '—') ?></td>
    <td><span class="status-chip status-<?= $b['status'] ?>" style="font-size:0.7rem;padding:3px 8px;border-radius:20px;background:rgba(139,92,246,0.12);color:var(--primary-bright)"><?= ucwords(str_replace('_',' ',$b['status'])) ?></span></td>
    <td style="text-align:center"><?= (int)$b['submission_count'] ?></td>
    <td>
        <div style="display:flex;gap:5px;flex-wrap:wrap">
            <a href="<?= SITE_URL ?>/bounty-detail.php?slug=<?= urlencode($b['slug']) ?>" target="_blank" class="btn-ghost btn-sm"><?= lucide('eye', ['size' => 12]) ?> View</a>
            <a href="<?= SITE_URL ?>/admin/bounty-edit.php?id=<?= $b['id'] ?>" class="btn-ghost btn-sm"><?= lucide('edit', ['size' => 12]) ?> Edit</a>
            <?php if (in_array($b['status'], ['published', 'judging'], true) && $b['type'] !== 'external'): ?><a href="<?= SITE_URL ?>/admin/bounty-winners.php?id=<?= $b['id'] ?>" class="btn-ghost btn-sm"><?= lucide('trophy', ['size' => 12]) ?> Winners</a><?php endif; ?>
            <?php if ($b['status'] === 'pending_review'): ?>
                <a href="?action=approve&id=<?= $b['id'] ?>&<?= csrfLink() ?>" class="btn-primary btn-sm"><?= lucide('check', ['size' => 12]) ?> Approve</a>
                <button type="button" class="btn-danger btn-sm" onclick="rejectPrompt(<?= $b['id'] ?>)"><?= lucide('x', ['size' => 12]) ?> Reject</button>
            <?php endif; ?>
            <a href="?action=feature&id=<?= $b['id'] ?>&<?= csrfLink() ?>" class="btn-ghost btn-sm"><?= lucide('star', ['size' => 12]) ?> <?= $b['is_featured'] ? 'Unfeature' : 'Feature' ?></a>
            <?php if (!in_array($b['status'], ['archived'])): ?><a href="?action=archive&id=<?= $b['id'] ?>&<?= csrfLink() ?>" class="btn-ghost btn-sm"><?= lucide('package', ['size' => 12]) ?> Archive</a><?php endif; ?>
            <a href="?action=delete&id=<?= $b['id'] ?>&<?= csrfLink() ?>" class="btn-danger btn-sm" onclick="return confirm('Delete this bounty?')"><?= lucide('trash', ['size' => 12]) ?> Delete</a>
        </div>
    </td>
</tr>
<?php endforeach; ?>
<?php if (empty($bounties)): ?><tr><td colspan="6" class="admin-empty"><?= lucide('inbox', ['size' => 16]) ?> No bounties found.</td></tr><?php endif; ?>
</tbody>
</table>
</div>

<form method="POST" id="rejectForm" style="display:none"><?= csrfField() ?><input type="text" name="reason" id="rejectReason"></form>
<script>
function rejectPrompt(id) {
    const reason = prompt('Reason for rejecting (shown to the submitter):', 'Please provide more details.');
    if (reason === null) return;
    const f = document.getElementById('rejectForm');
    f.action = '?action=reject&id=' + id;
    document.getElementById('rejectReason').value = reason;
    f.submit();
}
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
