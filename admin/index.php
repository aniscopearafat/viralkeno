<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';

$action = (string)($_GET['action'] ?? 'dashboard');

if ($action === 'login') {
    if (admin_user()) redirect(base_url() . '/admin/');
    $error = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $password = (string)($_POST['password'] ?? '');
        $ipHash = hash('sha256', $_SERVER['REMOTE_ADDR'] ?? 'unknown');
        db()->exec('DELETE FROM login_attempts WHERE attempted_at<DATE_SUB(NOW(),INTERVAL 30 DAY)');
        $recent = (int)(query_one('SELECT COUNT(*) n FROM login_attempts WHERE (ip_hash=? OR email=?) AND was_successful=0 AND attempted_at>DATE_SUB(NOW(),INTERVAL 15 MINUTE)', [$ipHash,$email])['n'] ?? 0);
        if ($recent >= 5) {
            $error = 'অনেকবার ভুল চেষ্টা হয়েছে। 15 মিনিট পরে আবার চেষ্টা করুন।';
        } else {
            $admin = query_one('SELECT * FROM admins WHERE email=? AND is_active=1 LIMIT 1', [$email]);
            $ok = $admin && password_verify($password, $admin['password_hash']);
            db()->prepare('INSERT INTO login_attempts(email,ip_hash,was_successful,attempted_at) VALUES(?,?,?,NOW())')->execute([$email,$ipHash,$ok?1:0]);
            if ($ok) {
                if (password_needs_rehash($admin['password_hash'], PASSWORD_DEFAULT)) db()->prepare('UPDATE admins SET password_hash=? WHERE id=?')->execute([password_hash($password,PASSWORD_DEFAULT),$admin['id']]);
                session_regenerate_id(true);
                $token = bin2hex(random_bytes(32));
                db()->prepare('INSERT INTO admin_sessions(admin_id,token_hash,user_agent,ip_hash,expires_at,created_at) VALUES(?,?,?,?,DATE_ADD(NOW(),INTERVAL 30 DAY),NOW())')->execute([$admin['id'],hash('sha256',$token),mb_substr($_SERVER['HTTP_USER_AGENT']??'',0,255),$ipHash]);
                $_SESSION['admin_id'] = (int)$admin['id'];
                $_SESSION['admin_session_token'] = $token;
                db()->prepare('UPDATE admins SET last_login_at=NOW() WHERE id=?')->execute([$admin['id']]);
                redirect(base_url() . '/admin/');
            }
            $error = 'Email অথবা password সঠিক নয়।';
        }
    }
    ?><!doctype html><html lang="bn"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Admin Login | ViralKeno</title><link rel="stylesheet" href="<?= e(base_url()) ?>/assets/css/admin.css?v=1.0.0"></head><body><main class="login-wrap"><section class="login"><div class="logo">VK</div><h1>Admin Login</h1><p class="muted">ViralKeno newsroom control panel</p><?php if($error): ?><div class="flash error"><?= e($error) ?></div><?php endif; ?><form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><label>Email<input type="email" name="email" required autocomplete="username"></label><label>Password<input type="password" name="password" required autocomplete="current-password"></label><button class="primary" type="submit">Secure login</button></form><p><a href="<?= e(base_url()) ?>">← Website-এ ফিরে যান</a></p></section></main></body></html><?php exit;
}

if ($action === 'logout') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect(base_url() . '/admin/');
    require_admin();
    verify_csrf();
    $token = $_SESSION['admin_session_token'] ?? '';
    if ($token) db()->prepare('UPDATE admin_sessions SET revoked_at=NOW() WHERE token_hash=?')->execute([hash('sha256',$token)]);
    $_SESSION = [];
    if (ini_get('session.use_cookies')) { $p=session_get_cookie_params(); setcookie(session_name(),'',time()-42000,$p['path'],$p['domain']??'',(bool)$p['secure'],(bool)$p['httponly']); }
    session_destroy();
    redirect(base_url() . '/admin/index.php?action=login');
}

$admin = require_admin();
require_once dirname(__DIR__) . '/app/ai.php';
ai_schema();

function admin_url(string $action = 'dashboard', array $params = []): string
{
    return base_url() . '/admin/index.php?' . http_build_query(['action'=>$action] + $params);
}

