<?php
declare(strict_types=1);

/**
 * ViralKeno AI newsroom module.
 *
 * AI can research and create DRAFTS only. It never uploads an image and never
 * publishes an article. Human review + featured image are required before
 * publishing (enforced in admin/index.php).
 */

function ai_schema(): void
{
    static $ready = false;
    if ($ready) return;

    db()->exec("CREATE TABLE IF NOT EXISTS ai_runs (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        slot_key VARCHAR(64) NOT NULL,
        provider VARCHAR(32) NOT NULL,
        model VARCHAR(190) NOT NULL,
        status VARCHAR(24) NOT NULL,
        article_id BIGINT UNSIGNED NULL,
        message TEXT NULL,
        started_at DATETIME NOT NULL,
        finished_at DATETIME NULL,
        PRIMARY KEY (id),
        KEY idx_ai_runs_slot_date (slot_key, started_at),
        KEY idx_ai_runs_article (article_id),
        KEY idx_ai_runs_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    db()->exec("CREATE TABLE IF NOT EXISTS ai_draft_meta (
        article_id BIGINT UNSIGNED NOT NULL,
        provider VARCHAR(32) NOT NULL,
        model VARCHAR(190) NOT NULL,
        source_links LONGTEXT NULL,
        research_notes LONGTEXT NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY (article_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $ready = true;
}

function ai_save_setting(string $key, string $value): void
{
    db()->prepare('INSERT INTO settings(setting_key,setting_value,updated_at) VALUES(?,?,NOW()) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_at=NOW()')
        ->execute([$key, $value]);
}

function ai_default_model(string $provider): string
{
    return match ($provider) {
        'openai' => 'gpt-5.6-luna',
        'groq' => 'groq/compound',
        default => 'gemini-3.8-flash',
    };
}

function ai_crypto_key(): string
{
    $material = (string)cfg('db.pass', '') . '|' . (string)cfg('app.url', '') . '|' . (string)cfg('app.session_name', 'viralkeno_session');
    return hash('sha256', $material, true);
}

function ai_encrypt_secret(string $plain): string
{
    if ($plain === '') return '';
    $key = ai_crypto_key();

    if (function_exists('sodium_crypto_secretbox')) {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($plain, $nonce, $key);
        return 's1:' . base64_encode($nonce . $cipher);
    }

    if (function_exists('openssl_encrypt')) {
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) throw new RuntimeException('Secret encryption failed.');
        return 'o1:' . base64_encode($iv . $tag . $cipher);
    }

    throw new RuntimeException('Server-এ Sodium বা OpenSSL encryption extension দরকার।');
}

function ai_decrypt_secret(string $stored): string
{
    if ($stored === '') return '';
    $key = ai_crypto_key();

    try {
        if (str_starts_with($stored, 's1:') && function_exists('sodium_crypto_secretbox_open')) {
            $raw = base64_decode(substr($stored, 3), true);
            if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) return '';
            $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $cipher = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $plain = sodium_crypto_secretbox_open($cipher, $nonce, $key);
            return $plain === false ? '' : $plain;
        }
        if (str_starts_with($stored, 'o1:') && function_exists('openssl_decrypt')) {
            $raw = base64_decode(substr($stored, 3), true);
            if ($raw === false || strlen($raw) <= 28) return '';
            $iv = substr($raw, 0, 12);
            $tag = substr($raw, 12, 16);
            $cipher = substr($raw, 28);
            $plain = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
            return $plain === false ? '' : $plain;
        }
    } catch (Throwable $e) {
        error_log('ViralKeno AI decrypt error: ' . $e->getMessage());
    }
    return '';
}

function ai_secret(string $key): string
{
    return ai_decrypt_secret((string)setting($key, ''));
}

function ai_provider_config(?array $override = null): array
{
    $provider = strtolower(trim((string)($override['provider'] ?? setting('ai_provider', 'gemini'))));
    if (!in_array($provider, ['gemini', 'openai', 'groq'], true)) $provider = 'gemini';
    $model = trim((string)($override['model'] ?? setting('ai_model', ai_default_model($provider))));
    if ($model === '') $model = ai_default_model($provider);

    $postedKey = (string)($override['api_key'] ?? '');
    $postedUser = (string)($override['api_username'] ?? '');
    $postedPass = (string)($override['api_password'] ?? '');

    return [
        'provider' => $provider,
        'model' => $model,
        'api_key' => $postedKey !== '' ? $postedKey : ai_secret('ai_api_key'),
        'api_username' => $postedUser !== '' ? $postedUser : ai_secret('ai_api_username'),
        'api_password' => $postedPass !== '' ? $postedPass : ai_secret('ai_api_password'),
    ];
}

function ai_http_json(string $url, array $payload, array $headers = [], int $timeout = 55): array
{
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $baseHeaders = ['Content-Type: application/json', 'Accept: application/json', 'User-Agent: ViralKeno-AI/1.0'];
    $headers = array_merge($baseHeaders, $headers);

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $response = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($response === false || $errno) throw new RuntimeException('AI API connection failed: ' . ($error ?: 'cURL error ' . $errno));
    } else {
        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $headers),
            'content' => $body,
            'timeout' => $timeout,
            'ignore_errors' => true,
        ]]);
        $response = @file_get_contents($url, false, $context);
        if ($response === false) throw new RuntimeException('AI API connection failed. PHP cURL/HTTPS outbound access check করুন।');
        $status = 0;
        foreach (($http_response_header ?? []) as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) $status = (int)$m[1];
        }
    }

    $decoded = json_decode((string)$response, true);
    if (!is_array($decoded)) throw new RuntimeException('AI API invalid JSON response দিয়েছে.');
    if ($status >= 400) {
        $message = (string)($decoded['error']['message'] ?? $decoded['message'] ?? ('HTTP ' . $status));
        throw new RuntimeException('AI API error: ' . mb_substr($message, 0, 500));
    }
    return $decoded;
}

