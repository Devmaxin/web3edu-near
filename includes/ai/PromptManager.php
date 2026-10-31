<?php
/**
 * PromptManager
 * ============================================================
 * Central place prompts are stored, fetched, and edited.
 * Nothing in the codebase hardcodes a prompt string for the AI to
 * use at runtime — every prompt is looked up by key from the
 * ai_prompts table, with an in-code fallback only used the very
 * first time (before migration_ai.sql has been run / seeded).
 * ============================================================
 */
class PromptManager
{
    /** Fallback defaults — only used if the DB row is missing entirely. */
    private const DEFAULTS = [
        'study_card_system' => 'Summarize this lesson into a concise study card. Rules: Use only the lesson content. Do not introduce external information. Maximum 6 bullet points. Maximum 100 words. Easy to revise. No long paragraphs. Respond ONLY as JSON: {"bullets": ["...", "..."]}',
        'ai_tutor_system'   => "You are Web3Edu AI Tutor. Your purpose is to teach Web3 using the Web3Edu curriculum.\n\nRules:\n- Always prioritize Web3Edu educational content over general knowledge.\n- Use your own knowledge only to supplement missing information.\n- Never contradict Web3Edu lessons.\n- Explain concepts clearly and simply, like a patient tutor.\n- Keep answers educational and beginner-friendly.\n- Stay strictly focused on Web3, blockchain, cryptocurrency, smart contracts, and related development topics.\n- Politely refuse questions unrelated to Web3/blockchain.\n- If information is unavailable, clearly say so instead of inventing details.\n- Avoid financial advice — focus on education.\n- Keep replies reasonably concise (under ~200 words) unless the user asks for more depth.",
        'ai_mentor_study_plan' => 'You are the Web3Edu AI Mentor. Using the learner profile provided, generate a short, personalized study plan for the next few days. Reference their actual current career path, guide, and progress by name. Keep it under 150 words, a numbered list of 3-5 concrete next actions.',
        'ai_mentor_daily_recommendation' => 'You are the Web3Edu AI Mentor. Using the learner profile provided, suggest exactly 3 short, specific actions for today. One line each, no preamble.',
        'ai_mentor_motivation' => 'You are the Web3Edu AI Mentor. Using the learner profile provided, write ONE short, specific motivational sentence referencing their actual progress or streak. Under 30 words.',
        'ai_mentor_quiz_review' => 'You are the Web3Edu AI Mentor. The learner just failed a quiz. For each wrong answer, briefly explain why it was incorrect and suggest one lesson to review. Under 180 words. Be encouraging.',
        'ai_mentor_project_review' => 'You are the Web3Edu AI Mentor providing PRELIMINARY, SUPPLEMENTARY feedback on a project submission before human review. Be constructive and specific. Make clear this is AI feedback, not a final decision. Under 150 words.',
    ];

    public static function get(string $key): string
    {
        try {
            $db   = getDB();
            $stmt = $db->prepare('SELECT content FROM ai_prompts WHERE prompt_key = ? LIMIT 1');
            $stmt->execute([$key]);
            $row = $stmt->fetch();
            if ($row && trim((string)$row['content']) !== '') {
                return $row['content'];
            }
        } catch (\Throwable $e) {
            // Table may not exist yet — fall through to default
        }
        return self::DEFAULTS[$key] ?? '';
    }

    public static function set(string $key, string $label, string $content): bool
    {
        $db = getDB();
        $stmt = $db->prepare('
            INSERT INTO ai_prompts (prompt_key, label, content)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE content = VALUES(content), label = VALUES(label), updated_at = NOW()
        ');
        return $stmt->execute([$key, $label, $content]);
    }

    /** All prompts, for the admin Prompt Management UI. */
    public static function all(): array
    {
        try {
            $db = getDB();
            return $db->query('SELECT prompt_key, label, content, updated_at FROM ai_prompts ORDER BY label')->fetchAll();
        } catch (\Throwable $e) {
            return [];
        }
    }
}