function post_redirect(string $action, array $params = []): void
{
    redirect(admin_url($action, $params));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $postAction = (string)($_POST['form_action'] ?? '');
    try {
        if ($postAction === 'save_article') {
            $id = (int)($_POST['id'] ?? 0);
            $title = trim((string)($_POST['title'] ?? ''));
            $excerpt = trim((string)($_POST['excerpt'] ?? ''));
            $body = sanitize_html((string)($_POST['body'] ?? ''));
            $categoryId = (int)($_POST['category_id'] ?? 0);
            $authorId = (int)($_POST['author_id'] ?? 0);
            if (mb_strlen($title) < 5 || mb_strlen($excerpt) < 20 || mb_strlen(strip_tags($body)) < 40 || !$categoryId || !$authorId) throw new RuntimeException('Title, excerpt, body, category এবং author ঠিকভাবে পূরণ করুন।');
            $slug = unique_slug('articles', (string)($_POST['slug'] ?: $title), $id);
            $status = in_array($_POST['status'] ?? '', ['draft','scheduled','published'], true) ? $_POST['status'] : 'draft';
            $publishedAt = trim((string)($_POST['published_at'] ?? ''));
            $publishedAt = $publishedAt ? date('Y-m-d H:i:s', strtotime($publishedAt)) : ($status === 'published' ? date('Y-m-d H:i:s') : null);
            if ($status === 'scheduled' && (!$publishedAt || strtotime($publishedAt) <= time())) throw new RuntimeException('Scheduled article-এর future date দিন।');
            $sourceName = trim((string)($_POST['source_name'] ?? ''));
            $sourceUrl = filter_var($_POST['source_url'] ?? '', FILTER_VALIDATE_URL) ? trim((string)$_POST['source_url']) : null;
            if ($status !== 'draft' && ($sourceName === '' || !$sourceUrl)) throw new RuntimeException('Publish বা schedule করার আগে original source name ও valid URL দিন।');
            $existing = $id ? query_one('SELECT * FROM articles WHERE id=?',[$id]) : null;
            $image = $existing['featured_image'] ?? null;
            if (!empty($_FILES['featured_image']['name'])) $image = save_uploaded_image($_FILES['featured_image']);
            if ($status !== 'draft' && !$image) throw new RuntimeException('Publish বা schedule করার আগে featured image অবশ্যই add করুন।');
            $data = [
                $authorId,$categoryId,$title,$slug,$excerpt,$body,$image,trim((string)($_POST['image_alt']??'')),trim((string)($_POST['image_caption']??'')),trim((string)($_POST['content_label']??'প্রতিবেদন')),
                $sourceName,$sourceUrl,filter_var($_POST['embed_url']??'',FILTER_VALIDATE_URL)?trim((string)$_POST['embed_url']):null,
                trim((string)($_POST['fact_check_rating']??'')),trim((string)($_POST['reporter_disclosure']??'')),trim((string)($_POST['correction_note']??'')),trim((string)($_POST['seo_title']??'')),trim((string)($_POST['meta_description']??'')),
                $status,isset($_POST['is_featured'])?1:0,isset($_POST['is_trending'])?1:0,$publishedAt
            ];
            if ($id) {
                if (!$existing) throw new RuntimeException('Article পাওয়া যায়নি।');
                $data[]=$id;
                db()->prepare('UPDATE articles SET author_id=?,category_id=?,title=?,slug=?,excerpt=?,body=?,featured_image=?,image_alt=?,image_caption=?,content_label=?,source_name=?,source_url=?,embed_url=?,fact_check_rating=?,reporter_disclosure=?,correction_note=?,seo_title=?,meta_description=?,status=?,is_featured=?,is_trending=?,published_at=?,updated_at=NOW() WHERE id=?')->execute($data);
            } else {
                db()->prepare('INSERT INTO articles(author_id,category_id,title,slug,excerpt,body,featured_image,image_alt,image_caption,content_label,source_name,source_url,embed_url,fact_check_rating,reporter_disclosure,correction_note,seo_title,meta_description,status,is_featured,is_trending,published_at,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())')->execute($data);
                $id=(int)db()->lastInsertId();
            }
            db()->prepare('DELETE FROM article_tags WHERE article_id=?')->execute([$id]);
            $tagNames=array_filter(array_unique(array_map('trim',explode(',',(string)($_POST['tags']??'')))));
            foreach($tagNames as $tagName){$tag=query_one('SELECT id FROM tags WHERE name=?',[$tagName]);if(!$tag){$tagSlug=unique_slug('tags',$tagName);db()->prepare('INSERT INTO tags(name,slug,created_at) VALUES(?,?,NOW())')->execute([$tagName,$tagSlug]);$tagId=(int)db()->lastInsertId();}else{$tagId=(int)$tag['id'];}db()->prepare('INSERT IGNORE INTO article_tags(article_id,tag_id) VALUES(?,?)')->execute([$id,$tagId]);}
            flash('success','Article saved successfully.'); post_redirect('articles');
        }
        if ($postAction === 'delete_article') {
            $id=(int)($_POST['id']??0); $row=query_one('SELECT featured_image FROM articles WHERE id=?',[$id]);
            db()->prepare('DELETE FROM ai_draft_meta WHERE article_id=?')->execute([$id]);
            db()->prepare('DELETE FROM articles WHERE id=?')->execute([$id]);
            if($row && str_starts_with((string)$row['featured_image'],'uploads/images/')) @unlink(VK_ROOT.'/'.$row['featured_image']);
            flash('success','Article deleted.'); post_redirect('articles');
        }
        if ($postAction === 'save_category') {
            $id=(int)($_POST['id']??0);$name=trim((string)($_POST['name']??''));if(mb_strlen($name)<2)throw new RuntimeException('Category name দিন।');$slug=unique_slug('categories',(string)($_POST['slug']?:$name),$id);$data=[$name,$slug,trim((string)($_POST['description']??'')),(int)($_POST['sort_order']??0),isset($_POST['is_active'])?1:0];
            if($id){$data[]=$id;db()->prepare('UPDATE categories SET name=?,slug=?,description=?,sort_order=?,is_active=?,updated_at=NOW() WHERE id=?')->execute($data);}else{db()->prepare('INSERT INTO categories(name,slug,description,sort_order,is_active,created_at,updated_at) VALUES(?,?,?,?,?,NOW(),NOW())')->execute($data);}flash('success','Category saved.');post_redirect('categories');
        }
        if ($postAction === 'save_author') {
            $id=(int)($_POST['id']??0);$name=trim((string)($_POST['name']??''));$email=trim((string)($_POST['email']??''));if(mb_strlen($name)<2)throw new RuntimeException('Author name দিন।');$data=[$name,filter_var($email,FILTER_VALIDATE_EMAIL)?$email:null,trim((string)($_POST['bio']??'')),isset($_POST['is_active'])?1:0];
            if($id){$data[]=$id;db()->prepare('UPDATE authors SET name=?,email=?,bio=?,is_active=?,updated_at=NOW() WHERE id=?')->execute($data);}else{db()->prepare('INSERT INTO authors(name,email,bio,is_active,created_at,updated_at) VALUES(?,?,?,?,NOW(),NOW())')->execute($data);}flash('success','Author saved.');post_redirect('authors');
        }
        if ($postAction === 'save_tag') {
            $id=(int)($_POST['id']??0);$name=trim((string)($_POST['name']??''));if(mb_strlen($name)<1)throw new RuntimeException('Tag name দিন।');$slug=unique_slug('tags',(string)($_POST['slug']?:$name),$id);
            if($id)db()->prepare('UPDATE tags SET name=?,slug=? WHERE id=?')->execute([$name,$slug,$id]);else db()->prepare('INSERT INTO tags(name,slug,created_at) VALUES(?,?,NOW())')->execute([$name,$slug]);flash('success','Tag saved.');post_redirect('tags');
        }
        if ($postAction === 'delete_taxonomy') {
            $type=(string)($_POST['type']??'');$id=(int)($_POST['id']??0);$map=['categories'=>'categories','authors'=>'authors','tags'=>'tags'];if(!isset($map[$type]))throw new RuntimeException('Invalid delete type.');
            db()->prepare('DELETE FROM '.$map[$type].' WHERE id=?')->execute([$id]);flash('success','Item deleted.');post_redirect($type);
        }
        if ($postAction === 'save_page') {
            $id=(int)($_POST['id']??0);$title=trim((string)($_POST['title']??''));$body=sanitize_html((string)($_POST['body']??''));if(mb_strlen($title)<2||mb_strlen(strip_tags($body))<20)throw new RuntimeException('Page title ও content লিখুন।');$slug=unique_slug('pages',(string)($_POST['slug']?:$title),$id);$data=[$title,$slug,$body,trim((string)($_POST['seo_title']??'')),trim((string)($_POST['meta_description']??'')),isset($_POST['is_published'])?1:0];
            if($id){$data[]=$id;db()->prepare('UPDATE pages SET title=?,slug=?,body=?,seo_title=?,meta_description=?,is_published=?,updated_at=NOW() WHERE id=?')->execute($data);}else{db()->prepare('INSERT INTO pages(title,slug,body,seo_title,meta_description,is_published,created_at,updated_at) VALUES(?,?,?,?,?,?,NOW(),NOW())')->execute($data);}flash('success','Page saved.');post_redirect('pages');
        }
        if ($postAction === 'delete_page') {db()->prepare('DELETE FROM pages WHERE id=?')->execute([(int)$_POST['id']]);flash('success','Page deleted.');post_redirect('pages');}
        if ($postAction === 'save_ads') {
            foreach(query_all('SELECT id,slot_key FROM advertisement_slots') as $slot){$key=$slot['slot_key'];$enabled=isset($_POST['enabled'][$key])?1:0;$code=(string)($_POST['code'][$key]??'');db()->prepare('UPDATE advertisement_slots SET ad_code=?,is_enabled=?,updated_at=NOW() WHERE id=?')->execute([$code,$enabled,$slot['id']]);}flash('success','Advertisement slots updated.');post_redirect('ads');
        }
        if ($postAction === 'save_settings') {
            $keys=['site_name','tagline','meta_description','contact_email','facebook_url','youtube_url','in_article_after_paragraph'];
            foreach($keys as $key){$value=trim((string)($_POST[$key]??''));db()->prepare('INSERT INTO settings(setting_key,setting_value,updated_at) VALUES(?,?,NOW()) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_at=NOW()')->execute([$key,$value]);}
            foreach(['logo_path'=>'logo_file','favicon_path'=>'favicon_file'] as $key=>$fileKey){if(!empty($_FILES[$fileKey]['name'])){$path=save_uploaded_image($_FILES[$fileKey]);db()->prepare('INSERT INTO settings(setting_key,setting_value,updated_at) VALUES(?,?,NOW()) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_at=NOW()')->execute([$key,$path]);}}
            flash('success','Website settings saved.');post_redirect('settings');
        }
        if ($postAction === 'message_status') {db()->prepare('UPDATE contact_messages SET status=? WHERE id=?')->execute([in_array($_POST['status']??'',['new','read','archived'],true)?$_POST['status']:'read',(int)$_POST['id']]);flash('success','Message updated.');post_redirect('messages');}
        if ($postAction === 'delete_message') {db()->prepare('DELETE FROM contact_messages WHERE id=?')->execute([(int)$_POST['id']]);flash('success','Message deleted.');post_redirect('messages');}
        if ($postAction === 'change_password') {
            $current=(string)($_POST['current_password']??'');$new=(string)($_POST['new_password']??'');if(!password_verify($current,$admin['password_hash']))throw new RuntimeException('Current password সঠিক নয়।');if(strlen($new)<12||!preg_match('/[A-Z]/',$new)||!preg_match('/[a-z]/',$new)||!preg_match('/\d/',$new))throw new RuntimeException('নতুন password 12+ character এবং uppercase, lowercase ও number দিন।');db()->prepare('UPDATE admins SET password_hash=?,updated_at=NOW() WHERE id=?')->execute([password_hash($new,PASSWORD_DEFAULT),$admin['id']]);flash('success','Password changed.');post_redirect('security');
        }
        if ($postAction === 'logout_all') {db()->prepare('UPDATE admin_sessions SET revoked_at=NOW() WHERE admin_id=?')->execute([$admin['id']]);$_SESSION=[];session_destroy();redirect(base_url().'/admin/index.php?action=login');}
    } catch (Throwable $e) {
        error_log('ViralKeno admin action error: '.$e->getMessage());
        flash('error',$e instanceof PDOException ? 'Database action সম্পন্ন হয়নি। Linked content থাকলে আগে সেটি update করুন।' : $e->getMessage());
        post_redirect($action ?: 'dashboard');
    }
}