function ai_extract_openai_text(array $response): string
{
    if (!empty($response['output_text']) && is_string($response['output_text'])) return trim($response['output_text']);
    $text = '';
    foreach (($response['output'] ?? []) as $item) {
        if (($item['type'] ?? '') !== 'message') continue;
        foreach (($item['content'] ?? []) as $part) {
            if (isset($part['text']) && is_string($part['text'])) $text .= $part['text'];
        }
    }
    return trim($text);
}

function ai_call_provider(string $prompt, bool $useWebSearch = true, ?array $override = null): array
{
    $cfg = ai_provider_config($override);
    if ($cfg['api_key'] === '') throw new RuntimeException('API key সেট করা হয়নি। AI Control থেকে API key save করুন।');

    if ($cfg['provider'] === 'gemini') {
        $payload = [
            'contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
            'generationConfig' => ['temperature' => 0.35],
        ];
        if ($useWebSearch) $payload['tools'] = [['google_search' => (object)[]]];
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($cfg['model']) . ':generateContent';
        $response = ai_http_json($url, $payload, ['x-goog-api-key: ' . $cfg['api_key']]);
        $text = '';
        foreach (($response['candidates'][0]['content']['parts'] ?? []) as $part) {
            if (isset($part['text']) && is_string($part['text'])) $text .= $part['text'];
        }
    } elseif ($cfg['provider'] === 'openai') {
        $payload = ['model' => $cfg['model'], 'input' => $prompt];
        if ($useWebSearch) $payload['tools'] = [['type' => 'web_search_preview', 'search_context_size' => 'medium']];
        $response = ai_http_json('https://api.openai.com/v1/responses', $payload, ['Authorization: Bearer ' . $cfg['api_key']]);
        $text = ai_extract_openai_text($response);
    } else {
        $payload = [
            'model' => $cfg['model'],
            'messages' => [['role' => 'user', 'content' => $prompt]],
            'temperature' => 0.35,
        ];

        // groq/compound and groq/compound-mini use server-side web search automatically.
        // Do NOT send citation_options to Compound models; Groq rejects that argument.
        if ($useWebSearch && str_starts_with($cfg['model'], 'openai/gpt-oss-')) {
            $payload['tools'] = [['type' => 'browser_search']];
            $payload['tool_choice'] = 'required';
        } elseif ($useWebSearch && !in_array($cfg['model'], ['groq/compound', 'groq/compound-mini'], true)) {
            throw new RuntimeException('Live news research-এর জন্য Groq model হিসেবে groq/compound, groq/compound-mini অথবা openai/gpt-oss-* ব্যবহার করুন।');
        }
        $response = ai_http_json('https://api.groq.com/openai/v1/chat/completions', $payload, ['Authorization: Bearer ' . $cfg['api_key']]);
        $text = trim((string)($response['choices'][0]['message']['content'] ?? ''));
    }

    if ($text === '') throw new RuntimeException('AI কোনো usable text return করেনি। Model/API setting check করুন।');
    return ['text' => $text, 'provider' => $cfg['provider'], 'model' => $cfg['model']];
}

