<?php
/**
 * Web3edu — Gamification Helpers
 * Include this file where needed. Requires config.php already loaded.
 * All functions are safe to call multiple times (idempotent where appropriate).
 */
require_once __DIR__ . '/certificates.php';

/**
 * Mark a lesson as completed for the logged-in user.
 * Returns true if this is a NEW completion, false if already completed.
 */
function markLessonComplete(int $lessonId): bool {
    if (!isLoggedIn()) return false;
    $db = getDB();
    $userId = (int)$_SESSION['user_id'];

    // Already completed?
    $chk = $db->prepare("SELECT id FROM user_lesson_progress WHERE user_id=? AND lesson_id=?");
    $chk->execute([$userId, $lessonId]);
    if ($chk->fetch()) return false; // already done

    $ins = $db->prepare("INSERT INTO user_lesson_progress (user_id, lesson_id) VALUES (?,?)");
    $ins->execute([$userId, $lessonId]);
    return true;
}

/**
 * Check if a lesson is completed by the logged-in user.
 */
function isLessonCompleted(int $lessonId): bool {
    if (!isLoggedIn()) return false;
    $db = getDB();
    $stmt = $db->prepare("SELECT id FROM user_lesson_progress WHERE user_id=? AND lesson_id=?");
    $stmt->execute([(int)$_SESSION['user_id'], $lessonId]);
    return (bool)$stmt->fetch();
}

/**
 * Get total completed lessons for the logged-in user.
 */
function getUserCompletedCount(): int {
    if (!isLoggedIn()) return 0;
    $db = getDB();
    $stmt = $db->prepare("SELECT COUNT(*) FROM user_lesson_progress WHERE user_id=?");
    $stmt->execute([(int)$_SESSION['user_id']]);
    return (int)$stmt->fetchColumn();
}

/**
 * Get progress for a specific guide path.
 * Returns ['completed'=>N, 'total'=>N, 'pct'=>0-100].
 */
