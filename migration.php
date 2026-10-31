<?php
/**
 * Web3Edu V1 -> V2 Content Migration Tool
 * ==========================================
 * Migrates educational content (lessons, guides, videos, blog posts,
 * airdrops, homepage content) from the old Web3Edu V1 database into the
 * finalized V2 database, WITHOUT touching the V2 schema and WITHOUT
 * migrating any user/account data (see README.md, Phase 9 exclusions).
 *
 * Safe to re-run: every insert is guarded by an existence check (by
 * primary key and/or unique slug/name), so running this script twice
 * will not create duplicates.
 *
 * Configure the two database connections below, then run:
 *   php migration.php
 *
 * See README.md for full instructions.
 */

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

// ---------------------------------------------------------------------
// CONFIGURATION - edit these to match your environment
// ---------------------------------------------------------------------
$config = [
    'host'     => getenv('DB_HOST') ?: '127.0.0.1',
    'port'     => getenv('DB_PORT') ?: '3306',
    'user'     => getenv('DB_USER') ?: 'root',
    'pass'     => getenv('DB_PASS') ?: '',
    'v1_db'    => getenv('V1_DB') ?: 'web3edu_v1',
    'v2_db' => getenv('V2_DB') ?: 'web3education',
];

// ---------------------------------------------------------------------
// Logger
// ---------------------------------------------------------------------
final class MigrationLogger
{
    private $fh;
    public $migrated = [];
    public $skipped  = [];
    public $failed    = [];
    public $warnings = [];

    public function __construct(string $path)
    {
        $this->fh = fopen($path, 'w');
        $this->line('====================================================');
        $this->line('Web3Edu V1 -> V2 Migration Log');
        $this->line('Started: ' . date('Y-m-d H:i:s'));
        $this->line('====================================================');
    }

    public function line(string $msg): void
    {
        fwrite($this->fh, $msg . "\n");
    }

    public function section(string $title): void
    {
        $this->line('');
        $this->line('--- ' . $title . ' ---');
    }

    public function migrated(string $table, string $detail): void
    {
        $this->migrated[$table] = ($this->migrated[$table] ?? 0) + 1;
        $this->line("[MIGRATED] $table: $detail");
    }

    public function skipped(string $table, string $detail, string $reason): void
    {
        $this->skipped[$table] = ($this->skipped[$table] ?? 0) + 1;
        $this->line("[SKIPPED]  $table: $detail -- reason: $reason");
    }

    public function failed(string $table, string $detail, string $reason): void
    {
        $this->failed[$table] = ($this->failed[$table] ?? 0) + 1;
        $this->line("[FAILED]   $table: $detail -- reason: $reason");
    }

    public function warn(string $msg): void
    {
        $this->warnings[] = $msg;
        $this->line("[WARNING]  $msg");
    }

    public function summary(): void
    {
        $this->line('');
        $this->line('====================================================');
        $this->line('SUMMARY');
        $this->line('====================================================');
        $this->line('Migrated records:');
        foreach ($this->migrated as $t => $c) {
            $this->line("  - $t: $c");
        }
        if (empty($this->migrated)) {
            $this->line('  (none)');
        }
        $this->line('Skipped records:');
        foreach ($this->skipped as $t => $c) {
            $this->line("  - $t: $c");
        }
        if (empty($this->skipped)) {
            $this->line('  (none)');
        }
        $this->line('Failed records:');
        foreach ($this->failed as $t => $c) {
            $this->line("  - $t: $c");
        }
        if (empty($this->failed)) {
            $this->line('  (none)');
        }
        $this->line('Warnings: ' . count($this->warnings));
        foreach ($this->warnings as $w) {
            $this->line("  - $w");
        }
        $this->line('Finished: ' . date('Y-m-d H:i:s'));
        $this->line('====================================================');
    }

    public function close(): void
    {
        fclose($this->fh);
    }
}

// ---------------------------------------------------------------------
// Connect
// ---------------------------------------------------------------------
function connect(array $cfg, string $dbKey): PDO
{
    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $cfg['host'], $cfg['port'], $cfg[$dbKey]);
    $pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    return $pdo;
}

$log = new MigrationLogger(__DIR__ . '/migration.log');