function ai_extract_json(string $text): array
{
    $text = trim($text);
    $text = preg_replace('/^```(?:json)?\s*/i', '', $text) ?? $text;
    $text = preg_replace('/\s*```$/', '', $text) ?? $text;
    $start = strpos($text, '{');
    $end = strrpos($text, '}');
    if ($start !== false && $end !== false && $end >= $start) $text = substr($text, $start, $end - $start + 1);
    $data = json_decode($text, true);
    if (!is_array($data)) throw new RuntimeException('AI response JSON parse করা যায়নি। Run log দেখে model instruction adjust করুন।');
    return $data;
}

function ai_recent_story_context(): string
{
    $rows = query_all("SELECT title,source_url FROM articles ORDER BY created_at DESC LIMIT 40");
    if (!$rows) return 'No recent stories are stored yet.';
    $lines = [];
    foreach ($rows as $row) {
        $line = '- ' . trim((string)$row['title']);
        if (!empty($row['source_url'])) $line .= ' | ' . $row['source_url'];
        $lines[] = $line;
    }
    return implode("\n", $lines);
}

function ai_writing_rules(): string
{
    $default = <<<TXT
ViralKeno is a Bangla-first news/viral explainer website for Bangla-speaking readers, mainly Bangladesh.
Write natural Bangla with common English terms only where they sound normal.
Do not copy sentences from publishers. Use facts from multiple reliable sources and write an original article.
Prefer Bangladesh trending/breaking stories, social media trends, creators, entertainment, technology/AI, unusual internet stories and fact-checks.
Social media may be used as a trend signal, but factual claims must be verified with reliable sources when possible.
Never invent view counts, engagement numbers, quotes, dates, names or statistics. If unavailable, say the public count/details are unavailable.
Avoid sensational claims that cannot be verified.
The article must be a DRAFT for human review. Do not include or suggest an image inside the article body.
Use safe HTML only: p, h2, h3, h4, strong, em, ul, ol, li, blockquote, a.
TXT;
    return trim((string)setting('ai_writing_rules', $default));
}

