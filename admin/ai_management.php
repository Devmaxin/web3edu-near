<?php
$adminTitle = 'AI Management';
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/../includes/ai/AIServiceFactory.php';
require_once __DIR__ . '/../includes/ai/PromptManager.php';

$db     = getDB();
$errors = [];
$saved  = null;
$testResult = null;

// ── Handle POST ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $upsert = function (string $key, string $val) use ($db) {
        $db->prepare("INSERT INTO site_settings (setting_key, setting_val) VALUES (?, ?)
                      ON DUPLICATE KEY UPDATE setting_val = VALUES(setting_val), updated_at = NOW()")
           ->execute([$key, $val]);
    };

    if (isset($_POST['test_connection'])) {
        // Exercises the EXACT same path a real AI Tutor chat uses
        // (AIServiceFactory -> provider adapter -> HTTP call) with the
        // config as currently saved in site_settings, bypassing rate
        // limits/topic guard/context search entirely. This is the fastest
        // way to tell "wrong key" apart from "wrong model" apart from
        // "server can't reach the provider at all" — the chat widget's
        // generic user-facing message deliberately never reveals which one.
        $start = microtime(true);
        try {
            $adapter = AIServiceFactory::make();
            $result  = $adapter->chat(
                [['role' => 'user', 'content' => 'Reply with exactly: OK']],
                ['max_tokens' => 10, 'temperature' => 0]
            );
            $testResult = [
                'success'   => true,
                'provider'  => $adapter->getName(),
                'model'     => getSetting('ai_model', ''),
                'reply'     => $result['content'],
                'ms'        => round((microtime(true) - $start) * 1000),
            ];
        } catch (\Throwable $e) {
            $testResult = [
                'success'  => false,
                'provider' => getSetting('ai_provider', 'groq'),
                'model'    => getSetting('ai_model', ''),
                'error'    => $e->getMessage(),
                'ms'       => round((microtime(true) - $start) * 1000),
            ];
        }
    }

    if (isset($_POST['save_provider'])) {
        $provider = trim($_POST['ai_provider'] ?? 'groq');
        if (!in_array($provider, AIServiceFactory::SUPPORTED_PROVIDERS, true)) {
            $errors[] = 'Unknown provider selected.';
        } else {
            $upsert('ai_provider', $provider);
            $upsert('ai_model', trim($_POST['ai_model'] ?? ''));
            $upsert('ai_request_timeout', (string)max(5, (int)($_POST['ai_request_timeout'] ?? 30)));
            $upsert('ai_api_key_groq',       trim($_POST['ai_api_key_groq']       ?? ''));
            $upsert('ai_api_key_openai',     trim($_POST['ai_api_key_openai']     ?? ''));
            $upsert('ai_api_key_gemini',     trim($_POST['ai_api_key_gemini']     ?? ''));
            $upsert('ai_api_key_anthropic',  trim($_POST['ai_api_key_anthropic']  ?? ''));
            $upsert('ai_api_key_openrouter', trim($_POST['ai_api_key_openrouter'] ?? ''));
            $upsert('ai_ollama_base_url',    trim($_POST['ai_ollama_base_url']    ?? ''));
            $saved = 'provider';
        }
    }

    if (isset($_POST['save_rate_limit'])) {
        $limit = (int)($_POST['ai_free_daily_chat_limit'] ?? 1);
        if ($limit < 0) $errors[] = 'Free daily question limit cannot be negative.';
        else { $upsert('ai_free_daily_chat_limit', (string)$limit); $saved = 'rate_limit'; }
    }

    if (isset($_POST['save_prompts'])) {
        PromptManager::set('ai_tutor_system',   'AI Tutor — System Prompt',         trim($_POST['ai_tutor_system']   ?? ''));
        PromptManager::set('study_card_system', 'AI Study Card — System Prompt',    trim($_POST['study_card_system'] ?? ''));
        $saved = 'prompts';
    }
}

