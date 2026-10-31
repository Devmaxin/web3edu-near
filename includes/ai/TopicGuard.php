<?php
/**
 * TopicGuard
 * ============================================================
 * Decides whether a question belongs to Web3edu's AI Tutor at all,
 * BEFORE any content search or provider call happens. This is the
 * "Determine if the question is Web3-related" step in the AI
 * Knowledge Flow — deliberately cheap (no network call) so
 * off-topic questions never cost a token or a search query.
 *
 * Keyword-based by design (same philosophy as KeywordContentSearch):
 * a static core vocabulary of Web3/blockchain terms, expanded
 * dynamically with the platform's own lesson/guide/tag titles so new
 * terms taught on Web3Edu automatically become "in scope" without a
 * code change. (Previously sourced from the Glossary table, which has
 * been removed platform-wide in favor of the AI Tutor.)
 * ============================================================
 */
class TopicGuard
{
    /** Core allow-list — the topics explicitly called out as in-scope. */
    private const CORE_TOPICS = [
        'web3', 'web 3', 'blockchain', 'crypto', 'cryptocurrency', 'bitcoin', 'btc',
        'smart contract', 'solidity', 'rust', 'ethereum', 'eth', 'layer 1', 'layer1',
        'layer 2', 'layer2', 'l1', 'l2', 'wallet', 'metamask', 'nft', 'defi', 'dao',
        'tokenomics', 'token', 'consensus', 'proof of stake', 'proof of work', 'pos', 'pow',
        'mining', 'staking', 'validator', 'node', 'gas fee', 'gas', 'dapp', 'dapps',
        'ledger', 'hash', 'hashing', 'merkle', 'private key', 'public key', 'seed phrase',
        'mnemonic', 'airdrop', 'ico', 'ido', 'dex', 'cex', 'exchange', 'liquidity',
        'yield farming', 'amm', 'stablecoin', 'bridge', 'rollup', 'zk', 'zero knowledge',
        'evm', 'erc-20', 'erc20', 'erc-721', 'erc721', 'solana', 'polygon', 'arbitrum',
        'optimism', 'binance', 'bnb', 'avalanche', 'cardano', 'polkadot', 'chainlink',
        'oracle', 'multisig', 'cold wallet', 'hot wallet', 'hardware wallet', 'phishing',
        'rug pull', 'whitepaper', 'mainnet', 'testnet', 'fork', 'protocol', 'ipfs',
        'metaverse', 'dao governance', 'crypto trading', 'trading', 'market cap',
        'blockchain programming', 'web3 development', 'smart contracts', 'security audit',
        'reentrancy', 'security',
        // Careers/Opportunities — Web3Edu has a whole built-in Opportunities Hub
        // (jobs, grants, hackathons, bounties, ambassador programs, events,
        // fellowships, internships), so discussing any of these is squarely
        // in the Tutor's mission, not a tangent from it.
        'job', 'jobs', 'career', 'careers', 'hire', 'hiring', 'hired', 'employer',
        'employment', 'salary', 'freelance', 'freelancing', 'internship', 'fellowship',
        'grant', 'hackathon', 'bounty', 'bounties', 'ambassador', 'resume', 'cv',
        'cover letter', 'interview', 'portfolio', 'opportunity', 'opportunities',
        'get a job', 'find a job', 'this space', 'this industry', 'this field',
        // Learning-platform vocabulary — asking about lessons, guides, courses,
        // certificates, or what to study is inherently on-topic here even
        // without blockchain jargon in the same sentence.
        'lesson', 'guide', 'course', 'curriculum', 'roadmap', 'career path',
        'certificate', 'certification', 'quiz', 'assessment', 'project', 'skill',
        'skills', 'learn', 'learning', 'study', 'studying',
    ];

