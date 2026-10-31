<?php
/**
 * ContentSearchInterface
 * ============================================================
 * Abstraction over "search Web3edu's own educational content".
 *
 * KeywordContentSearch (below) is the initial, always-available
 * implementation. The interface is deliberately small so a future
 * VectorContentSearch (embeddings + a vector DB) can be dropped in
 * as a straight swap — nothing above this layer (ContextBuilder,
 * AIService, any future AI feature) needs to change.
 * ============================================================
 */
interface ContentSearchInterface
{
    /**
     * @param string $query User's question / search text
     * @param int    $limit Max results to return
     * @param array  $types Optional filter: subset of ['lesson','guide','blog','video']
     * @return ContentResult[] Ranked best-first
     */
    public function search(string $query, int $limit = 5, array $types = []): array;
}

/**
 * ContentResult
 * ------------------------------------------------------------
 * One normalized hit from any content source, regardless of which
 * search implementation produced it.
 */
class ContentResult
{
    public function __construct(
        public string $type,      // 'lesson' | 'guide' | 'blog' | 'video'
        public int    $id,
        public string $title,
        public string $snippet,   // Trimmed body text used as AI context
        public float  $score,     // Relevance score, higher = better
        public string $url = '',  // Optional deep link, for citation/reference
        public ?string $guideTitle = null // Set when a lesson is being surfaced via a guide path
    ) {}

    /** Compact block fed into the AI prompt context. */
    public function toContextBlock(): string
    {
        $label = match ($this->type) {
            'lesson'   => 'Web3edu Lesson',
            'guide'    => 'Web3edu Guide',
            'blog'     => 'Web3edu Blog',
            'video'    => 'Web3edu Video',
            default    => 'Web3edu Content',
        };
        $suffix = $this->guideTitle ? " (from guide: {$this->guideTitle})" : '';
        return "[{$label}{$suffix}] {$this->title}\n{$this->snippet}";
    }
}
