<?php
require_once __DIR__ . '/search/ContentSearchInterface.php';
require_once __DIR__ . '/search/KeywordContentSearch.php';

/**
 * ContentSearchService
 * ============================================================
 * Single entry point the rest of the app uses to search Web3edu's
 * own educational content (lessons, guides, blog, videos).
 *
 * Reusable for ANY future AI feature — not just the AI Tutor.
 *
 * Which underlying ContentSearchInterface implementation runs is a
 * config value (`ai_search_engine` in site_settings), exactly like
 * AIServiceFactory picks the LLM provider. Today only 'keyword' exists.
 * When a semantic/embeddings search is built later, it becomes
 * 'vector' here — nothing else in the codebase changes.
 * ============================================================
 */
class ContentSearchService
{
    private static ?ContentSearchInterface $engine = null;

    public static function engine(): ContentSearchInterface
    {
        if (self::$engine !== null) {
            return self::$engine;
        }

        $mode = getSetting('ai_search_engine', 'keyword');
        self::$engine = match ($mode) {
            // 'vector' => new VectorContentSearch(...),  // future: embeddings + vector DB
            default => new KeywordContentSearch(),
        };
        return self::$engine;
    }

    /**
     * @param string $query
     * @param int    $limit
     * @param array  $types Optional filter: subset of ['lesson','guide','blog','video']
     * @return ContentResult[]
     */
    public static function search(string $query, int $limit = 5, array $types = []): array
    {
        try {
            return self::engine()->search($query, $limit, $types);
        } catch (\Throwable $e) {
            return []; // search must never break the AI Tutor — worst case, it falls back to general knowledge
        }
    }
}