try {
    $v1 = connect($config, 'v1_db');
    $v2 = connect($config, 'v2_db');
} catch (Throwable $e) {
    $log->line('[FATAL] Could not connect to database(s): ' . $e->getMessage());
    $log->summary();
    $log->close();
    fwrite(STDERR, "Connection failed: " . $e->getMessage() . "\n");
    exit(1);
}

// ---------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------
function existsById(PDO $db, string $table, int $id): bool
{
    $stmt = $db->prepare("SELECT id FROM `$table` WHERE id = :id LIMIT 1");
    $stmt->execute(['id' => $id]);
    return (bool) $stmt->fetch();
}

function existsBySlug(PDO $db, string $table, string $slug): bool
{
    $stmt = $db->prepare("SELECT id FROM `$table` WHERE slug = :slug LIMIT 1");
    $stmt->execute(['slug' => $slug]);
    return (bool) $stmt->fetch();
}

function nextAutoIncrement(PDO $db, string $table): int
{
    $stmt = $db->query("SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$table'");
    $row = $stmt->fetch();
    return (int) ($row['AUTO_INCREMENT'] ?? 1);
}

function bumpAutoIncrement(PDO $db, string $table): void
{
    $stmt = $db->query("SELECT MAX(id) AS m FROM `$table`");
    $row = $stmt->fetch();
    $max = (int) ($row['m'] ?? 0);
    $next = $max + 1;
    $current = nextAutoIncrement($db, $table);
    if ($next > $current) {
        $db->exec("ALTER TABLE `$table` AUTO_INCREMENT = $next");
    }
}

// =======================================================================
// PHASE 1: ANALYSIS (lightweight - table presence + row counts)
// =======================================================================
$log->section('Phase 1: Analysis');

$v1Tables = $v1->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
$v2Tables = $v2->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

$log->line('V1 tables found: ' . count($v1Tables));
$log->line('V2 tables found: ' . count($v2Tables));

// Tables intentionally NOT migrated, per Phase 9 and content-scope rules.
// These are either user/account data (explicitly excluded), or V1 tables
// with no corresponding V2 destination and not part of the requested
// content types (lessons, guides, videos, blog, airdrops, homepage).
$excludedTables = [
    'users'                  => 'user/account data - explicitly excluded (Phase 9)',
    'admins'                 => 'V1 admins are not migrated - V2 keeps its own existing administrator account (Phase 9)',
    'purchases'               => 'payment/purchase history - explicitly excluded (Phase 9)',
    'ai_conversations'        => 'user AI conversation history - explicitly excluded (Phase 9)',
    'ai_messages'             => 'user AI conversation history - explicitly excluded (Phase 9)',
    'ai_prompts'              => 'admin-configured prompts - not a requested content type, left for manual review',
    'ai_usage_log'            => 'usage analytics - explicitly excluded (Phase 9)',
    'badges'                  => 'gamification/badges - explicitly excluded (Phase 9)',
    'user_badges'             => 'user progress data - explicitly excluded (Phase 9)',
    'user_lesson_progress'    => 'user progress data - explicitly excluded (Phase 9)',
    'quiz_results'            => 'user progress data - explicitly excluded (Phase 9)',
    'quiz_unlock_log'         => 'user progress data - explicitly excluded (Phase 9)',
    'quizzes'                 => 'quiz definitions reference badges.id (excluded table) and were not listed as a requested content type - left for manual review',
    'quiz_questions'          => 'depends on quizzes - see quizzes note above',
    'quiz_required_lessons'   => 'depends on quizzes - see quizzes note above',
    'site_settings'           => 'admin/site configuration - V2 has its own fresh configuration, not a content type',
    'maintenance_exceptions'  => 'operational/admin data tied to V1 user IDs - not applicable to V2',
    'glossary'                => 'no corresponding table exists in the finalized V2 schema; not in the requested content-type list, and creating a new table would modify the finalized V2 architecture - flagged for manual decision, not migrated',
    'media_uploads'           => 'table is empty in the V1 dump (0 rows) - nothing to migrate; V2 media_library is used instead for any new media records this tool creates',
];

