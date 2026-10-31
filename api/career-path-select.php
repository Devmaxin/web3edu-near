<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/career_paths.php';
header('Content-Type: application/json');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Please sign in.']);
    exit;
}

$userId = (int)$_SESSION['user_id'];
$action = $_POST['action'] ?? '';

if ($action === 'skip') {
    markLearnOnboardingSeen($userId, 'skipped');
    echo json_encode(['success' => true]);
    exit;
}

if ($action === 'select') {
    $careerPathId = intval($_POST['career_path_id'] ?? 0);
    $path = $careerPathId ? getCareerPath($careerPathId) : null;

    if (!$path || !$path['is_active']) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid career path.']);
        exit;
    }

    $ok = switchActiveCareerPath($userId, $careerPathId);
    if (!$ok) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Could not set your career path. Please try again.']);
        exit;
    }

    // Only the very first selection (onboarding) marks onboarding as seen —
    // later "Change Path" switches shouldn't need to re-touch this, but it's
    // harmless/idempotent to set it here too since a switch implies the user
    // has already been through the flow at least once.
    markLearnOnboardingSeen($userId, 'completed');

    echo json_encode([
        'success'     => true,
        'career_path' => [
            'id'   => (int)$path['id'],
            'name' => $path['name'],
            'icon' => $path['icon'],
        ],
    ]);
    exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'error' => 'Unknown action.']);