function admin_header(string $title, string $active): void
{
    global $admin;
    $items=['dashboard'=>'Dashboard','articles'=>'Articles','categories'=>'Categories','tags'=>'Tags','authors'=>'Authors','pages'=>'Static pages','ads'=>'Ads','messages'=>'Messages','settings'=>'Settings','security'=>'Security'];
    ?><!doctype html><html lang="bn"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= e($title) ?> | ViralKeno Admin</title><link rel="stylesheet" href="<?= e(base_url()) ?>/assets/css/admin.css?v=1.1.0"></head><body><div class="admin-shell"><aside class="side"><a class="side-brand" href="<?= e(admin_url()) ?>"><span>VK</span> ViralKeno</a><nav><?php foreach($items as $key=>$label): ?><a class="<?= $active===$key?'active':'' ?>" href="<?= e(admin_url($key)) ?>"><?= e($label) ?></a><?php if($key==='settings'): ?><a href="<?= e(base_url()) ?>/admin/ai.php">AI Control</a><?php endif; ?><?php endforeach; ?></nav><div class="side-foot">Signed in as<br><strong><?= e($admin['name']) ?></strong></div></aside><main class="main"><header class="topbar"><h1><?= e($title) ?></h1><div class="top-actions"><a class="btn" href="<?= e(base_url()) ?>" target="_blank" rel="noopener">View site ↗</a><form method="post" action="<?= e(admin_url('logout')) ?>"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><button type="submit">Logout</button></form></div></header><?php foreach(take_flashes() as $flash): ?><div class="flash <?= e($flash['type']) ?>"><?= e($flash['message']) ?></div><?php endforeach; ?><?php
}
function admin_footer(): void { ?></main></div><script>document.addEventListener('submit',function(e){if(e.target.matches('[data-confirm]')&&!confirm(e.target.getAttribute('data-confirm')))e.preventDefault();});</script></body></html><?php }

