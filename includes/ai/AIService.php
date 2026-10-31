<?php
require_once __DIR__ . '/AIServiceFactory.php';
require_once __DIR__ . '/PromptManager.php';
require_once __DIR__ . '/TopicGuard.php';
require_once __DIR__ . '/ContextBuilder.php';

/**
 * AIService
 * ============================================================
 * The ONLY class the rest of the app (API endpoints, admin pages)
 * should talk to for AI features.
 *
 *   Frontend → Backend (api/*.php) → AIService → AIServiceFactory → Provider
 *
 * The frontend never knows which vendor is active. Swapping Groq for
 * OpenAI/Gemini/Anthropic/OpenRouter/Ollama is a config change in the
 * admin AI Management page — zero code or frontend changes required.
 * ============================================================
 */
class AIService
{
    /** Rough $ per 1K tokens, used only for the admin "estimated cost" stat. Intentionally approximate. */
    private const COST_PER_1K_TOKENS = [
        'groq'       => 0.0006,
        'openai'     => 0.005,
        'gemini'     => 0.0025,
        'anthropic'  => 0.006,
        'openrouter' => 0.003,
        'ollama'     => 0.0,
    ];

    /**
     * AI Tutor: continue a conversation.
     *
     * Follows the Web3edu AI Knowledge Flow:
     *   1. If this is the START of a new conversation (no prior history),
     *      determine if the question is Web3-related (TopicGuard). Off-topic
     *      questions are politely declined WITHOUT calling the AI provider.
     *      Once a conversation is underway, this check is skipped — replies
     *      like "yh", "tell me more", "why?" are conversation continuations,
     *      not new questions, and must never be re-validated.
     *   2. Search Web3edu's own content (ContentSearchService via ContextBuilder),
     *      prioritizing the lesson/guide currently being viewed.
     *   3. Build a context block from that content.
     *   4. Send system prompt + FULL conversation history + context + the new
     *      user message to the configured provider, so the model always has
     *      the complete thread — not just the latest line.
     *
     * @param array  $history Prior turns (oldest first, current message NOT included yet):
     *                        [['role'=>'user'|'assistant','content'=>string], ...]
     * @param string $userMessage The new user message
     * @param int|null $userId For usage logging
     * @param string $membershipType 'free' | 'premium'
     * @param array|null $currentContext ['lesson_id'=>int,'lesson_type'=>'learn'|'guide'] — the lesson/guide the user is currently viewing, if any
     *
     * @return array{success:bool, content:?string, error:?string}
     */
    public static function askTutor(array $history, string $userMessage, ?int $userId, string $membershipType = 'free', ?array $currentContext = null): array
    {
        // A conversation is "new" only when there is no prior history yet.
        // This is the ONLY case the Web3 topic filter applies to — once a
        // conversation is underway, every message is treated as part of it.
        $isNewConversation = empty($history);

        // ── Step 1: Validate topic ONLY at the start of a new conversation ──
        if ($isNewConversation && !TopicGuard::isOnTopic($userMessage)) {
            self::logUsage('chat', $userId, $membershipType, 'guard', null, 0, 0, true, null);
            return ['success' => true, 'content' => TopicGuard::declineMessage(), 'error' => null];
        }

        // ── Steps 2–3: Search Web3edu content, build a prioritized context ──
        // Still runs for every message (including follow-ups) so replies like
        // "tell me more" or "why?" can pull in fresh supporting content.
        $context = ContextBuilder::build($userMessage, $currentContext);

        $systemPrompt = self::buildTutorSystemPrompt($context);

        // ── Full conversation history, oldest first, then the new message ──
        $messages = [['role' => 'system', 'content' => $systemPrompt]];
        foreach ($history as $turn) {
            if (in_array($turn['role'], ['user', 'assistant'], true)) {
                $messages[] = ['role' => $turn['role'], 'content' => (string)$turn['content']];
            }
        }
        $messages[] = ['role' => 'user', 'content' => $userMessage];

        // ── Step 4: Send system prompt + history + context + message to the provider ──
        return self::run('chat', $messages, ['temperature' => 0.5, 'max_tokens' => 700], $userId, $membershipType);
    }

