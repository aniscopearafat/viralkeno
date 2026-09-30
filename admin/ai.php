<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/ai.php';

$admin = require_admin();
ai_schema();

function ai_admin_url(array $params = []): string
{
    $base = base_url() . '/admin/ai.php';
    return $params ? $base . '?' . http_build_query($params) : $base;
}

function ai_admin_header(string $title = 'AI Control Center'): void
{
    global $admin;
    $items = [
        'Dashboard' => base_url() . '/admin/',
        'Articles' => base_url() . '/admin/index.php?action=articles',
        'Categories' => base_url() . '/admin/index.php?action=categories',
        'Tags' => base_url() . '/admin/index.php?action=tags',
        'Authors' => base_url() . '/admin/index.php?action=authors',
        'Static pages' => base_url() . '/admin/index.php?action=pages',
        'Ads' => base_url() . '/admin/index.php?action=ads',
        'Messages' => base_url() . '/admin/index.php?action=messages',
        'Settings' => base_url() . '/admin/index.php?action=settings',
        'AI Control' => ai_admin_url(),
        'Security' => base_url() . '/admin/index.php?action=security',
    ];
    ?><!doctype html><html lang="bn"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= e($title) ?> | ViralKeno Admin</title><link rel="stylesheet" href="<?= e(base_url()) ?>/assets/css/admin.css?v=1.1.0"></head><body><div class="admin-shell"><aside class="side"><a class="side-brand" href="<?= e(base_url()) ?>/admin/"><span>VK</span> ViralKeno</a><nav><?php foreach ($items as $label => $href): ?><a class="<?= $label === 'AI Control' ? 'active' : '' ?>" href="<?= e($href) ?>"><?= e($label) ?></a><?php endforeach; ?></nav><div class="side-foot">Signed in as<br><strong><?= e($admin['name']) ?></strong></div></aside><main class="main"><header class="topbar"><div><h1><?= e($title) ?></h1><p class="muted">AI researches and writes drafts only. Human review + featured image are mandatory before publish.</p></div><div class="top-actions"><a class="btn" href="<?= e(base_url()) ?>" target="_blank" rel="noopener">View site ↗</a></div></header><?php foreach (take_flashes() as $flash): ?><div class="flash <?= e($flash['type']) ?>"><?= e($flash['message']) ?></div><?php endforeach; ?><?php
}

function ai_admin_footer(): void
{
    ?></main></div><script>
(function(){
  var provider=document.querySelector('[name="provider"]');
  var model=document.querySelector('[name="model"]');
  var defaults={gemini:'gemini-3.8-flash',openai:'gpt-5.6-luna',groq:'groq/compound'};
  if(provider&&model){provider.addEventListener('change',function(){if(!model.value.trim()||Object.values(defaults).indexOf(model.value.trim())!==-1)model.value=defaults[provider.value]||'';});}
  document.addEventListener('submit',function(e){if(e.target.matches('[data-confirm]')&&!confirm(e.target.getAttribute('data-confirm')))e.preventDefault();});
})();
</script></body></html><?php
}

$testResult = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['form_action'] ?? '');
    try {
        if ($action === 'save_ai_settings') {
            $provider = strtolower(trim((string)($_POST['provider'] ?? 'gemini')));
            if (!in_array($provider, ['gemini','openai','groq'], true)) throw new RuntimeException('Invalid AI provider.');
            $model = trim((string)($_POST['model'] ?? ''));
            if ($model === '') $model = ai_default_model($provider);

            ai_save_setting('ai_enabled', isset($_POST['ai_enabled']) ? '1' : '0');
            ai_save_setting('ai_provider', $provider);
            ai_save_setting('ai_model', $model);
            ai_save_setting('ai_author_id', (string)(int)($_POST['ai_author_id'] ?? 0));
            ai_save_setting('ai_fallback_category_id', (string)(int)($_POST['ai_fallback_category_id'] ?? 0));
            ai_save_setting('ai_writing_rules', trim((string)($_POST['ai_writing_rules'] ?? '')));

            if (isset($_POST['clear_credentials'])) {
                ai_save_setting('ai_api_key', '');
                ai_save_setting('ai_api_username', '');
                ai_save_setting('ai_api_password', '');
            } else {
                foreach (['api_key'=>'ai_api_key','api_username'=>'ai_api_username','api_password'=>'ai_api_password'] as $field => $key) {
                    $value = trim((string)($_POST[$field] ?? ''));
                    if ($value !== '') ai_save_setting($key, ai_encrypt_secret($value));
                }
            }

            for ($i = 1; $i <= 5; $i++) {
                $time = trim((string)($_POST['schedule_' . $i] ?? ''));
                if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time)) throw new RuntimeException('Schedule slot ' . $i . '-এর valid time দিন।');
                ai_save_setting('ai_schedule_' . $i, $time);
                ai_save_setting('ai_schedule_' . $i . '_enabled', isset($_POST['schedule_' . $i . '_enabled']) ? '1' : '0');
            }
            flash('success', 'AI configuration এবং ৫টি schedule slot saved.');
            redirect(ai_admin_url());
        }

        if ($action === 'test_ai_connection') {
            $override = [
                'provider' => (string)($_POST['provider'] ?? 'gemini'),
                'model' => (string)($_POST['model'] ?? ''),
                'api_key' => trim((string)($_POST['api_key'] ?? '')),
                'api_username' => trim((string)($_POST['api_username'] ?? '')),
                'api_password' => trim((string)($_POST['api_password'] ?? '')),
            ];
            $testResult = ai_test_connection($override);
        }

        if ($action === 'run_ai_now') {
            $result = ai_generate_draft('manual-' . date('His'));
            $type = $result['status'] === 'success' ? 'success' : ($result['status'] === 'failed' ? 'error' : 'success');
            $message = $result['message'];
            if (!empty($result['article_id'])) $message .= ' Article #' . (int)$result['article_id'] . ' — এখন human review + image add করুন।';
            flash($type, $message);
            redirect(ai_admin_url());
        }

        if ($action === 'run_due_slots') {
            $result = ai_run_due_slots();
            $type = $result['status'] === 'failed' ? 'error' : 'success';
            flash($type, $result['message']);
            redirect(ai_admin_url());
        }
    } catch (Throwable $e) {
        if ($action === 'test_ai_connection') {
            $testResult = ['ok'=>false,'message'=>$e->getMessage(),'provider'=>(string)($_POST['provider'] ?? ''),'model'=>(string)($_POST['model'] ?? '')];
        } else {
            flash('error', $e->getMessage());
            redirect(ai_admin_url());
        }
    }
}