// ── Load current config ─────────────────────────────────────
$provider     = getSetting('ai_provider', 'groq');
$model        = getSetting('ai_model', 'llama-3.3-70b-versatile');
$timeout      = (int)getSetting('ai_request_timeout', '30');

// Known Groq model retirements (see https://console.groq.com/docs/deprecations).
// Not exhaustive/auto-updating — just enough to stop the single most common
// "key is fine, model got retired" support case from looking like total
// AI Tutor failure with no clue why.
const GROQ_DEPRECATED_MODELS = [
    'llama-3.3-70b-versatile'                    => ['shutdown' => '2026-08-16', 'replacement' => 'openai/gpt-oss-120b'],
    'llama-3.1-8b-instant'                       => ['shutdown' => '2026-08-16', 'replacement' => 'openai/gpt-oss-20b'],
    'qwen/qwen3-32b'                             => ['shutdown' => '2026-07-17', 'replacement' => 'openai/gpt-oss-120b'],
    'meta-llama/llama-4-scout-17b-16e-instruct'  => ['shutdown' => '2026-07-17', 'replacement' => 'openai/gpt-oss-120b'],
    'meta-llama/llama-4-maverick-17b-128e-instruct' => ['shutdown' => '2026-03-09', 'replacement' => 'openai/gpt-oss-120b'],
    'mixtral-8x7b-32768'                         => ['shutdown' => '2025-03-20', 'replacement' => 'llama-3.3-70b-versatile'],
    'llama3-70b-8192'                            => ['shutdown' => '2025-08-30', 'replacement' => 'llama-3.3-70b-versatile'],
    'llama3-8b-8192'                             => ['shutdown' => '2025-08-30', 'replacement' => 'llama-3.1-8b-instant'],
];
$modelDeprecation = ($provider === 'groq') ? (GROQ_DEPRECATED_MODELS[$model] ?? null) : null;
if ($modelDeprecation) {
    $isRetired = strtotime($modelDeprecation['shutdown']) <= time();
    $errors[] = ($isRetired ? "The configured model \"{$model}\" has already been retired by Groq (shutdown {$modelDeprecation['shutdown']}) — every AI Tutor request is currently failing because of this. "
                             : "The configured model \"{$model}\" is deprecated and will stop working on {$modelDeprecation['shutdown']}. ")
              . "Switch to \"{$modelDeprecation['replacement']}\" below.";
}
$keys = [
    'groq'       => getSetting('ai_api_key_groq', ''),
    'openai'     => getSetting('ai_api_key_openai', ''),
    'gemini'     => getSetting('ai_api_key_gemini', ''),
    'anthropic'  => getSetting('ai_api_key_anthropic', ''),
    'openrouter' => getSetting('ai_api_key_openrouter', ''),
];
$ollamaUrl    = getSetting('ai_ollama_base_url', 'http://localhost:11434');
$freeLimit    = (int)getSetting('ai_free_daily_chat_limit', '1');
$prompts      = PromptManager::all();
$promptByKey  = [];
foreach ($prompts as $p) { $promptByKey[$p['prompt_key']] = $p; }
$tutorPrompt  = $promptByKey['ai_tutor_system']['content']   ?? PromptManager::get('ai_tutor_system');
$cardPrompt   = $promptByKey['study_card_system']['content'] ?? PromptManager::get('study_card_system');

