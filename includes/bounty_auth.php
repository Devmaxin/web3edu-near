<?php
/**
 * Web3edu — Bounty Authorization Layer
 *
 * Every bounty-section page (user-facing and admin) should call these
 * helpers instead of re-implementing "is this the owner / is this an
 * admin / is this bounty in the right state" checks inline. Centralizing
 * them here means a future bounty feature only has to call the right
 * can*() function instead of re-deriving the rule.
 *
 * Covers: Admins, Hosts, Participants, Official/Hosted/External bounties,
 * Premium-only bounties, Archived/Closed bounties.
 *
 * Include this after includes/config.php. It pulls in includes/bounties.php
 * (the bounty engine) and admin/auth.php (isAdmin()/requireAdmin()) itself,
 * so pages only need this one require.
 */
require_once __DIR__ . '/bounties.php';
require_once __DIR__ . '/../admin/auth.php'; // isAdmin(), requireAdmin(), getCurrentAdmin()

// ============================================================
// LOW-LEVEL OWNERSHIP CHECKS (private helpers — not part of the
// public "can*" surface, but kept here so every rule above is built
// from the same primitives).
// ============================================================

/** True if $userId is the Web3Edu user behind a hosted bounty's host_id. */
function bountyAuthIsHostOwner(int $userId, array $bounty): bool
{
    if (empty($bounty['host_id'])) return false;
    $host = getBountyHostById((int)$bounty['host_id']);
    return $host !== null && (int)$host['user_id'] === $userId;
}

/** True if $userId is who submitted/shared this bounty (external bounties, or the host on a hosted one). */
function bountyAuthIsSubmitter(int $userId, array $bounty): bool
{
    return (int)($bounty['submitted_by'] ?? 0) === $userId;
}

// ============================================================
// PUBLIC PERMISSION HELPERS
// ============================================================

/**
 * Umbrella "can this user administer this bounty at all" check — used to
 * gate the host-dashboard actions (archive/close/edit) that apply to any
 * bounty a host owns. Admins always pass. Official bounties are Web3Edu's
 * own (admin-only) and External bounties have no "manager" beyond the
 * admin who approves them, so only Hosted bounties grant this to a host.
 */
function canManageBounty(int $userId, int $bountyId): bool
{
    if (isAdmin()) return true;
    $bounty = getBounty($bountyId);
    if (!$bounty) return false;
    return $bounty['type'] === 'hosted' && bountyAuthIsHostOwner($userId, $bounty);
}

/**
 * Editing the bounty's content (title/description/reward/etc). Admins can
 * edit anything, any time. A host may edit their own Hosted bounty's
 * content as long as it isn't archived — once archived it's a closed
 * record, not a draft to keep tweaking.
 */
function canEditBounty(int $userId, int $bountyId): bool
{
    if (isAdmin()) return true;
    $bounty = getBounty($bountyId);
    if (!$bounty || $bounty['type'] !== 'hosted') return false;
    if (!bountyAuthIsHostOwner($userId, $bounty)) return false;
    return $bounty['status'] !== 'archived';
}

/** Deleting a bounty record outright stays admin-only, matching the existing admin/bounties.php behavior. */
function canDeleteBounty(int $userId, int $bountyId): bool
{
    return isAdmin();
}

/**
 * Viewing a single submission's details. Admins see everything. A
 * participant sees their own submission. A host sees submissions to
 * bounties they own.
 */
function canViewSubmission(int $userId, int $submissionId): bool
{
    if (isAdmin()) return true;
    $stmt = getDB()->prepare("SELECT * FROM bounty_submissions WHERE id = ?");
    $stmt->execute([$submissionId]);
    $sub = $stmt->fetch();
    if (!$sub) return false;
    if ((int)$sub['user_id'] === $userId) return true;

    $bounty = getBounty((int)$sub['bounty_id']);
    return $bounty !== null && bountyAuthIsHostOwner($userId, $bounty);
}

/** CSV export of a bounty's participants — admin, or the host who owns it. */
function canExportSubmissions(int $userId, int $bountyId): bool
{
    if (isAdmin()) return true;
    $bounty = getBounty($bountyId);
    return $bounty !== null && bountyAuthIsHostOwner($userId, $bounty);
}

/**
 * Picking winners. Official bounties: admins only (Web3Edu runs them).
 * Hosted bounties: the owning host, or an admin overriding on their
 * behalf. External bounties never have on-platform winner selection —
 * winners are announced on the external platform instead.
 */
function canSelectWinners(int $userId, int $bountyId): bool
{
    $bounty = getBounty($bountyId);
    if (!$bounty || $bounty['type'] === 'external') return false;
    if (isAdmin()) return true;
    if ($bounty['type'] === 'official') return false;
    return bountyAuthIsHostOwner($userId, $bounty);
}

/**
 * Whether $userId may submit an entry right now. Combines bounty type
 * (on-platform submissions only apply to official/hosted), lifecycle
 * status, Premium-only gating, and the one-submission-per-user rule —
 * every rule bounty-submit forms need to check before accepting an entry.
 */
function canSubmitToBounty(int $userId, int $bountyId): bool
{
    $bounty = getBounty($bountyId);
    if (!$bounty || $bounty['type'] === 'external') return false;
    if ($bounty['status'] !== 'published') return false;
    if (!userCanSubmitToBounty($bounty)) return false; // Premium-only gate
    if (getBountySubmission($bountyId, $userId)) return false; // already submitted
    return true;
}

/** Commenting is open to any logged-in user on any bounty that still exists (moderation is a documented future addition — see BOUNTY_MARKETPLACE.md). */
function canCommentOnBounty(int $userId, int $bountyId): bool
{
    return getBounty($bountyId) !== null;
}

/** The Host Dashboard is for admins (support/oversight) and users whose Host Profile has been approved — a pending/rejected/suspended profile does not unlock it. */
function canViewHostDashboard(int $userId): bool
{
    return isAdmin() || isApprovedHost($userId);
}

/** Creating a new Hosted Bounty requires an approved Host Profile — mirrors canViewHostDashboard() since they're gated by the same thing. */
function canCreateHostedBounty(int $userId): bool
{
    return isAdmin() || isApprovedHost($userId);
}

/** Managing a Host Profile's org details — admins, or the account that owns it. */
function canManageHostProfile(int $userId, int $hostId): bool
{
    if (isAdmin()) return true;
    $host = getBountyHostById($hostId);
    return $host !== null && (int)$host['user_id'] === $userId;
}

/**
 * Viewing/editing a Bounty Profile — a user may only ever see their own
 * (display name, bio, socials, wallets, bank details); admins may view or
 * edit any profile for support/verification purposes.
 */
function canViewBountyProfile(int $viewerId, int $profileUserId): bool
{
    return isAdmin() || $viewerId === $profileUserId;
}

function canEditBountyProfile(int $viewerId, int $profileUserId): bool
{
    return canViewBountyProfile($viewerId, $profileUserId);
}
