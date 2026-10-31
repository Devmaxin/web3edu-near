<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/projects.php';
require_once __DIR__ . '/../includes/resources.php';

if (!isLoggedIn()) {
    $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'];
    setFlash('info', 'Sign in to work on projects.');
    header('Location: ' . SITE_URL . '/login.php'); exit;
}
requireProfileComplete();

$userId    = (int)$_SESSION['user_id'];
$projectId = intval($_GET['project_id'] ?? 0);
$guideId   = isset($_GET['guide_id']) && $_GET['guide_id'] !== '' ? intval($_GET['guide_id']) : null;

$project = getProject($projectId);
if (!$project) {
    setFlash('error', 'That project could not be found.');
    header('Location: ' . SITE_URL . '/learn.php'); exit;
}

$submission = getUserSubmission($userId, $projectId, $guideId);
$feedback = $submission ? getSubmissionFeedback($submission['id']) : [];
$projectResources = getLinkedResources('project', $projectId);
$locked = $submission && $submission['status'] === 'approved';

$pageTitle = $project['title'];
$extraJs = [SITE_URL . '/assets/js/bookmark.js'];
require_once __DIR__ . '/../includes/header.php';
?>

<section class="container" style="max-width:760px;padding-top:32px;padding-bottom:60px">

    <div style="margin-bottom:24px">
        <span style="display:inline-block;padding:3px 10px;border-radius:20px;font-size:0.72rem;font-weight:700;background:rgba(139,92,246,0.12);color:var(--primary-bright);margin-bottom:10px">
            <?= ucfirst($project['project_type']) ?> Project
        </span>
        <h1 class="gradient-text" style="font-size:clamp(1.4rem,4vw,2rem);font-weight:800;margin-bottom:8px;display:flex;align-items:center;gap:10px">
            <?= sanitize($project['title']) ?>
            <button type="button" class="bookmark-btn <?= isBookmarked('project', $projectId) ? 'active' : '' ?>"
                    data-type="project" data-id="<?= $projectId ?>"
                    aria-pressed="<?= isBookmarked('project', $projectId) ? 'true' : 'false' ?>"
                    title="<?= isBookmarked('project', $projectId) ? 'Remove bookmark' : 'Save for later' ?>" style="font-size:0.9rem">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" stroke="none"><path d="M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z" fill="none" stroke="currentColor" stroke-width="2"/></svg>
            </button>
        </h1>
        <?php if ($project['description']): ?><p style="color:var(--text-muted)"><?= sanitize($project['description']) ?></p><?php endif; ?>
        <?php if ($project['estimated_duration']): ?><p style="color:var(--text-muted);font-size:0.82rem">⏱ <?= sanitize($project['estimated_duration']) ?></p><?php endif; ?>
    </div>

    <?php if ($project['instructions']): ?>
    <div class="card" style="padding:20px;margin-bottom:16px">
        <h2 style="font-size:0.95rem;font-weight:700;margin-bottom:10px">Instructions</h2>
        <div style="font-size:0.88rem;line-height:1.7;color:var(--text-dim)"><?= $project['instructions'] ?></div>
    </div>
    <?php endif; ?>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px">
        <?php if ($project['learning_objectives']): ?>
        <div class="card" style="padding:18px">
            <h3 style="font-size:0.85rem;font-weight:700;margin-bottom:8px">🎯 Learning Objectives</h3>
            <p style="font-size:0.82rem;color:var(--text-muted);white-space:pre-line"><?= sanitize($project['learning_objectives']) ?></p>
        </div>
        <?php endif; ?>
        <?php if ($project['requirements']): ?>
        <div class="card" style="padding:18px">
            <h3 style="font-size:0.85rem;font-weight:700;margin-bottom:8px">✅ Requirements</h3>
            <p style="font-size:0.82rem;color:var(--text-muted);white-space:pre-line"><?= sanitize($project['requirements']) ?></p>
        </div>
        <?php endif; ?>
        <?php if ($project['deliverables']): ?>
        <div class="card" style="padding:18px">
            <h3 style="font-size:0.85rem;font-weight:700;margin-bottom:8px">📦 Deliverables</h3>
            <p style="font-size:0.82rem;color:var(--text-muted);white-space:pre-line"><?= sanitize($project['deliverables']) ?></p>
        </div>
        <?php endif; ?>
        <?php if ($project['starter_files_url']): ?>
        <div class="card" style="padding:18px">
            <h3 style="font-size:0.85rem;font-weight:700;margin-bottom:8px">📁 Starter Files</h3>
            <a href="<?= sanitize($project['starter_files_url']) ?>" target="_blank" rel="noopener" style="font-size:0.82rem;color:var(--primary-bright)">Download →</a>
        </div>
        <?php endif; ?>
    </div>

    <?php if (!empty($projectResources)): ?>
    <div class="card" style="padding:18px;margin-bottom:16px">
        <h3 style="font-size:0.85rem;font-weight:700;margin-bottom:8px">📚 Related Resources</h3>
        <div style="display:flex;flex-direction:column;gap:6px">
        <?php foreach ($projectResources as $res): ?>
            <a href="<?= sanitize($res['url']) ?>" target="_blank" rel="noopener" style="font-size:0.82rem;color:var(--text-muted)"><?= sanitize($res['title']) ?> →</a>
        <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($submission['ai_review'])): ?>
    <div class="card" style="padding:18px;margin-bottom:16px;border-color:rgba(139,92,246,0.35);background:linear-gradient(145deg, rgba(139,92,246,0.06), transparent)">
        <h3 style="font-size:0.85rem;font-weight:700;margin-bottom:8px">🧠 AI Mentor Feedback <span style="font-weight:400;color:var(--text-muted);font-size:0.72rem">(preliminary — a human will still review this)</span></h3>
        <p style="font-size:0.84rem;color:var(--text-dim);line-height:1.6;white-space:pre-line"><?= sanitize($submission['ai_review']) ?></p>
    </div>
    <?php endif; ?>

    <?php if (!empty($feedback)): ?>
    <div class="card" style="padding:18px;margin-bottom:16px;border-color:rgba(245,158,11,0.3)">
        <h3 style="font-size:0.85rem;font-weight:700;margin-bottom:10px">Review Feedback</h3>
        <?php foreach ($feedback as $f): ?>
        <div style="font-size:0.82rem;color:var(--text-muted);margin-bottom:8px;padding-bottom:8px;border-bottom:1px solid var(--border)">
            <strong style="color:var(--text-dim)"><?= ucfirst(str_replace('_',' ',$f['decision'])) ?></strong> — <?= sanitize($f['feedback']) ?>
            <div style="font-size:0.72rem;margin-top:2px"><?= date('M j, Y', strtotime($f['created_at'])) ?></div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="card" style="padding:22px">
        <h2 style="font-size:0.95rem;font-weight:700;margin-bottom:14px">Your Submission</h2>

        <?php if ($locked): ?>
            <div style="text-align:center;padding:20px">
                <div style="font-size:2rem;margin-bottom:8px">🎉</div>
                <p style="font-weight:700;color:#86efac">Approved!</p>
                <p style="font-size:0.82rem;color:var(--text-muted);margin-top:6px">This project is locked and now appears in your <a href="<?= SITE_URL ?>/portfolio.php" style="color:var(--primary-bright)">Portfolio</a>.</p>
            </div>
        <?php else: ?>
        <form id="projectForm">
            <div class="form-group">
                <label class="form-label">GitHub Repository URL</label>
                <input type="url" name="github_url" class="form-input" placeholder="https://github.com/you/project" value="<?= sanitize($submission['github_url'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Live Demo URL</label>
                <input type="url" name="live_demo_url" class="form-input" placeholder="https://your-project.vercel.app" value="<?= sanitize($submission['live_demo_url'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Video Demo URL <span style="font-weight:400;color:var(--text-muted)">(optional)</span></label>
                <input type="url" name="video_demo_url" class="form-input" placeholder="https://youtube.com/watch?v=..." value="<?= sanitize($submission['video_demo_url'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Technologies Used <span style="font-weight:400;color:var(--text-muted)">(comma-separated)</span></label>
                <input type="text" name="technologies" class="form-input" placeholder="Solidity, Hardhat, React" value="<?= sanitize($submission['technologies'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Additional Notes</label>
                <textarea name="notes" class="form-input" rows="3"><?= sanitize($submission['notes'] ?? '') ?></textarea>
            </div>

            <div id="submitMsg" style="display:none;margin-bottom:14px;padding:12px 14px;border-radius:var(--radius);font-size:0.85rem"></div>

            <div style="display:flex;gap:10px">
                <button type="button" onclick="submitProject(false)" class="btn-ghost">Save Draft</button>
                <button type="button" onclick="submitProject(true)" class="btn-primary">Submit for Review</button>
            </div>
        </form>
        <?php endif; ?>
    </div>
</section>

<script>
function submitProject(finalize) {
    const form = document.getElementById('projectForm');
    const body = new URLSearchParams(new FormData(form));
    body.set('project_id', '<?= (int)$projectId ?>');
    <?php if ($guideId): ?>body.set('guide_id', '<?= (int)$guideId ?>');<?php endif; ?>
    body.set('submit', finalize ? '1' : '0');

    fetch(window.SITE_URL + '/api/project-submit.php', { method: 'POST', body })
        .then(r => r.json())
        .then(data => {
            const msg = document.getElementById('submitMsg');
            msg.style.display = 'block';
            if (data.success) {
                msg.style.background = 'rgba(34,197,94,0.1)'; msg.style.color = '#86efac';
                msg.textContent = finalize ? 'Submitted for review!' : 'Draft saved.';
                if (finalize) setTimeout(() => location.reload(), 1200);
            } else {
                msg.style.background = 'rgba(239,68,68,0.1)'; msg.style.color = '#fca5a5';
                msg.textContent = data.error || 'Something went wrong.';
            }
        });
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