    /**
     * Combines the editable base persona prompt with the retrieved Web3edu
     * context and explicit prioritization rules. The base prompt stays
     * admin-editable via PromptManager; the prioritization scaffolding
     * around it is architectural and always applied.
     */
    private static function buildTutorSystemPrompt(array $context): string
    {
        $basePrompt = PromptManager::get('ai_tutor_system');

        if (!$context['has_context']) {
            return $basePrompt . "\n\nNo matching Web3edu content was found for this question. Answer using your own knowledge, but stay strictly within Web3/blockchain topics, say clearly if you're not fully certain, and never invent Web3edu-specific facts (like guide names or platform features) that weren't given to you.";
        }

        return $basePrompt . "\n\n---\n"
            . "WEB3EDU EDUCATIONAL CONTENT (use this as your primary source — prioritize it over your own general knowledge; never contradict it; only use outside knowledge to fill in small gaps it doesn't cover):\n\n"
            . $context['context_text']
            . "\n---\nIf the content above does not fully answer the question, say what you're confident about, and clearly note if you're supplementing with general knowledge beyond Web3edu's own materials.";
    }

    /**
     * AI Study Card: summarize lesson content into 4-6 bullets, ≤100 words.
     *
     * @return array{success:bool, bullets:array, error:?string}
     */
    public static function generateStudyCard(string $lessonTitle, string $lessonContent, ?int $userId, string $membershipType = 'free'): array
    {
        $systemPrompt = PromptManager::get('study_card_system');
        $lessonContent = mb_substr(trim($lessonContent), 0, 6000); // keep prompt small & on-topic

        // Groq (and other OpenAI-compatible providers) reject the request with a
        // 400 error when json_mode/response_format=json_object is requested but
        // none of the messages contain the literal word "json". study_card_system
        // is free-text and editable from Admin -> AI Management -> Prompt
        // Management, so a saved edit that drops that word would silently break
        // every study card without touching the AI Tutor (which never uses
        // json_mode). Enforce it here instead of trusting the stored prompt.
        if (stripos($systemPrompt, 'json') === false) {
            $systemPrompt .= "\n\nRespond ONLY with a valid JSON object.";
        }

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user',   'content' => "Lesson title: {$lessonTitle}\n\nLesson content:\n{$lessonContent}"],
        ];

        // JSON enforcement: the earlier fix here disabled json_mode entirely,
        // because plain response_format:{type:"json_object"} was
        // undocumented/unreliable specifically for openai/gpt-oss-120b on
        // Groq. That's no longer the right tool: Groq now offers Structured
        // Outputs strict mode (response_format:{type:"json_schema",...,
        // strict:true}) with guaranteed, constrained-decoding schema
        // adherence, and it's supported precisely on the two models this
        // feature needs — openai/gpt-oss-20b and openai/gpt-oss-120b (see
        // https://console.groq.com/docs/structured-outputs#supported-models).
        // With strict:true the model literally cannot emit a token outside
        // the schema, so the "model adds a preamble / wraps the JSON in
        // prose or extra commentary" failure mode that broke json_decode()
        // in extractBullets() (and fell through to the line-splitting
        // fallback, producing garbage bullets) can't happen anymore.
        //
        // json_schema is passed alongside the older, schema-less json_mode
        // so providers other than Groq (or a Groq model without Structured
        // Outputs support) still get a "valid JSON, shape not guaranteed"
        // fallback via response_format:json_object instead of an unconstrained
        // prompt — see each provider's chat() for exactly which one it honors.
        $studyCardSchema = [
            'name'   => 'study_card',
            'strict' => true,
            'schema' => [
                'type'                 => 'object',
                'properties'           => [
                    'bullets' => [
                        'type'     => 'array',
                        'items'    => ['type' => 'string'],
                        'minItems' => 4,
                        'maxItems' => 6,
                    ],
                ],
                'required'             => ['bullets'],
                'additionalProperties' => false,
            ],
        ];