if ($action === 'dashboard') {
    $stats=['Published'=>(int)(query_one("SELECT COUNT(*) n FROM articles WHERE status='published'")['n']??0),'Drafts'=>(int)(query_one("SELECT COUNT(*) n FROM articles WHERE status='draft'")['n']??0),'Total views'=>(int)(query_one('SELECT SUM(views_count) n FROM articles')['n']??0),'New messages'=>(int)(query_one("SELECT COUNT(*) n FROM contact_messages WHERE status='new'")['n']??0)];
    $recent=query_all('SELECT title,status,views_count,updated_at FROM articles ORDER BY updated_at DESC LIMIT 8');admin_header('Newsroom overview','dashboard');?><div class="stats"><?php foreach($stats as $label=>$value): ?><div class="stat"><span><?= e($label) ?></span><strong><?= number_format($value) ?></strong></div><?php endforeach; ?></div><section class="panel"><div class="topbar"><h2>Recent articles</h2><a class="btn primary" href="<?= e(admin_url('article_edit')) ?>">+ New article</a></div><div class="table-wrap"><table><thead><tr><th>Title</th><th>Status</th><th>Views</th><th>Updated</th></tr></thead><tbody><?php foreach($recent as $row): ?><tr><td><?= e($row['title']) ?></td><td><span class="status <?= e($row['status']) ?>"><?= e($row['status']) ?></span></td><td><?= number_format((int)$row['views_count']) ?></td><td><?= e(date('d M, H:i',strtotime($row['updated_at']))) ?></td></tr><?php endforeach; ?></tbody></table></div></section><?php admin_footer();exit;
}

if ($action === 'articles') {
    $articles=query_all('SELECT a.id,a.title,a.slug,a.status,a.published_at,a.views_count,a.is_featured,a.is_trending,c.name category_name,au.name author_name,IF(adm.article_id IS NULL,0,1) ai_generated FROM articles a JOIN categories c ON c.id=a.category_id JOIN authors au ON au.id=a.author_id LEFT JOIN ai_draft_meta adm ON adm.article_id=a.id ORDER BY a.created_at DESC LIMIT 100');admin_header('Articles','articles');?><section class="panel"><div class="topbar"><div><h2>All stories</h2><p class="muted">Draft, schedule, publish, feature এবং trend control.</p></div><a class="btn primary" href="<?= e(admin_url('article_edit')) ?>">+ New article</a></div><div class="table-wrap"><table><thead><tr><th>Story</th><th>Category</th><th>Status</th><th>Views</th><th>Flags</th><th>Actions</th></tr></thead><tbody><?php foreach($articles as $row): ?><tr><td><strong><?= e($row['title']) ?></strong><?php if(!empty($row['ai_generated'])): ?> <span class="ai-badge">AI Generated</span><?php endif; ?><br><small><?= e($row['author_name']) ?></small></td><td><?= e($row['category_name']) ?></td><td><span class="status <?= e($row['status']) ?>"><?= e($row['status']) ?></span></td><td><?= number_format((int)$row['views_count']) ?></td><td><?= $row['is_featured']?'Featured ':'' ?><?= $row['is_trending']?'Trending':'' ?></td><td><div class="actions"><a class="btn" href="<?= e(admin_url('article_edit',['id'=>$row['id']])) ?>">Edit</a><a class="btn" href="<?= e(admin_url('preview',['id'=>$row['id']])) ?>">Preview</a><form method="post" data-confirm="Article permanently delete করবেন?"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="form_action" value="delete_article"><input type="hidden" name="id" value="<?= (int)$row['id'] ?>"><button class="danger" type="submit">Delete</button></form></div></td></tr><?php endforeach; ?></tbody></table></div></section><?php admin_footer();exit;
}