$provider = strtolower((string)setting('ai_provider', 'gemini'));
if (!in_array($provider, ['gemini','openai','groq'], true)) $provider = 'gemini';
$model = (string)setting('ai_model', ai_default_model($provider));
$apiKeySaved = (string)setting('ai_api_key', '') !== '';
$userSaved = (string)setting('ai_api_username', '') !== '';
$passSaved = (string)setting('ai_api_password', '') !== '';
$authors = query_all('SELECT id,name FROM authors WHERE is_active=1 ORDER BY name');
$categories = query_all('SELECT id,name FROM categories WHERE is_active=1 ORDER BY sort_order,name');
$slots = ai_schedule_slots();
$logs = query_all('SELECT r.*,a.title article_title FROM ai_runs r LEFT JOIN articles a ON a.id=r.article_id ORDER BY r.id DESC LIMIT 30');
$dailyCount = ai_daily_draft_count();

ai_admin_header();
?>
<div class="stats">
  <div class="stat"><span>AI today</span><strong><?= $dailyCount ?>/5</strong></div>
  <div class="stat"><span>Provider</span><strong class="stat-text"><?= e(ucfirst($provider)) ?></strong></div>
  <div class="stat"><span>Model</span><strong class="stat-text"><?= e($model) ?></strong></div>
  <div class="stat"><span>Automation</span><strong class="stat-text"><?= setting('ai_enabled','0')==='1'?'Enabled':'Off' ?></strong></div>
</div>

<?php if ($testResult): ?><div class="flash <?= !empty($testResult['ok'])?'success':'error' ?>"><strong><?= !empty($testResult['ok'])?'Connected ✓':'Connection failed' ?></strong> — <?= e($testResult['message'] ?? '') ?><?php if (!empty($testResult['provider'])): ?> <small><?= e($testResult['provider']) ?> / <?= e($testResult['model'] ?? '') ?></small><?php endif; ?></div><?php endif; ?>

<div class="editor-note"><strong>InfinityFree Free limitation:</strong> free hosting-এর Cron Jobs feature disabled, তাই server-side exact-time automation সেখানে নিজে থেকে চলবে না। এই module schedule save করবে এবং manual testing/run করবে; full automatic 5-slot execution-এর জন্য cron-capable hosting লাগবে।</div>