// ── Usage statistics ────────────────────────────────────────
$stats = [
    'study_cards'   => 0, 'ai_chats' => 0, 'premium_chats' => 0, 'free_chats' => 0,
    'tokens'        => 0, 'cost' => 0.0, 'errors' => 0,
];
try {
    $stats['study_cards']   = (int)$db->query("SELECT COUNT(*) FROM ai_usage_log WHERE type='study_card' AND success=1")->fetchColumn();
    $stats['ai_chats']      = (int)$db->query("SELECT COUNT(*) FROM ai_usage_log WHERE type='chat' AND success=1")->fetchColumn();
    $stats['premium_chats'] = (int)$db->query("SELECT COUNT(*) FROM ai_usage_log WHERE type='chat' AND success=1 AND membership_type='premium'")->fetchColumn();
    $stats['free_chats']    = (int)$db->query("SELECT COUNT(*) FROM ai_usage_log WHERE type='chat' AND success=1 AND membership_type='free'")->fetchColumn();
    $stats['tokens']        = (int)$db->query("SELECT COALESCE(SUM(tokens_input+tokens_output),0) FROM ai_usage_log")->fetchColumn();
    $stats['cost']          = (float)$db->query("SELECT COALESCE(SUM(cost_estimated),0) FROM ai_usage_log")->fetchColumn();
    $stats['errors']        = (int)$db->query("SELECT COUNT(*) FROM ai_usage_log WHERE success=0")->fetchColumn();
    $logs = $db->query("SELECT * FROM ai_usage_log ORDER BY id DESC LIMIT 50")->fetchAll();
} catch (\Throwable $e) {
    $errors[] = 'AI tables not found yet. Please run database/migration_ai.sql first.';
    $logs = [];
}

function aiCard(string $title, string $sub, string $iconSvg): void {
    echo '<div style="padding:20px 24px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:12px;background:linear-gradient(90deg,rgba(139,92,246,0.08),transparent)">';
    echo '<div style="width:38px;height:38px;border-radius:10px;background:linear-gradient(135deg,var(--primary),var(--pink));display:flex;align-items:center;justify-content:center;flex-shrink:0">' . $iconSvg . '</div>';
    echo '<div><div style="font-weight:700;font-size:1rem">' . $title . '</div><div style="color:var(--text-muted);font-size:0.8rem;margin-top:2px">' . $sub . '</div></div>';
    echo '</div>';
}
$inputStyle = 'width:100%;padding:11px 16px;background:var(--bg-input);border:1.5px solid var(--border);border-radius:var(--radius);color:var(--text);font-size:0.9rem;font-family:var(--font-mono)';
$labelStyle = 'display:block;font-size:0.82rem;font-weight:600;margin-bottom:8px;color:var(--text)';
?>

<h1 class="admin-page-title">
    <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:middle;margin-right:8px;flex-shrink:0"><path d="M12 8V4H8"/><rect width="16" height="12" x="4" y="8" rx="2"/><path d="M2 14h2"/><path d="M20 14h2"/><path d="M15 13v2"/><path d="M9 13v2"/></svg>
    AI Management
</h1>
<p style="color:var(--text-muted);font-size:0.88rem;margin-bottom:24px;max-width:760px">
    Configure the active AI provider, model, prompts, rate limits, and review usage — all without touching code.
    Frontend (AI Tutor + AI Study Cards) never knows which provider is active; only this page decides.
</p>

<?php if ($saved): ?>
<div style="background:rgba(11,121,88,0.1);border:1px solid rgba(11,121,88,0.3);color:#0B7958;border-radius:var(--radius);padding:14px 18px;margin-bottom:24px;font-size:0.9rem">✓ Saved successfully.</div>
<?php endif; ?>
<?php if (!empty($errors)): ?>
<div style="background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.3);color:#fca5a5;border-radius:var(--radius);padding:14px 18px;margin-bottom:24px;font-size:0.9rem">
    <?php foreach ($errors as $e): ?><div><?= sanitize($e) ?></div><?php endforeach; ?>
</div>
<?php endif; ?>