function ai_news_prompt(string $duplicateNote = ''): string
{
    $categories = array_column(query_all('SELECT name FROM categories WHERE is_active=1 ORDER BY sort_order,name'), 'name');
    $now = date('Y-m-d H:i:s T');
    $categoryText = $categories ? implode(', ', $categories) : 'Bangladesh, Social Media, Entertainment, Technology & AI, Fact Check';
    $duplicateText = $duplicateNote !== '' ? "\nIMPORTANT: Previous selection was a duplicate. Choose a clearly different story. {$duplicateNote}\n" : '';
    $rules = ai_writing_rules();
    $recent = ai_recent_story_context();

    return <<<PROMPT
You are the newsroom research-and-draft assistant for ViralKeno.
Current newsroom time: {$now} (Asia/Dhaka).

TASK
Search the live web and choose ONE fresh, high-interest news/trend story suitable for ViralKeno right now. Cross-check with at least two reliable sources when possible. Official/primary sources have highest priority. If a claim cannot be verified, do not present it as fact.
{$duplicateText}
SITE WRITING RULES
{$rules}

AVAILABLE CATEGORIES
{$categoryText}

RECENT VIRALKENO STORIES — DO NOT DUPLICATE
{$recent}

Return ONLY one valid JSON object. No Markdown fence and no commentary outside JSON.
Use exactly these keys:
{
  "title": "Bangla-first headline",
  "excerpt": "short 1-2 sentence excerpt",
  "body_html": "complete original safe HTML article, no image tags",
  "category": "one category from the available category list",
  "tags": ["tag1", "tag2", "tag3"],
  "content_label": "প্রতিবেদন or Fact Check or Explainer",
  "source_name": "primary/most authoritative source name",
  "source_url": "primary source URL",
  "source_links": ["https://...", "https://..."],
  "fact_check_rating": "max 50 characters; empty if not a fact-check",
  "reporter_disclosure": "brief editor/research disclosure",
  "correction_note": "empty unless a correction note is actually needed",
  "seo_title": "SEO title",
  "meta_description": "SEO meta description under 320 characters",
  "research_notes": "private concise notes for the human reviewer: what was verified, uncertainties, and why this story was selected"
}

Hard rules: never publish; never include an image; never use an image URL; source_url must be a valid http/https URL; source_links must contain the URLs actually used for verification.
PROMPT;
}

function ai_normalize_title(string $title): string
{
    $title = mb_strtolower(trim($title));
    $title = preg_replace('/[^\p{L}\p{N}]+/u', '', $title) ?? $title;
    return $title;
}

function ai_is_duplicate(string $title, ?string $sourceUrl): bool
{
    if ($sourceUrl && query_one('SELECT id FROM articles WHERE source_url=? LIMIT 1', [$sourceUrl])) return true;
    $needle = ai_normalize_title($title);
    if ($needle === '') return false;
    foreach (query_all('SELECT title FROM articles ORDER BY created_at DESC LIMIT 100') as $row) {
        if (ai_normalize_title((string)$row['title']) === $needle) return true;
    }
    return false;
}

function ai_pick_category_id(string $name): int
{
    $name = trim($name);
    if ($name !== '') {
        $row = query_one('SELECT id FROM categories WHERE is_active=1 AND (LOWER(name)=LOWER(?) OR LOWER(slug)=LOWER(?)) LIMIT 1', [$name, slugify($name)]);
        if ($row) return (int)$row['id'];
    }
    $fallback = (int)setting('ai_fallback_category_id', '0');
    if ($fallback && query_one('SELECT id FROM categories WHERE id=? AND is_active=1', [$fallback])) return $fallback;
    return (int)(query_one('SELECT id FROM categories WHERE is_active=1 ORDER BY sort_order,name LIMIT 1')['id'] ?? 0);
}

function ai_pick_author_id(): int
{
    $preferred = (int)setting('ai_author_id', '0');
    if ($preferred && query_one('SELECT id FROM authors WHERE id=? AND is_active=1', [$preferred])) return $preferred;
    return (int)(query_one('SELECT id FROM authors WHERE is_active=1 ORDER BY id LIMIT 1')['id'] ?? 0);
}

function ai_clean_body(string $html): string
{
    // AI drafts must not carry images. Human editors add the featured image manually.
    $html = preg_replace('/<img\b[^>]*>/i', '', $html) ?? $html;
    return sanitize_html($html);
}

function ai_daily_draft_count(): int
{
    return (int)(query_one("SELECT COUNT(*) n FROM ai_runs WHERE status='success' AND article_id IS NOT NULL AND DATE(started_at)=CURDATE()")['n'] ?? 0);
}

function ai_log_start(string $slotKey, string $provider, string $model): int
{
    db()->prepare("INSERT INTO ai_runs(slot_key,provider,model,status,started_at) VALUES(?,?,?,'running',NOW())")
        ->execute([$slotKey, $provider, $model]);
    return (int)db()->lastInsertId();
}

function ai_log_finish(int $runId, string $status, ?int $articleId, string $message): void
{
    db()->prepare('UPDATE ai_runs SET status=?,article_id=?,message=?,finished_at=NOW() WHERE id=?')
        ->execute([$status, $articleId, mb_substr($message, 0, 5000), $runId]);
}