if ($action === 'article_edit') {
    $id=(int)($_GET['id']??0);$article=$id?query_one('SELECT * FROM articles WHERE id=?',[$id]):null;$categories=query_all('SELECT id,name FROM categories WHERE is_active=1 ORDER BY sort_order,name');$authors=query_all('SELECT id,name FROM authors WHERE is_active=1 ORDER BY name');$tagText=$id?implode(', ',array_column(query_all('SELECT t.name FROM tags t JOIN article_tags at ON at.tag_id=t.id WHERE at.article_id=? ORDER BY t.name',[$id]),'name')):'';$aiMeta=$id?query_one('SELECT * FROM ai_draft_meta WHERE article_id=?',[$id]):null;$aiSources=[];if($aiMeta&&!empty($aiMeta['source_links'])){$decoded=json_decode((string)$aiMeta['source_links'],true);if(is_array($decoded))$aiSources=array_values(array_filter($decoded,static fn($u)=>is_string($u)&&filter_var($u,FILTER_VALIDATE_URL)));}admin_header($id?'Edit article':'New article','articles');?><?php if($aiMeta): ?><section class="panel ai-review"><div class="topbar"><div><h2>AI Research Notes <span class="ai-badge">AI Generated</span></h2><p class="muted">Private editor-only information · <?= e($aiMeta['provider']) ?> / <?= e($aiMeta['model']) ?></p></div></div><p><?= nl2br(e($aiMeta['research_notes']??'')) ?></p><?php if($aiSources): ?><div class="ai-source-list"><strong>Research sources</strong><?php foreach($aiSources as $source): ?><a href="<?= e($source) ?>" target="_blank" rel="noopener noreferrer"><?= e($source) ?> ↗</a><?php endforeach; ?></div><?php endif; ?><p class="editor-note"><strong>Human review required:</strong> AI image add করেনি। Featured image manually add না করলে Publish/Schedule হবে না।</p></section><?php endif; ?><form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="form_action" value="save_article"><input type="hidden" name="id" value="<?= $id ?>"><section class="panel grid"><div class="field"><label>Headline</label><input name="title" required maxlength="255" value="<?= e($article['title']??'') ?>"></div><div class="field"><label>Slug</label><input name="slug" maxlength="190" value="<?= e($article['slug']??'') ?>"><small>খালি রাখলে headline থেকে automatic slug হবে।</small></div><div class="field"><label>Short excerpt</label><textarea name="excerpt" required maxlength="600" rows="3"><?= e($article['excerpt']??'') ?></textarea></div><div class="field"><label>Article body (safe HTML)</label><textarea class="code" name="body" required rows="18"><?= e($article['body']??'<p>প্রথম paragraph…</p><h2>Section heading</h2><p>পরের paragraph…</p>') ?></textarea><small>Allowed: p, h2–h4, strong, em, lists, blockquote, link, image, code.</small></div></section><section class="panel"><h2>Publishing</h2><div class="grid three"><label>Category<select name="category_id" required><?php foreach($categories as $row): ?><option value="<?= (int)$row['id'] ?>" <?= (int)($article['category_id']??0)===(int)$row['id']?'selected':'' ?>><?= e($row['name']) ?></option><?php endforeach; ?></select></label><label>Author<select name="author_id" required><?php foreach($authors as $row): ?><option value="<?= (int)$row['id'] ?>" <?= (int)($article['author_id']??0)===(int)$row['id']?'selected':'' ?>><?= e($row['name']) ?></option><?php endforeach; ?></select></label><label>Status<select name="status"><option value="draft" <?= ($article['status']??'draft')==='draft'?'selected':'' ?>>Draft</option><option value="scheduled" <?= ($article['status']??'')==='scheduled'?'selected':'' ?>>Scheduled</option><option value="published" <?= ($article['status']??'')==='published'?'selected':'' ?>>Published</option></select></label><label>Publish date & time<input type="datetime-local" name="published_at" value="<?= !empty($article['published_at'])?e(date('Y-m-d\TH:i',strtotime($article['published_at']))):'' ?>"></label><label>Content label<input name="content_label" value="<?= e($article['content_label']??'প্রতিবেদন') ?>" placeholder="Report / Opinion / Rumour / Fact Check"></label><label>Tags<input name="tags" value="<?= e($tagText) ?>" placeholder="Viral, AI, Creator"></label></div><div class="checks"><label><input type="checkbox" name="is_featured" <?= !empty($article['is_featured'])?'checked':'' ?>> Featured story</label><label><input type="checkbox" name="is_trending" <?= !empty($article['is_trending'])?'checked':'' ?>> Trending story</label></div></section><section class="panel"><h2>Image & media</h2><div class="grid two"><label>Featured image<input type="file" name="featured_image" accept="image/jpeg,image/png,image/webp"><small>JPG, PNG, WebP · max 3MB · max 4000×4000</small></label><label>Image alt text<input name="image_alt" maxlength="255" value="<?= e($article['image_alt']??'') ?>"></label><label>Image caption<input name="image_caption" maxlength="500" value="<?= e($article['image_caption']??'') ?>"></label><label>YouTube / Facebook / TikTok URL<input type="url" name="embed_url" value="<?= e($article['embed_url']??'') ?>"></label></div><?php if(!empty($article['featured_image'])): ?><p class="code-note">Current: <?= e($article['featured_image']) ?></p><?php endif; ?></section><section class="panel"><h2>Source & transparency</h2><div class="grid two"><label>Source name<input name="source_name" maxlength="190" value="<?= e($article['source_name']??'') ?>"></label><label>Original source URL<input type="url" name="source_url" value="<?= e($article['source_url']??'') ?>"></label><label>Fact-check rating<input name="fact_check_rating" maxlength="80" value="<?= e($article['fact_check_rating']??'') ?>" placeholder="True / False / Misleading / Unverified"></label><label>Reporter/editor disclosure<textarea name="reporter_disclosure" rows="4"><?= e($article['reporter_disclosure']??'') ?></textarea></label><label>Correction note<textarea name="correction_note" rows="4"><?= e($article['correction_note']??'') ?></textarea></label></div></section><section class="panel"><h2>SEO</h2><div class="grid two"><label>SEO title<input name="seo_title" maxlength="255" value="<?= e($article['seo_title']??'') ?>"></label><label>Meta description<textarea name="meta_description" maxlength="320" rows="3"><?= e($article['meta_description']??'') ?></textarea></label></div></section><button class="primary" type="submit">Save article</button></form><?php admin_footer();exit;
}

