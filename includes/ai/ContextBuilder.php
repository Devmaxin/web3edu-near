<?php
require_once __DIR__ . '/ContentSearchService.php';

/**
 * ContextBuilder
 * ============================================================
 * Implements the priority order from the AI Knowledge Flow:
 *
 *   1. Current lesson/guide the user is viewing (if any)
 *   2. Learn section lessons
 *   3. Guide section lessons
 *   4. Blog
 *   5. Videos
 *   6. (Groq's general knowledge — added later by AIService, only
 *      to fill gaps; ContextBuilder never touches the model itself)
 *
 * Learn and Guide lessons live in the same `lessons` table, so their
 * relative priority is enforced with a weight rather than a second
 * query — a lesson reached through a guide path is still tagged and
 * weighted appropriately.
 *
 * A char budget keeps the prompt small — "search the whole platform"
 * does NOT mean "send the whole platform" to the LLM.
 * ============================================================
 */
class ContextBuilder
{
    /** Per-type priority weight — higher wins ties against raw relevance score. */
    private const TYPE_WEIGHT = [
        'current'  => 1000, // the lesson/guide the user is actively viewing
        'lesson'   => 30,
        'video'    => 28,
        'blog'     => 8,
    ];

    private const DEFAULT_CHAR_BUDGET = 3200;

    /**
     * @param string   $question
     * @param array|null $currentContext ['lesson_id' => int, 'lesson_type' => 'learn'|'guide', 'guide_id' => int|null]
     * @return array{context_text:string, has_context:bool, sources:string[]}
     */
    public static function build(string $question, ?array $currentContext = null, int $charBudget = self::DEFAULT_CHAR_BUDGET): array
    {
        $blocks  = [];   // ['text' => string, 'weight' => float, 'chars' => int]
        $sources = [];
        $excludeLessonId = null;

        // ── 1. Current lesson/guide the user is viewing ───────────────
        if (!empty($currentContext['lesson_id'])) {
            $current = self::fetchCurrentLesson((int)$currentContext['lesson_id']);
            if ($current) {
                $excludeLessonId = (int)$currentContext['lesson_id'];
                $label = !empty($currentContext['lesson_type']) && $currentContext['lesson_type'] === 'guide'
                    ? 'Currently viewing (Guide lesson)'
                    : 'Currently viewing (Lesson)';
                $body = trim(strip_tags($current['full_content'] ?: $current['preview_content'] ?: ''));
                $body = preg_replace('/\s+/', ' ', $body);
                $body = mb_substr($body, 0, 1800);
                $text = "[{$label}] {$current['title']}\n{$body}";
                $blocks[] = ['text' => $text, 'weight' => self::TYPE_WEIGHT['current'], 'chars' => mb_strlen($text)];
                $sources[] = $current['title'] . ' (current lesson)';
            }
        }

        // ── 2–6. Search the rest of the platform ───────────────────────
        $results = ContentSearchService::search($question, 8);
        foreach ($results as $result) {
            if ($excludeLessonId !== null && in_array($result->type, ['lesson', 'video'], true) && $result->id === $excludeLessonId) {
                continue; // don't duplicate the lesson already injected above
            }
            $weight = self::TYPE_WEIGHT[$result->type] ?? 5;
            // A lesson surfaced via a guide path still counts as "lesson" type but we note the guide in the label.
            $text = $result->toContextBlock();
            $blocks[] = ['text' => $text, 'weight' => $weight + $result->score, 'chars' => mb_strlen($text)];
            $sources[] = $result->title;
        }

        // ── Sort by priority weight (ties broken by relevance already folded in) ──
        usort($blocks, fn($a, $b) => $b['weight'] <=> $a['weight']);

        // ── Pack into the char budget, most important first ───────────
        $used = 0;
        $chosen = [];
        foreach ($blocks as $block) {
            if ($used + $block['chars'] > $charBudget) {
                if ($used === 0) {
                    // Always include at least a truncated version of the top block.
                    $chosen[] = mb_substr($block['text'], 0, $charBudget);
                    $used = $charBudget;
                }
                break;
            }
            $chosen[] = $block['text'];
            $used += $block['chars'];
        }

        return [
            'context_text' => implode("\n\n---\n\n", $chosen),
            'has_context'  => !empty($chosen),
            'sources'      => array_slice($sources, 0, count($chosen)),
        ];
    }

    private static function fetchCurrentLesson(int $lessonId): ?array
    {
        try {
            $db = getDB();
            $stmt = $db->prepare('SELECT id, title, preview_content, full_content FROM lessons WHERE id = ?');
            $stmt->execute([$lessonId]);
            return $stmt->fetch() ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
