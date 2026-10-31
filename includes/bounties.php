<?php
/**
 * Web3edu — Bounty Marketplace
 *
 * Core library for bounties, bounty profiles, hosts, submissions,
 * payments, and winners. Include after config.php (and certificates.php
 * is pulled in automatically — winner certificates reuse that engine).
 */
require_once __DIR__ . '/certificates.php';
require_once __DIR__ . '/lucide.php';

// ============================================================
// CATEGORIES
// ============================================================

function getBountyCategories(bool $activeOnly = true): array
{
    $db = getDB();
    $sql = "SELECT * FROM bounty_categories" . ($activeOnly ? " WHERE is_active = 1" : "") . " ORDER BY sort_order ASC, name ASC";
    return $db->query($sql)->fetchAll();
}

// ============================================================
// BOUNTY PROFILE (separate from the normal Web3Edu profile)
// ============================================================

function getBountyProfile(int $userId): ?array
{
    $stmt = getDB()->prepare("SELECT * FROM bounty_profiles WHERE user_id = ?");
    $stmt->execute([$userId]);
    return $stmt->fetch() ?: null;
}

function hasBountyProfile(int $userId): bool
{
    return getBountyProfile($userId) !== null;
}

function saveBountyProfile(int $userId, array $data): bool
{
    $db = getDB();
    $fields = [
        'display_name'    => trim($data['display_name'] ?? ''),
        'bio'             => trim($data['bio'] ?? '') ?: null,
        'country'         => trim($data['country'] ?? ''),
        'twitter'         => trim($data['twitter'] ?? '') ?: null,
        'discord'         => trim($data['discord'] ?? '') ?: null,
        'telegram'        => trim($data['telegram'] ?? '') ?: null,
        'github'          => trim($data['github'] ?? '') ?: null,
        'portfolio_url'   => trim($data['portfolio_url'] ?? '') ?: null,
        'solana_wallet'   => trim($data['solana_wallet'] ?? '') ?: null,
        'ethereum_wallet' => trim($data['ethereum_wallet'] ?? '') ?: null,
        'ton_wallet'      => trim($data['ton_wallet'] ?? '') ?: null,
        'bank_name'       => trim($data['bank_name'] ?? '') ?: null,
        'account_number'  => trim($data['account_number'] ?? '') ?: null,
    ];

    if ($fields['display_name'] === '' || $fields['country'] === '' || empty($fields['twitter'])) return false;

    $existing = getBountyProfile($userId);
    if ($existing) {
        $set = implode(', ', array_map(fn($c) => "`$c`=?", array_keys($fields)));
        $params = array_values($fields);
        $params[] = $userId;
        $db->prepare("UPDATE bounty_profiles SET $set WHERE user_id=?")->execute($params);
    } else {
        $fields['user_id'] = $userId;
        $cols = array_keys($fields);
        $colList = implode(',', array_map(fn($c) => "`$c`", $cols));
        $placeholders = implode(',', array_fill(0, count($cols), '?'));
        try {
            $db->prepare("INSERT INTO bounty_profiles ($colList) VALUES ($placeholders)")->execute(array_values($fields));
        } catch (PDOException $e) {
            // Same uniq_bounty_profile_user race already handled the same
            // way in createOrGetBountyHost()/createHostApplication() below —
            // the loser of a near-simultaneous double-submit just falls
            // back to updating the winner's row instead of erroring out.
            if ((int)$e->getCode() !== 23000) throw $e;
            unset($fields['user_id']);
            $set = implode(', ', array_map(fn($c) => "`$c`=?", array_keys($fields)));
            $params = array_values($fields);
            $params[] = $userId;
            $db->prepare("UPDATE bounty_profiles SET $set WHERE user_id=?")->execute($params);
        }
    }
    return true;
}

// ============================================================
// HOSTS — auto-created on a user's first hosted bounty
// ============================================================

function getBountyHost(int $userId): ?array
{
    $stmt = getDB()->prepare("SELECT * FROM bounty_hosts WHERE user_id = ?");
    $stmt->execute([$userId]);
    return $stmt->fetch() ?: null;
}

function getBountyHostById(int $hostId): ?array
{
    $stmt = getDB()->prepare("SELECT * FROM bounty_hosts WHERE id = ?");
    $stmt->execute([$hostId]);
    return $stmt->fetch() ?: null;
}

function isBountyHost(int $userId): bool
{
    return getBountyHost($userId) !== null;
}

/** True only once a Host Profile exists AND has been approved — this (not isBountyHost()) is the real gate for the Host Dashboard and Hosted Bounty creation. */
function isApprovedHost(int $userId): bool
{
    $host = getBountyHost($userId);
    return $host !== null && $host['status'] === 'approved';
}

/** Human label for a Host Profile status, used consistently on the status page and admin list. */
function hostStatusLabel(string $status): string
{
    return match ($status) {
        'pending_approval' => 'Pending Approval',
        'approved'          => 'Approved',
        'request_changes'   => 'Request Changes',
        'rejected'          => 'Rejected',
        'suspended'         => 'Suspended',
        default             => ucwords(str_replace('_', ' ', $status)),
    };
}

/**
 * Creates the host profile the first time an ADMIN hosts a bounty on behalf
 * of an organization, or returns the existing one. Used only from
 * admin/bounties.php's "Hosted" bounty-type creator — admin-initiated hosts
 * are approved immediately since an admin is deliberately setting them up,
 * unlike the self-serve "Become a Host" flow (see createHostApplication()),
 * which starts at pending_approval and must go through review.
 *
 * The `user_id` column has a UNIQUE constraint at the DB level, so even a
 * race between two near-simultaneous first submits can't produce two rows —
 * the loser of the race just falls through to re-fetching the winner's row
 * below instead of erroring out.
 */
function createOrGetBountyHost(int $userId, array $data): array
{
    $existing = getBountyHost($userId);
    if ($existing) return $existing;

    $db = getDB();
    try {
        $db->prepare("
            INSERT INTO bounty_hosts (user_id, organization_name, logo_path, website, contact_email, description, is_verified, status)
            VALUES (?,?,?,?,?,?,0,'approved')
        ")->execute([
            $userId,
            trim($data['organization_name'] ?? ''),
            $data['logo_path'] ?? null,
            trim($data['website'] ?? '') ?: null,
            trim($data['contact_email'] ?? ''),
            trim($data['description'] ?? '') ?: null,
        ]);
    } catch (\PDOException $e) {
        // SQLSTATE 23000 = integrity constraint violation. If this is the
        // unique-user_id race, another request already created the profile —
        // just return it. Any other integrity error still needs to surface.
        if ($e->getCode() !== '23000' || !getBountyHost($userId)) {
            throw $e;
        }
    }

    return getBountyHost($userId);
}

// ============================================================
// HOST APPLICATIONS — self-serve "Become a Host" (Phase 1)
// ============================================================

/**
 * Self-serve Host Profile creation ("Become a Host"). Unlike
 * createOrGetBountyHost() (admin-initiated, auto-approved), a self-serve
 * application always starts at 'pending_approval' and must be reviewed in
 * Admin -> Bounties -> Host Applications before the user can create any
 * Hosted Bounties. Creating a Host Profile never creates a bounty.
 */
function createHostApplication(int $userId, array $data): array
{
    $existing = getBountyHost($userId);
    if ($existing) return $existing;

    $db = getDB();
    try {
        $db->prepare("
            INSERT INTO bounty_hosts
                (user_id, organization_name, logo_path, website, contact_email, description, country, organization_type, twitter, discord, telegram, is_verified, status)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,0,'pending_approval')
        ")->execute([
            $userId,
            trim($data['organization_name'] ?? ''),
            $data['logo_path'] ?? null,
            trim($data['website'] ?? '') ?: null,
            trim($data['contact_email'] ?? ''),
            trim($data['description'] ?? '') ?: null,
            trim($data['country'] ?? '') ?: null,
            trim($data['organization_type'] ?? '') ?: null,
            trim($data['twitter'] ?? '') ?: null,
            trim($data['discord'] ?? '') ?: null,
            trim($data['telegram'] ?? '') ?: null,
        ]);
    } catch (\PDOException $e) {
        if ($e->getCode() !== '23000' || !getBountyHost($userId)) {
            throw $e;
        }
    }

    $host = getBountyHost($userId);

    Analytics::track('bounty_host_started', $userId, ['host_id' => (int)$host['id'], 'organization_name' => $host['organization_name']]);

    try {
        notifyAdmins('host_profile_submitted_admin', [
            'username'          => currentUsername($userId),
            'organization_name' => $host['organization_name'],
        ]);
    } catch (\Throwable $e) {
        error_log("[BountyHost] Post-save admin notification failed for host application #{$host['id']}: " . $e->getMessage());
    }

    return $host;
}