function ai_create_article_from_payload(array $data, string $provider, string $model): int
{
    $title = trim((string)($data['title'] ?? ''));
    $excerpt = trim((string)($data['excerpt'] ?? ''));
    $body = ai_clean_body((string)($data['body_html'] ?? ''));
    $sourceName = trim((string)($data['source_name'] ?? ''));
    $sourceUrlRaw = trim((string)($data['source_url'] ?? ''));
    $sourceUrl = filter_var($sourceUrlRaw, FILTER_VALIDATE_URL) ? $sourceUrlRaw : null;

    if (mb_strlen($title) < 5 || mb_strlen($excerpt) < 20 || mb_strlen(strip_tags($body)) < 80) {
        throw new RuntimeException('AI draft-এর title/excerpt/body অসম্পূর্ণ।');
    }
    if (!$sourceUrl || $sourceName === '') throw new RuntimeException('AI valid primary source name/URL দেয়নি।');
    if (ai_is_duplicate($title, $sourceUrl)) throw new RuntimeException('DUPLICATE_STORY');

    $categoryId = ai_pick_category_id((string)($data['category'] ?? ''));
    $authorId = ai_pick_author_id();
    if (!$categoryId || !$authorId) throw new RuntimeException('Active category/author পাওয়া যায়নি। AI settings থেকে fallback category ও author সেট করুন।');

    $slug = unique_slug('articles', $title);
    $factRating = mb_substr(trim((string)($data['fact_check_rating'] ?? '')), 0, 50);
    $contentLabel = mb_substr(trim((string)($data['content_label'] ?? 'প্রতিবেদন')), 0, 80);
    $seoTitle = mb_substr(trim((string)($data['seo_title'] ?? $title)), 0, 255);
    $metaDescription = mb_substr(trim((string)($data['meta_description'] ?? $excerpt)), 0, 320);

    $stmt = db()->prepare("INSERT INTO articles(
        author_id,category_id,title,slug,excerpt,body,featured_image,image_alt,image_caption,content_label,
        source_name,source_url,embed_url,fact_check_rating,reporter_disclosure,correction_note,seo_title,meta_description,
        status,is_featured,is_trending,published_at,created_at,updated_at
    ) VALUES(?,?,?,?,?,?,NULL,'','',?,?,?,?,?,?,?,?,?,'draft',0,0,NULL,NOW(),NOW())");

    $stmt->execute([
        $authorId,
        $categoryId,
        $title,
        $slug,
        $excerpt,
        $body,
        $contentLabel,
        $sourceName,
        $sourceUrl,
        null,
        $factRating,
        trim((string)($data['reporter_disclosure'] ?? '')),
        trim((string)($data['correction_note'] ?? '')),
        $seoTitle,
        $metaDescription,
    ]);
    $articleId = (int)db()->lastInsertId();

    $tags = $data['tags'] ?? [];
    if (is_string($tags)) $tags = explode(',', $tags);
    if (is_array($tags)) {
        $tagNames = array_slice(array_values(array_filter(array_unique(array_map(static fn($v) => trim((string)$v), $tags)))), 0, 12);
        foreach ($tagNames as $tagName) {
            if ($tagName === '') continue;
            $tag = query_one('SELECT id FROM tags WHERE name=? LIMIT 1', [$tagName]);
            if (!$tag) {
                $tagSlug = unique_slug('tags', $tagName);
                db()->prepare('INSERT INTO tags(name,slug,created_at) VALUES(?,?,NOW())')->execute([$tagName, $tagSlug]);
                $tagId = (int)db()->lastInsertId();
            } else {
                $tagId = (int)$tag['id'];
            }
            db()->prepare('INSERT IGNORE INTO article_tags(article_id,tag_id) VALUES(?,?)')->execute([$articleId, $tagId]);
        }
    }

    $links = $data['source_links'] ?? [];
    if (!is_array($links)) $links = [];
    $cleanLinks = [];
    foreach ($links as $link) {
        $link = trim((string)$link);
        if (filter_var($link, FILTER_VALIDATE_URL)) $cleanLinks[] = $link;
    }
    if (!in_array($sourceUrl, $cleanLinks, true)) array_unshift($cleanLinks, $sourceUrl);

    db()->prepare('INSERT INTO ai_draft_meta(article_id,provider,model,source_links,research_notes,created_at) VALUES(?,?,?,?,?,NOW())')
        ->execute([$articleId, $provider, $model, json_encode(array_values(array_unique($cleanLinks)), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), trim((string)($data['research_notes'] ?? ''))]);

    return $articleId;
}