<form method="post" class="grid">
  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
  <section class="panel">
    <div class="topbar"><div><h2>AI Configuration</h2><p class="muted">Provider/model future-এ code change ছাড়াই বদলাতে পারবেন।</p></div><label class="toggle-line"><input type="checkbox" name="ai_enabled" <?= setting('ai_enabled','0')==='1'?'checked':'' ?>> AI enabled</label></div>
    <div class="grid two">
      <label>Select AI provider
        <select name="provider">
          <option value="gemini" <?= $provider==='gemini'?'selected':'' ?>>Gemini</option>
          <option value="openai" <?= $provider==='openai'?'selected':'' ?>>OpenAI / ChatGPT API</option>
          <option value="groq" <?= $provider==='groq'?'selected':'' ?>>Groq</option>
        </select>
      </label>
      <label>Model name<input name="model" required value="<?= e($model) ?>" placeholder="gemini-3.8-flash"><small>Editable. Groq live research-এর জন্য groq/compound recommended.</small></label>
      <label>API key<input type="password" name="api_key" autocomplete="new-password" placeholder="<?= $apiKeySaved?'Saved — leave blank to keep':'Paste API key' ?>"><small><?= $apiKeySaved?'Encrypted API key saved.':'No API key saved yet.' ?></small></label>
      <label>API username (optional)<input name="api_username" autocomplete="off" placeholder="<?= $userSaved?'Saved — leave blank to keep':'Usually not required' ?>"></label>
      <label>API password (optional)<input type="password" name="api_password" autocomplete="new-password" placeholder="<?= $passSaved?'Saved — leave blank to keep':'Usually not required' ?>"></label>
      <label>AI draft author<select name="ai_author_id"><option value="0">Auto: first active author</option><?php foreach ($authors as $row): ?><option value="<?= (int)$row['id'] ?>" <?= (int)setting('ai_author_id','0')===(int)$row['id']?'selected':'' ?>><?= e($row['name']) ?></option><?php endforeach; ?></select></label>
      <label>Fallback category<select name="ai_fallback_category_id"><option value="0">Auto: first active category</option><?php foreach ($categories as $row): ?><option value="<?= (int)$row['id'] ?>" <?= (int)setting('ai_fallback_category_id','0')===(int)$row['id']?'selected':'' ?>><?= e($row['name']) ?></option><?php endforeach; ?></select></label>
    </div>
    <div class="checks"><label><input type="checkbox" name="clear_credentials"> Clear all saved API credentials on Save</label></div>
  </section>

  <section class="panel">
    <h2>Daily AI Schedule — Asia/Dhaka</h2>
    <p class="muted">Maximum 5 AI drafts/day. প্রতিটি enabled slot একবার করে run হবে।</p>
    <div class="grid five-slots">
      <?php foreach ($slots as $i => $slot): $n=$i+1; ?>
        <label><?= e($slot['label']) ?><input type="time" name="schedule_<?= $n ?>" required value="<?= e($slot['time']) ?>"><span class="toggle-line"><input type="checkbox" name="schedule_<?= $n ?>_enabled" <?= $slot['enabled']?'checked':'' ?>> Enabled</span></label>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="panel">
    <h2>AI Draft Writing Rules</h2>
    <p class="muted">এটা editable prompt policy। AI শুধু complete draft লিখবে; image/publish করবে না।</p>
    <label>Rules<textarea class="code" name="ai_writing_rules" rows="16"><?= e(ai_writing_rules()) ?></textarea></label>
  </section>

  <div class="actions large-actions">
    <button class="primary" type="submit" name="form_action" value="save_ai_settings">Save AI Configuration</button>
    <button type="submit" name="form_action" value="test_ai_connection">Test Connection</button>
  </div>
</form>

<section class="panel">
  <div class="topbar"><div><h2>Manual controls</h2><p class="muted">Testing এবং InfinityFree free hosting-এ manual fallback.</p></div><span class="status ai">AI drafts: <?= $dailyCount ?>/5 today</span></div>
  <div class="actions large-actions">
    <form method="post" data-confirm="এখন live web research করে ১টি AI draft তৈরি করবেন? API usage হতে পারে।"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><button class="primary" name="form_action" value="run_ai_now">Create 1 Draft Now</button></form>
    <form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><button name="form_action" value="run_due_slots">Run Due Slot Now</button></form>
  </div>
</section>

<section class="panel">
  <div class="topbar"><div><h2>Run history</h2><p class="muted">Success/failed/skipped logs for review and debugging.</p></div></div>
  <div class="table-wrap"><table><thead><tr><th>Time</th><th>Slot</th><th>Provider / Model</th><th>Status</th><th>Draft</th><th>Message</th></tr></thead><tbody>
  <?php foreach ($logs as $row): ?><tr>
    <td><?= e(date('d M, H:i', strtotime($row['started_at']))) ?></td>
    <td><?= e($row['slot_key']) ?></td>
    <td><?= e($row['provider']) ?><br><small><?= e($row['model']) ?></small></td>
    <td><span class="status <?= e($row['status']) ?>"><?= e($row['status']) ?></span></td>
    <td><?php if ($row['article_id']): ?><a class="btn" href="<?= e(base_url()) ?>/admin/index.php?action=article_edit&id=<?= (int)$row['article_id'] ?>">#<?= (int)$row['article_id'] ?> Edit</a><?php else: ?>—<?php endif; ?></td>
    <td><small><?= e($row['message'] ?? '') ?></small></td>
  </tr><?php endforeach; ?>
  </tbody></table></div>
  <?php if (!$logs): ?><div class="empty">AI run history এখনও খালি।</div><?php endif; ?>
</section>
<?php ai_admin_footer();