/**
 * Edit-and-resubmit — only meaningful while a Host Profile is in
 * 'request_changes' (per the spec, that's the one status where editing +
 * resubmitting is allowed). Moves the status back to 'pending_approval' and
 * clears the previous admin message so the review screen doesn't show stale
 * feedback next to a freshly-edited profile.
 */
function resubmitHostApplication(int $hostId, array $data): bool
{
    $db = getDB();
    $ok = $db->prepare("
        UPDATE bounty_hosts SET
            organization_name = ?, logo_path = COALESCE(?, logo_path), website = ?, contact_email = ?,
            description = ?, country = ?, organization_type = ?, twitter = ?, discord = ?, telegram = ?,
            status = 'pending_approval', admin_message = NULL
        WHERE id = ? AND status = 'request_changes'
    ")->execute([
        trim($data['organization_name'] ?? ''),
        $data['logo_path'] ?? null,
        trim($data['website'] ?? '') ?: null,
        trim($data['contact_email'] ?? ''),
        trim($data['description'] ?? '') ?: null,
        trim($data['country'] ?? '') ?: null,
        trim($data['organization_type'] ?? '') ?: null,
        trim($data['twitter'] ?? '') ?: null,
        trim($data['discord'] ?? '') ?: null,
        trim($data['telegram'] ?? '') ?: null,
        $hostId,
    ]);

    if ($ok) {
        $host = getBountyHostById($hostId);
        try {
            notifyAdmins('host_profile_resubmitted_admin', [
                'username'          => currentUsername((int)$host['user_id']),
                'organization_name' => $host['organization_name'],
            ]);
        } catch (\Throwable $e) {
            error_log("[BountyHost] Post-resubmit admin notification failed for host #{$hostId}: " . $e->getMessage());
        }
    }

    return (bool)$ok;
}

/** Owner edits their org details while already approved — updates in place, does NOT touch status/verification/fee_policy (those stay admin-controlled). */
function updateApprovedHostProfile(int $hostId, array $data): bool
{
    $db = getDB();
    return (bool)$db->prepare("
        UPDATE bounty_hosts SET
            organization_name = ?, logo_path = COALESCE(?, logo_path), website = ?, contact_email = ?,
            description = ?, country = ?, organization_type = ?, twitter = ?, discord = ?, telegram = ?
        WHERE id = ? AND status = 'approved'
    ")->execute([
        trim($data['organization_name'] ?? ''),
        $data['logo_path'] ?? null,
        trim($data['website'] ?? '') ?: null,
        trim($data['contact_email'] ?? ''),
        trim($data['description'] ?? '') ?: null,
        trim($data['country'] ?? '') ?: null,
        trim($data['organization_type'] ?? '') ?: null,
        trim($data['twitter'] ?? '') ?: null,
        trim($data['discord'] ?? '') ?: null,
        trim($data['telegram'] ?? '') ?: null,
        $hostId,
    ]);
}