foreach ($excludedTables as $t => $reason) {
    if (in_array($t, $v1Tables, true)) {
        $stmt = $v1->query("SELECT COUNT(*) AS c FROM `$t`");
        $count = (int) $stmt->fetch()['c'];
        $log->skipped($t, "$count row(s) in V1", $reason);
    }
}

// =======================================================================
// PHASE 2: CATEGORIES  (v1.video_categories -> v2.categories)
// v2.lessons.video_category_id points at this table, so it must be
// migrated before lessons.
// =======================================================================
$log->section('Phase 2: Categories (video_categories -> categories)');

$v2->beginTransaction();
try {
    $rows = $v1->query("SELECT * FROM video_categories ORDER BY id")->fetchAll();
    foreach ($rows as $r) {
        if (existsById($v2, 'categories', (int)$r['id']) || existsBySlug($v2, 'categories', $r['slug'])) {
            $log->skipped('categories', "id={$r['id']} slug={$r['slug']}", 'already exists in V2 (idempotent re-run)');
            continue;
        }
        $stmt = $v2->prepare("INSERT INTO categories (id, name, slug, description, icon, sort_order, created_at, updated_at)
            VALUES (:id, :name, :slug, :description, :icon, :sort_order, :created_at, :updated_at)");
        $stmt->execute([
            'id' => $r['id'],
            'name' => $r['name'],
            'slug' => $r['slug'],
            'description' => $r['description'],
            'icon' => $r['icon'],
            'sort_order' => $r['sort_order'],
            'created_at' => $r['created_at'],
            'updated_at' => $r['created_at'],
        ]);
        $log->migrated('categories', "id={$r['id']} name={$r['name']}");
    }
    bumpAutoIncrement($v2, 'categories');
    $v2->commit();
} catch (Throwable $e) {
    $v2->rollBack();
    $log->failed('categories', 'bulk migration', $e->getMessage());
}

// =======================================================================
// PHASE 3: LESSONS  (v1.lessons -> v2.lessons)
// Column mapping:
//   position       -> sort_order (renamed in V2)
//   course_id      -> DROPPED (no course system in V2, and all V1 values are NULL)
//   video_category_id -> carried through as-is (categories migrated in Phase 2 with identical IDs)
//   (new in V2, defaulted) standalone_type = NULL, updated_at = created_at,
//                          deleted_at = NULL, deleted_by = NULL
// =======================================================================
$log->section('Phase 3: Lessons');

$v2->beginTransaction();
try {
    $rows = $v1->query("SELECT * FROM lessons ORDER BY id")->fetchAll();
    foreach ($rows as $r) {
        if (existsById($v2, 'lessons', (int)$r['id'])) {
            $log->skipped('lessons', "id={$r['id']} slug={$r['slug']}", 'already exists in V2 (idempotent re-run)');
            continue;
        }
        if (existsBySlug($v2, 'lessons', $r['slug'])) {
            $log->skipped('lessons', "id={$r['id']} slug={$r['slug']}", 'a lesson with this slug already exists in V2 under a different id');
            continue;
        }
        if (!empty($r['course_id'])) {
            $log->warn("lesson id={$r['id']} had a non-null course_id={$r['course_id']} in V1; V2 has no course system, course_id was dropped");
        }
        $stmt = $v2->prepare("INSERT INTO lessons
            (id, title, slug, category, preview_content, full_content, video_url, video_id,
             sort_order, video_category_id, access_level, standalone_type, thumbnail, duration,
             is_featured, audio_url, created_at, updated_at, deleted_at, deleted_by)
            VALUES
            (:id, :title, :slug, :category, :preview_content, :full_content, :video_url, :video_id,
             :sort_order, :video_category_id, :access_level, NULL, :thumbnail, :duration,
             :is_featured, :audio_url, :created_at, :updated_at, NULL, NULL)");
        $stmt->execute([
            'id' => $r['id'],
            'title' => $r['title'],
            'slug' => $r['slug'],
            'category' => $r['category'],
            'preview_content' => $r['preview_content'],
            'full_content' => $r['full_content'],
            'video_url' => $r['video_url'],
            'video_id' => $r['video_id'],
            'sort_order' => $r['position'],
            'video_category_id' => $r['video_category_id'],
            'access_level' => $r['access_level'],
            'thumbnail' => $r['thumbnail'],
            'duration' => $r['duration'],
            'is_featured' => $r['is_featured'],
            'audio_url' => $r['audio_url'],
            'created_at' => $r['created_at'],
            'updated_at' => $r['created_at'],
        ]);
        $log->migrated('lessons', "id={$r['id']} title=\"{$r['title']}\"");
    }
    bumpAutoIncrement($v2, 'lessons');
    $v2->commit();
} catch (Throwable $e) {
    $v2->rollBack();
    $log->failed('lessons', 'bulk migration', $e->getMessage());
}

// =======================================================================
// PHASE 4: GUIDES (v1.guide_paths -> v2.guide_paths)
// Column mapping: add xp_reward = NULL, certificate_template_id = NULL,
// updated_at = created_at, deleted_at/deleted_by = NULL.
// =======================================================================
$log->section('Phase 4: Guides (guide_paths)');

$v2->beginTransaction();
try {
    $rows = $v1->query("SELECT * FROM guide_paths ORDER BY id")->fetchAll();
    foreach ($rows as $r) {
        if (existsById($v2, 'guide_paths', (int)$r['id'])) {
            $log->skipped('guide_paths', "id={$r['id']} slug={$r['slug']}", 'already exists in V2 (idempotent re-run)');
            continue;
        }
        if ($r['slug'] !== null && existsBySlug($v2, 'guide_paths', $r['slug'])) {
            $log->skipped('guide_paths', "id={$r['id']} slug={$r['slug']}", 'a guide with this slug already exists in V2 under a different id');
            continue;
        }
        $stmt = $v2->prepare("INSERT INTO guide_paths
            (id, title, description, thumbnail, slug, difficulty, is_active,
             xp_reward, certificate_template_id, created_at, updated_at, deleted_at, deleted_by)
            VALUES
            (:id, :title, :description, :thumbnail, :slug, :difficulty, :is_active,
             NULL, NULL, :created_at, :updated_at, NULL, NULL)");
        $stmt->execute([
            'id' => $r['id'],
            'title' => $r['title'],
            'description' => $r['description'],
            'thumbnail' => $r['thumbnail'],
            'slug' => $r['slug'],
            'difficulty' => $r['difficulty'],
            'is_active' => $r['is_active'],
            'created_at' => $r['created_at'],
            'updated_at' => $r['created_at'],
        ]);
        $log->migrated('guide_paths', "id={$r['id']} title=\"{$r['title']}\"");
    }

    // Create (or find) the "Imported Lessons" default guide required by Phase 3
    // of the brief: every lesson must belong to a guide after migration.
    $importedGuideId = null;
    $stmt = $v2->prepare("SELECT id FROM guide_paths WHERE slug = 'imported-lessons' LIMIT 1");
    $stmt->execute();
    $existing = $stmt->fetch();
    if ($existing) {
        $importedGuideId = (int) $existing['id'];
        $log->line("'Imported Lessons' guide already exists (id=$importedGuideId), reusing it");
    } else {
        $stmt = $v2->prepare("INSERT INTO guide_paths
            (title, description, thumbnail, slug, difficulty, is_active,
             xp_reward, certificate_template_id, created_at, updated_at, deleted_at, deleted_by)
            VALUES
            ('Imported Lessons', 'Lessons migrated from Web3Edu V1 that were not part of a structured guide.',
             NULL, 'imported-lessons', 'Beginner', 1, NULL, NULL, NOW(), NOW(), NULL, NULL)");
        $stmt->execute();
        $importedGuideId = (int) $v2->lastInsertId();
        $log->migrated('guide_paths', "id=$importedGuideId title=\"Imported Lessons\" (created as default guide)");
    }

    bumpAutoIncrement($v2, 'guide_paths');
    $v2->commit();
} catch (Throwable $e) {
    $v2->rollBack();
    $log->failed('guide_paths', 'bulk migration', $e->getMessage());
    $importedGuideId = null;
}

// =======================================================================
// PHASE 5: GUIDE <-> LESSON RELATIONSHIPS (v1.guide_path_lessons -> v2)
// Rows whose guide_id does not exist in v1.guide_paths are orphaned FKs
// in the source data itself and are skipped (their lessons still end up
// attached to a guide via any other valid row, or via "Imported Lessons"
// below if they have no valid association at all).
// =======================================================================
$log->section('Phase 5: Guide <-> Lesson relationships');

$lessonsWithAGuide = [];

$v2->beginTransaction();
try {
    $rows = $v1->query("
        SELECT gpl.*, gp.id AS guide_exists
        FROM guide_path_lessons gpl
        LEFT JOIN guide_paths gp ON gp.id = gpl.guide_id
        ORDER BY gpl.id
    ")->fetchAll();

    foreach ($rows as $r) {
        if ($r['guide_exists'] === null) {
            $log->skipped('guide_path_lessons', "guide_id={$r['guide_id']} lesson_id={$r['lesson_id']}",
                'orphaned reference in V1 source data - guide_id does not exist in v1.guide_paths');
            continue;
        }
        $lessonsWithAGuide[(int)$r['lesson_id']] = true;

        // idempotent: unique key (guide_id, lesson_id) in V2
        $stmt = $v2->prepare("SELECT id FROM guide_path_lessons WHERE guide_id = :g AND lesson_id = :l LIMIT 1");
        $stmt->execute(['g' => $r['guide_id'], 'l' => $r['lesson_id']]);
        if ($stmt->fetch()) {
            $log->skipped('guide_path_lessons', "guide_id={$r['guide_id']} lesson_id={$r['lesson_id']}", 'relationship already exists in V2 (idempotent re-run)');
            continue;
        }
        if (!existsById($v2, 'lessons', (int)$r['lesson_id']) || !existsById($v2, 'guide_paths', (int)$r['guide_id'])) {
            $log->failed('guide_path_lessons', "guide_id={$r['guide_id']} lesson_id={$r['lesson_id']}", 'referenced lesson or guide is missing in V2 - cannot safely insert');
            continue;
        }
        $stmt = $v2->prepare("INSERT INTO guide_path_lessons (guide_id, lesson_id, sort_order) VALUES (:g, :l, :s)");
        $stmt->execute(['g' => $r['guide_id'], 'l' => $r['lesson_id'], 's' => $r['sort_order']]);
        $log->migrated('guide_path_lessons', "guide_id={$r['guide_id']} lesson_id={$r['lesson_id']}");
    }

    // Every remaining lesson (no valid guide association at all) goes
    // into the "Imported Lessons" guide.
    if ($importedGuideId !== null) {
        $allLessonIds = array_map('intval', $v1->query("SELECT id FROM lessons")->fetchAll(PDO::FETCH_COLUMN));
        $orphanLessons = array_values(array_diff($allLessonIds, array_keys($lessonsWithAGuide)));
        $sortOrder = 0;
        foreach ($orphanLessons as $lessonId) {
            $stmt = $v2->prepare("SELECT id FROM guide_path_lessons WHERE guide_id = :g AND lesson_id = :l LIMIT 1");
            $stmt->execute(['g' => $importedGuideId, 'l' => $lessonId]);
            if ($stmt->fetch()) {
                $log->skipped('guide_path_lessons', "guide_id=$importedGuideId lesson_id=$lessonId", 'already linked to Imported Lessons (idempotent re-run)');
                $sortOrder++;
                continue;
            }
            if (!existsById($v2, 'lessons', $lessonId)) {
                $log->failed('guide_path_lessons', "guide_id=$importedGuideId lesson_id=$lessonId", 'lesson missing in V2, cannot link to Imported Lessons');
                continue;
            }
            $stmt = $v2->prepare("INSERT INTO guide_path_lessons (guide_id, lesson_id, sort_order) VALUES (:g, :l, :s)");
            $stmt->execute(['g' => $importedGuideId, 'l' => $lessonId, 's' => $sortOrder]);
            $log->migrated('guide_path_lessons', "guide_id=$importedGuideId (Imported Lessons) lesson_id=$lessonId");
            $sortOrder++;
        }
    } else {
        $log->warn('Could not create/find the Imported Lessons guide - unassociated lessons were not linked to any guide');
    }

    bumpAutoIncrement($v2, 'guide_path_lessons');
    $v2->commit();
} catch (Throwable $e) {
    $v2->rollBack();
    $log->failed('guide_path_lessons', 'bulk migration', $e->getMessage());
}

// =======================================================================
// PHASE 6: BLOG POSTS (v1.blog_posts -> v2.blog_posts)
// Note: v1.author_id pointed at users.id; v2.author_id points at
// admins.id (schema comment: "corrected ... blog posts are admin-authored").
// Every V1 blog post in this dataset has author_id = NULL, so this is
// carried through as NULL rather than guessed at.
// =======================================================================
$log->section('Phase 6: Blog posts');

$v2->beginTransaction();
try {
    $rows = $v1->query("SELECT * FROM blog_posts ORDER BY id")->fetchAll();
    foreach ($rows as $r) {
        if (existsById($v2, 'blog_posts', (int)$r['id'])) {
            $log->skipped('blog_posts', "id={$r['id']} slug={$r['slug']}", 'already exists in V2 (idempotent re-run)');
            continue;
        }
        if (existsBySlug($v2, 'blog_posts', $r['slug'])) {
            $log->skipped('blog_posts', "id={$r['id']} slug={$r['slug']}", 'a post with this slug already exists in V2 under a different id');
            continue;
        }
        $authorId = $r['author_id'];
        if ($authorId !== null) {
            // V1 author_id referenced users.id; users are not migrated, so
            // this can no longer be resolved safely. Null it out and warn.
            $log->warn("blog post id={$r['id']} had a non-null V1 author_id={$authorId} (referenced a V1 user) - set to NULL since users are not migrated");
            $authorId = null;
        }
        $excerpt = $r['excerpt'];
        if ($excerpt !== null && strlen($excerpt) > 500) {
            $excerpt = substr($excerpt, 0, 500);
            $log->warn("blog post id={$r['id']} excerpt truncated to 500 chars to fit V2 schema (varchar(500) vs V1 text)");
        }
        $stmt = $v2->prepare("INSERT INTO blog_posts
            (id, title, slug, excerpt, content, cover_image, author_id, is_published, is_featured,
             created_at, updated_at, deleted_at, deleted_by)
            VALUES
            (:id, :title, :slug, :excerpt, :content, :cover_image, :author_id, :is_published, :is_featured,
             :created_at, :updated_at, NULL, NULL)");
        $stmt->execute([
            'id' => $r['id'],
            'title' => $r['title'],
            'slug' => $r['slug'],
            'excerpt' => $excerpt,
            'content' => $r['content'],
            'cover_image' => $r['cover_image'],
            'author_id' => $authorId,
            'is_published' => $r['is_published'],
            'is_featured' => $r['is_featured'],
            'created_at' => $r['created_at'],
            'updated_at' => $r['created_at'],
        ]);
        $log->migrated('blog_posts', "id={$r['id']} title=\"{$r['title']}\"");
    }
    bumpAutoIncrement($v2, 'blog_posts');
    $v2->commit();
} catch (Throwable $e) {
    $v2->rollBack();
    $log->failed('blog_posts', 'bulk migration', $e->getMessage());
}

// =======================================================================
// PHASE 7: AIRDROPS (v1.airdrops -> v2.airdrops)
// Note: v1.featured_image has no equivalent column in v2.airdrops. It is
// preserved by creating a v2.media_library record instead
// (attachable_type = 'airdrop'), so the asset reference is not lost.
// =======================================================================
$log->section('Phase 7: Airdrops');

$v2->beginTransaction();
try {
    $rows = $v1->query("SELECT * FROM airdrops ORDER BY id")->fetchAll();
    if (empty($rows)) {
        $log->line('No airdrop records found in V1 - nothing to migrate.');
    }
    foreach ($rows as $r) {
        if (existsById($v2, 'airdrops', (int)$r['id'])) {
            $log->skipped('airdrops', "id={$r['id']} slug={$r['slug']}", 'already exists in V2 (idempotent re-run)');
            continue;
        }
        if ($r['slug'] !== null && existsBySlug($v2, 'airdrops', $r['slug'])) {
            $log->skipped('airdrops', "id={$r['id']} slug={$r['slug']}", 'an airdrop with this slug already exists in V2 under a different id');
            continue;
        }
        $stmt = $v2->prepare("INSERT INTO airdrops
            (id, title, slug, description, full_guide, project_url, reward, difficulty, deadline,
             status, access_level, is_featured, created_at, updated_at, deleted_at, deleted_by)
            VALUES
            (:id, :title, :slug, :description, :full_guide, :project_url, :reward, :difficulty, :deadline,
             :status, :access_level, :is_featured, :created_at, :updated_at, NULL, NULL)");
        $stmt->execute([
            'id' => $r['id'],
            'title' => $r['title'],
            'slug' => $r['slug'],
            'description' => $r['description'],
            'full_guide' => $r['full_guide'],
            'project_url' => $r['project_url'],
            'reward' => $r['reward'],
            'difficulty' => $r['difficulty'],
            'deadline' => $r['deadline'],
            'status' => $r['status'],
            'access_level' => $r['access_level'],
            'is_featured' => $r['is_featured'],
            'created_at' => $r['created_at'],
            'updated_at' => $r['created_at'],
        ]);
        $log->migrated('airdrops', "id={$r['id']} title=\"{$r['title']}\"");

        if (!empty($r['featured_image'])) {
            $stmt = $v2->prepare("SELECT id FROM media_library WHERE attachable_type = 'airdrop' AND attachable_id = :id AND path = :path LIMIT 1");
            $stmt->execute(['id' => $r['id'], 'path' => $r['featured_image']]);
            if (!$stmt->fetch()) {
                $stmt = $v2->prepare("INSERT INTO media_library
                    (filename, path, original_name, attachable_type, attachable_id, created_at, updated_at)
                    VALUES (:filename, :path, :path2, 'airdrop', :id, NOW(), NOW())");
                $stmt->execute([
                    'filename' => basename($r['featured_image']),
                    'path' => $r['featured_image'],
                    'path2' => $r['featured_image'],
                    'id' => $r['id'],
                ]);
                $log->migrated('media_library', "airdrop id={$r['id']} featured_image={$r['featured_image']} (v2.airdrops has no featured_image column, so this was preserved via media_library)");
            }
        }
    }
    bumpAutoIncrement($v2, 'airdrops');
    $v2->commit();
} catch (Throwable $e) {
    $v2->rollBack();
    $log->failed('airdrops', 'bulk migration', $e->getMessage());
}

// =======================================================================
// PHASE 8: HOMEPAGE CONTENT
//   - hero_headlines: only add headlines that don't already exist
//     (matched by headline_text) - V2 already has all 7 V1 headlines.
//   - crypto_carousel_items: add V1 coin logos that aren't already
//     present (matched by coin_name), without touching/removing V2's
//     existing carousel entries.
// =======================================================================
$log->section('Phase 8: Homepage content');

$v2->beginTransaction();
try {
    $rows = $v1->query("SELECT * FROM hero_headlines ORDER BY id")->fetchAll();
    foreach ($rows as $r) {
        $stmt = $v2->prepare("SELECT id FROM hero_headlines WHERE headline_text = :t LIMIT 1");
        $stmt->execute(['t' => $r['headline_text']]);
        if ($stmt->fetch()) {
            $log->skipped('hero_headlines', "\"{$r['headline_text']}\"", 'identical headline already present in V2');
            continue;
        }
        $stmt = $v2->prepare("INSERT INTO hero_headlines (headline_text, sort_order, is_active) VALUES (:t, :s, :a)");
        $stmt->execute(['t' => $r['headline_text'], 's' => $r['sort_order'], 'a' => $r['is_enabled']]);
        $log->migrated('hero_headlines', "\"{$r['headline_text']}\"");
    }

    $rows = $v1->query("SELECT * FROM crypto_logos ORDER BY id")->fetchAll();
    foreach ($rows as $r) {
        $stmt = $v2->prepare("SELECT id FROM crypto_carousel_items WHERE coin_name = :n LIMIT 1");
        $stmt->execute(['n' => $r['coin_name']]);
        if ($stmt->fetch()) {
            $log->skipped('crypto_carousel_items', "coin_name={$r['coin_name']}", 'a carousel item with this coin name already exists in V2');
            continue;
        }
        $stmt = $v2->prepare("INSERT INTO crypto_carousel_items (coin_name, logo_path, sort_order, is_active, created_at)
            VALUES (:n, :p, :s, :a, :c)");
        $stmt->execute([
            'n' => $r['coin_name'],
            'p' => $r['logo_path'],
            's' => $r['sort_order'],
            'a' => $r['is_enabled'],
            'c' => $r['created_at'],
        ]);
        $log->migrated('crypto_carousel_items', "coin_name={$r['coin_name']} (added alongside V2's existing carousel items)");
    }

    bumpAutoIncrement($v2, 'crypto_carousel_items');
    $v2->commit();
} catch (Throwable $e) {
    $v2->rollBack();
    $log->failed('homepage_content', 'bulk migration', $e->getMessage());
}

$log->warn('Physical media files (images, audio, thumbnails) referenced by migrated rows are NOT copied by this script - it only migrates database path references. Copy the V1 /uploads directory into the V2 uploads directory to make these paths resolve. See README.md.');

// =======================================================================
// PHASE 9 (of brief): VERIFICATION
// =======================================================================
$log->section('Verification');

function countRows(PDO $db, string $table): int
{
    return (int) $db->query("SELECT COUNT(*) AS c FROM `$table`")->fetch()['c'];
}

$verif = [
    'lessons'               => [countRows($v1, 'lessons'), countRows($v2, 'lessons')],
    'guide_paths'           => [countRows($v1, 'guide_paths') + 1 /* +1 for Imported Lessons */, countRows($v2, 'guide_paths')],
    'guide_path_lessons'    => [null, countRows($v2, 'guide_path_lessons')],
    'blog_posts'            => [countRows($v1, 'blog_posts'), countRows($v2, 'blog_posts')],
    'airdrops'              => [countRows($v1, 'airdrops'), countRows($v2, 'airdrops')],
    'categories'            => [countRows($v1, 'video_categories'), countRows($v2, 'categories')],
    'crypto_carousel_items' => [null, countRows($v2, 'crypto_carousel_items')],
    'hero_headlines'        => [countRows($v1, 'hero_headlines'), countRows($v2, 'hero_headlines')],
];
foreach ($verif as $t => $counts) {
    [$expected, $actual] = $counts;
    if ($expected === null) {
        $log->line("$t: V2 now has $actual row(s)");
    } else {
        $log->line("$t: V1 had $expected row(s), V2 now has $actual row(s)");
    }
}

// lessons that ended up with no guide at all (should be zero)
$stmt = $v2->query("
    SELECT l.id, l.title FROM lessons l
    LEFT JOIN guide_path_lessons gpl ON gpl.lesson_id = l.id
    WHERE gpl.id IS NULL
");
$orphanLessonsAfter = $stmt->fetchAll();
if (!empty($orphanLessonsAfter)) {
    foreach ($orphanLessonsAfter as $o) {
        $log->warn("lesson id={$o['id']} title=\"{$o['title']}\" is still not linked to any guide after migration");
    }
} else {
    $log->line('OK: every lesson in V2 belongs to at least one guide.');
}

// broken FK check: guide_path_lessons pointing at missing lessons/guides
$stmt = $v2->query("
    SELECT gpl.id FROM guide_path_lessons gpl
    LEFT JOIN lessons l ON l.id = gpl.lesson_id
    LEFT JOIN guide_paths gp ON gp.id = gpl.guide_id
    WHERE l.id IS NULL OR gp.id IS NULL
");
$brokenFks = $stmt->fetchAll();
if (!empty($brokenFks)) {
    $log->warn(count($brokenFks) . ' guide_path_lessons row(s) reference a missing lesson or guide in V2');
} else {
    $log->line('OK: no broken foreign keys in guide_path_lessons.');
}

// media reference sanity (path presence only - cannot verify physical files)
$missingThumb = (int) $v2->query("SELECT COUNT(*) c FROM lessons WHERE thumbnail IS NULL AND video_url IS NULL")->fetch()['c'];
if ($missingThumb > 0) {
    $log->warn("$missingThumb lesson(s) have neither a thumbnail nor a video_url - no visual media to reconnect");
}

$log->summary();
$log->close();

echo "Migration complete. See migration.log for full details.\n";