function getGuideProgress(int $guideId): array {
    if (!isLoggedIn()) return ['completed'=>0,'total'=>0,'pct'=>0];
    $db = getDB();
    $userId = (int)$_SESSION['user_id'];

    
    $total = $db->prepare("SELECT COUNT(*) FROM guide_path_lessons WHERE guide_id=?");
    $total->execute([$guideId]);
    $total = (int)$total->fetchColumn();

    if ($total === 0) return ['completed'=>0,'total'=>0,'pct'=>0];

    $done = $db->prepare("
        SELECT COUNT(*) FROM guide_path_lessons gpl
        INNER JOIN user_lesson_progress ulp ON ulp.lesson_id = gpl.lesson_id AND ulp.user_id = ?
        WHERE gpl.guide_id = ?
    ");
    $done->execute([$userId, $guideId]);
    $done = (int)$done->fetchColumn();

    return [
        'completed' => $done,
        'total'     => $total,
        'pct'       => (int)round($done / $total * 100),
    ];
}

/**
 * Evaluate and award all eligible badges for the logged-in user.
 * Returns array of newly awarded badge rows (may be empty).
 */
/**
 * The one badge service. Checks every count-based dimension a badge can be
 * configured against (required_lessons, required_career_paths,
 * required_projects, required_certificates) for one user, awards any newly-
 * qualifying badge exactly once, and fires the same notification +
 * certificate-rule evaluation for all of them consistently. Every other
 * badge-awarding call site (lesson completion, project approval, streak
 * milestones with a direct badge_id) should route through this rather than
 * hand-rolling its own INSERT IGNORE + notify logic.
 */
function evaluateBadgeService(int $userId): array {
    $db = getDB();

    $counts = [];
    $lessonStmt = $db->prepare("SELECT COUNT(*) FROM user_lesson_progress WHERE user_id=?");
    $lessonStmt->execute([$userId]);
    $counts['required_lessons'] = (int)$lessonStmt->fetchColumn();

    $cpStmt = $db->prepare("SELECT COUNT(*) FROM user_career_paths WHERE user_id=? AND status='completed'");
    $cpStmt->execute([$userId]);
    $counts['required_career_paths'] = (int)$cpStmt->fetchColumn();

    $projStmt = $db->prepare("SELECT COUNT(*) FROM project_submissions WHERE user_id=? AND status='approved'");
    $projStmt->execute([$userId]);
    $counts['required_projects'] = (int)$projStmt->fetchColumn();

    $certStmt = $db->prepare("SELECT COUNT(*) FROM certificates WHERE user_id=? AND revoked_at IS NULL");
    $certStmt->execute([$userId]);
    $counts['required_certificates'] = (int)$certStmt->fetchColumn();

    $pending = $db->prepare("
        SELECT * FROM badges
        WHERE is_active = 1 AND deleted_at IS NULL
          AND id NOT IN (SELECT badge_id FROM user_badges WHERE user_id = ?)
    ");
    $pending->execute([$userId]);

    $newBadges = [];
    foreach ($pending->fetchAll() as $badge) {
        $qualifies = false;
        foreach (['required_lessons', 'required_career_paths', 'required_projects', 'required_certificates'] as $dim) {
            if ((int)$badge[$dim] > 0 && $counts[$dim] >= (int)$badge[$dim]) { $qualifies = true; break; }
        }
        if (!$qualifies) continue;

        $ins = $db->prepare("INSERT IGNORE INTO user_badges (user_id, badge_id) VALUES (?,?)");
        $ins->execute([$userId, $badge['id']]);
        if ($ins->rowCount() > 0) {
            $newBadges[] = $badge;
            awardXp($userId, 'badge_earned', 'badge', (int)$badge['id'], (int)($badge['xp_reward'] ?? 0) ?: null);
            notifyUser($userId, 'badge_earned', '🏅 Badge Earned: ' . $badge['name'], $badge['description'] ?: '', SITE_URL . '/dashboard.php');
            evaluateCertificateRules($userId, 'badge_earned', ['badge_id' => (int)$badge['id'], 'badge_name' => $badge['name']]);
        }
    }

    return $newBadges;
}

/** Session-bound entry point kept for the existing lesson-completion call site — routes through the same badge service. */
function evaluateAndAwardBadges(): array {
    if (!isLoggedIn()) return [];
    return evaluateBadgeService((int)$_SESSION['user_id']);
}

/**
 * Get all badges earned by the logged-in user (most recent first).
 */
function getUserBadges(): array {
    if (!isLoggedIn()) return [];
    $db = getDB();
    $stmt = $db->prepare("
        SELECT b.*, ub.earned_at
        FROM user_badges ub
        INNER JOIN badges b ON b.id = ub.badge_id
        WHERE ub.user_id = ?
        ORDER BY ub.earned_at DESC
    ");
    $stmt->execute([(int)$_SESSION['user_id']]);
    return $stmt->fetchAll();
}

/**
 * Award a specific badge directly (used after quiz pass).
 * Returns true if newly awarded, false if already had it.
 */
function awardBadge(int $badgeId): bool {
    if (!isLoggedIn()) return false;
    $db = getDB();
    $userId = (int)$_SESSION['user_id'];
    $ins = $db->prepare("INSERT IGNORE INTO user_badges (user_id, badge_id) VALUES (?,?)");
    $ins->execute([$userId, $badgeId]);
    $newlyAwarded = $ins->rowCount() > 0;
    if ($newlyAwarded) {
        $bStmt = $db->prepare("SELECT name FROM badges WHERE id=?"); $bStmt->execute([$badgeId]);
        evaluateCertificateRules($userId, 'badge_earned', ['badge_id' => $badgeId, 'badge_name' => $bStmt->fetchColumn() ?: '']);
    }
    return $newlyAwarded;
}

/**
 * Awards the one-time onboarding completion reward: +10 XP (via the
 * existing xp_rules/awardXp() ledger, action_key='onboarding_complete') and
 * the "First Step" badge (badge_key='first_step', via user_badges).
 *
 * Idempotent: awardXp() dedupes on (user_id, action_key, reference_type,
 * reference_id) — here reference_id is the user's own id, so this can only
 * ever pay out once per user regardless of how many times it's called
 * (refresh, retry, direct POST replay, etc). The badge award is a second,
 * independent INSERT IGNORE, so even if the XP award somehow succeeded on a
 * prior call but the badge step failed (e.g. request interrupted), a later
 * call can still safely finish the badge half without re-paying XP.
 *
 * Call this from the server-side onboarding completion handler only —
 * never award this from client-side JS.
 */
function awardOnboardingReward(int $userId): array
{
    $db = getDB();

    $xpAwarded = awardXp($userId, 'onboarding_complete', 'onboarding', $userId);

    $badge = null;
    $badgeStmt = $db->prepare("SELECT * FROM badges WHERE badge_key='first_step' AND is_active=1 AND deleted_at IS NULL LIMIT 1");
    $badgeStmt->execute();
    $badgeRow = $badgeStmt->fetch();

    if ($badgeRow) {
        $ins = $db->prepare("INSERT IGNORE INTO user_badges (user_id, badge_id) VALUES (?,?)");
        $ins->execute([$userId, $badgeRow['id']]);
        if ($ins->rowCount() > 0) {
            $badge = $badgeRow;
            notifyUser($userId, 'badge_earned', '🏅 Badge Earned: ' . $badgeRow['name'], $badgeRow['description'] ?: '', SITE_URL . '/dashboard.php');
            evaluateCertificateRules($userId, 'badge_earned', ['badge_id' => (int)$badgeRow['id'], 'badge_name' => $badgeRow['name']]);
        }
    }

    $totalStmt = $db->prepare("SELECT total_xp FROM users WHERE id=?");
    $totalStmt->execute([$userId]);

    return [
        'xp_awarded' => $xpAwarded,       // 0 if this user already has the reward
        'badge'      => $badge,           // badge row if newly awarded this call, else null
        'total_xp'   => (int)($totalStmt->fetchColumn() ?: 0),
    ];
}

/**
 * One-time session flash for the onboarding celebration UI, so the reward
 * screen survives the redirect from onboarding.php -> dashboard.php without
 * re-running awardOnboardingReward() on a page refresh. Read-once, same
 * pattern as the existing setFlash()/getFlash() in includes/config.php.
 */
function setOnboardingRewardFlash(array $reward): void {
    $_SESSION['onboarding_reward_flash'] = $reward;
}
function getOnboardingRewardFlash(): ?array {
    if (isset($_SESSION['onboarding_reward_flash'])) {
        $reward = $_SESSION['onboarding_reward_flash'];
        unset($_SESSION['onboarding_reward_flash']);
        return $reward;
    }
    return null;
}

/**
 * Awards a comeback bonus if the user is returning (via a qualifying
 * learning activity — called from updateStreak() only, never from a bare
 * login) after crossing one or more configured comeback_rules thresholds.
 * Picks the single HIGHEST threshold actually crossed (e.g. a 20-day gap
 * matches the 14-day tier, not both 14 and 7) and awards its xp_reward /
 * freeze_reward exactly once via comeback_reward_log.
 *
 * Anti-farming: the uniqueness key is (user_id, anchor_date), where
 * anchor_date is the OLD last_activity_date being returned from — a
 * value that can only change via a genuine completed learning activity.
 * A user cannot re-earn the same reward by repeatedly leaving and
 * returning; last_activity_date has already advanced to today by the
 * time they could try again, so the next reward requires a brand new gap
 * of qualifying length measured from that new date.
 */
function evaluateComebackBonus(int $userId, ?string $lastActivityDate, ?int $daysGap): void
{
    if ($daysGap === null || $lastActivityDate === null) return;

    $db = getDB();
    $rule = $db->prepare("
        SELECT * FROM comeback_rules
        WHERE is_active = 1 AND basis = 'learning_activity' AND days_threshold <= ?
        ORDER BY days_threshold DESC LIMIT 1
    ");
    $rule->execute([$daysGap]);
    $rule = $rule->fetch();
    if (!$rule) return;

    $ins = $db->prepare("INSERT IGNORE INTO comeback_reward_log (user_id, rule_id, anchor_date, xp_awarded, freeze_awarded) VALUES (?,?,?,?,?)");
    $ins->execute([$userId, $rule['id'], $lastActivityDate, (int)$rule['xp_reward'], (int)$rule['freeze_reward']]);
    if ($ins->rowCount() === 0) return; // already rewarded for this exact return

    if ((int)$rule['xp_reward'] > 0) {
        awardXp($userId, 'comeback_bonus', 'comeback_rule', (int)$rule['id'], (int)$rule['xp_reward']);
    }
    if ((int)$rule['freeze_reward'] > 0) {
        grantStreakFreeze($userId, (int)$rule['freeze_reward'], 'comeback_bonus', $rule['label']);
    }
    if (!empty($rule['badge_id'])) {
        $db->prepare("INSERT IGNORE INTO user_badges (user_id, badge_id) VALUES (?,?)")->execute([$userId, $rule['badge_id']]);
    }

    if ((int)$rule['xp_reward'] > 0 || (int)$rule['freeze_reward'] > 0) {
        $parts = [];
        if ((int)$rule['xp_reward'] > 0) $parts[] = "+{$rule['xp_reward']} XP";
        if ((int)$rule['freeze_reward'] > 0) $parts[] = "+{$rule['freeze_reward']} streak freeze" . ((int)$rule['freeze_reward'] !== 1 ? 's' : '');
        notifyUser($userId, 'comeback_bonus', '👋 Welcome back!', "Comeback bonus: " . implode(' and ', $parts) . ".", SITE_URL . '/dashboard.php');
    }
}

/**
 * Returns this user's notification preference row, creating the default
 * (everything on) on first read rather than requiring a row to exist for
 * every user up front.
 */
function getUserNotificationPreferences(int $userId): array
{
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM user_notification_preferences WHERE user_id=?");
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    if ($row) return $row;
    return ['user_id' => $userId, 'inactivity_reminders' => 1, 'achievement_notifications' => 1];
}

/** Whether this user currently wants inactivity/comeback reminder emails. */
function wantsInactivityReminders(int $userId): bool
{
    return (bool)(getUserNotificationPreferences($userId)['inactivity_reminders'] ?? 1);
}

/** Upserts a user's notification preferences (called from profile.php settings tab). */
function saveUserNotificationPreferences(int $userId, bool $inactivityReminders, bool $achievementNotifications): void
{
    $db = getDB();
    $db->prepare("
        INSERT INTO user_notification_preferences (user_id, inactivity_reminders, achievement_notifications)
        VALUES (?,?,?)
        ON DUPLICATE KEY UPDATE inactivity_reminders=VALUES(inactivity_reminders), achievement_notifications=VALUES(achievement_notifications)
    ")->execute([$userId, $inactivityReminders ? 1 : 0, $achievementNotifications ? 1 : 0]);
}

/**
 * Get the last lesson the user was on (for "Continue" button on dashboard).
 */
function getLastLesson(): ?array {
    if (!isLoggedIn()) return null;
    $db = getDB();
    $stmt = $db->prepare("
        SELECT l.* FROM user_lesson_progress ulp
        INNER JOIN lessons l ON l.id = ulp.lesson_id
        WHERE ulp.user_id = ?
        ORDER BY ulp.completed_at DESC
        LIMIT 1
    ");
    $stmt->execute([(int)$_SESSION['user_id']]);
    return $stmt->fetch() ?: null;
}

/**
 * Get next lesson in guide path after last completed.
 */
function getNextGuideLesson(int $guideId): ?array {
    if (!isLoggedIn()) return null;
    $db = getDB();
    $userId = (int)$_SESSION['user_id'];

    $stmt = $db->prepare("
        SELECT l.* FROM guide_path_lessons gpl
        INNER JOIN lessons l ON l.id = gpl.lesson_id
        WHERE gpl.guide_id = ?
          AND gpl.lesson_id NOT IN (
              SELECT lesson_id FROM user_lesson_progress WHERE user_id = ?
          )
        ORDER BY gpl.sort_order ASC
        LIMIT 1
    ");
    $stmt->execute([$guideId, $userId]);
    return $stmt->fetch() ?: null;
}

/* ============================================================
 * XP, LEVELING, STREAKS, CERTIFICATES — Phase 5 automation.
 * Every earning event in the app should route through awardXp()
 * so the ledger (user_activity_log) stays the single source of
 * truth and users.total_xp/current_level stay in sync.
 * ============================================================ */

/**
 * Resolves how much XP a lesson completion should pay out.
 * If the lesson was completed in the context of a guide path (guideId > 0)
 * and that guide-lesson row has a custom xp_reward set, that value wins.
 * Otherwise returns null, meaning "use the global xp_rules.lesson_complete value".
 */
function getLessonXpOverride(int $lessonId, int $guideId = 0): ?int
{
    if ($guideId <= 0) return null;
    $db = getDB();
    $stmt = $db->prepare("SELECT xp_reward FROM guide_path_lessons WHERE guide_id=? AND lesson_id=?");
    $stmt->execute([$guideId, $lessonId]);
    $val = $stmt->fetchColumn();
    return ($val === false || $val === null) ? null : (int)$val;
}

/**
 * Awards XP for a qualifying action, deduplicating on (user, action, reference)
 * so the same event never pays out twice. Returns the amount actually awarded
 * (0 if the rule is inactive, missing, or this exact event was already paid).
 */
function awardXp(int $userId, string $actionKey, ?string $referenceType = null, ?int $referenceId = null, ?int $overrideXp = null): int
{
    $db = getDB();

    if ($referenceId !== null) {
        $dupe = $db->prepare("SELECT id FROM user_activity_log WHERE user_id=? AND action_key=? AND reference_type=? AND reference_id=?");
        $dupe->execute([$userId, $actionKey, $referenceType, $referenceId]);
        if ($dupe->fetch()) return 0; // already paid for this exact event
    }

    $xpValue = $overrideXp;
    if ($xpValue === null) {
        $rule = $db->prepare("SELECT xp_value FROM xp_rules WHERE action_key=? AND is_active=1");
        $rule->execute([$actionKey]);
        $xpValue = $rule->fetchColumn();
        if ($xpValue === false) return 0; // rule missing/inactive
        $xpValue = (int)$xpValue;
    }
    if ($xpValue <= 0) return 0;

    $db->prepare("INSERT INTO user_activity_log (user_id, action_key, reference_type, reference_id, xp_awarded) VALUES (?,?,?,?,?)")
       ->execute([$userId, $actionKey, $referenceType, $referenceId, $xpValue]);

    $db->prepare("UPDATE users SET total_xp = total_xp + ? WHERE id = ?")->execute([$xpValue, $userId]);

    recalculateLevel($userId);

    return $xpValue;
}

/** Recomputes current_level from the levels table and fires a notification on level-up. */
function recalculateLevel(int $userId): void
{
    $db = getDB();
    $stmt = $db->prepare("SELECT total_xp, current_level FROM users WHERE id=?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    if (!$user) return;

    $levelStmt = $db->prepare("SELECT level_number, title FROM levels WHERE xp_required <= ? ORDER BY xp_required DESC LIMIT 1");
    $levelStmt->execute([$user['total_xp']]);
    $newLevel = $levelStmt->fetch();
    if (!$newLevel) return;

    if ((int)$newLevel['level_number'] !== (int)$user['current_level']) {
        $db->prepare("UPDATE users SET current_level=? WHERE id=?")->execute([$newLevel['level_number'], $userId]);
        if ((int)$newLevel['level_number'] > (int)$user['current_level']) {
            notifyUser($userId, 'level_up', 'Level up!', "You've reached Level {$newLevel['level_number']}: {$newLevel['title']}!", SITE_URL . '/dashboard.php');
            evaluateCertificateRules($userId, 'xp_level', ['level_number' => (int)$newLevel['level_number'], 'level_title' => $newLevel['title']]);
        }
    }
}

/**
 * Returns the user's available streak freeze balance.
 */
function getStreakFreezeBalance(int $userId): int
{
    $db = getDB();
    $stmt = $db->prepare("SELECT streak_freeze_count FROM users WHERE id=?");
    $stmt->execute([$userId]);
    $val = $stmt->fetchColumn();
    return $val === false ? 0 : (int)$val;
}

/**
 * Grants streak freezes to a user (admin grant today; future achievements/
 * milestones can call this too). Logged in streak_freeze_log as 'earned' so
 * balances stay auditable. Not tied to a specific calendar day, so no
 * uniqueness constraint applies here — callers are responsible for not
 * granting the same reward twice (e.g. checking a milestone isn't already
 * completed before calling this).
 */
function grantStreakFreeze(int $userId, int $amount = 1, string $source = 'admin_grant', ?string $note = null): void
{
    if ($amount <= 0) return;
    $db = getDB();
    $db->prepare("UPDATE users SET streak_freeze_count = streak_freeze_count + ? WHERE id=?")
       ->execute([$amount, $userId]);
    $db->prepare("INSERT INTO streak_freeze_log (user_id, action, source, note) VALUES (?, 'earned', ?, ?)")
       ->execute([$userId, $source, $note]);
}

/**
 * Updates the user's daily activity streak. Call once from any
 * "qualifying activity" (currently: lesson completion). Idempotent for
 * repeat calls on the same day. Awards daily_streak XP once per day and
 * checks streak_milestones for bonus XP/badge.
 *
 * Streak freezes: if the user missed exactly one qualifying day (gap of
 * two calendar days between last_activity_date and today) and has a freeze
 * available, the freeze is consumed automatically to protect the streak
 * instead of resetting it. The freeze consumption is logged against the
 * specific missed date (streak_freeze_log.protected_date), and that
 * (user_id, protected_date) pair is unique at the DB level, so the same
 * missed day can never burn two freezes even under a duplicate/retried
 * call. A gap of more than one missed day, or a missed day with no freeze
 * available, still breaks the streak exactly as before.
 */
function updateStreak(int $userId): void
{
    $db = getDB();
    $stmt = $db->prepare("SELECT current_streak_days, longest_streak_days, last_activity_date, streak_freeze_count FROM users WHERE id=?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    if (!$user) return;

    $today = date('Y-m-d');
    if ($user['last_activity_date'] === $today) return; // already counted today

    $lastDate   = $user['last_activity_date'];
    $daysGap    = $lastDate ? (int)round((strtotime($today) - strtotime($lastDate)) / 86400) : null;
    $freezeUsed = false;

    if ($daysGap === 1) {
        // Consecutive day — normal increment.
        $newStreak = (int)$user['current_streak_days'] + 1;
    } elseif ($daysGap === 2 && (int)$user['streak_freeze_count'] > 0) {
        // Exactly one day missed and a freeze is available — try to protect it.
        $missedDate = date('Y-m-d', strtotime($lastDate . ' +1 day'));
        $alreadyProtected = $db->prepare("SELECT id FROM streak_freeze_log WHERE user_id=? AND action='consumed' AND protected_date=?");
        $alreadyProtected->execute([$userId, $missedDate]);
        if ($alreadyProtected->fetch()) {
            // This exact missed day was already protected (duplicate/retried call) —
            // treat as continuation without spending a second freeze.
            $newStreak = (int)$user['current_streak_days'] + 1;
            $freezeUsed = true; // already spent; don't decrement again below
        } else {
            $consumed = $db->prepare("UPDATE users SET streak_freeze_count = streak_freeze_count - 1 WHERE id=? AND streak_freeze_count > 0");
            $consumed->execute([$userId]);
            if ($consumed->rowCount() > 0) {
                $db->prepare("INSERT IGNORE INTO streak_freeze_log (user_id, action, source, protected_date) VALUES (?, 'consumed', 'streak_missed_day', ?)")
                   ->execute([$userId, $missedDate]);
                $newStreak = (int)$user['current_streak_days'] + 1;
                notifyUser($userId, 'streak_freeze_used', '🧊 Streak Freeze Used', "Your streak freeze protected your {$user['current_streak_days']}-day streak.", SITE_URL . '/dashboard.php');
            } else {
                // Balance changed underneath us (race) — fall back to a normal reset.
                $newStreak = 1;
            }
        }
    } else {
        // No prior activity, or more than one day missed with no freeze to cover it.
        $newStreak = 1;
    }

    $newLongest = max($newStreak, (int)$user['longest_streak_days']);

    // Comeback bonus — evaluated against the OLD last_activity_date (the
    // gap the user is actually returning from), before it's overwritten
    // below. No-ops for daysGap < the smallest configured threshold.
    if ($daysGap !== null) {
        evaluateComebackBonus($userId, $lastDate, $daysGap);
    }

    $db->prepare("UPDATE users SET current_streak_days=?, longest_streak_days=?, last_activity_date=? WHERE id=?")
       ->execute([$newStreak, $newLongest, $today, $userId]);

    awardXp($userId, 'daily_streak', 'streak', null);

    $milestone = $db->prepare("SELECT * FROM streak_milestones WHERE days_required=? AND is_active=1");
    $milestone->execute([$newStreak]);
    if ($m = $milestone->fetch()) {
        awardXp($userId, 'daily_streak', 'streak_milestone', (int)$m['id'], (int)$m['xp_bonus']);
        if (!empty($m['badge_id'])) {
            $db->prepare("INSERT IGNORE INTO user_badges (user_id, badge_id) VALUES (?,?)")->execute([$userId, $m['badge_id']]);
        }
        notifyUser($userId, 'streak_milestone', "{$newStreak}-day streak! 🔥", "You've earned a bonus " . (int)$m['xp_bonus'] . " XP.", SITE_URL . '/dashboard.php');
    }
}

/**
 * Checks whether completing a lesson finished any guide(s) it belongs to,
 * and if so: awards completion XP and attempts certificate issuance
 * (subject to that guide's certificate settings). Call after
 * markLessonComplete() returns true.
 */
function checkGuideCompletion(int $userId, int $lessonId): void
{
    $db = getDB();

    // Guide paths containing this lesson
    $guideIds = $db->prepare("SELECT DISTINCT guide_id FROM guide_path_lessons WHERE lesson_id=?");
    $guideIds->execute([$lessonId]);
    foreach ($guideIds->fetchAll(PDO::FETCH_COLUMN) as $guideId) {
        $progress = getGuideProgress((int)$guideId);
        if ($progress['total'] > 0 && $progress['completed'] === $progress['total']) {
            $guideStmt = $db->prepare("SELECT * FROM guide_paths WHERE id=?"); $guideStmt->execute([$guideId]);
            $guideRow = $guideStmt->fetch();
            if (!$guideRow) continue;

            // XP + "lessons done" notification fire once, on the lesson
            // completion event, regardless of certificate eligibility below.
            $alreadyXp = $db->prepare("SELECT id FROM user_activity_log WHERE user_id=? AND action_key='guide_complete' AND reference_type='guide' AND reference_id=?");
            $alreadyXp->execute([$userId, $guideId]);
            if (!$alreadyXp->fetch()) {
                Analytics::track('guide_completed', $userId, ['guide_id' => $guideId, 'title' => $guideRow['title']]);
                awardXp($userId, 'guide_complete', 'guide', (int)$guideId, $guideRow['xp_reward'] ?? null);
                notifyUser($userId, 'guide_completed', 'Guide Completed 🎉', "You finished \"{$guideRow['title']}\".", SITE_URL . '/certificates.php');
                emailUser($userId, 'guide_completion', ['title' => $guideRow['title']]);
            }

            maybeIssueGuideCertificate($userId, (int)$guideId);
        }
    }
}

/**
 * Evaluates a guide's certificate settings (min completion %, min quiz
 * score, require final quiz, require premium) for one user and issues the
 * certificate if every configured requirement is met. Safe to call
 * repeatedly (from lesson completion AND from quiz submission) — issuance
 * itself is idempotent via issueCertificateV2().
 */
/**
 * Phase 2 pipeline check: Lessons -> Quiz -> Project -> Assessment.
 * Returns true only if every requirement the guide is CONFIGURED to require
 * (via guide_paths.min_completion_percent/require_final_quiz/require_project/
 * require_assessment) is actually satisfied for this user. Guides that don't
 * opt into a given requirement (the Phase 1 default for every existing guide)
 * simply skip that check, so pre-Phase-2 guides behave exactly as before —
 * "complete" still just means "every lesson done".
 *
 * Shared by maybeIssueGuideCertificate() (certificate issuance) and the
 * Career Path roadmap engine (guide unlock/current-stage gating), so the
 * definition of "this guide is done" never drifts between the two.
 */
function isGuidePipelineComplete(int $userId, int $guideId, ?array $guide = null): bool
{
    $db = getDB();
    if ($guide === null) {
        $guideStmt = $db->prepare("SELECT * FROM guide_paths WHERE id=?");
        $guideStmt->execute([$guideId]);
        $guide = $guideStmt->fetch();
    }
    if (!$guide) return false;

    $progress = getGuideProgressForUser($guideId, $userId);
    $minPct = isset($guide['min_completion_percent']) ? (int)$guide['min_completion_percent'] : 100;
    if ($progress['pct'] < $minPct) return false;

    if (!empty($guide['require_final_quiz'])) {
        $q = $db->prepare("SELECT id FROM quizzes WHERE guide_id=? AND quiz_type='quiz' AND is_active=1 LIMIT 1");
        $q->execute([$guideId]);
        $quizId = $q->fetchColumn();
        if (!$quizId) return false; // required but no quiz configured — can't satisfy
        $qr = $db->prepare("SELECT passed FROM quiz_results WHERE user_id=? AND quiz_id=?");
        $qr->execute([$userId, $quizId]);
        if (!(int)$qr->fetchColumn()) return false;
    }

    if (!empty($guide['require_project'])) {
        $reqProjects = $db->prepare("SELECT project_id FROM guide_projects WHERE guide_id=? AND requirement='required' AND is_visible=1");
        $reqProjects->execute([$guideId]);
        foreach ($reqProjects->fetchAll(PDO::FETCH_COLUMN) as $projectId) {
            $sub = $db->prepare("SELECT status FROM project_submissions WHERE user_id=? AND project_id=? AND guide_id=?");
            $sub->execute([$userId, $projectId, $guideId]);
            if ($sub->fetchColumn() !== 'approved') return false;
        }
    }

    if (!empty($guide['require_assessment'])) {
        $assessStmt = $db->prepare("SELECT id FROM quizzes WHERE guide_id=? AND quiz_type='assessment' AND is_active=1 LIMIT 1");
        $assessStmt->execute([$guideId]);
        $assessmentId = $assessStmt->fetchColumn();
        if (!$assessmentId) return false;
        $ar = $db->prepare("SELECT passed FROM quiz_results WHERE user_id=? AND quiz_id=?");
        $ar->execute([$userId, $assessmentId]);
        if (!(int)$ar->fetchColumn()) return false;
    }

    return true;
}

/**
 * Evaluates a guide's certificate settings (min completion %, min quiz
 * score, require final quiz, require premium) for one user and issues the
 * certificate if every configured requirement is met. Safe to call
 * repeatedly (from lesson completion AND from quiz submission) — issuance
 * itself is idempotent via issueCertificateV2().
 */
function maybeIssueGuideCertificate(int $userId, int $guideId): ?string
{
    $db = getDB();
    $guideStmt = $db->prepare("SELECT * FROM guide_paths WHERE id=?");
    $guideStmt->execute([$guideId]);
    $guide = $guideStmt->fetch();
    if (!$guide) return null;

    if (isset($guide['certificate_enabled']) && !$guide['certificate_enabled']) return null;

    if (!isGuidePipelineComplete($userId, $guideId, $guide)) return null;

    // min_quiz_score is a separate, stricter threshold on top of
    // require_final_quiz's plain pass/fail — kept here rather than folded
    // into isGuidePipelineComplete() since it's a certificate-only gate,
    // not part of "is the guide's material done".
    if (!empty($guide['min_quiz_score'])) {
        $guideQuiz = $db->prepare("SELECT id FROM quizzes WHERE guide_id=? AND quiz_type='quiz' AND is_active=1 LIMIT 1");
        $guideQuiz->execute([$guideId]);
        $quizId = $guideQuiz->fetchColumn();
        if ($quizId) {
            $qr = $db->prepare("SELECT score FROM quiz_results WHERE user_id=? AND quiz_id=?");
            $qr->execute([$userId, $quizId]);
            $score = $qr->fetchColumn();
            if ($score === false || (int)$score < (int)$guide['min_quiz_score']) return null;
        }
    }

    if (!empty($guide['require_premium'])) {
        if (!userHasActivePremium($userId)) return null;
    }

    $typeRow = getCertificateTypeByKey('guide_completion');
    $typeId = $typeRow['id'] ?? null;
    if (!$typeId) return null; // certificate_types not seeded — run the v2 migration

    $expiresDays = !empty($guide['certificate_expires_days']) ? (int)$guide['certificate_expires_days'] : null;

    return issueCertificateV2(
        $userId,
        'guide_path',
        $guideId,
        (int)$typeId,
        $guide['certificate_template_id'] ? (int)$guide['certificate_template_id'] : null,
        null,
        [
            'certificate_title' => $guide['title'],
            'guide_name'        => $guide['title'],
            'learning_path'     => $guide['title'],
            'allow_redownload'  => isset($guide['allow_redownload']) ? (bool)$guide['allow_redownload'] : true,
        ],
        $expiresDays
    );
}

/** Same shape as getGuideProgress() but for an arbitrary user (not just $_SESSION). */
function getGuideProgressForUser(int $guideId, int $userId): array
{
    $db = getDB();
    $total = $db->prepare("SELECT COUNT(*) FROM guide_path_lessons WHERE guide_id=?");
    $total->execute([$guideId]);
    $total = (int)$total->fetchColumn();
    if ($total === 0) return ['completed'=>0,'total'=>0,'pct'=>0];

    $done = $db->prepare("
        SELECT COUNT(*) FROM guide_path_lessons gpl
        INNER JOIN user_lesson_progress ulp ON ulp.lesson_id = gpl.lesson_id AND ulp.user_id = ?
        WHERE gpl.guide_id = ?
    ");
    $done->execute([$userId, $guideId]);
    $done = (int)$done->fetchColumn();

    return ['completed' => $done, 'total' => $total, 'pct' => (int)round($done / $total * 100)];
}

/**
 * Backward-compatible shim. Older call sites (and any custom code outside
 * this delivery) that call issueCertificate($userId, 'guide_path', $guideId)
 * keep working — it now routes through the guide-settings-aware
 * maybeIssueGuideCertificate() for guide_path, or the generic
 * issueCertificateV2() for anything else.
 */
function issueCertificate(int $userId, string $issuableType, int $issuableId): ?string
{
    if ($issuableType === 'guide_path') {
        return maybeIssueGuideCertificate($userId, $issuableId);
    }
    $typeRow = getCertificateTypeByKey('guide_completion');
    return issueCertificateV2($userId, $issuableType, $issuableId, (int)($typeRow['id'] ?? 0));
}

/* ============================================================================
 * MILESTONE SYSTEM (Phase 3)
 *
 * Admin-configurable milestones (milestones + milestone_requirements) that
 * complete via `user_milestones` — a COMPLETION RECORD ONLY. There is no
 * progress table: progress toward an incomplete milestone is always
 * computed live, in evaluateMilestoneRequirement()/getMilestoneProgress(),
 * directly from the tables the rest of this file already treats as the
 * source of truth (user_lesson_progress, quiz_results, guide/career-path
 * completion, users.total_xp/longest_streak_days, user_badges). Nothing
 * here awards XP or badges through a separate mechanism — every reward
 * routes through the existing awardXp() ledger and user_badges table.
 *
 * Because progress is always computed live rather than tracked
 * incrementally, a milestone created after users already satisfy its
 * requirements is recognized correctly the first time evaluateMilestones()
 * runs for them — there is nothing to backfill.
 * ============================================================================
 */

/**
 * Metadata describing each supported requirement_type, for the
 * admin milestone-requirement form to render type-aware fields (a
 * dropdown of lessons vs. a bare count input, etc). Adding a genuinely
 * new TYPE (not composed from these) means adding a case here AND in
 * evaluateMilestoneRequirement() — the "no code change" extensibility is
 * for composing new milestones out of these types, not for inventing new
 * evaluation logic.
 */
function milestoneRequirementTypes(): array
{
    return [
        'lesson_complete'      => ['label' => 'Complete a specific lesson',        'needs_target' => true,  'target_source' => 'lessons',      'needs_count' => false],
        'lesson_count'         => ['label' => 'Complete N lessons (any)',          'needs_target' => false, 'target_source' => null,           'needs_count' => true],
        'guide_complete'       => ['label' => 'Complete a specific guide',         'needs_target' => true,  'target_source' => 'guide_paths',  'needs_count' => false],
        'guide_count'          => ['label' => 'Complete N guides (any)',           'needs_target' => false, 'target_source' => null,           'needs_count' => true],
        'quiz_pass'            => ['label' => 'Pass a specific quiz',              'needs_target' => true,  'target_source' => 'quizzes',      'needs_count' => false],
        'quiz_count'           => ['label' => 'Pass N quizzes (any)',              'needs_target' => false, 'target_source' => null,           'needs_count' => true],
        'career_path_complete' => ['label' => 'Complete a specific career path',   'needs_target' => true,  'target_source' => 'career_paths', 'needs_count' => false],
        'points_earned'        => ['label' => 'Earn N total XP',                   'needs_target' => false, 'target_source' => null,           'needs_count' => true],
        'streak_achieved'      => ['label' => 'Reach an N-day streak (longest)',   'needs_target' => false, 'target_source' => null,           'needs_count' => true],
        'badge_earned'         => ['label' => 'Earn a specific badge',             'needs_target' => true,  'target_source' => 'badges',       'needs_count' => false],
    ];
}

/**
 * Evaluates a single requirement row against live data for $userId.
 * Returns ['met'=>bool, 'current'=>int|null, 'target'=>int|null] — current/
 * target are populated where a numeric progress readout makes sense (count
 * and threshold types); null for simple pass/fail types.
 * Unknown requirement_type values fail safe (met=false) rather than being
 * silently skipped, so a milestone can never complete on a typo'd type.
 */
function evaluateMilestoneRequirement(int $userId, array $req): array
{
    $db = getDB();
    $type = $req['requirement_type'];
    $targetId = $req['target_id'] !== null ? (int)$req['target_id'] : null;
    $targetCount = $req['target_count'] !== null ? (int)$req['target_count'] : null;

    switch ($type) {
        case 'lesson_complete': {
            $stmt = $db->prepare("SELECT 1 FROM user_lesson_progress WHERE user_id=? AND lesson_id=?");
            $stmt->execute([$userId, $targetId]);
            $met = (bool)$stmt->fetchColumn();
            return ['met' => $met, 'current' => $met ? 1 : 0, 'target' => 1];
        }
        case 'lesson_count': {
            $stmt = $db->prepare("SELECT COUNT(*) FROM user_lesson_progress WHERE user_id=?");
            $stmt->execute([$userId]);
            $current = (int)$stmt->fetchColumn();
            return ['met' => $targetCount !== null && $current >= $targetCount, 'current' => $current, 'target' => $targetCount];
        }
        case 'guide_complete': {
            $met = isGuidePipelineComplete($userId, (int)$targetId);
            return ['met' => $met, 'current' => $met ? 1 : 0, 'target' => 1];
        }
        case 'guide_count': {
            $guides = $db->query("SELECT id FROM guide_paths WHERE is_active = 1 AND deleted_at IS NULL")->fetchAll();
            $current = 0;
            foreach ($guides as $g) {
                if (isGuidePipelineComplete($userId, (int)$g['id'])) $current++;
            }
            return ['met' => $targetCount !== null && $current >= $targetCount, 'current' => $current, 'target' => $targetCount];
        }
        case 'quiz_pass': {
            $stmt = $db->prepare("SELECT passed FROM quiz_results WHERE user_id=? AND quiz_id=?");
            $stmt->execute([$userId, $targetId]);
            $met = (bool)$stmt->fetchColumn();
            return ['met' => $met, 'current' => $met ? 1 : 0, 'target' => 1];
        }
        case 'quiz_count': {
            $stmt = $db->prepare("SELECT COUNT(DISTINCT quiz_id) FROM quiz_results WHERE user_id=? AND passed=1");
            $stmt->execute([$userId]);
            $current = (int)$stmt->fetchColumn();
            return ['met' => $targetCount !== null && $current >= $targetCount, 'current' => $current, 'target' => $targetCount];
        }
        case 'career_path_complete': {
            $stmt = $db->prepare("SELECT 1 FROM user_career_paths WHERE user_id=? AND career_path_id=? AND status='completed'");
            $stmt->execute([$userId, $targetId]);
            $met = (bool)$stmt->fetchColumn();
            return ['met' => $met, 'current' => $met ? 1 : 0, 'target' => 1];
        }
        case 'points_earned': {
            $stmt = $db->prepare("SELECT total_xp FROM users WHERE id=?");
            $stmt->execute([$userId]);
            $current = (int)$stmt->fetchColumn();
            return ['met' => $targetCount !== null && $current >= $targetCount, 'current' => $current, 'target' => $targetCount];
        }
        case 'streak_achieved': {
            $stmt = $db->prepare("SELECT longest_streak_days FROM users WHERE id=?");
            $stmt->execute([$userId]);
            $current = (int)$stmt->fetchColumn();
            return ['met' => $targetCount !== null && $current >= $targetCount, 'current' => $current, 'target' => $targetCount];
        }
        case 'badge_earned': {
            $stmt = $db->prepare("SELECT 1 FROM user_badges WHERE user_id=? AND badge_id=?");
            $stmt->execute([$userId, $targetId]);
            $met = (bool)$stmt->fetchColumn();
            return ['met' => $met, 'current' => $met ? 1 : 0, 'target' => 1];
        }
        default:
            return ['met' => false, 'current' => null, 'target' => null];
    }
}

/**
 * Live progress for one milestone: per-requirement met/current/target plus
 * an overall completed/total requirement count, matching the "6 / 8
 * requirements completed" style from the spec. Never reads or writes
 * user_milestones progress state — it's recomputed from scratch every call.
 */
function getMilestoneProgress(int $userId, int $milestoneId): array
{
    $db = getDB();
    $reqStmt = $db->prepare("SELECT * FROM milestone_requirements WHERE milestone_id=? ORDER BY sort_order ASC, id ASC");
    $reqStmt->execute([$milestoneId]);
    $requirements = $reqStmt->fetchAll();

    $results = [];
    $metCount = 0;
    foreach ($requirements as $req) {
        $evalResult = evaluateMilestoneRequirement($userId, $req);
        if ($evalResult['met']) $metCount++;
        $results[] = [
            'requirement_type' => $req['requirement_type'],
            'label'            => $req['label'],
            'target_id'        => $req['target_id'],
            'met'              => $evalResult['met'],
            'current'          => $evalResult['current'],
            'target'           => $evalResult['target'],
        ];
    }

    $total = count($requirements);
    return [
        'completed'    => $metCount,
        'total'        => $total,
        'pct'          => $total > 0 ? (int)round($metCount / $total * 100) : 0,
        'requirements' => $results,
    ];
}

/**
 * The one true milestone completion path — call this after any completion
 * event (lesson, quiz, career path, badge). Checks every active milestone
 * the user hasn't already completed; for each one where ALL requirements
 * are now met, records completion and pays out rewards exactly once.
 *
 * Idempotency: user_milestones.UNIQUE(user_id, milestone_id) is the real
 * guard — INSERT IGNORE either wins the row or silently no-ops, so this
 * is safe to call repeatedly, on every page refresh, or from two
 * concurrent/replayed requests racing each other; only one can ever win
 * the insert. XP is paid through the existing awardXp() ledger (which has
 * its own independent action_key+reference dedup), and the badge award is
 * a second INSERT IGNORE into user_badges — so even in the (practically
 * impossible, given the user_milestones guard) case of this function
 * somehow re-entering after a partial failure, neither the XP nor the
 * badge can be paid twice.
 *
 * Returns the list of milestones newly completed on this call (empty on a
 * no-op call, which is the common case).
 */
function evaluateMilestones(int $userId): array
{
    $db = getDB();
    $pending = $db->prepare("
        SELECT m.* FROM milestones m
        WHERE m.is_active = 1 AND m.deleted_at IS NULL
          AND NOT EXISTS (SELECT 1 FROM user_milestones um WHERE um.user_id = ? AND um.milestone_id = m.id)
        ORDER BY m.sort_order ASC, m.id ASC
    ");
    $pending->execute([$userId]);

    $newlyCompleted = [];

    foreach ($pending->fetchAll() as $milestone) {
        $progress = getMilestoneProgress($userId, (int)$milestone['id']);
        if ($progress['total'] === 0 || $progress['completed'] < $progress['total']) {
            continue; // not all requirements met yet (a milestone with zero requirements never auto-completes)
        }

        $ins = $db->prepare("INSERT IGNORE INTO user_milestones (user_id, milestone_id) VALUES (?, ?)");
        $ins->execute([$userId, $milestone['id']]);
        if ($ins->rowCount() === 0) {
            continue; // another concurrent/replayed call already completed this one
        }

        if ((int)$milestone['point_reward'] > 0) {
            awardXp($userId, 'milestone_complete', 'milestone', (int)$milestone['id'], (int)$milestone['point_reward']);
        }

        $badgeAwarded = null;
        if (!empty($milestone['badge_id'])) {
            $badgeIns = $db->prepare("INSERT IGNORE INTO user_badges (user_id, badge_id) VALUES (?, ?)");
            $badgeIns->execute([$userId, $milestone['badge_id']]);
            if ($badgeIns->rowCount() > 0) {
                $badgeStmt = $db->prepare("SELECT * FROM badges WHERE id=?");
                $badgeStmt->execute([$milestone['badge_id']]);
                $badgeAwarded = $badgeStmt->fetch();
            }
        }

        notifyUser(
            $userId,
            'milestone_complete',
            '🏆 Milestone Unlocked: ' . $milestone['name'],
            $milestone['description'] ?: ('+' . (int)$milestone['point_reward'] . ' XP'),
            SITE_URL . '/dashboard.php'
        );

        // Reuses the existing certificate automation (custom_event trigger
        // type) rather than building a separate achievement/verification
        // system in this phase — a no-op today unless/until an admin
        // configures a certificate_rules row for this event, which keeps
        // this phase from duplicating what Phase 4 is scoped to build.
        evaluateCertificateRules($userId, 'custom_event', [
            'event_key'    => 'milestone_complete',
            'milestone_id' => (int)$milestone['id'],
            'milestone_slug' => $milestone['slug'],
        ]);

        $milestone['badge_awarded'] = $badgeAwarded;
        $newlyCompleted[] = $milestone;
    }

    return $newlyCompleted;
}