if ($action === 'preview') {
    $article=query_one('SELECT a.*,c.name category_name,au.name author_name FROM articles a JOIN categories c ON c.id=a.category_id JOIN authors au ON au.id=a.author_id WHERE a.id=?',[(int)($_GET['id']??0)]);if(!$article){flash('error','Article পাওয়া যায়নি।');post_redirect('articles');}admin_header('Article preview','articles');?><section class="panel"><p class="editor-note">Admin-only preview · Status: <?= e($article['status']) ?></p><span class="status <?= e($article['status']) ?>"><?= e($article['category_name']) ?></span><h1><?= e($article['title']) ?></h1><p class="muted"><?= e($article['excerpt']) ?></p><?php if($article['featured_image']): ?><img class="preview-image" src="<?= e(article_image($article['featured_image'])) ?>" alt="<?= e($article['image_alt']?:$article['title']) ?>"><?php endif; ?><div class="preview-body"><?= $article['body'] ?></div></section><?php admin_footer();exit;
}

if ($action === 'categories') {
    $editId=(int)($_GET['id']??0);$edit=$editId?query_one('SELECT * FROM categories WHERE id=?',[$editId]):null;$rows=query_all('SELECT c.*,COUNT(a.id) article_count FROM categories c LEFT JOIN articles a ON a.category_id=c.id GROUP BY c.id ORDER BY c.sort_order,c.name');admin_header('Categories','categories');?><div class="grid two"><section class="panel"><h2><?= $edit?'Edit':'Add' ?> category</h2><form method="post" class="grid"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="form_action" value="save_category"><input type="hidden" name="id" value="<?= $editId ?>"><label>Name<input name="name" required value="<?= e($edit['name']??'') ?>"></label><label>Slug<input name="slug" value="<?= e($edit['slug']??'') ?>"></label><label>Description<textarea name="description" rows="4"><?= e($edit['description']??'') ?></textarea></label><label>Sort order<input name="sort_order" type="number" value="<?= (int)($edit['sort_order']??0) ?>"></label><div class="checks"><label><input type="checkbox" name="is_active" <?= !isset($edit['is_active'])||$edit['is_active']?'checked':'' ?>> Active</label></div><button class="primary">Save category</button></form></section><section class="panel"><h2>All categories</h2><div class="table-wrap"><table><thead><tr><th>Name</th><th>Articles</th><th>Actions</th></tr></thead><tbody><?php foreach($rows as $row): ?><tr><td><?= e($row['name']) ?><br><small><?= e($row['slug']) ?></small></td><td><?= (int)$row['article_count'] ?></td><td><div class="actions"><a class="btn" href="<?= e(admin_url('categories',['id'=>$row['id']])) ?>">Edit</a><form method="post" data-confirm="Category delete করবেন?"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="form_action" value="delete_taxonomy"><input type="hidden" name="type" value="categories"><input type="hidden" name="id" value="<?= (int)$row['id'] ?>"><button class="danger">Delete</button></form></div></td></tr><?php endforeach; ?></tbody></table></div></section></div><?php admin_footer();exit;
}