function ai_generate_draft(string $slotKey = 'manual'): array
{
    ai_schema();
    if ((string)setting('ai_enabled', '0') !== '1') return ['status' => 'skipped', 'message' => 'AI automation disabled.'];
    if (ai_daily_draft_count() >= 5) return ['status' => 'skipped', 'message' => 'আজকের ৫টি AI draft limit পূর্ণ হয়েছে।'];

    $cfg = ai_provider_config();
    $runId = ai_log_start($slotKey, $cfg['provider'], $cfg['model']);

    try {
        $duplicateNote = '';
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $result = ai_call_provider(ai_news_prompt($duplicateNote), true);
            $payload = ai_extract_json($result['text']);
            try {
                $articleId = ai_create_article_from_payload($payload, $result['provider'], $result['model']);
                ai_log_finish($runId, 'success', $articleId, 'Draft created successfully. Human review and featured image required before publish.');
                return ['status' => 'success', 'article_id' => $articleId, 'message' => 'AI draft তৈরি হয়েছে।'];
            } catch (RuntimeException $e) {
                if ($e->getMessage() !== 'DUPLICATE_STORY') throw $e;
                $duplicateNote = 'The title or primary source matched an existing ViralKeno article.';
            }
        }
        ai_log_finish($runId, 'skipped', null, 'Duplicate story selected twice; no draft created.');
        return ['status' => 'skipped', 'message' => 'Duplicate story পাওয়া গেছে; draft তৈরি হয়নি।'];
    } catch (Throwable $e) {
        ai_log_finish($runId, 'failed', null, $e->getMessage());
        return ['status' => 'failed', 'message' => $e->getMessage()];
    }
}

function ai_schedule_slots(): array
{
    $defaults = ['08:00', '12:00', '16:00', '19:00', '22:00'];
    $slots = [];
    for ($i = 1; $i <= 5; $i++) {
        $time = trim((string)setting('ai_schedule_' . $i, $defaults[$i - 1]));
        if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time)) $time = $defaults[$i - 1];
        $slots[] = [
            'key' => 'slot' . $i,
            'label' => 'Slot ' . $i,
            'time' => $time,
            'enabled' => (string)setting('ai_schedule_' . $i . '_enabled', '1') === '1',
        ];
    }
    return $slots;
}

function ai_slot_done_today(string $slotKey): bool
{
    return (bool)query_one("SELECT id FROM ai_runs WHERE slot_key=? AND status IN ('success','skipped','failed') AND DATE(started_at)=CURDATE() LIMIT 1", [$slotKey]);
}

function ai_run_due_slots(): array
{
    ai_schema();
    if ((string)setting('ai_enabled', '0') !== '1') return ['status' => 'skipped', 'message' => 'AI disabled.'];
    if (ai_daily_draft_count() >= 5) return ['status' => 'skipped', 'message' => 'Daily draft limit reached.'];

    $now = date('H:i');
    foreach (ai_schedule_slots() as $slot) {
        if (!$slot['enabled'] || $slot['time'] > $now || ai_slot_done_today($slot['key'])) continue;

        $lockName = 'vk_ai_' . date('Ymd') . '_' . $slot['key'];
        $locked = (int)(query_one('SELECT GET_LOCK(?,0) ok', [$lockName])['ok'] ?? 0) === 1;
        if (!$locked) continue;
        try {
            if (ai_slot_done_today($slot['key'])) continue;
            return ai_generate_draft($slot['key']);
        } finally {
            query_one('SELECT RELEASE_LOCK(?) released', [$lockName]);
        }
    }
    return ['status' => 'idle', 'message' => 'এখন কোনো due AI slot নেই।'];
}

