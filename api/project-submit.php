<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/projects.php';
require_once __DIR__ . '/../includes/gamification.php';
header('Content-Type: application/json');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Please sign in.']);
    exit;
}

$userId    = (int)$_SESSION['user_id'];
$projectId = intval($_POST['project_id'] ?? 0);
$guideId   = isset($_POST['guide_id']) && $_POST['guide_id'] !== '' ? intval($_POST['guide_id']) : null;
$submit    = ($_POST['submit'] ?? '') === '1';

$project = $projectId ? getProject($projectId) : null;
if (!$project) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Project not found.']);
    exit;
}

$existing = getUserSubmission($userId, $projectId, $guideId);
if ($existing && $existing['status'] === 'approved') {
    echo json_encode(['success' => false, 'error' => 'This project has already been approved and is locked.']);
    exit;
}

if ($submit && trim($_POST['github_url'] ?? '') === '' && trim($_POST['live_demo_url'] ?? '') === '') {
    echo json_encode(['success' => false, 'error' => 'Add at least a GitHub repository or a live demo link before submitting.']);
    exit;
}

$id = saveProjectSubmission($userId, $projectId, $guideId, [
    'github_url'     => $_POST['github_url'] ?? '',
    'live_demo_url'  => $_POST['live_demo_url'] ?? '',
    'video_demo_url' => $_POST['video_demo_url'] ?? '',
    'notes'          => $_POST['notes'] ?? '',
    'technologies'   => $_POST['technologies'] ?? '',
], $submit);

if ($submit) {
    $db = getDB();
    notifyUser($userId, 'project_submitted', 'Project Submitted', "\"{$project['title']}\" is now awaiting review.", SITE_URL . '/portfolio.php');

    // AI Mentor: supplementary preliminary feedback, never a substitute for
    // human review. Best-effort — a slow/failed AI call must never block
    // the (already-saved) submission.
    try {
        require_once __DIR__ . '/../includes/ai/AIService.php';
        $membershipType = userHasActivePremium($userId) ? 'premium' : 'free';
        AIService::reviewProjectSubmission($id, $membershipType);
    } catch (\Throwable $e) {
        error_log('AI project review failed: ' . $e->getMessage());
    }
}

echo json_encode([
    'success'     => true,
    'submission_id' => $id,
    'status'      => $submit ? 'submitted' : 'draft',
]);