if ($action === 'authors') {
    $editId=(int)($_GET['id']??0);$edit=$editId?query_one('SELECT * FROM authors WHERE id=?',[$editId]):null;$rows=query_all('SELECT au.*,COUNT(a.id) article_count FROM authors au LEFT JOIN articles a ON a.author_id=au.id GROUP BY au.id ORDER BY au.name');admin_header('Authors','authors');?><div class="grid two"><section class="panel"><h2><?= $edit?'Edit':'Add' ?> author</h2><form method="post" class="grid"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="form_action" value="save_author"><input type="hidden" name="id" value="<?= $editId ?>"><label>Name<input name="name" required value="<?= e($edit['name']??'') ?>"></label><label>Email<input name="email" type="email" value="<?= e($edit['email']??'') ?>"></label><label>Bio<textarea name="bio" rows="5"><?= e($edit['bio']??'') ?></textarea></label><div class="checks"><label><input type="checkbox" name="is_active" <?= !isset($edit['is_active'])||$edit['is_active']?'checked':'' ?>> Active</label></div><button class="primary">Save author</button></form></section><section class="panel"><h2>All authors</h2><div class="table-wrap"><table><thead><tr><th>Name</th><th>Articles</th><th>Actions</th></tr></thead><tbody><?php foreach($rows as $row): ?><tr><td><?= e($row['name']) ?><br><small><?= e($row['email']) ?></small></td><td><?= (int)$row['article_count'] ?></td><td><div class="actions"><a class="btn" href="<?= e(admin_url('authors',['id'=>$row['id']])) ?>">Edit</a><form method="post" data-confirm="Author delete করবেন?"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="form_action" value="delete_taxonomy"><input type="hidden" name="type" value="authors"><input type="hidden" name="id" value="<?= (int)$row['id'] ?>"><button class="danger">Delete</button></form></div></td></tr><?php endforeach; ?></tbody></table></div></section></div><?php admin_footer();exit;
}

if ($action === 'tags') {
    $editId=(int)($_GET['id']??0);$edit=$editId?query_one('SELECT * FROM tags WHERE id=?',[$editId]):null;$rows=query_all('SELECT t.*,COUNT(at.article_id) article_count FROM tags t LEFT JOIN article_tags at ON at.tag_id=t.id GROUP BY t.id ORDER BY t.name');admin_header('Tags','tags');?><div class="grid two"><section class="panel"><h2><?= $edit?'Edit':'Add' ?> tag</h2><form method="post" class="grid"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="form_action" value="save_tag"><input type="hidden" name="id" value="<?= $editId ?>"><label>Name<input name="name" required value="<?= e($edit['name']??'') ?>"></label><label>Slug<input name="slug" value="<?= e($edit['slug']??'') ?>"></label><button class="primary">Save tag</button></form></section><section class="panel"><h2>All tags</h2><div class="table-wrap"><table><thead><tr><th>Name</th><th>Articles</th><th>Actions</th></tr></thead><tbody><?php foreach($rows as $row): ?><tr><td><?= e($row['name']) ?><br><small><?= e($row['slug']) ?></small></td><td><?= (int)$row['article_count'] ?></td><td><div class="actions"><a class="btn" href="<?= e(admin_url('tags',['id'=>$row['id']])) ?>">Edit</a><form method="post" data-confirm="Tag delete করবেন?"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="form_action" value="delete_taxonomy"><input type="hidden" name="type" value="tags"><input type="hidden" name="id" value="<?= (int)$row['id'] ?>"><button class="danger">Delete</button></form></div></td></tr><?php endforeach; ?></tbody></table></div></section></div><?php admin_footer();exit;
}

if ($action === 'pages') {
    $editId=(int)($_GET['id']??0);$edit=$editId?query_one('SELECT * FROM pages WHERE id=?',[$editId]):null;$rows=query_all('SELECT * FROM pages ORDER BY title');admin_header('Static pages','pages');?><div class="grid two"><section class="panel"><h2><?= $edit?'Edit':'Add' ?> page</h2><form method="post" class="grid"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="form_action" value="save_page"><input type="hidden" name="id" value="<?= $editId ?>"><label>Title<input name="title" required value="<?= e($edit['title']??'') ?>"></label><label>Slug<input name="slug" value="<?= e($edit['slug']??'') ?>"></label><label>Body (safe HTML)<textarea class="code" name="body" rows="14" required><?= e($edit['body']??'<p>Page content…</p>') ?></textarea></label><label>SEO title<input name="seo_title" value="<?= e($edit['seo_title']??'') ?>"></label><label>Meta description<textarea name="meta_description" rows="3"><?= e($edit['meta_description']??'') ?></textarea></label><div class="checks"><label><input type="checkbox" name="is_published" <?= !isset($edit['is_published'])||$edit['is_published']?'checked':'' ?>> Published</label></div><button class="primary">Save page</button></form></section><section class="panel"><h2>All pages</h2><div class="table-wrap"><table><thead><tr><th>Page</th><th>Status</th><th>Actions</th></tr></thead><tbody><?php foreach($rows as $row): ?><tr><td><?= e($row['title']) ?><br><small><?= e($row['slug']) ?></small></td><td><?= $row['is_published']?'Published':'Hidden' ?></td><td><div class="actions"><a class="btn" href="<?= e(admin_url('pages',['id'=>$row['id']])) ?>">Edit</a><form method="post" data-confirm="Page delete করবেন?"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="form_action" value="delete_page"><input type="hidden" name="id" value="<?= (int)$row['id'] ?>"><button class="danger">Delete</button></form></div></td></tr><?php endforeach; ?></tbody></table></div></section></div><?php admin_footer();exit;
}