    /** Short greetings/pleasantries that should never be declined — let the model respond warmly and redirect. */
    private const GREETING_PATTERNS = [
        '/^\s*(hi|hello|hey|yo|sup)[\s!.,]*$/i',
        '/^\s*(thanks|thank you|thx|ty)[\s!.,]*$/i',
        '/^\s*(bye|goodbye|see ya)[\s!.,]*$/i',
        '/^\s*(who are you|what can you do|what is your name)[\s?!.,]*$/i',
    ];

    /**
     * "What should I learn / study next" style meta-questions. These carry
     * no Web3 jargon of their own (nothing for CORE_TOPICS to match), but
     * asking a tutor what to learn next is inherently on-topic on a learning
     * platform — the same reasoning GREETING_PATTERNS already uses for "hi"
     * or "who are you". Missing this category (not a stricter/weaker
     * keyword list) was the actual bug: a real first message like "what do
     * you think I should learn today?" had no path to ever match, so it was
     * declined instead of answered — but only on message #1 of a new
     * conversation, since the guard is skipped once history exists.
     */
    private const LEARNING_INTENT_PATTERNS = [
        '/\bi\s+(should|could|can)\s+(learn|study|start|begin|focus on|do)\b/i',      // "...I should learn..." (matches regardless of what precedes "I")
        '/\b(should|could|can)\s+i\s+(learn|study|start|begin)\b/i',                   // "should I learn..." (direct order)
        '/\bwhat\s+(do\s+you\s+)?(recommend|suggest)\b/i',
        '/\b(recommend|suggest)\b.{0,20}\b(lesson|guide|course|topic|path|project)\b/i', // "suggest a good project", "recommend some topic"
        '/\bwhat\'?s?\s+(is\s+)?next\b/i',                                             // "what's next" / "whats next" / "what is next"
        '/\bhelp\s+me\s+(choose|pick|decide|learn|study)\b/i',
        '/\b(study|learning)\s+plan\b/i',
        '/\bwhere\s+(should|do|can)\s+i\s+(start|begin)\b/i',
    ];

    private static ?array $platformTerms = null;

    public static function isOnTopic(string $question): bool
    {
        $q = strtolower(trim($question));
        if ($q === '') return false;

        foreach (self::GREETING_PATTERNS as $pattern) {
            if (preg_match($pattern, $q)) return true;
        }

        foreach (self::LEARNING_INTENT_PATTERNS as $pattern) {
            if (preg_match($pattern, $q)) return true;
        }

        foreach (self::CORE_TOPICS as $topic) {
            if (str_contains($q, $topic)) return true;
        }

        foreach (self::platformTerms() as $term) {
            if ($term !== '' && str_contains($q, $term)) return true;
        }

        return false;
    }

    public static function declineMessage(): string
    {
        return "I'm the Web3Edu AI Tutor, so I specialize in Web3 and blockchain education. Please ask me anything about blockchain, crypto, smart contracts, wallets, Solidity, Web3 development, or related topics.";
    }

    /**
     * Vocabulary drawn from what the platform actually teaches (lesson
     * titles, guide titles, tags), lowercased and cached for the request.
     * Extends the static topic list with whatever's currently being taught.
     */
    private static function platformTerms(): array
    {
        if (self::$platformTerms !== null) {
            return self::$platformTerms;
        }
        try {
            $db = getDB();
            $terms = [];
            $terms = array_merge($terms, $db->query("SELECT title FROM lessons WHERE deleted_at IS NULL")->fetchAll(PDO::FETCH_COLUMN));
            $terms = array_merge($terms, $db->query("SELECT title FROM guide_paths WHERE deleted_at IS NULL")->fetchAll(PDO::FETCH_COLUMN));
            $terms = array_merge($terms, $db->query("SELECT name FROM tags")->fetchAll(PDO::FETCH_COLUMN));
            self::$platformTerms = array_map(fn($t) => strtolower(trim($t)), $terms);
        } catch (\Throwable $e) {
            self::$platformTerms = [];
        }
        return self::$platformTerms;
    }
}
