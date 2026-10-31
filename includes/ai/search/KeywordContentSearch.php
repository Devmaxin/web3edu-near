<?php
require_once __DIR__ . '/ContentSearchInterface.php';

/**
 * KeywordContentSearch
 * ============================================================
 * Initial ContentSearchInterface implementation: SQL LIKE candidate
 * fetch + in-PHP keyword-overlap scoring. No external dependencies,
 * works on any MySQL install, and is intentionally isolated behind
 * ContentSearchInterface so a future embeddings/vector-DB search
 * (e.g. VectorContentSearch) can replace it without touching
 * ContextBuilder, AIService, or any other caller.
 *
 * Sources searched: lessons (covers BOTH Learn and Guide lessons —
 * they share the same `lessons` table; guide membership is resolved
 * via guide_path_lessons), blog_posts. Video lessons are
 * lessons with a non-empty video_id/video_url, tagged type='video'.
 * ============================================================
 */
class KeywordContentSearch implements ContentSearchInterface
{
    /** Common English/Web3 filler words that add noise to keyword matching. */
    private const STOPWORDS = [
        'the','a','an','is','are','was','were','be','been','being','to','of','and','or','in','on','at',
        'for','with','about','what','whats',"what's",'how','why','when','where','who','which','does','do',
        'can','could','would','should','i','you','he','she','it','we','they','my','your','me','explain',
        'like','please','tell','this','that','these','those','as','if','so','just','get','give','know',
    ];

    public function search(string $query, int $limit = 5, array $types = []): array
    {
        $keywords = $this->extractKeywords($query);
        if (empty($keywords)) {
            return [];
        }

        $results = [];
        $wantAll = empty($types);

        if ($wantAll || in_array('lesson', $types, true) || in_array('video', $types, true) || in_array('guide', $types, true)) {
            $results = array_merge($results, $this->searchLessons($keywords, $limit * 2));
        }
        if ($wantAll || in_array('blog', $types, true)) {
            $results = array_merge($results, $this->searchBlog($keywords, $limit));
        }

        usort($results, fn($a, $b) => $b->score <=> $a->score);

        return array_slice($results, 0, $limit);
    }

    /** Lowercase, strip punctuation, drop stopwords/short tokens, dedupe. */
    private function extractKeywords(string $query): array
    {
        $query = strtolower($query);
        preg_match_all('/[a-z0-9\-]+/', $query, $m);
        $tokens = array_unique(array_filter($m[0], function ($t) {
            return mb_strlen($t) >= 3 && !in_array($t, self::STOPWORDS, true);
        }));
        return array_values($tokens);
    }

    /** How many times any keyword appears in a text blob, weighted. */
    private function keywordScore(array $keywords, string $text, float $weight): float
    {
        $text = strtolower(strip_tags($text));
        $score = 0.0;
        foreach ($keywords as $kw) {
            $count = substr_count($text, $kw);
            if ($count > 0) {
                $score += $weight * min($count, 4); // diminishing returns per keyword
            }
        }
        return $score;
    }

    private function likeCandidates(array $keywords, int $capKeywords = 8): array
    {
        // Cap keyword count in the SQL WHERE to keep the query cheap; scoring in PHP still uses all keywords.
        return array_slice($keywords, 0, $capKeywords);
    }

