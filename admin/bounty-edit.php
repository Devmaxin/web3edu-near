<?php
$adminTitle = 'Edit Bounty';
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/../includes/bounties.php';

$db = getDB();
$id = (int)($_GET['id'] ?? 0);
$bounty = $id ? getBounty($id) : null;

if (!$bounty) {
    header('Location: ' . SITE_URL . '/admin/bounties.php');
    exit;
}

$errors = [];
$adminId = $_SESSION['admin_id'] ?? null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
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

    if (empty($errors)) {
        $data = [
            'type' => $_POST['type'] ?? $bounty['type'],
            'title' => $title,
            'category_id' => $_POST['category_id'] ?? '',
            'project_name' => trim($_POST['project_name'] ?? ''),
            'description' => trim($_POST['description'] ?? ''),
            'requirements' => trim($_POST['requirements'] ?? ''),
            'skills' => trim($_POST['skills'] ?? ''),
            'reward_display' => trim($_POST['reward_display'] ?? ''),
            'reward_breakdown' => $breakdown,
            'num_winners' => max(1, (int)($_POST['num_winners'] ?? 1)),
            'difficulty' => $_POST['difficulty'] ?? 'Intermediate',
            'country' => trim($_POST['country'] ?? ''),
            'deadline' => $_POST['deadline'] ?: null,
            'winner_announcement_date' => $_POST['winner_announcement_date'] ?: null,
            'external_platform' => trim($_POST['external_platform'] ?? ''),
            'external_url' => trim($_POST['external_url'] ?? ''),
            'status' => $_POST['status'] ?? $bounty['status'],
            'is_featured' => isset($_POST['is_featured']),
            'is_premium_only' => isset($_POST['is_premium_only']),
        ];
        if ($logoPath) $data['project_logo'] = $logoPath;
        if ($coverPath) $data['cover_image'] = $coverPath;

        updateBounty($id, $data);
        header('Location: ' . SITE_URL . '/admin/bounty-edit.php?id=' . $id . '&saved=1');
        exit;
    }
    // keep POSTed values on validation error so the form doesn't lose input
    $bounty = array_merge($bounty, $_POST);
}

$categories = getBountyCategories(false);
?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:12px">
    <div>
        <a href="<?= SITE_URL ?>/admin/bounties.php" class="btn-ghost btn-sm" style="margin-bottom:8px;display:inline-flex"><?= lucide('arrow-left', ['size' => 13]) ?> Back to Bounties</a>
        <h1 class="admin-page-title" style="margin:0;display:flex;align-items:center;gap:8px"><?= lucide('edit', ['size' => 18]) ?> Edit Bounty</h1>
    </div>
    <a href="<?= SITE_URL ?>/bounty-detail.php?slug=<?= urlencode($bounty['slug']) ?>" target="_blank" class="btn-ghost btn-sm"><?= lucide('external-link', ['size' => 13]) ?> View Live</a>
</div>

<?php if (isset($_GET['saved'])): ?><div class="admin-alert admin-alert-success"><?= lucide('check-circle', ['size' => 14]) ?> Bounty updated.</div><?php endif; ?>
<?php if (!empty($errors)): ?><div class="admin-alert admin-alert-error"><?php foreach ($errors as $e): ?><div><?= sanitize($e) ?></div><?php endforeach; ?></div><?php endif; ?>

<div style="background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-lg);padding:24px;max-width:680px">
    <?php
        $formMode = 'edit';
        $formAction = '?id=' . $id;
        require __DIR__ . '/partials/bounty-form.php';
    ?>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