/** Admin: approve a Host Application. Also sets verification + fee policy, both configured in the same review step. */
function approveHostApplication(int $hostId, int $adminId, bool $verified, string $feePolicy, string $internalNotes = '', string $messageToHost = ''): void
{
    $db = getDB();
    $db->prepare("
        UPDATE bounty_hosts SET
            status = 'approved', is_verified = ?, fee_policy = ?, internal_notes = ?, admin_message = ?,
            reviewed_by = ?, reviewed_at = NOW()
        WHERE id = ?
    ")->execute([$verified ? 1 : 0, $feePolicy, $internalNotes ?: null, $messageToHost ?: null, $adminId, $hostId]);

    $host = getBountyHostById($hostId);
    if ($host) {
        notifyUser((int)$host['user_id'], 'host_profile_approved', 'Host Profile Approved 🎉', "Your Host Profile for \"{$host['organization_name']}\" was approved. You can now create bounties.", SITE_URL . '/bounty-host-dashboard.php');
        emailUser((int)$host['user_id'], 'host_profile_approved', ['organization_name' => $host['organization_name']]);
    }
}

/** Admin: request changes on a Host Application — host may edit and resubmit (see resubmitHostApplication()). */
function requestHostChanges(int $hostId, int $adminId, string $message, string $internalNotes = ''): void
{
    $db = getDB();
    $db->prepare("
        UPDATE bounty_hosts SET status = 'request_changes', admin_message = ?, internal_notes = COALESCE(NULLIF(?, ''), internal_notes),
            reviewed_by = ?, reviewed_at = NOW()
        WHERE id = ?
    ")->execute([$message, $internalNotes, $adminId, $hostId]);

    $host = getBountyHostById($hostId);
    if ($host) {
        notifyUser((int)$host['user_id'], 'host_profile_request_changes', 'Action Needed on Host Profile', $message, SITE_URL . '/bounty-host-edit.php');
        emailUser((int)$host['user_id'], 'host_profile_request_changes', ['organization_name' => $host['organization_name'], 'message' => $message]);
    }
}

/** Admin: reject a Host Application outright — the user may start a completely new application if they wish. */
function rejectHostApplication(int $hostId, int $adminId, string $message, string $internalNotes = ''): void
{
    $db = getDB();
    $db->prepare("
        UPDATE bounty_hosts SET status = 'rejected', admin_message = ?, internal_notes = COALESCE(NULLIF(?, ''), internal_notes),
            reviewed_by = ?, reviewed_at = NOW()
        WHERE id = ?
    ")->execute([$message, $internalNotes, $adminId, $hostId]);

    $host = getBountyHostById($hostId);
    if ($host) {
        notifyUser((int)$host['user_id'], 'host_profile_rejected', 'Host Profile Not Approved', $message, SITE_URL . '/become-a-host.php');
        emailUser((int)$host['user_id'], 'host_profile_rejected', ['organization_name' => $host['organization_name'], 'message' => $message]);
    }
}

/** Admin: suspend a previously-approved host — profile stays visible but hosting is disabled until re-approved. */
function suspendHostApplication(int $hostId, int $adminId, string $message, string $internalNotes = ''): void
{
    $db = getDB();
    $db->prepare("
        UPDATE bounty_hosts SET status = 'suspended', admin_message = ?, internal_notes = COALESCE(NULLIF(?, ''), internal_notes),
            reviewed_by = ?, reviewed_at = NOW()
        WHERE id = ?
    ")->execute([$message, $internalNotes, $adminId, $hostId]);

    $host = getBountyHostById($hostId);
    if ($host) {
        notifyUser((int)$host['user_id'], 'host_profile_suspended', 'Host Profile Suspended', $message, SITE_URL . '/bounty-host-dashboard.php');
        emailUser((int)$host['user_id'], 'host_profile_suspended', ['organization_name' => $host['organization_name'], 'message' => $message]);
    }
}

/** Admin: rejected applicant wants to try again — reuses the same row (one Host Profile per user is a hard DB constraint) but resets it to a fresh pending_approval state. */
function reapplyHostApplication(int $hostId, array $data): bool
{
    $db = getDB();
    return (bool)$db->prepare("
        UPDATE bounty_hosts SET
            organization_name = ?, logo_path = COALESCE(?, logo_path), website = ?, contact_email = ?,
            description = ?, country = ?, organization_type = ?, twitter = ?, discord = ?, telegram = ?,
            status = 'pending_approval', admin_message = NULL, is_verified = 0
        WHERE id = ? AND status = 'rejected'
    ")->execute([
        trim($data['organization_name'] ?? ''),
        $data['logo_path'] ?? null,
        trim($data['website'] ?? '') ?: null,
        trim($data['contact_email'] ?? ''),
        trim($data['description'] ?? '') ?: null,
        trim($data['country'] ?? '') ?: null,
        trim($data['organization_type'] ?? '') ?: null,
        trim($data['twitter'] ?? '') ?: null,
        trim($data['discord'] ?? '') ?: null,
        trim($data['telegram'] ?? '') ?: null,
        $hostId,
    ]);
}

/** Badge label + Lucide icon key for a bounty's host / type, used consistently across cards and detail pages. */
function bountyTypeBadge(array $bounty): array
{
    switch ($bounty['type']) {
        case 'official':
            return ['label' => 'Official', 'icon' => lucide('crown', ['size' => 12]), 'class' => 'bounty-badge-official'];
        case 'external':
            return ['label' => 'External', 'icon' => lucide('link', ['size' => 12]), 'class' => 'bounty-badge-external'];
        case 'hosted':
        default:
            $verified = !empty($bounty['host_is_verified']);
            return $verified
                ? ['label' => 'Verified Host', 'icon' => lucide('shield-check', ['size' => 12]), 'class' => 'bounty-badge-verified']
                : ['label' => 'Community Host', 'icon' => lucide('users', ['size' => 12]), 'class' => 'bounty-badge-community'];
    }
}

/**
 * Premium-Only gating (feature 1). Only meaningful for official/hosted bounties —
 * external bounties are never gated since submissions happen off-platform.
 */
function bountyIsPremiumOnly(array $bounty): bool
{
    return $bounty['type'] !== 'external' && !empty($bounty['is_premium_only']);
}

/** Whether the given (possibly logged-out) user is allowed to submit an entry to this bounty. */
function userCanSubmitToBounty(array $bounty): bool
{
    if (!bountyIsPremiumOnly($bounty)) return true;
    return isPremium();
}

// ============================================================
// BOUNTIES — create / fetch / list
// ============================================================

const BOUNTY_SELECT_BASE = "
    SELECT b.*, bc.name AS category_name, bc.icon AS category_icon, bc.slug AS category_slug,
           bh.organization_name AS host_name, bh.logo_path AS host_logo, bh.is_verified AS host_is_verified,
           (SELECT COUNT(*) FROM user_bookmarks WHERE bookmarkable_type='bounty' AND bookmarkable_id=b.id) AS bookmark_count,
           (SELECT COUNT(*) FROM bounty_submissions WHERE bounty_id=b.id) AS submission_count
    FROM bounties b
    LEFT JOIN bounty_categories bc ON bc.id = b.category_id
    LEFT JOIN bounty_hosts bh ON bh.id = b.host_id
";

function getBounty(int $id): ?array
{
    $stmt = getDB()->prepare(BOUNTY_SELECT_BASE . " WHERE b.id = ? AND b.deleted_at IS NULL");
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function getBountyBySlug(string $slug): ?array
{
    $stmt = getDB()->prepare(BOUNTY_SELECT_BASE . " WHERE b.slug = ? AND b.deleted_at IS NULL");
    $stmt->execute([$slug]);
    return $stmt->fetch() ?: null;
}

/**
 * $filters: status (default 'published'), category_id, search, sort
 * (newest|ending_soon|highest_reward|featured), type, limit
 */
function listBounties(array $filters = []): array
{
    $db = getDB();
    $where = ['b.deleted_at IS NULL'];
    $params = [];

    $status = $filters['status'] ?? 'published';
    if ($status !== 'any') { $where[] = 'b.status = ?'; $params[] = $status; }

    if (!empty($filters['type'])) { $where[] = 'b.type = ?'; $params[] = $filters['type']; }
    if (!empty($filters['category_id'])) { $where[] = 'b.category_id = ?'; $params[] = (int)$filters['category_id']; }
    if (!empty($filters['search'])) {
        $where[] = '(b.title LIKE ? OR b.project_name LIKE ? OR b.skills LIKE ?)';
        $like = '%' . $filters['search'] . '%';
        array_push($params, $like, $like, $like);
    }

    $order = 'b.is_featured DESC, b.created_at DESC';
    switch ($filters['sort'] ?? '') {
        case 'newest':          $order = 'b.published_at DESC, b.created_at DESC'; break;
        case 'ending_soon':     $order = '(b.deadline IS NULL) ASC, b.deadline ASC'; break;
        case 'highest_reward':  $order = 'b.listing_fee_kobo DESC, b.created_at DESC'; break; // see note below
        case 'featured':        $order = 'b.is_featured DESC, b.created_at DESC'; break;
    }

    $limit = isset($filters['limit']) ? (int)$filters['limit'] : 50;

    $sql = BOUNTY_SELECT_BASE . " WHERE " . implode(' AND ', $where) . " ORDER BY $order LIMIT $limit";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function getFeaturedBounties(int $limit = 3): array
{
    return listBounties(['status' => 'published', 'sort' => 'featured', 'limit' => $limit]);
}

/** Winners across every bounty, most recently announced first. */
function getRecentWinners(int $limit = 6): array
{
    $stmt = getDB()->prepare("
        SELECT s.*, b.title AS bounty_title, b.slug AS bounty_slug, b.project_logo, u.username, u.avatar
        FROM bounty_submissions s
        INNER JOIN bounties b ON b.id = s.bounty_id
        INNER JOIN users u ON u.id = s.user_id
        WHERE s.status = 'winner' AND b.deleted_at IS NULL
        ORDER BY s.updated_at DESC
        LIMIT ?
    ");
    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

/** Note: "highest reward" sorting is approximate since reward_display is free text (e.g. "5,000 USDC" or "₦300,000"); admins should mark standout bounties as Featured for a reliable top spot. */

// ============================================================
// CREATE BOUNTIES (official / hosted / external)
// ============================================================

/** Admin-created Official Web3Edu bounty — published immediately (no review/payment needed). */
function createOfficialBounty(array $data, int $adminId): int
{
    $db = getDB();
    $slug = uniqueSlug('bounties', generateSlug($data['title']));

    $db->beginTransaction();
    try {
        $db->prepare("
            INSERT INTO bounties
                (type, category_id, title, slug, project_name, project_logo, cover_image, description, requirements, skills,
                 reward_display, reward_breakdown, num_winners, difficulty, country, deadline, winner_announcement_date,
                 status, is_featured, is_premium_only, approved_by, approved_at, published_at)
            VALUES ('official',?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?, 'published', ?, ?, ?, NOW(), NOW())
        ")->execute([
            $data['category_id'] ?: null, $data['title'], $slug, $data['project_name'] ?: null, $data['project_logo'] ?? null, $data['cover_image'] ?? null,
            $data['description'] ?? '', $data['requirements'] ?? '', $data['skills'] ?? '',
            $data['reward_display'] ?? '', json_encode($data['reward_breakdown'] ?? []), (int)($data['num_winners'] ?? 1),
            $data['difficulty'] ?? 'Intermediate', $data['country'] ?: null, $data['deadline'] ?: null, $data['winner_announcement_date'] ?: null,
            !empty($data['is_featured']) ? 1 : 0, !empty($data['is_premium_only']) ? 1 : 0, $adminId,
        ]);
        $bountyId = (int)$db->lastInsertId();
        $db->commit();
        return $bountyId;
    } catch (\Throwable $e) {
        $db->rollBack();
        error_log("[Bounty] Failed to save official bounty (admin #{$adminId}): " . $e->getMessage());
        throw $e;
    }
}

/** User hosts a bounty on Web3Edu — goes to pending_review. */
function createHostedBounty(int $userId, int $hostId, array $data): int
{
    $db = getDB();
    $slug = uniqueSlug('bounties', generateSlug($data['title']));
    $listingFee = bountyListingFeeForHost($hostId);

    // The save itself is the one step that must be atomic and must be allowed
    // to fail loudly (the caller needs to know if the bounty was NOT created).
    $db->beginTransaction();
    try {
        $db->prepare("
            INSERT INTO bounties
                (type, host_id, category_id, title, slug, project_name, project_logo, description, requirements, skills,
                 reward_display, reward_breakdown, num_winners, difficulty, country, deadline, winner_announcement_date,
                 status, listing_fee_kobo, submitted_by)
            VALUES ('hosted',?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?, 'pending_review', ?, ?)
        ")->execute([
            $hostId, $data['category_id'] ?: null, $data['title'], $slug, $data['project_name'] ?? '' ?: null, $data['project_logo'] ?? null,
            $data['description'] ?? '', $data['requirements'] ?? '', $data['skills'] ?? '',
            $data['reward_display'] ?? '', json_encode($data['reward_breakdown'] ?? []), (int)($data['num_winners'] ?? 1),
            $data['difficulty'] ?? 'Intermediate', $data['country'] ?? '' ?: null, $data['deadline'] ?? '' ?: null, $data['winner_announcement_date'] ?? '' ?: null,
            $listingFee, $userId,
        ]);
        $bountyId = (int)$db->lastInsertId();
        $db->commit();
    } catch (\Throwable $e) {
        $db->rollBack();
        error_log("[Bounty] Failed to save hosted bounty for user #{$userId}: " . $e->getMessage());
        throw $e; // this one really did fail — the caller must show an error, not redirect
    }

    Analytics::track('bounty_hosted', $userId, ['bounty_id' => $bountyId, 'host_id' => $hostId, 'title' => $data['title']]);

    // Everything below runs AFTER the commit. It's best-effort: the bounty is
    // already safely saved, so none of this may ever throw back out to the caller.
    try {
        notifyAdmins('bounty_submitted_admin', ['username' => currentUsername($userId), 'title' => $data['title'], 'type' => 'Hosted']);
    } catch (\Throwable $e) {
        error_log("[Bounty] Post-save notification failed for hosted bounty #{$bountyId}: " . $e->getMessage());
    }

    return $bountyId;
}

/** User shares an external bounty (Superteam Earn, Gitcoin, etc) — goes to pending_review. Duplicate URLs are rejected. */
function createExternalBounty(int $userId, array $data): array
{
    $db = getDB();
    $url = trim($data['external_url'] ?? '');

    $dupe = $db->prepare("SELECT id FROM bounties WHERE external_url = ? AND deleted_at IS NULL");
    $dupe->execute([$url]);
    if ($dupe->fetch()) return ['success' => false, 'error' => 'This bounty URL has already been submitted.'];

    $slug = uniqueSlug('bounties', generateSlug($data['title']));

    $db->beginTransaction();
    try {
        $db->prepare("
            INSERT INTO bounties
                (type, category_id, title, slug, project_name, description, skills,
                 reward_display, difficulty, country, deadline,
                 external_platform, external_url, status, submitted_by)
            VALUES ('external',?,?,?,?,?,?,?,?,?,?,?,?, 'pending_review', ?)
        ")->execute([
            $data['category_id'] ?: null, $data['title'], $slug, $data['project_name'] ?: null,
            $data['description'] ?? '', $data['skills'] ?? '',
            $data['reward_display'] ?? '', $data['difficulty'] ?? 'Intermediate', $data['country'] ?: null, $data['deadline'] ?: null,
            $data['external_platform'] ?? 'Other', $url, $userId,
        ]);
        $bountyId = (int)$db->lastInsertId();
        $db->commit();
    } catch (\Throwable $e) {
        $db->rollBack();
        error_log("[Bounty] Failed to save external bounty for user #{$userId}: " . $e->getMessage());
        throw $e;
    }

    try {
        notifyAdmins('bounty_submitted_admin', ['username' => currentUsername($userId), 'title' => $data['title'], 'type' => 'External']);
    } catch (\Throwable $e) {
        error_log("[Bounty] Post-save notification failed for external bounty #{$bountyId}: " . $e->getMessage());
    }

    return ['success' => true, 'id' => $bountyId];
}

/**
 * Admin: updates any bounty (official / hosted / external) — feature 3.
 * $data keys mirror the Create form fields; only keys present are validated,
 * everything else (host_id, submitted_by, timestamps, view_count, etc.) is left alone.
 * A new project_logo path is only applied when one was actually uploaded.
 */
function updateBounty(int $bountyId, array $data): bool
{
    $existing = getBounty($bountyId);
    if (!$existing) return false;
    $db = getDB();

    $type = in_array($data['type'] ?? '', ['official', 'hosted', 'external'], true) ? $data['type'] : $existing['type'];

    $breakdown = $data['reward_breakdown'] ?? json_decode($existing['reward_breakdown'] ?? '[]', true) ?? [];

    $fields = [
        'type'                      => $type,
        'category_id'               => $data['category_id'] !== '' && $data['category_id'] !== null ? (int)$data['category_id'] : null,
        'title'                     => trim($data['title'] ?? $existing['title']),
        'project_name'              => trim($data['project_name'] ?? '') ?: null,
        'project_logo'              => $data['project_logo'] ?? $existing['project_logo'],
        'cover_image'               => $data['cover_image'] ?? ($existing['cover_image'] ?? null),
        'description'               => $data['description'] ?? $existing['description'],
        'requirements'              => $data['requirements'] ?? $existing['requirements'],
        'skills'                    => $data['skills'] ?? $existing['skills'],
        'reward_display'            => $data['reward_display'] ?? $existing['reward_display'],
        'reward_breakdown'          => json_encode($breakdown),
        'num_winners'               => max(1, (int)($data['num_winners'] ?? $existing['num_winners'])),
        'difficulty'                => in_array($data['difficulty'] ?? '', ['Beginner', 'Intermediate', 'Advanced'], true) ? $data['difficulty'] : $existing['difficulty'],
        'country'                   => trim($data['country'] ?? '') ?: null,
        'deadline'                  => $data['deadline'] ?: null,
        'winner_announcement_date'  => $data['winner_announcement_date'] ?: null,
        'external_platform'        => $type === 'external' ? trim($data['external_platform'] ?? $existing['external_platform'] ?? '') : $existing['external_platform'],
        'external_url'              => $type === 'external' ? (trim($data['external_url'] ?? '') ?: null) : $existing['external_url'],
        'status'                    => in_array($data['status'] ?? '', ['draft', 'pending_review', 'awaiting_payment', 'published', 'judging', 'winners_announced', 'archived', 'rejected'], true) ? $data['status'] : $existing['status'],
        'is_featured'               => !empty($data['is_featured']) ? 1 : 0,
        // Premium-Only never applies to external bounties (submissions happen off-platform).
        'is_premium_only'           => ($type !== 'external' && !empty($data['is_premium_only'])) ? 1 : 0,
    ];

    $wasPublished = $existing['status'] !== 'published' && $fields['status'] === 'published';

    $set = implode(', ', array_map(fn($c) => "`$c`=?", array_keys($fields)));
    $params = array_values($fields);
    $params[] = $bountyId;
    $db->prepare("UPDATE bounties SET $set WHERE id=?")->execute($params);

    if ($wasPublished && empty($existing['published_at'])) {
        $db->prepare("UPDATE bounties SET published_at=NOW() WHERE id=?")->execute([$bountyId]);
    }

    return true;
}

function currentUsername(int $userId): string
{
    $stmt = getDB()->prepare("SELECT username FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    return (string)($stmt->fetchColumn() ?: 'a user');
}

// ============================================================
// ADMIN: approve / reject / publish
// ============================================================

/** Approves a pending bounty. External bounties publish immediately; hosted bounties move to awaiting_payment (or publish immediately if the listing fee is 0). */
function approveBounty(int $bountyId, int $adminId): bool
{
    $bounty = getBounty($bountyId);
    if (!$bounty || $bounty['status'] !== 'pending_review') return false;
    $db = getDB();

    if ($bounty['type'] === 'external') {
        $db->prepare("UPDATE bounties SET status='published', approved_by=?, approved_at=NOW(), published_at=NOW() WHERE id=?")
           ->execute([$adminId, $bountyId]);
        if ($bounty['submitted_by']) {
            awardXp((int)$bounty['submitted_by'], 'bounty_external_shared_approved', 'bounty', $bountyId);
            notifyUser((int)$bounty['submitted_by'], 'bounty_approved', 'Bounty Approved', "\"{$bounty['title']}\" was approved and is now live.", SITE_URL . '/bounty-detail.php?slug=' . $bounty['slug']);
            emailUser((int)$bounty['submitted_by'], 'bounty_approved_host', ['title' => $bounty['title'], 'next_step' => 'It is now live on Web3Edu — thanks for sharing it!']);
        }
        return true;
    }

    // hosted — fee-exempt (host policy or a globally-disabled listing fee) skips payment entirely
    $host = getBountyHostById((int)$bounty['host_id']);
    $isFeeExempt = (int)$bounty['listing_fee_kobo'] <= 0
        || !bountyListingFeeEnabled()
        || ($host && ($host['fee_policy'] ?? 'standard') === 'exempt');

    if ($isFeeExempt) {
        publishBounty($bountyId);
        $db->prepare("UPDATE bounties SET approved_by=?, approved_at=NOW() WHERE id=?")->execute([$adminId, $bountyId]);
        return true;
    }

    $db->prepare("UPDATE bounties SET status='awaiting_payment', approved_by=?, approved_at=NOW() WHERE id=?")->execute([$adminId, $bountyId]);
    if ($host) {
        notifyUser((int)$host['user_id'], 'bounty_approved', 'Bounty Approved — Payment Needed', "\"{$bounty['title']}\" was approved. Pay the listing fee to publish it.", SITE_URL . '/bounty-payment.php?id=' . $bountyId);
        emailUser((int)$host['user_id'], 'bounty_payment_requested', [
            'title' => $bounty['title'], 'payment_url' => SITE_URL . '/bounty-payment.php?id=' . $bountyId,
        ]);
    }
    return true;
}

function rejectBounty(int $bountyId, string $reason, int $adminId): bool
{
    $bounty = getBounty($bountyId);
    if (!$bounty) return false;
    $db = getDB();
    $db->prepare("UPDATE bounties SET status='rejected', rejection_reason=?, approved_by=?, approved_at=NOW() WHERE id=?")
       ->execute([$reason, $adminId, $bountyId]);

    if ($bounty['submitted_by']) {
        notifyUser((int)$bounty['submitted_by'], 'bounty_rejected', 'Bounty Needs Changes', "\"{$bounty['title']}\": {$reason}", SITE_URL . '/bounty-submit.php');
        emailUser((int)$bounty['submitted_by'], 'bounty_rejected_host', ['title' => $bounty['title'], 'reason' => $reason]);
    }
    return true;
}

/**
 * Admin: archives a bounty (used as the "cancel" action for a live bounty —
 * there is no separate 'cancelled' bounty status; archived is the existing
 * terminal state). If it had already been published, every participant who
 * submitted (and hasn't already been told the bounty is done, i.e. isn't
 * already 'winner'/'rejected' from a completed judging pass) is notified.
 */
function archiveBountyAndNotifyParticipants(int $bountyId): bool
{
    $bounty = getBounty($bountyId);
    if (!$bounty) return false;
    $wasLive = in_array($bounty['status'], ['published', 'judging'], true);

    getDB()->prepare("UPDATE bounties SET status='archived' WHERE id=?")->execute([$bountyId]);

    if ($wasLive) {
        foreach (getBountySubmissions($bountyId) as $sub) {
            if (in_array($sub['status'], ['winner', 'rejected'], true)) continue; // already resolved — don't re-notify
            notifyUser((int)$sub['user_id'], 'bounty_cancelled', 'Bounty Cancelled', "\"{$bounty['title']}\" has been cancelled by the organizer.", SITE_URL . '/bounty.php');
            emailUser((int)$sub['user_id'], 'general_notification', ['subject' => 'Bounty Cancelled', 'message' => "\"{$bounty['title']}\" has been cancelled. We're sorry for the inconvenience."]);
        }
    }

    return true;
}

function publishBounty(int $bountyId): void
{
    $db = getDB();
    $bounty = getBounty($bountyId);
    if (!$bounty) return;
    $db->prepare("UPDATE bounties SET status='published', published_at=NOW() WHERE id=?")->execute([$bountyId]);

    $ownerId = $bounty['type'] === 'hosted' && $bounty['host_id']
        ? (getBountyHostById((int)$bounty['host_id'])['user_id'] ?? null)
        : $bounty['submitted_by'];
    if ($ownerId) {
        notifyUser((int)$ownerId, 'bounty_published', 'Bounty Published 🚀', "\"{$bounty['title']}\" is now live.", SITE_URL . '/bounty-detail.php?slug=' . $bounty['slug']);
        emailUser((int)$ownerId, 'bounty_published', ['title' => $bounty['title'], 'bounty_url' => SITE_URL . '/bounty-detail.php?slug=' . $bounty['slug']]);
    }
}

// ============================================================
// PAYMENTS — listing fee (Paystack or crypto)
// ============================================================

/** Whether the listing-fee system is turned on globally (Admin → Bounty Marketplace Settings). */
function bountyListingFeeEnabled(): bool
{
    return getSetting('bounty_listing_fee_enabled', '1') === '1';
}

/** Whether a given payment method is turned on globally. */
function bountyPaymentMethodEnabled(string $method): bool
{
    return $method === 'crypto'
        ? getSetting('bounty_crypto_enabled', '1') === '1'
        : getSetting('bounty_paystack_enabled', '1') === '1';
}

/** Currency code to show/store alongside the listing fee (e.g. "NGN"). */
function bountyListingFeeCurrency(): string
{
    return getSetting('bounty_listing_fee_currency', 'NGN');
}

/**
 * The listing fee (in kobo) a specific host should be charged, honoring
 * both their Fee Policy (set by an admin on the Host application — see
 * `bounty_hosts.fee_policy`) and the global on/off switch. Fee-exempt hosts
 * and a globally-disabled listing fee both resolve to 0, which is what
 * `approveBounty()` treats as "publish immediately, no payment step".
 */
function bountyListingFeeForHost(int $hostId): int
{
    if (!bountyListingFeeEnabled()) return 0;
    $host = getBountyHostById($hostId);
    if ($host && ($host['fee_policy'] ?? 'standard') === 'exempt') return 0;
    return (int)getSetting('bounty_listing_fee_kobo', '1000000');
}

/** Reusable status badge (label + CSS class) for a bounty_payments.status value — one source of truth for every page that displays payment status. */
function bountyPaymentStatusBadge(string $status): array
{
    return match ($status) {
        'pending'       => ['label' => 'Pending',              'class' => 'status-pending'],
        'paid'          => ['label' => 'Paid',                 'class' => 'status-paid'],
        'under_review'  => ['label' => 'Awaiting Verification','class' => 'status-awaiting_verification'],
        'approved'      => ['label' => 'Approved',             'class' => 'status-approved'],
        'rejected'      => ['label' => 'Rejected',              'class' => 'status-rejected'],
        'failed'        => ['label' => 'Failed',                'class' => 'status-failed'],
        default         => ['label' => ucwords(str_replace('_', ' ', $status)), 'class' => 'status-pending'],
    };
}

function createPaystackBountyPayment(int $bountyId, int $hostUserId, int $amountKobo, string $reference): int
{
    $db = getDB();
    $bounty = getBounty($bountyId);
    $db->prepare("INSERT INTO bounty_payments (bounty_id, host_id, method, amount_kobo, currency, reference, status) VALUES (?,?,'paystack',?,?,?,'pending')")
       ->execute([$bountyId, $bounty['host_id'], $amountKobo, bountyListingFeeCurrency(), $reference]);
    return (int)$db->lastInsertId();
}

/** Verifies + approves a Paystack listing-fee payment. Wrapped in a transaction with a row lock so two near-simultaneous
 *  callback hits (page refresh, Paystack retrying the redirect) can't both publish/notify twice. */
function approvePaystackBountyPayment(string $reference): bool
{
    $db = getDB();
    $db->beginTransaction();
    try {
        $stmt = $db->prepare("SELECT * FROM bounty_payments WHERE reference = ? AND method='paystack' FOR UPDATE");
        $stmt->execute([$reference]);
        $payment = $stmt->fetch();

        if (!$payment) { $db->rollBack(); return false; }
        if ($payment['status'] === 'approved') { $db->commit(); return true; } // already processed — idempotent no-op

        $db->prepare("UPDATE bounty_payments SET status='approved', paid_at=NOW(), reviewed_at=NOW() WHERE id=?")->execute([$payment['id']]);
        $db->commit();
    } catch (\Throwable $e) {
        $db->rollBack();
        error_log('[Bounty] Paystack payment approval failed for reference ' . $reference . ': ' . $e->getMessage());
        return false;
    }

    publishBounty((int)$payment['bounty_id']);
    $bounty = getBounty((int)$payment['bounty_id']);
    $host = getBountyHostById((int)$payment['host_id']);
    if ($host) {
        $amount = bountyListingFeeCurrency() . ' ' . number_format(($payment['amount_kobo'] ?? 0) / 100, 2);
        emailUser((int)$host['user_id'], 'bounty_payment_received_host', ['title' => $bounty['title'] ?? '', 'amount' => $amount, 'method' => 'Paystack']);
    }
    notifyAdmins('bounty_payment_admin', ['title' => $bounty['title'] ?? '', 'method' => 'Paystack']);
    return true;
}

/** Returns the currently configured receiving wallet for a network name (as used on the payment page's network picker). */
function bountyWalletForNetwork(string $network): string
{
    return match (strtolower($network)) {
        'ton'      => getSetting('bounty_ton_wallet', ''),
        'solana'   => getSetting('bounty_solana_wallet', ''),
        'ethereum' => getSetting('bounty_ethereum_wallet', ''),
        default    => '',
    };
}

/** Returns the currently configured suggested payment amount for a network name. */
function bountySuggestedAmountForNetwork(string $network): string
{
    return match (strtolower($network)) {
        'ton'      => getSetting('bounty_ton_suggested_amount', ''),
        'solana'   => getSetting('bounty_solana_suggested_amount', ''),
        'ethereum' => getSetting('bounty_ethereum_suggested_amount', ''),
        default    => '',
    };
}

/** Host submits proof of a crypto listing-fee payment — goes to admin review. Blocks a second submission while one is already pending/under review, to avoid duplicate rows from double-clicks or page refreshes. */
function submitCryptoBountyPayment(int $bountyId, int $hostUserId, array $data, ?string $screenshotPath): int
{
    $db = getDB();
    $bounty = getBounty($bountyId);

    $existing = $db->prepare("SELECT id FROM bounty_payments WHERE bounty_id = ? AND status IN ('pending','under_review') ORDER BY created_at DESC LIMIT 1");
    $existing->execute([$bountyId]);
    if ($row = $existing->fetch()) return (int)$row['id']; // already have one awaiting review — don't create a duplicate

    $network = $data['network'] ?? '';
    $db->prepare("
        INSERT INTO bounty_payments (bounty_id, host_id, method, currency, network, wallet_used, tx_hash, amount_crypto, screenshot_path, notes, status)
        VALUES (?,?,'crypto',?,?,?,?,?,?,?,'under_review')
    ")->execute([
        $bountyId, $bounty['host_id'], bountyListingFeeCurrency(), $network, bountyWalletForNetwork($network), $data['tx_hash'] ?? '',
        $data['amount'] ?? '', $screenshotPath, $data['notes'] ?? '',
    ]);
    $paymentId = (int)$db->lastInsertId();
    emailUser($hostUserId, 'bounty_payment_awaiting_verification_host', ['title' => $bounty['title']]);
    notifyAdmins('bounty_payment_admin', ['title' => $bounty['title'], 'method' => 'Crypto (' . $network . ')']);
    return $paymentId;
}

function approveBountyPayment(int $paymentId, int $adminId): bool
{
    $db = getDB();
    $db->beginTransaction();
    try {
        $stmt = $db->prepare("SELECT * FROM bounty_payments WHERE id = ? FOR UPDATE");
        $stmt->execute([$paymentId]);
        $payment = $stmt->fetch();
        if (!$payment) { $db->rollBack(); return false; }
        if ($payment['status'] === 'approved') { $db->commit(); return true; } // idempotent — admin double-click / double-tap

        $db->prepare("UPDATE bounty_payments SET status='approved', paid_at=NOW(), reviewed_by=?, reviewed_at=NOW() WHERE id=?")->execute([$adminId, $paymentId]);
        $db->commit();
    } catch (\Throwable $e) {
        $db->rollBack();
        error_log("[Bounty] Payment #{$paymentId} approval failed: " . $e->getMessage());
        return false;
    }

    publishBounty((int)$payment['bounty_id']);
    $bounty = getBounty((int)$payment['bounty_id']);
    $host = getBountyHostById((int)$payment['host_id']);
    if ($host) {
        notifyUser((int)$host['user_id'], 'bounty_payment_approved', 'Payment Approved', "Your listing payment has been approved. \"{$bounty['title']}\" is now live.", SITE_URL . '/bounty-detail.php?slug=' . ($bounty['slug'] ?? ''));
        emailUser((int)$host['user_id'], 'bounty_payment_approved', ['title' => $bounty['title'] ?? '', 'bounty_url' => SITE_URL . '/bounty-detail.php?slug=' . ($bounty['slug'] ?? '')]);
    }
    return true;
}

function rejectBountyPayment(int $paymentId, string $reason, int $adminId): bool
{
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM bounty_payments WHERE id = ?");
    $stmt->execute([$paymentId]);
    $payment = $stmt->fetch();
    if (!$payment || in_array($payment['status'], ['approved', 'rejected'], true)) return false; // idempotent guard

    $db->prepare("UPDATE bounty_payments SET status='rejected', rejection_reason=?, reviewed_by=?, reviewed_at=NOW() WHERE id=?")
       ->execute([$reason, $adminId, $paymentId]);

    $bounty = getBounty((int)$payment['bounty_id']);
    $host = getBountyHostById((int)$payment['host_id']);
    if ($host) {
        // A rejected listing payment returns the bounty to draft so the host can fix and resubmit — it should not stay stuck in awaiting_payment forever.
        $db->prepare("UPDATE bounties SET status='draft' WHERE id=? AND status='awaiting_payment'")->execute([$payment['bounty_id']]);
        notifyUser((int)$host['user_id'], 'bounty_payment_rejected', 'Payment Needs Attention', $reason, SITE_URL . '/bounty-payment.php?id=' . $payment['bounty_id']);
        emailUser((int)$host['user_id'], 'bounty_payment_rejected', ['title' => $bounty['title'] ?? '', 'reason' => $reason]);
    }
    return true;
}

// ============================================================
// SUBMISSIONS
// ============================================================

function getBountySubmission(int $bountyId, int $userId): ?array
{
    $stmt = getDB()->prepare("SELECT * FROM bounty_submissions WHERE bounty_id=? AND user_id=?");
    $stmt->execute([$bountyId, $userId]);
    return $stmt->fetch() ?: null;
}

function getBountySubmissionById(int $submissionId): ?array
{
    $stmt = getDB()->prepare("SELECT * FROM bounty_submissions WHERE id = ?");
    $stmt->execute([$submissionId]);
    return $stmt->fetch() ?: null;
}

/** Every submission a user has made, newest first, with the bounty's logo/title/slug for the My Submissions page. */
function getUserBountySubmissions(int $userId): array
{
    $stmt = getDB()->prepare("
        SELECT s.*, b.title AS bounty_title, b.slug AS bounty_slug, b.project_logo, b.type AS bounty_type,
               bh.logo_path AS host_logo
        FROM bounty_submissions s
        INNER JOIN bounties b ON b.id = s.bounty_id
        LEFT JOIN bounty_hosts bh ON bh.id = b.host_id
        WHERE s.user_id = ?
        ORDER BY s.created_at DESC
    ");
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

/** Human-facing status label for a submission — "Pending Review"/"Under Review"/"Winner"/"Not Selected"/"Cancelled" per spec. */
function bountySubmissionStatusLabel(array $submission): string
{
    switch ($submission['status']) {
        case 'submitted':     return 'Pending Review';
        case 'under_review':  return 'Under Review';
        case 'winner':        return 'Winner';
        case 'rejected':      return 'Not Selected';
        case 'cancelled':     return 'Cancelled';
        default:              return ucfirst(str_replace('_', ' ', $submission['status']));
    }
}

/** Host/admin sets feedback text on a submission — surfaced on the participant's My Submissions page. */
function setBountySubmissionFeedback(int $submissionId, int $bountyId, string $feedback): bool
{
    // SECURITY: $bountyId must be the bounty the caller has already been
    // authorized (via canManageBounty()) to manage — enforcing it here,
    // in the same UPDATE, closes a cross-bounty IDOR: without this
    // condition, a host authorized for their own bounty could submit a
    // submission_id belonging to a different bounty and overwrite that
    // unrelated submission's feedback. rowCount() tells the caller
    // whether a row was actually matched (true same-bounty case) versus
    // silently matching nothing (wrong bounty or nonexistent ID) — same
    // bool return type as before, now actually meaningful.
    $stmt = getDB()->prepare("UPDATE bounty_submissions SET host_feedback = ? WHERE id = ? AND bounty_id = ?");
    $stmt->execute([trim($feedback) ?: null, $submissionId, $bountyId]);
    return $stmt->rowCount() > 0;
}

/**
 * Admin Submission Management — every submission across every bounty, with
 * the participant's Bounty Profile (display name, country, wallets) joined
 * in. $filters: bounty_id, host_id, user_id (participant), status, search
 * (matches username/display name/email/bounty title).
 */
function listBountySubmissionsAdmin(array $filters = []): array
{
    $db = getDB();
    $where = ['1=1'];
    $params = [];

    if (!empty($filters['bounty_id'])) { $where[] = 's.bounty_id = ?'; $params[] = (int)$filters['bounty_id']; }
    if (!empty($filters['host_id']))   { $where[] = 'b.host_id = ?'; $params[] = (int)$filters['host_id']; }
    if (!empty($filters['user_id']))   { $where[] = 's.user_id = ?'; $params[] = (int)$filters['user_id']; }
    if (!empty($filters['status']))    { $where[] = 's.status = ?'; $params[] = $filters['status']; }
    if (!empty($filters['search'])) {
        $where[] = '(u.username LIKE ? OR u.email LIKE ? OR bp.display_name LIKE ? OR b.title LIKE ?)';
        $like = '%' . $filters['search'] . '%';
        array_push($params, $like, $like, $like, $like);
    }

    $limit = isset($filters['limit']) ? (int)$filters['limit'] : 300;

    $sql = "
        SELECT s.*, u.username, u.email, b.title AS bounty_title, b.slug AS bounty_slug, b.host_id,
               bh.organization_name AS host_name,
               bp.display_name, bp.country, bp.solana_wallet, bp.ethereum_wallet, bp.ton_wallet,
               bp.bank_name, bp.account_number
        FROM bounty_submissions s
        INNER JOIN bounties b ON b.id = s.bounty_id
        INNER JOIN users u ON u.id = s.user_id
        LEFT JOIN bounty_hosts bh ON bh.id = b.host_id
        LEFT JOIN bounty_profiles bp ON bp.user_id = s.user_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY s.created_at DESC
        LIMIT $limit
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Admin Bounty Profiles — search/list users who've completed a Bounty
 * Profile, with their wallets/bank/social info for admin support & payout
 * verification.
 */
function listBountyProfilesAdmin(string $search = '', int $limit = 200): array
{
    $db = getDB();
    $where = ['1=1'];
    $params = [];
    if ($search !== '') {
        $where[] = '(u.username LIKE ? OR u.email LIKE ? OR bp.display_name LIKE ?)';
        $like = '%' . $search . '%';
        array_push($params, $like, $like, $like);
    }
    $stmt = $db->prepare("
        SELECT bp.*, u.username, u.email
        FROM bounty_profiles bp
        INNER JOIN users u ON u.id = bp.user_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY bp.updated_at DESC
        LIMIT $limit
    ");
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function getBountySubmissions(int $bountyId): array
{
    $stmt = getDB()->prepare("
        SELECT s.*, u.username, u.avatar
        FROM bounty_submissions s INNER JOIN users u ON u.id = s.user_id
        WHERE s.bounty_id = ? ORDER BY s.created_at DESC
    ");
    $stmt->execute([$bountyId]);
    return $stmt->fetchAll();
}

function submitBountyEntry(int $bountyId, int $userId, string $url, string $description): bool
{
    $db = getDB();
    if (getBountySubmission($bountyId, $userId)) return false; // one submission per user per bounty

    // The check above narrows the common case, but two near-simultaneous
    // requests (double-click, two open tabs) can both pass it before either
    // INSERT lands — the uniq_bounty_user_submission constraint is what
    // actually prevents the duplicate row, so a violation here just means
    // "someone else's identical request won the race." Treat it the same
    // as the check above catching it (return false — already submitted)
    // instead of letting the exception surface as an uncaught 500.
    try {
        $db->prepare("INSERT INTO bounty_submissions (bounty_id, user_id, submission_url, description) VALUES (?,?,?,?)")
           ->execute([$bountyId, $userId, $url, $description]);
    } catch (PDOException $e) {
        if ((int)$e->getCode() === 23000) return false;
        throw $e;
    }
    awardXp($userId, 'bounty_submitted', 'bounty', $bountyId);

    $bounty = getBounty($bountyId);

    Analytics::track('bounty_submission_made', $userId, ['bounty_id' => $bountyId, 'bounty_title' => $bounty['title']]);
    notifyAdmins('bounty_submission_admin', [
        'username'     => currentUsername($userId),
        'bounty_title' => $bounty['title'],
        'link'         => SITE_URL . '/admin/bounty-submissions.php?bounty_id=' . $bountyId,
    ]);

    // Confirms receipt to the participant — wires up the 'new_submission' event
    // that was reserved (but unwired) in the Notification Preferences catalog.
    notifyUser($userId, 'submission_received', 'Submission Received', "Your entry for \"{$bounty['title']}\" was received and is awaiting review.", SITE_URL . '/bounty-submissions.php');
    emailUser($userId, 'bounty_submission_received', ['title' => $bounty['title']]);

    return true;
}

/**
 * Admin/host submission status change (single row) — used by
 * admin/bounty-submissions.php's status dropdown. Centralizes the
 * notify-on-change logic that page used to skip entirely (it just ran a
 * raw UPDATE). Winner/rejected selection made *here* (one row, no
 * placement/prize/certificate) stays deliberately separate from
 * announceBountyWinners() (bulk, with certificates) — same status values,
 * different call site, so this only ever fires the plain notification,
 * never touches XP or certificates.
 */
function updateBountySubmissionStatus(int $submissionId, string $status): bool
{
    if (!in_array($status, ['submitted', 'under_review', 'winner', 'rejected', 'cancelled'], true)) return false;

    $sub = getBountySubmissionById($submissionId);
    if (!$sub || $sub['status'] === $status) return false; // no-op: nothing changed, nothing to notify

    getDB()->prepare("UPDATE bounty_submissions SET status = ? WHERE id = ?")->execute([$status, $submissionId]);

    $bounty = getBounty((int)$sub['bounty_id']);
    $title = $bounty['title'] ?? 'your bounty submission';
    $link = SITE_URL . '/bounty-submissions.php';

    switch ($status) {
        case 'under_review':
            notifyUser((int)$sub['user_id'], 'submission_under_review', 'Submission Under Review', "Your entry for \"{$title}\" is now being reviewed.", $link);
            break;
        case 'cancelled':
            notifyUser((int)$sub['user_id'], 'submission_cancelled', 'Submission Cancelled', "Your entry for \"{$title}\" was cancelled.", $link);
            emailUser((int)$sub['user_id'], 'general_notification', ['subject' => 'Submission Cancelled', 'message' => "Your submission for \"{$title}\" was cancelled. Reach out to support if this looks wrong."]);
            break;
        case 'winner':
            notifyUser((int)$sub['user_id'], 'bounty_winner', "🏆 You won \"{$title}\"!", "Congratulations! You were selected as a winner for {$title}.", SITE_URL . '/bounty-detail.php?slug=' . ($bounty['slug'] ?? ''));
            emailUser((int)$sub['user_id'], 'bounty_winner', ['title' => $title, 'placement' => '', 'prize' => $sub['prize_awarded'] ?? '']);
            break;
        case 'rejected':
            notifyUser((int)$sub['user_id'], 'bounty_thanks', 'Thanks for participating!', "You weren't selected as a winner in \"{$title}\", but we'd love to see you try the next one.", SITE_URL . '/bounty.php');
            emailUser((int)$sub['user_id'], 'bounty_participant_thanks', ['title' => $title]);
            break;
        case 'submitted':
            notifyUser((int)$sub['user_id'], 'submission_received', 'Submission Received', "Your entry for \"{$title}\" is back in the review queue.", $link);
            break;
    }

    return true;
}

// ============================================================
// WINNERS + CERTIFICATES
// ============================================================

/**
 * $winners: [ ['submission_id' => int, 'placement' => 1, 'prize' => '1,800 USDC'], ... ]
 * Marks winners, issues a Bounty Winner certificate for each (via the
 * existing certificate engine), awards XP, emails/notifies winners, and
 * sends a polite thank-you notification to every non-winning participant.
 */
function announceBountyWinners(int $bountyId, array $winners): void
{
    $db = getDB();
    $bounty = getBounty($bountyId);
    if (!$bounty) return;

    $winnerSubIds = array_column($winners, 'submission_id');

    foreach ($winners as $w) {
        // SECURITY: a submitted winner submission ID must actually belong
        // to THIS bounty — the caller is only authorized to manage
        // $bountyId, so enforcing that relationship here (not just in the
        // controller) closes a cross-bounty IDOR: without the bounty_id
        // condition, a host authorized for their own bounty could submit
        // another bounty's submission ID and have it mutated, certified,
        // and notified as a winner of this bounty. A submission ID that
        // doesn't belong to $bountyId is treated exactly like a
        // nonexistent one — silently skipped, same as the pre-existing
        // "if (!$sub) continue" behavior for any invalid ID.
        $subStmt = $db->prepare("SELECT * FROM bounty_submissions WHERE id = ? AND bounty_id = ?");
        $subStmt->execute([$w['submission_id'], $bountyId]);
        $sub = $subStmt->fetch();
        if (!$sub) continue;

        $placementLabel = ['1' => '1st', '2' => '2nd', '3' => '3rd'][(string)$w['placement']] ?? ($w['placement'] . 'th');

        $db->prepare("UPDATE bounty_submissions SET status='winner', placement=?, prize_awarded=? WHERE id=?")
           ->execute([$w['placement'], $w['prize'] ?? '', $sub['id']]);

        awardXp((int)$sub['user_id'], 'bounty_won', 'bounty_submission', $sub['id']);

        $typeRow = getCertificateTypeByKey('bounty_winner');
        $certCode = null;
        if ($typeRow) {
            $defaultTpl = getDefaultTemplateForType((int)$typeRow['id']);
            if ($defaultTpl) {
                $certCode = issueManualCertificate(
                    (int)$sub['user_id'],
                    (int)$typeRow['id'],
                    (int)$defaultTpl['id'],
                    $bounty['title'] . ' — ' . $placementLabel . ' Place',
                    [
                        'guide_name' => $bounty['title'],
                        'placement'  => $placementLabel,
                        'prize'      => $w['prize'] ?? '',
                        'host_name'  => $bounty['host_name'] ?? 'Web3Edu',
                    ]
                );
            }
            if ($certCode) {
                $db->prepare("UPDATE bounty_submissions SET certificate_code=? WHERE id=?")->execute([$certCode, $sub['id']]);
            }
        }

        notifyUser((int)$sub['user_id'], 'bounty_winner', "🏆 You won \"{$bounty['title']}\"!", "You placed {$placementLabel} and won {$w['prize']}.", SITE_URL . '/bounty-detail.php?slug=' . $bounty['slug']);
        emailUser((int)$sub['user_id'], 'bounty_winner', ['title' => $bounty['title'], 'placement' => $placementLabel, 'prize' => $w['prize'] ?? '']);

        Analytics::track('bounty_won', (int)$sub['user_id'], [
            'bounty_id' => $bountyId, 'bounty_title' => $bounty['title'], 'placement' => $placementLabel, 'prize' => $w['prize'] ?? '',
        ]);
        notifyAdmins('bounty_won_admin', [
            'username'     => currentUsername((int)$sub['user_id']),
            'bounty_title' => $bounty['title'],
            'placement'    => $placementLabel,
            'link'         => SITE_URL . '/bounty-detail.php?slug=' . $bounty['slug'],
        ]);
    }

    // Thank-you to everyone else who submitted
    $allSubs = $db->prepare("SELECT * FROM bounty_submissions WHERE bounty_id = ?");
    $allSubs->execute([$bountyId]);
    foreach ($allSubs->fetchAll() as $sub) {
        if (in_array($sub['id'], $winnerSubIds)) continue;
        if ($sub['status'] === 'rejected') continue;
        $db->prepare("UPDATE bounty_submissions SET status='rejected' WHERE id=? AND status != 'winner'")->execute([$sub['id']]); // "rejected" here just means "not selected" — reused status
        notifyUser((int)$sub['user_id'], 'bounty_thanks', 'Thanks for participating!', "You weren't selected as a winner in \"{$bounty['title']}\", but we'd love to see you try the next one.", SITE_URL . '/bounty.php');
        emailUser((int)$sub['user_id'], 'bounty_participant_thanks', ['title' => $bounty['title']]);
    }

    $db->prepare("UPDATE bounties SET status='winners_announced' WHERE id=?")->execute([$bountyId]);
}

// ============================================================
// NOTIFICATIONS / EMAIL SHORTHANDS
// ============================================================
//
// notifyUser(), emailUser(), and notifyAdmins() — used throughout this
// file as a post-save side effect from every bounty-creation and
// status-change path — now live in includes/notifications/
// NotificationService.php, loaded globally by includes/config.php, so
// every module (subscriptions, gamification, certificates, projects,
// auth) shares the exact same notification/email code instead of each
// re-implementing it. No call site in this file changed.

// ============================================================
// ADMIN: STATS + CSV EXPORT
// ============================================================

function getBountyStats(): array
{
    $db = getDB();
    return [
        'total_bounties'    => (int)$db->query("SELECT COUNT(*) FROM bounties WHERE deleted_at IS NULL")->fetchColumn(),
        'published'         => (int)$db->query("SELECT COUNT(*) FROM bounties WHERE status='published' AND deleted_at IS NULL")->fetchColumn(),
        'pending_review'    => (int)$db->query("SELECT COUNT(*) FROM bounties WHERE status='pending_review' AND deleted_at IS NULL")->fetchColumn(),
        'awaiting_payment'  => (int)$db->query("SELECT COUNT(*) FROM bounties WHERE status='awaiting_payment' AND deleted_at IS NULL")->fetchColumn(),
        'winners_announced' => (int)$db->query("SELECT COUNT(*) FROM bounties WHERE status='winners_announced' AND deleted_at IS NULL")->fetchColumn(),
        'total_hosts'       => (int)$db->query("SELECT COUNT(*) FROM bounty_hosts")->fetchColumn(),
        'total_submissions' => (int)$db->query("SELECT COUNT(*) FROM bounty_submissions")->fetchColumn(),
        'pending_payments'  => (int)$db->query("SELECT COUNT(*) FROM bounty_payments WHERE status='under_review'")->fetchColumn(),
    ];
}

/**
 * CSV export of every participant (submission) for a bounty, for Admin ->
 * Bounties -> Export and the Host Dashboard's "Export Participants CSV".
 * Includes each participant's Bounty Profile (display name, email, country,
 * wallets, Nigerian bank details when set) alongside their submission.
 */
function exportBountyParticipantsCsv(int $bountyId): string
{
    $db = getDB();
    $stmt = $db->prepare("
        SELECT s.*, u.username, u.email,
               bp.display_name, bp.country, bp.solana_wallet, bp.ethereum_wallet, bp.ton_wallet,
               bp.bank_name, bp.account_number
        FROM bounty_submissions s
        INNER JOIN users u ON u.id = s.user_id
        LEFT JOIN bounty_profiles bp ON bp.user_id = s.user_id
        WHERE s.bounty_id = ?
        ORDER BY s.created_at DESC
    ");
    $stmt->execute([$bountyId]);
    $subs = $stmt->fetchAll();

    $out = fopen('php://temp', 'w+');
    fputcsv($out, [
        'Username', 'Display Name', 'Email', 'Country',
        'Solana Wallet', 'Ethereum Wallet', 'TON Wallet', 'Nigerian Bank', 'Account Number',
        'Submission Link', 'Description', 'Status', 'Placement', 'Prize', 'Submitted At',
    ]);
    foreach ($subs as $s) {
        $isNigeria = strcasecmp((string)($s['country'] ?? ''), 'Nigeria') === 0;
        fputcsv($out, [
            $s['username'],
            $s['display_name'] ?? '',
            $s['email'],
            $s['country'] ?? '',
            $s['solana_wallet'] ?? '',
            $s['ethereum_wallet'] ?? '',
            $s['ton_wallet'] ?? '',
            $isNigeria ? ($s['bank_name'] ?? '') : '',
            $isNigeria ? ($s['account_number'] ?? '') : '',
            $s['submission_url'],
            $s['description'],
            bountySubmissionStatusLabel($s),
            $s['placement'],
            $s['prize_awarded'],
            $s['created_at'],
        ]);
    }
    rewind($out);
    $csv = stream_get_contents($out);
    fclose($out);
    return $csv;
}

/**
 * Admin-authored External bounty — Admins can also share/aggregate an
 * external bounty directly (Feature 6), publishing immediately since it's
 * admin-approved by construction. Mirrors createExternalBounty() exactly
 * (same duplicate-URL guard, same columns) but with no submitter and no
 * pending-review step.
 */
function createExternalBountyAsAdmin(array $data, int $adminId): array
{
    $db = getDB();
    $url = trim($data['external_url'] ?? '');

    $dupe = $db->prepare("SELECT id FROM bounties WHERE external_url = ? AND deleted_at IS NULL");
    $dupe->execute([$url]);
    if ($dupe->fetch()) return ['success' => false, 'error' => 'This bounty URL has already been submitted.'];

    $slug = uniqueSlug('bounties', generateSlug($data['title']));

    $db->beginTransaction();
    try {
        $db->prepare("
            INSERT INTO bounties
                (type, category_id, title, slug, project_name, description, skills,
                 reward_display, difficulty, country, deadline,
                 external_platform, external_url, status, approved_by, approved_at, published_at)
            VALUES ('external',?,?,?,?,?,?,?,?,?,?,?,?, 'published', ?, NOW(), NOW())
        ")->execute([
            $data['category_id'] ?: null, $data['title'], $slug, $data['project_name'] ?: null,
            $data['description'] ?? '', $data['skills'] ?? '',
            $data['reward_display'] ?? '', $data['difficulty'] ?? 'Intermediate', $data['country'] ?: null, $data['deadline'] ?: null,
            $data['external_platform'] ?? 'Other', $url, $adminId,
        ]);
        $bountyId = (int)$db->lastInsertId();
        $db->commit();
        return ['success' => true, 'id' => $bountyId];
    } catch (\Throwable $e) {
        $db->rollBack();
        error_log("[Bounty] Admin external bounty creation failed (admin #{$adminId}): " . $e->getMessage());
        throw $e;
    }
}