        // GPT-OSS 120B is a reasoning model: part of max_tokens is spent on
        // internal reasoning before the structured JSON is emitted. At the
        // previous max_tokens=400 with no reasoning_effort set (Groq's
        // default is 'medium'), that budget could be exhausted by reasoning
        // alone, truncating the response before the schema-conforming JSON
        // was produced — extractBullets() then has nothing valid to parse.
        // max_tokens raised to 800 for headroom, and reasoning_effort
        // pinned to 'low' (only supported on GPT-OSS 20B/120B) to keep
        // reasoning short for a task this simple, so more of the budget
        // reaches the actual answer.
        $result = self::run('study_card', $messages, [
            'temperature'      => 0.3,
            'max_tokens'       => 800,
            'json_mode'        => true,
            'json_schema'      => $studyCardSchema,
            'reasoning_effort' => 'low',
        ], $userId, $membershipType);

        if (!$result['success']) {
            return ['success' => false, 'bullets' => [], 'error' => $result['error']];
        }

        $bullets = self::extractBullets($result['content']);
        if (empty($bullets)) {
            return ['success' => false, 'bullets' => [], 'error' => 'Could not parse study card from AI response.'];
        }

        return ['success' => true, 'bullets' => $bullets, 'error' => null];
    }

    /**
     * Builds a compact, structured summary of the learner's own progress —
     * career path, roadmap stage, recent quiz/project activity, streak,
     * bookmarks, notes — entirely from EXISTING model functions
     * (career_paths.php / projects.php / gamification.php). This never
     * computes anything itself; it only assembles what those already
     * compute, the same way ContextBuilder assembles Web3edu CONTENT rather
     * than recomputing it.
     */
    private static function buildLearnerContext(int $userId): array {
        require_once __DIR__ . '/../career_paths.php';
        require_once __DIR__ . '/../projects.php';

        $db = getDB();
        $lines = [];

        $active = getActiveUserCareerPath($userId);
        if ($active) {
            $roadmap = computeCareerPathRoadmap($userId, (int)$active['career_path_id']);
            $lines[] = "Current Career Path: {$active['name']} ({$roadmap['progress']['pct']}% of required guides complete)";
            $next = resolveNextLearningItem($userId, (int)$active['career_path_id']);
            if ($next) {
                $lines[] = "Next up ({$next['type']}): " . ($next['lesson_title'] ?? $next['guide_title'] ?? $next['project_title'] ?? $next['assessment_title'] ?? $next['resource_title'] ?? 'unknown');
            } else {
                $lines[] = 'This career path is fully complete.';
            }
        } else {
            $lines[] = 'No active Career Path chosen yet.';
        }

        $userStmt = $db->prepare("SELECT current_streak_days, longest_streak_days, total_xp, current_level FROM users WHERE id=?");
        $userStmt->execute([$userId]);
        if ($u = $userStmt->fetch()) {
            $lines[] = "Streak: {$u['current_streak_days']} days (longest: {$u['longest_streak_days']}). XP: {$u['total_xp']}, Level {$u['current_level']}.";
        }

        $failedQuiz = $db->prepare("
            SELECT q.title FROM quiz_results qr INNER JOIN quizzes q ON q.id = qr.quiz_id
            WHERE qr.user_id = ? AND qr.passed = 0 ORDER BY qr.taken_at DESC LIMIT 2
        ");
        $failedQuiz->execute([$userId]);
        $failed = $failedQuiz->fetchAll(PDO::FETCH_COLUMN);
        if ($failed) $lines[] = 'Recently failed quiz(zes): ' . implode(', ', $failed);

        $draftProjects = $db->prepare("
            SELECT p.title FROM project_submissions ps INNER JOIN projects p ON p.id = ps.project_id
            WHERE ps.user_id = ? AND ps.status IN ('draft','needs_revision') LIMIT 2
        ");
        $draftProjects->execute([$userId]);
        $drafts = $draftProjects->fetchAll(PDO::FETCH_COLUMN);
        if ($drafts) $lines[] = 'Project(s) in progress or needing revision: ' . implode(', ', $drafts);

        $bookmarkCount = $db->prepare("SELECT COUNT(*) FROM user_bookmarks WHERE user_id=?");
        $bookmarkCount->execute([$userId]);
        $lines[] = 'Total bookmarks saved: ' . (int)$bookmarkCount->fetchColumn();

        return $lines;
    }

    /**
     * AI Mentor: personalized study plan. Cached per calendar day in
     * ai_daily_insights so repeat dashboard loads don't re-call the provider.
     */
    public static function generateStudyPlan(int $userId, string $membershipType = 'free', bool $forceRegenerate = false): array {
        return self::cachedMentorInsight($userId, 'study_plan', 'ai_mentor_study_plan', $membershipType, $forceRegenerate, 300);
    }

    /** AI Mentor: 3 short daily recommendations, same caching strategy as the study plan. */
    public static function getDailyRecommendation(int $userId, string $membershipType = 'free', bool $forceRegenerate = false): array {
        return self::cachedMentorInsight($userId, 'daily_recommendation', 'ai_mentor_daily_recommendation', $membershipType, $forceRegenerate, 150);
    }

    /** AI Mentor: one short motivational line, same caching strategy. */
    public static function getMotivationalMessage(int $userId, string $membershipType = 'free', bool $forceRegenerate = false): array {
        return self::cachedMentorInsight($userId, 'motivation', 'ai_mentor_motivation', $membershipType, $forceRegenerate, 60);
    }

    /** Shared cache-or-generate path for the three learner-context-driven mentor insights above. */
    private static function cachedMentorInsight(int $userId, string $insightType, string $promptKey, string $membershipType, bool $forceRegenerate, int $maxTokens): array {
        $db = getDB();
        $today = date('Y-m-d');

        if (!$forceRegenerate) {
            $cached = $db->prepare("SELECT content FROM ai_daily_insights WHERE user_id=? AND insight_type=? AND generated_date=?");
            $cached->execute([$userId, $insightType, $today]);
            if ($row = $cached->fetch()) {
                return ['success' => true, 'content' => $row['content'], 'error' => null, 'cached' => true];
            }
        }

        $context = self::buildLearnerContext($userId);
        $messages = [
            ['role' => 'system', 'content' => PromptManager::get($promptKey)],
            ['role' => 'user', 'content' => "Learner profile:\n- " . implode("\n- ", $context)],
        ];
        $result = self::run($insightType, $messages, ['temperature' => 0.6, 'max_tokens' => $maxTokens], $userId, $membershipType);

        if ($result['success']) {
            $db->prepare("
                INSERT INTO ai_daily_insights (user_id, insight_type, content, generated_date) VALUES (?,?,?,?)
                ON DUPLICATE KEY UPDATE content = VALUES(content)
            ")->execute([$userId, $insightType, $result['content'], $today]);
        }

        return ['success' => $result['success'], 'content' => $result['content'], 'error' => $result['error'], 'cached' => false];
    }

    /**
     * AI Mentor: explains quiz mistakes right after a failed submission.
     * Called inline from submit_quiz.php with the questions/answers already
     * in memory — nothing is persisted beyond the existing quiz_results row,
     * matching the brief's "reuse the current AI service, don't replace the
     * existing API" instruction rather than adding a new answer-history table.
     *
     * @param array $wrongQuestions [['question'=>string,'correct_answer'=>string,'submitted_answer'=>string], ...]
     */
    public static function explainQuizMistakes(array $wrongQuestions, ?int $userId, string $membershipType = 'free'): array {
        if (empty($wrongQuestions)) return ['success' => true, 'content' => null, 'error' => null];

        $summary = '';
        foreach ($wrongQuestions as $i => $w) {
            $summary .= ($i + 1) . ". Question: {$w['question']}\n   Correct answer: {$w['correct_answer']}\n   Learner answered: {$w['submitted_answer']}\n";
        }

        $messages = [
            ['role' => 'system', 'content' => PromptManager::get('ai_mentor_quiz_review')],
            ['role' => 'user', 'content' => "Missed questions:\n{$summary}"],
        ];
        return self::run('quiz_review', $messages, ['temperature' => 0.4, 'max_tokens' => 350], $userId, $membershipType);
    }

    /**
     * AI Mentor: preliminary, supplementary feedback on a project submission.
     * Stored on project_submissions.ai_review — never touches
     * project_feedback, which remains exclusively human review history.
     */
    public static function reviewProjectSubmission(int $submissionId, string $membershipType = 'free'): array {
        $db = getDB();
        $stmt = $db->prepare("
            SELECT ps.*, p.title AS project_title, p.description AS project_description, p.requirements
            FROM project_submissions ps INNER JOIN projects p ON p.id = ps.project_id
            WHERE ps.id = ?
        ");
        $stmt->execute([$submissionId]);
        $sub = $stmt->fetch();
        if (!$sub) return ['success' => false, 'content' => null, 'error' => 'Submission not found.'];

        $summary = "Project: {$sub['project_title']}\n"
            . "Requirements: {$sub['requirements']}\n"
            . "GitHub: " . ($sub['github_url'] ?: 'not provided') . "\n"
            . "Live demo: " . ($sub['live_demo_url'] ?: 'not provided') . "\n"
            . "Technologies used: " . ($sub['technologies'] ?: 'not specified') . "\n"
            . "Learner notes: " . ($sub['notes'] ?: 'none');

        $messages = [
            ['role' => 'system', 'content' => PromptManager::get('ai_mentor_project_review')],
            ['role' => 'user', 'content' => $summary],
        ];
        $result = self::run('project_review', $messages, ['temperature' => 0.5, 'max_tokens' => 350], (int)$sub['user_id'], $membershipType);

        if ($result['success']) {
            $db->prepare("UPDATE project_submissions SET ai_review = ?, ai_reviewed_at = NOW() WHERE id = ?")
               ->execute([$result['content'], $submissionId]);
        }

        return $result;
    }

    /** Shared call + logging path for both features. */
    private static function run(string $usageType, array $messages, array $options, ?int $userId, string $membershipType): array
    {
        $provider = getSetting('ai_provider', 'groq');
        $model    = getSetting('ai_model', '');

        try {
            $adapter = AIServiceFactory::make();
            $result  = $adapter->chat($messages, $options);

            self::logUsage($usageType, $userId, $membershipType, $adapter->getName(), $model, $result['tokens_input'], $result['tokens_output'], true, null);

            return ['success' => true, 'content' => $result['content'], 'error' => null];
        } catch (\Throwable $e) {
            // Safety net (AI Study Card only, never AI Tutor): json_mode /
            // json_schema are the only request options the Study Card sends
            // that the Tutor never does. With Groq Structured Outputs
            // strict:true on a supported model (openai/gpt-oss-20b /
            // openai/gpt-oss-120b — see GroqProvider::chat() and
            // AIService::generateStudyCard()) the provider itself guarantees
            // schema-conformant JSON via constrained decoding, so this
            // shouldn't normally fire for that path. It still exists to
            // absorb anything outside that guarantee: an admin switching
            // ai_model/ai_provider to something that doesn't support
            // Structured Outputs, a transient provider-side error, etc.
            //
            // Recover unconditionally: whenever json_mode or json_schema was
            // set and the call fails for ANY reason, retry once with both
            // dropped (matching a hand-picked list of error-text keywords is
            // fragile — provider error wording isn't a stable contract to
            // pattern-match against). extractBullets() already has a
            // line-splitting fallback for non-JSON provider output, so the
            // retry still produces a usable card. This costs one extra HTTP
            // call only on the failure path, never on the (common) success
            // path.
            if (!empty($options['json_mode']) || !empty($options['json_schema'])) {
                try {
                    $retryOptions = $options;
                    unset($retryOptions['json_mode'], $retryOptions['json_schema']);
                    $adapter = AIServiceFactory::make();
                    $result  = $adapter->chat($messages, $retryOptions);

                    self::logUsage(
                        $usageType, $userId, $membershipType, $adapter->getName(), $model,
                        $result['tokens_input'], $result['tokens_output'], true,
                        'Recovered by retrying without json_mode/json_schema (provider rejected response_format: ' . $e->getMessage() . ')'
                    );

                    return ['success' => true, 'content' => $result['content'], 'error' => null];
                } catch (\Throwable $retryException) {
                    self::logUsage($usageType, $userId, $membershipType, $provider, $model, 0, 0, false, $retryException->getMessage());
                    // Surface the ORIGINAL error, not the retry's — the retry
                    // was purely a recovery attempt. If both calls fail for
                    // the same underlying reason (bad key, network outage)
                    // the messages will usually match anyway; if they don't,
                    // the first failure is the one that actually explains
                    // what's broken (a json_mode/json_schema-specific
                    // rejection only ever shows up in $e, never in the retry).
                    return ['success' => false, 'content' => null, 'error' => $e->getMessage()];
                }
            }

            self::logUsage($usageType, $userId, $membershipType, $provider, $model, 0, 0, false, $e->getMessage());
            return ['success' => false, 'content' => null, 'error' => $e->getMessage()];
        }
    }

    /** Parses {"bullets":[...]} from the model output; falls back to line-splitting if it ignored JSON mode. */
    private static function extractBullets(string $raw): array
    {
        $raw = trim($raw);
        // Strip markdown code fences some models add despite json_mode
        $raw = preg_replace('/^```(json)?|```$/m', '', $raw);
        $raw = trim($raw);

        $decoded = json_decode($raw, true);
        if (is_array($decoded) && !empty($decoded['bullets']) && is_array($decoded['bullets'])) {
            $bullets = array_map(fn($b) => trim((string)$b), $decoded['bullets']);
            $bullets = array_filter($bullets, fn($b) => $b !== '');
            return array_slice(array_values($bullets), 0, 6);
        }

        // Fallback: treat each non-empty line (optionally prefixed with - or •) as a bullet
        $lines = preg_split('/\r?\n/', $raw);
        $bullets = [];
        foreach ($lines as $line) {
            $line = trim($line);
            $line = preg_replace('/^[-•*]\s*/', '', $line);
            if ($line !== '') $bullets[] = $line;
        }
        return array_slice($bullets, 0, 6);
    }

    private static function logUsage(
        string $type, ?int $userId, string $membershipType, ?string $provider, ?string $model,
        int $tokensIn, int $tokensOut, bool $success, ?string $error
    ): void {
        try {
            $rate = self::COST_PER_1K_TOKENS[$provider] ?? 0.002;
            $cost = (($tokensIn + $tokensOut) / 1000) * $rate;

            $db = getDB();
            $stmt = $db->prepare('
                INSERT INTO ai_usage_log
                    (user_id, type, membership_type, provider, model, tokens_input, tokens_output, cost_estimated, success, error_message)
                VALUES (?,?,?,?,?,?,?,?,?,?)
            ');
            $stmt->execute([$userId, $type, $membershipType, $provider, $model, $tokensIn, $tokensOut, round($cost, 6), $success ? 1 : 0, $error ? mb_substr($error, 0, 480) : null]);
        } catch (\Throwable $e) {
            // Logging must never break the user-facing feature.
        }
    }
}
