<?php
/**
 * Admin — Project Submission Review
 * Pending queue + full history. Feedback is visible to the user (Portfolio
 * page shows it). Approving may immediately complete a guide's pipeline and
 * issue its certificate — reviewProjectSubmission() handles that already.
 */
$adminTitle = 'Project Submissions';
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/../includes/projects.php';

$db = getDB();
$adminId = (int)($_SESSION['admin_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'review') {
    $submissionId = intval($_POST['submission_id'] ?? 0);
    $decision = $_POST['decision'] ?? '';
    $feedback = trim($_POST['feedback'] ?? '');
    if ($submissionId && in_array($decision, ['approved','rejected','needs_revision'], true)) {
        reviewProjectSubmission($submissionId, $decision, $adminId, $feedback);
        header('Location: ?reviewed=1'); exit;
    }
}

$tab = $_GET['tab'] ?? 'pending';
$pending = getPendingSubmissions();
$all = getAllSubmissionsAdmin();
?>

<?php if (isset($_GET['reviewed'])): ?><div class="admin-alert admin-alert-success">Review submitted.</div><?php endif; ?>

<h1 class="admin-page-title">Project Submissions</h1>

<div style="display:flex;gap:8px;margin-bottom:20px">
    <a href="?tab=pending" class="btn-<?= $tab==='pending'?'primary':'ghost' ?> btn-sm">Pending Review (<?= count($pending) ?>)</a>
    <a href="?tab=all" class="btn-<?= $tab==='all'?'primary':'ghost' ?> btn-sm">All Submissions</a>
</div>

<?php $rows = $tab === 'pending' ? $pending : $all; ?>

<?php if (empty($rows)): ?>
    <div style="text-align:center;padding:40px;color:var(--text-muted)">Nothing here yet.</div>
<?php endif; ?>

<div style="display:flex;flex-direction:column;gap:14px">
<?php foreach ($rows as $s): ?>
    <?php $feedback = getSubmissionFeedback($s['id']); ?>
    <div style="background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-lg);padding:18px">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:8px;margin-bottom:10px">
            <div>
                <div style="font-weight:700;font-size:0.95rem"><?= sanitize($s['project_title']) ?></div>
                <div style="font-size:0.78rem;color:var(--text-muted)">
                    by <strong><?= sanitize($s['username']) ?></strong>
                    <?php if ($s['guide_title']): ?> · guide: <?= sanitize($s['guide_title']) ?><?php endif; ?>
                    · submitted <?= $s['submitted_at'] ? date('M j, Y', strtotime($s['submitted_at'])) : '—' ?>
                </div>
            </div>
            <span style="padding:3px 10px;border-radius:20px;font-size:0.72rem;font-weight:700;
                <?php
                    $badgeColors = ['submitted'=>'background:rgba(59,130,246,0.12);color:#93c5fd','approved'=>'background:rgba(34,197,94,0.12);color:#86efac','rejected'=>'background:rgba(239,68,68,0.12);color:#fca5a5','needs_revision'=>'background:rgba(245,158,11,0.12);color:#fbbf24'];
                    echo $badgeColors[$s['status']] ?? '';
                ?>"><?= ucfirst(str_replace('_',' ',$s['status'])) ?></span>
        </div>

        <div style="display:flex;gap:16px;flex-wrap:wrap;font-size:0.82rem;margin-bottom:10px">
            <?php if ($s['github_url']): ?><a href="<?= sanitize($s['github_url']) ?>" target="_blank" rel="noopener" style="color:var(--primary-bright)">GitHub →</a><?php endif; ?>
            <?php if ($s['live_demo_url']): ?><a href="<?= sanitize($s['live_demo_url']) ?>" target="_blank" rel="noopener" style="color:var(--primary-bright)">Live Demo →</a><?php endif; ?>
            <?php if ($s['video_demo_url']): ?><a href="<?= sanitize($s['video_demo_url']) ?>" target="_blank" rel="noopener" style="color:var(--primary-bright)">Video →</a><?php endif; ?>
        </div>
        <?php if ($s['notes']): ?><p style="font-size:0.82rem;color:var(--text-muted);margin-bottom:10px">"<?= sanitize($s['notes']) ?>"</p><?php endif; ?>

        <?php if (!empty($feedback)): ?>
        <div style="border-top:1px solid var(--border);padding-top:10px;margin-top:10px">
            <?php foreach ($feedback as $f): ?>
                <div style="font-size:0.76rem;color:var(--text-muted);margin-bottom:4px">
                    <strong><?= ucfirst(str_replace('_',' ',$f['decision'])) ?></strong> by <?= sanitize($f['admin_name'] ?? 'Admin') ?> — <?= sanitize($f['feedback']) ?>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if ($s['status'] === 'submitted'): ?>
        <form method="POST" style="display:flex;gap:8px;flex-wrap:wrap;margin-top:12px;border-top:1px solid var(--border);padding-top:12px">
            <input type="hidden" name="action" value="review">
            <input type="hidden" name="submission_id" value="<?= $s['id'] ?>">
            <input type="text" name="feedback" class="form-input" placeholder="Feedback (visible to the user)" style="flex:1;min-width:220px">
            <button type="submit" name="decision" value="approved" class="btn-primary btn-sm">Approve</button>
            <button type="submit" name="decision" value="needs_revision" class="btn-ghost btn-sm">Request Revision</button>
            <button type="submit" name="decision" value="rejected" class="btn-danger btn-sm">Reject</button>
        </form>
        <?php endif; ?>
    </div>
<?php endforeach; ?>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