/**
 * Called by a real public-site page view through the lightweight visitor trigger.
 *
 * The visitor scheduler only runs the CURRENT schedule window (the latest enabled
 * slot whose time has passed). It does not backfill several missed slots at once.
 * A global cooldown prevents every page view from checking continuously, while a
 * per-slot MySQL lock prevents duplicate drafts from concurrent visitors.
 */
function ai_visitor_trigger(int $cooldownSeconds = 60): array
{
    ai_schema();

    if ((string)setting('ai_enabled', '0') !== '1') {
        return ['status' => 'skipped', 'message' => 'AI disabled.'];
    }
    if (ai_daily_draft_count() >= 5) {
        return ['status' => 'skipped', 'message' => 'Daily draft limit reached.'];
    }

    $cooldownSeconds = max(30, min(300, $cooldownSeconds));
    $gateLock = 'vk_ai_visitor_gate';
    $locked = (int)(query_one('SELECT GET_LOCK(?,0) ok', [$gateLock])['ok'] ?? 0) === 1;
    if (!$locked) {
        return ['status' => 'busy', 'message' => 'Another visitor check is already starting.'];
    }

    try {
        // Read directly instead of setting() so this request always sees the latest timestamp.
        $row = query_one("SELECT setting_value FROM settings WHERE setting_key='ai_visitor_last_check' LIMIT 1");
        $lastCheck = (int)($row['setting_value'] ?? 0);
        $nowTs = time();

        if ($lastCheck > 0 && ($nowTs - $lastCheck) < $cooldownSeconds) {
            return ['status' => 'cooldown', 'message' => 'Visitor scheduler checked recently.'];
        }

        ai_save_setting('ai_visitor_last_check', (string)$nowTs);
    } finally {
        query_one('SELECT RELEASE_LOCK(?) released', [$gateLock]);
    }

    // Visitor mode uses the latest active schedule window only. Example: at 16:30,
    // Slot 3 (16:00) may run, but old 08:00/12:00 slots are not backfilled in a burst.
    $now = date('H:i');
    $currentSlot = null;
    foreach (array_reverse(ai_schedule_slots()) as $slot) {
        if ($slot['enabled'] && $slot['time'] <= $now) {
            $currentSlot = $slot;
            break;
        }
    }

    if (!$currentSlot) {
        return ['status' => 'idle', 'message' => 'প্রথম AI schedule slot এখনো due হয়নি।'];
    }
    if (ai_slot_done_today($currentSlot['key'])) {
        return ['status' => 'idle', 'message' => 'Current visitor schedule slot already handled today.'];
    }

    $lockName = 'vk_ai_' . date('Ymd') . '_' . $currentSlot['key'];
    $slotLocked = (int)(query_one('SELECT GET_LOCK(?,0) ok', [$lockName])['ok'] ?? 0) === 1;
    if (!$slotLocked) {
        return ['status' => 'busy', 'message' => 'Current AI slot is already running.'];
    }

    try {
        if (ai_slot_done_today($currentSlot['key'])) {
            return ['status' => 'idle', 'message' => 'Current visitor schedule slot already handled today.'];
        }
        return ai_generate_draft($currentSlot['key']);
    } finally {
        query_one('SELECT RELEASE_LOCK(?) released', [$lockName]);
    }
}

function ai_test_connection(?array $override = null): array
{
    $cfg = ai_provider_config($override);
    $result = ai_call_provider('Reply with exactly: VIRALKENO_AI_OK', false, $override);
    $ok = str_contains(strtoupper($result['text']), 'VIRALKENO_AI_OK');
    return [
        'ok' => $ok,
        'provider' => $cfg['provider'],
        'model' => $cfg['model'],
        'message' => $ok ? 'Connection successful.' : 'API responded, but the expected test text was not returned.',
    ];
}