<div style="display:grid;gap:24px;max-width:880px">

    <!-- ── AI PROVIDER & MODEL ──────────────────────────── -->
    <form method="POST">
    <div style="background:var(--bg-card);border:1.5px solid var(--border);border-radius:var(--radius-lg);overflow:hidden">
        <?php aiCard('AI Provider &amp; Model', 'Switch providers and models with a config change — no code or frontend changes needed.',
            '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.07 4.93a10 10 0 0 1 0 14.14M4.93 4.93a10 10 0 0 0 0 14.14"/></svg>'); ?>
        <div style="padding:24px;display:grid;gap:20px">

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px" class="resp-grid">
                <div>
                    <label style="<?= $labelStyle ?>">Active Provider</label>
                    <select name="ai_provider" style="<?= $inputStyle ?>">
                        <?php foreach (AIServiceFactory::SUPPORTED_PROVIDERS as $p): ?>
                        <option value="<?= $p ?>" <?= $provider === $p ? 'selected' : '' ?>><?= ucfirst($p) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label style="<?= $labelStyle ?>">Current Model</label>
                    <input type="text" name="ai_model" value="<?= sanitize($model) ?>" placeholder="e.g. llama-3.3-70b-versatile" style="<?= $inputStyle ?>">
                </div>
            </div>

            <div>
                <label style="<?= $labelStyle ?>">Request Timeout (seconds)</label>
                <input type="number" name="ai_request_timeout" value="<?= (int)$timeout ?>" min="5" max="120" style="<?= $inputStyle ?>;max-width:140px">
            </div>

            <div style="border-top:1px solid var(--border);padding-top:18px">
                <div style="font-size:0.78rem;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.06em;margin-bottom:14px">API Keys (per provider)</div>
                <div style="display:grid;gap:14px">
                    <div><label style="<?= $labelStyle ?>">Groq API Key</label><input type="password" name="ai_api_key_groq" value="<?= sanitize($keys['groq']) ?>" placeholder="gsk_…" style="<?= $inputStyle ?>"></div>
                    <div><label style="<?= $labelStyle ?>">OpenAI API Key</label><input type="password" name="ai_api_key_openai" value="<?= sanitize($keys['openai']) ?>" placeholder="sk-…" style="<?= $inputStyle ?>"></div>
                    <div><label style="<?= $labelStyle ?>">Google Gemini API Key</label><input type="password" name="ai_api_key_gemini" value="<?= sanitize($keys['gemini']) ?>" style="<?= $inputStyle ?>"></div>
                    <div><label style="<?= $labelStyle ?>">Anthropic API Key</label><input type="password" name="ai_api_key_anthropic" value="<?= sanitize($keys['anthropic']) ?>" placeholder="sk-ant-…" style="<?= $inputStyle ?>"></div>
                    <div><label style="<?= $labelStyle ?>">OpenRouter API Key</label><input type="password" name="ai_api_key_openrouter" value="<?= sanitize($keys['openrouter']) ?>" style="<?= $inputStyle ?>"></div>
                    <div><label style="<?= $labelStyle ?>">Ollama Base URL</label><input type="text" name="ai_ollama_base_url" value="<?= sanitize($ollamaUrl) ?>" placeholder="http://localhost:11434" style="<?= $inputStyle ?>"></div>
                </div>
            </div>

            <button type="submit" name="save_provider" value="1" class="btn-upgrade btn-sm" style="justify-self:start">Save AI Configuration</button>
        </div>
    </div>
    </form>

    <!-- ── TEST CONNECTION ──────────────────────────────── -->
    <form method="POST">
    <div style="background:var(--bg-card);border:1.5px solid var(--border);border-radius:var(--radius-lg);overflow:hidden">
        <?php aiCard('Test Connection', 'Sends one tiny live request through the exact same code path the AI Tutor uses, with whatever is currently saved above. Save your configuration first, then test it.',
            '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21.2 8A10 10 0 1 1 15.6 2.2"/><polyline points="22 4 12 14.01 9 11.01"/></svg>'); ?>
        <div style="padding:24px;display:grid;gap:16px">
            <div style="font-size:0.84rem;color:var(--text-muted)">
                Currently saved: <code style="color:var(--text)"><?= sanitize($provider) ?> / <?= sanitize($model) ?></code>
            </div>
            <button type="submit" name="test_connection" value="1" class="btn-upgrade btn-sm" style="justify-self:start">Send Test Message</button>

            <?php if ($testResult): ?>
            <div style="border-radius:var(--radius);padding:14px 16px;font-size:0.86rem;
                <?= $testResult['success']
                    ? 'background:rgba(11,121,88,0.1);border:1px solid rgba(11,121,88,0.3);color:#0B7958'
                    : 'background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.3);color:#fca5a5' ?>">
                <?php if ($testResult['success']): ?>
                    ✓ Success — <?= sanitize($testResult['provider']) ?> / <?= sanitize($testResult['model']) ?> replied in <?= (int)$testResult['ms'] ?>ms:
                    <div style="margin-top:6px;font-family:var(--font-mono);color:var(--text)"><?= sanitize($testResult['reply']) ?></div>
                <?php else: ?>
                    ✗ Failed — <?= sanitize($testResult['provider']) ?> / <?= sanitize($testResult['model']) ?> (<?= (int)$testResult['ms'] ?>ms):
                    <div style="margin-top:6px;font-family:var(--font-mono);color:var(--text)"><?= sanitize($testResult['error']) ?></div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
    </form>

    <!-- ── PROMPT MANAGEMENT ────────────────────────────── -->
    <form method="POST">
    <div style="background:var(--bg-card);border:1.5px solid var(--border);border-radius:var(--radius-lg);overflow:hidden">
        <?php aiCard('Prompt Management', 'Edit the system prompts used by the AI Tutor and AI Study Cards. Changes apply immediately, no deploy needed.',
            '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5v-15A2.5 2.5 0 0 1 6.5 2H20v20H6.5a2.5 2.5 0 0 1 0-5H20"/></svg>'); ?>
        <div style="padding:24px;display:grid;gap:20px">
            <div>
                <label style="<?= $labelStyle ?>">AI Tutor — System Prompt</label>
                <textarea name="ai_tutor_system" rows="5" style="<?= $inputStyle ?>;resize:vertical"><?= sanitize($tutorPrompt) ?></textarea>
            </div>
            <div>
                <label style="<?= $labelStyle ?>">AI Study Card — System Prompt</label>
                <textarea name="study_card_system" rows="7" style="<?= $inputStyle ?>;resize:vertical"><?= sanitize($cardPrompt) ?></textarea>
                <div style="font-size:0.76rem;color:var(--text-muted);margin-top:6px">Must keep the model responding with JSON <code>{"bullets":[...]}</code> for the flashcard to render correctly.</div>
            </div>
            <button type="submit" name="save_prompts" value="1" class="btn-upgrade btn-sm" style="justify-self:start">Save Prompts</button>
        </div>
    </div>
    </form>

    <!-- ── RATE LIMITS ──────────────────────────────────── -->
    <form method="POST">
    <div style="background:var(--bg-card);border:1.5px solid var(--border);border-radius:var(--radius-lg);overflow:hidden">
        <?php aiCard('Rate Limits', 'Free vs Premium AI Tutor question limits. Study Cards always stay unlimited and free.',
            '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>'); ?>
        <div style="padding:24px;display:grid;gap:16px">
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px" class="resp-grid">
                <div>
                    <label style="<?= $labelStyle ?>">Free — AI Questions / Day</label>
                    <input type="number" name="ai_free_daily_chat_limit" value="<?= (int)$freeLimit ?>" min="0" max="50" style="<?= $inputStyle ?>">
                </div>
                <div>
                    <label style="<?= $labelStyle ?>">Premium — AI Questions / Day</label>
                    <input type="text" value="Unlimited" disabled style="<?= $inputStyle ?>;opacity:0.6">
                </div>
                <div>
                    <label style="<?= $labelStyle ?>">Study Cards (all users)</label>
                    <input type="text" value="Unlimited — always free" disabled style="<?= $inputStyle ?>;opacity:0.6">
                </div>
            </div>
            <button type="submit" name="save_rate_limit" value="1" class="btn-upgrade btn-sm" style="justify-self:start">Save Rate Limits</button>
        </div>
    </div>
    </form>

    <!-- ── USAGE STATISTICS ─────────────────────────────── -->
    <div style="background:var(--bg-card);border:1.5px solid var(--border);border-radius:var(--radius-lg);overflow:hidden">
        <?php aiCard('Usage Statistics', 'All-time AI feature usage across the platform.',
            '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>'); ?>
        <div style="padding:24px;display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:16px">
            <?php
            $statCards = [
                ['Study Cards Generated', $stats['study_cards']],
                ['AI Chats',              $stats['ai_chats']],
                ['Premium Chats',         $stats['premium_chats']],
                ['Free Chats',            $stats['free_chats']],
                ['Estimated Tokens',      number_format($stats['tokens'])],
                ['Estimated Cost',        '$' . number_format($stats['cost'], 4)],
                ['Failed Requests',       $stats['errors']],
            ];
            foreach ($statCards as [$label, $val]): ?>
            <div style="background:var(--bg-mid,rgba(255,255,255,0.03));border:1px solid var(--border);border-radius:var(--radius);padding:16px">
                <div style="font-size:0.7rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.06em;margin-bottom:6px"><?= $label ?></div>
                <div style="font-family:var(--font-head);font-size:1.4rem;font-weight:800;color:var(--primary-bright)"><?= $val ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- ── LOGS ──────────────────────────────────────────── -->
    <div style="background:var(--bg-card);border:1.5px solid var(--border);border-radius:var(--radius-lg);overflow:hidden">
        <?php aiCard('Recent Logs', 'Last 50 AI requests — successes and failures.',
            '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5v-15A2.5 2.5 0 0 1 6.5 2H20v20H6.5a2.5 2.5 0 0 1 0-5H20"/><path d="M18 14h-8"/><path d="M15 18h-5"/></svg>'); ?>
        <div style="padding:8px 0;overflow-x:auto">
            <table style="width:100%;border-collapse:collapse;font-size:0.8rem;min-width:760px">
                <thead>
                    <tr style="text-align:left;color:var(--text-muted);border-bottom:1px solid var(--border)">
                        <th style="padding:10px 18px">Time</th><th>Type</th><th>Plan</th><th>Provider / Model</th><th>Tokens</th><th>Cost</th><th>Status</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($logs)): ?>
                    <tr><td colspan="7" style="padding:18px;text-align:center;color:var(--text-muted)">No AI activity logged yet.</td></tr>
                <?php endif; ?>
                <?php foreach ($logs as $log): ?>
                    <tr style="border-bottom:1px solid var(--border-subtle)">
                        <td style="padding:9px 18px;color:var(--text-muted);white-space:nowrap"><?= timeAgo($log['created_at']) ?></td>
                        <td><?= $log['type'] === 'study_card' ? '🧠 Study Card' : '💬 Chat' ?></td>
                        <td><?= $log['membership_type'] === 'premium' ? '⭐ Premium' : 'Free' ?></td>
                        <td style="font-family:var(--font-mono);font-size:0.76rem"><?= sanitize(($log['provider'] ?? '—') . ' / ' . ($log['model'] ?? '—')) ?></td>
                        <td><?= (int)$log['tokens_input'] + (int)$log['tokens_output'] ?></td>
                        <td>$<?= number_format((float)$log['cost_estimated'], 5) ?></td>
                        <td>
                            <?php if ($log['success']): ?>
                                <span style="color:#0B7958">✓ OK</span>
                            <?php else: ?>
                                <span style="color:#f87171">✗ Error</span>
                                <?php if (!empty($log['error_message'])): ?>
                                <div style="font-size:0.72rem;color:var(--text-muted);margin-top:2px;max-width:280px;white-space:normal"><?= sanitize($log['error_message']) ?></div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<style>
@media (max-width: 640px) { .resp-grid { grid-template-columns: 1fr !important; } }
</style>

<?php require_once __DIR__ . '/footer.php'; ?>