if ($action === 'ads') {
    $rows=query_all('SELECT * FROM advertisement_slots ORDER BY id');admin_header('Advertisement slots','ads');?><p class="editor-note"><strong>Ad code শুধু administrator edit করতে পারে।</strong> Google AdSense approval পাওয়ার আগে fake ad code দেবেন না। কোনো ad admin panel-এ দেখানো হয় না।</p><form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="form_action" value="save_ads"><?php foreach($rows as $row): ?><section class="panel"><div class="topbar"><div><h2><?= e($row['name']) ?></h2><code><?= e($row['slot_key']) ?></code></div><label><input type="checkbox" name="enabled[<?= e($row['slot_key']) ?>]" <?= $row['is_enabled']?'checked':'' ?>> Enabled</label></div><label>Advertisement code<textarea class="code" name="code[<?= e($row['slot_key']) ?>]" rows="6" placeholder="Approved ad network code এখানে দিন"><?= e($row['ad_code']) ?></textarea></label></section><?php endforeach; ?><button class="primary">Save ad slots</button></form><?php admin_footer();exit;
}

if ($action === 'messages') {
    $rows=query_all('SELECT * FROM contact_messages ORDER BY created_at DESC LIMIT 100');admin_header('Contact messages','messages');?><section class="panel"><div class="table-wrap"><table><thead><tr><th>Sender</th><th>Message</th><th>Status</th><th>Actions</th></tr></thead><tbody><?php foreach($rows as $row): ?><tr><td><strong><?= e($row['name']) ?></strong><br><a href="mailto:<?= e($row['email']) ?>"><?= e($row['email']) ?></a><br><small><?= e($row['created_at']) ?></small></td><td><strong><?= e($row['subject']) ?></strong><p><?= nl2br(e($row['message'])) ?></p></td><td><span class="status"><?= e($row['status']) ?></span></td><td><div class="actions"><form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="form_action" value="message_status"><input type="hidden" name="id" value="<?= (int)$row['id'] ?>"><input type="hidden" name="status" value="<?= $row['status']==='archived'?'read':'archived' ?>"><button><?= $row['status']==='archived'?'Restore':'Archive' ?></button></form><form method="post" data-confirm="Message delete করবেন?"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="form_action" value="delete_message"><input type="hidden" name="id" value="<?= (int)$row['id'] ?>"><button class="danger">Delete</button></form></div></td></tr><?php endforeach; ?></tbody></table></div><?php if(!$rows): ?><div class="empty">কোনো message নেই।</div><?php endif; ?></section><?php admin_footer();exit;
}

if ($action === 'settings') {
    admin_header('Website settings','settings');?><form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="form_action" value="save_settings"><section class="panel"><h2>Brand & contact</h2><div class="grid two"><label>Website name<input name="site_name" value="<?= e(setting('site_name','ViralKeno')) ?>"></label><label>Tagline<input name="tagline" value="<?= e(setting('tagline','কী Viral, কেন Viral')) ?>"></label><label>Contact email<input type="email" name="contact_email" value="<?= e(setting('contact_email')) ?>"></label><label>Facebook URL<input type="url" name="facebook_url" value="<?= e(setting('facebook_url')) ?>"></label><label>YouTube URL<input type="url" name="youtube_url" value="<?= e(setting('youtube_url')) ?>"></label><label>Logo image<input type="file" name="logo_file" accept="image/jpeg,image/png,image/webp"></label><label>Favicon image<input type="file" name="favicon_file" accept="image/jpeg,image/png,image/webp"></label></div></section><section class="panel"><h2>SEO & layout</h2><div class="grid two"><label>Site meta description<textarea name="meta_description" maxlength="320" rows="4"><?= e(setting('meta_description')) ?></textarea></label><label>In-article ad after paragraph<input type="number" name="in_article_after_paragraph" min="1" max="12" value="<?= e(setting('in_article_after_paragraph','3')) ?>"></label></div></section><section class="panel"><div class="topbar"><div><h2>AI Newsroom</h2><p class="muted">Provider, model, API credentials, ৫টি daily schedule, writing rules এবং run history.</p></div><a class="btn primary" href="<?= e(base_url()) ?>/admin/ai.php">Open AI Control</a></div></section><button class="primary">Save settings</button></form><?php admin_footer();exit;
}

if ($action === 'security') {
    $sessions=query_all('SELECT id,user_agent,created_at,expires_at FROM admin_sessions WHERE admin_id=? AND revoked_at IS NULL AND expires_at>NOW() ORDER BY created_at DESC',[$admin['id']]);admin_header('Security','security');?><div class="grid two"><section class="panel"><h2>Change password</h2><form method="post" class="grid"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="form_action" value="change_password"><label>Current password<input type="password" name="current_password" required autocomplete="current-password"></label><label>New strong password<input type="password" name="new_password" required minlength="12" autocomplete="new-password"><small>12+ characters, uppercase, lowercase ও number.</small></label><button class="primary">Change password</button></form></section><section class="panel"><h2>Active sessions</h2><p><?= count($sessions) ?>টি active admin session.</p><form method="post" data-confirm="সব device থেকে logout করবেন?"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="form_action" value="logout_all"><button class="danger">Logout from all sessions</button></form></section></div><?php admin_footer();exit;
}

http_response_code(404);admin_header('Admin page not found','dashboard');?><section class="panel"><p>Requested admin page পাওয়া যায়নি।</p><a class="btn primary" href="<?= e(admin_url()) ?>">Dashboard</a></section><?php admin_footer();