    private function searchLessons(array $keywords, int $limit): array
    {
        $db = getDB();
        $likeKeywords = $this->likeCandidates($keywords);
        $conditions = [];
        $params = [];
        foreach ($likeKeywords as $kw) {
            $conditions[] = '(title LIKE ? OR preview_content LIKE ? OR full_content LIKE ?)';
            $like = '%' . $kw . '%';
            array_push($params, $like, $like, $like);
        }
        if (empty($conditions)) return [];

        $sql = 'SELECT id, title, category, preview_content, full_content, access_level, video_id, video_url
                FROM lessons WHERE ' . implode(' OR ', $conditions) . ' LIMIT 60';
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        if (empty($rows)) return [];

        // Resolve guide membership for these lessons in one query.
        $lessonIds = array_column($rows, 'id');
        $guideMap = [];
        if (!empty($lessonIds)) {
            $placeholders = implode(',', array_fill(0, count($lessonIds), '?'));
            $gStmt = $db->prepare("
                SELECT gpl.lesson_id, gp.title AS guide_title
                FROM guide_path_lessons gpl
                JOIN guide_paths gp ON gp.id = gpl.guide_id AND gp.is_active = 1
                WHERE gpl.lesson_id IN ($placeholders)
            ");
            $gStmt->execute($lessonIds);
            foreach ($gStmt->fetchAll() as $g) {
                $guideMap[(int)$g['lesson_id']] = $g['guide_title'];
            }
        }

        $results = [];
        foreach ($rows as $row) {
            $body = $row['full_content'] ?: $row['preview_content'] ?: '';
            $score = $this->keywordScore($keywords, $row['title'], 5.0)
                   + $this->keywordScore($keywords, $row['preview_content'] ?? '', 2.0)
                   + $this->keywordScore($keywords, $body, 1.0);
            if ($score <= 0) continue;

            $type = !empty($row['video_id']) || !empty($row['video_url']) ? 'video' : 'lesson';
            $results[] = new ContentResult(
                type: $type,
                id: (int)$row['id'],
                title: $row['title'],
                snippet: $this->makeSnippet($body ?: ($row['preview_content'] ?? ''), $keywords),
                score: $score,
                guideTitle: $guideMap[(int)$row['id']] ?? null
            );
        }
        usort($results, fn($a, $b) => $b->score <=> $a->score);
        return array_slice($results, 0, $limit);
    }

    private function searchBlog(array $keywords, int $limit): array
    {
        $db = getDB();
        $likeKeywords = $this->likeCandidates($keywords);
        $conditions = [];
        $params = [];
        foreach ($likeKeywords as $kw) {
            $conditions[] = '(title LIKE ? OR excerpt LIKE ? OR content LIKE ?)';
            $like = '%' . $kw . '%';
            array_push($params, $like, $like, $like);
        }
        if (empty($conditions)) return [];

        $stmt = $db->prepare("SELECT id, title, excerpt, content FROM blog_posts WHERE is_published = 1 AND (" . implode(' OR ', $conditions) . ') LIMIT 40');
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $results = [];
        foreach ($rows as $row) {
            $score = $this->keywordScore($keywords, $row['title'], 5.0)
                   + $this->keywordScore($keywords, $row['excerpt'] ?? '', 2.0)
                   + $this->keywordScore($keywords, $row['content'] ?? '', 1.0);
            if ($score <= 0) continue;
            $body = $row['content'] ?: $row['excerpt'] ?: '';
            $results[] = new ContentResult('blog', (int)$row['id'], $row['title'], $this->makeSnippet($body, $keywords), $score);
        }
        usort($results, fn($a, $b) => $b->score <=> $a->score);
        return array_slice($results, 0, $limit);
    }

    /** Strip HTML and trim to a window around the first keyword hit, so context stays token-cheap. */
    private function makeSnippet(string $html, array $keywords, int $windowChars = 500): string
    {
        $text = trim(preg_replace('/\s+/', ' ', strip_tags($html)));
        if ($text === '') return '';

        $lower = strtolower($text);
        $pos = false;
        foreach ($keywords as $kw) {
            $p = strpos($lower, $kw);
            if ($p !== false) { $pos = $p; break; }
        }
        if ($pos === false) {
            return mb_substr($text, 0, $windowChars);
        }
        $start = max(0, $pos - 120);
        $snippet = mb_substr($text, $start, $windowChars);
        return ($start > 0 ? '…' : '') . $snippet . (mb_strlen($text) > $start + $windowChars ? '…' : '');
    }
}
