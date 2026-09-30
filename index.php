<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';

$route = route_path();
$route = $route === 'index.php' ? '' : $route;

// Visitor-triggered AI scheduler endpoint.
// A normal visitor page calls this asynchronously after the page has loaded.
if ($route === '__ai-visitor-tick') {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        http_response_code(405);
        header('Allow: POST');
        exit;
    }

    verify_csrf();
    // Release PHP's session lock before the potentially long AI request, so this
    // visitor can continue browsing other pages without waiting for AI generation.
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

    require_once __DIR__ . '/app/ai.php';

    ignore_user_abort(true);
    @set_time_limit(65);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

    try {
        $result = ai_visitor_trigger(60);
        echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (Throwable $e) {
        error_log('ViralKeno visitor AI trigger error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['status' => 'failed', 'message' => 'Visitor AI trigger failed.'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}
$siteName = (string)setting('site_name', 'ViralKeno');
$tagline = (string)setting('tagline', 'কী Viral, কেন Viral');

function published_where(): string
{
    return "a.status='published' AND a.published_at<=NOW()";
}

function render_header(array $meta = []): void
{
    global $siteName, $tagline;
    $title = $meta['title'] ?? $siteName . ' — ' . $tagline;
    $description = $meta['description'] ?? setting('meta_description', 'বাংলায় ভাইরাল গল্প, creator update, entertainment, technology, AI trends এবং fact-check।');
    $canonical = $meta['canonical'] ?? url(route_path());
    $image = $meta['image'] ?? '';
    $type = $meta['type'] ?? 'website';
    $categories = query_all('SELECT name, slug FROM categories WHERE is_active=1 ORDER BY sort_order, name LIMIT 8');
    ?>
<!doctype html>
<html lang="bn" data-theme="light">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= e($title) ?></title>
  <meta name="description" content="<?= e($description) ?>">
  <link rel="canonical" href="<?= e($canonical) ?>">
  <meta property="og:locale" content="bn_BD"><meta property="og:type" content="<?= e($type) ?>">
  <meta property="og:title" content="<?= e($title) ?>"><meta property="og:description" content="<?= e($description) ?>">
  <meta property="og:url" content="<?= e($canonical) ?>"><?php if ($image): ?><meta property="og:image" content="<?= e($image) ?>"><?php endif; ?>
  <meta name="twitter:card" content="summary_large_image"><meta name="twitter:title" content="<?= e($title) ?>"><meta name="twitter:description" content="<?= e($description) ?>">
  <?php if ($image): ?><meta name="twitter:image" content="<?= e($image) ?>"><?php endif; ?>
  <link rel="alternate" type="application/rss+xml" title="ViralKeno RSS" href="<?= e(url('rss.xml')) ?>">
  <?php if (setting('favicon_path')): ?><link rel="icon" href="<?= e(article_image((string)setting('favicon_path'))) ?>"><?php endif; ?>
  <link rel="stylesheet" href="<?= e(base_url()) ?>/assets/css/app.css?v=1.0.0">
  <link rel="stylesheet" href="<?= e(base_url()) ?>/assets/css/brand.css?v=1.0.0">
  <script>try{document.documentElement.dataset.theme=localStorage.getItem('vk-theme')||'light'}catch(e){}</script>
  <?php if (!empty($meta['jsonld'])): ?><script type="application/ld+json"><?= json_encode($meta['jsonld'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script><?php endif; ?>
</head>
<body>
<a class="skip-link" href="#main">মূল কনটেন্টে যান</a>
<header class="site-header">
  <div class="top-strip"><div class="container"><span><?= e(date('l, d F Y')) ?></span><span class="trust-note">স্বচ্ছ উৎস · দায়িত্বশীল প্রতিবেদন</span></div></div>
  <div class="header-main container">
    <button class="icon-btn menu-toggle" type="button" aria-label="মেনু খুলুন" aria-expanded="false" aria-controls="site-nav">☰</button>
    <a class="brand" href="<?= e(url()) ?>" aria-label="ViralKeno হোম">
      <?php if (setting('logo_path')): ?><img class="brand-logo" src="<?= e(article_image((string)setting('logo_path'))) ?>" alt="<?= e($siteName) ?> logo" width="44" height="44"><?php else: ?><span class="brand-mark">VK</span><?php endif; ?><span><strong><?= e($siteName) ?></strong><small><?= e($tagline) ?></small></span>
    </a>
    <form class="header-search" action="<?= e(url('search')) ?>" method="get" role="search">
      <?php if (!cfg('app.pretty_urls', true)): ?><input type="hidden" name="route" value="search"><?php endif; ?>
      <label class="sr-only" for="header-q">খুঁজুন</label><input id="header-q" name="q" type="search" placeholder="কী খুঁজছেন?" value="<?= e($_GET['q'] ?? '') ?>"><button type="submit">খুঁজুন</button>
    </form>
    <button class="icon-btn theme-toggle" type="button" aria-label="রঙের মোড বদলান">◐</button>
  </div>
  <nav id="site-nav" class="main-nav" aria-label="প্রধান নেভিগেশন"><div class="container">
    <a href="<?= e(url('latest')) ?>">Latest Viral</a>
    <?php foreach ($categories as $cat): ?><a href="<?= e(url('category/' . $cat['slug'])) ?>"><?= e($cat['name']) ?></a><?php endforeach; ?>
  </div></nav>
</header>
<?php
    $trending = query_all('SELECT title, slug FROM articles a WHERE ' . published_where() . ' AND is_trending=1 ORDER BY published_at DESC LIMIT 5');
    if ($trending): ?>
<section class="ticker" aria-label="ট্রেন্ডিং"><div class="container"><strong>ট্রেন্ডিং</strong><div class="ticker-track"><?php foreach ($trending as $item): ?><a href="<?= e(url('article/' . $item['slug'])) ?>"><?= e($item['title']) ?></a><?php endforeach; ?></div></div></section>
<?php endif; ?>
<main id="main" class="site-main">
<?php
}

function render_footer(): void
{
    global $siteName, $tagline;
    echo render_ad('footer');
    ?>
</main>
<section class="follow"><div class="container follow-inner"><div><span class="eyebrow">VIRALKENO COMMUNITY</span><h2>ভাইরাল হওয়ার আগে গল্পটা বুঝুন</h2><p>বিশ্বাসযোগ্য উৎসসহ সহজ বাংলায় প্রতিদিনের internet culture.</p></div><div class="social-links"><a href="<?= e(setting('facebook_url', '#')) ?>" rel="noopener">Facebook</a><a href="<?= e(setting('youtube_url', '#')) ?>" rel="noopener">YouTube</a></div></div></section>
<section class="newsletter"><div class="container"><div><strong>ViralKeno Brief</strong><span>Newsletter শিগগিরই আসছে</span></div><form data-newsletter><label class="sr-only" for="newsletter-email">ইমেইল</label><input id="newsletter-email" type="email" placeholder="আপনার ইমেইল" required><button type="submit">Notify me</button></form></div></section>
<footer class="footer"><div class="container footer-grid"><div><a class="brand footer-brand" href="<?= e(url()) ?>"><span class="brand-mark">VK</span><span><strong><?= e($siteName) ?></strong><small><?= e($tagline) ?></small></span></a><p>ViralKeno প্রকাশিত তথ্যকে উৎস, প্রসঙ্গ ও স্বচ্ছতার সঙ্গে উপস্থাপন করে।</p></div><div><strong>সম্পাদকীয়</strong><a href="<?= e(url('page/about-us')) ?>">About Us</a><a href="<?= e(url('page/editorial-policy')) ?>">Editorial Policy</a><a href="<?= e(url('page/corrections-policy')) ?>">Corrections Policy</a></div><div><strong>আইনি</strong><a href="<?= e(url('page/privacy-policy')) ?>">Privacy Policy</a><a href="<?= e(url('page/disclaimer')) ?>">Disclaimer</a><a href="<?= e(url('contact')) ?>">Contact Us</a></div></div><div class="container footer-bottom"><span>© <?= date('Y') ?> ViralKeno. সর্বস্বত্ব সংরক্ষিত।</span><a href="<?= e(url('rss.xml')) ?>">RSS</a></div></footer>
<div class="consent" data-consent hidden><p>সাইটের প্রয়োজনীয় কার্যক্রম ও ভবিষ্যৎ analytics/ads consent-এর জন্য cookie notice রাখা হয়েছে।</p><div><button data-consent-accept>ঠিক আছে</button><button class="text-btn" data-consent-close>এখন নয়</button></div></div>
<script>
// Visitor-triggered AI schedule check. It runs after the visible page is loaded,
// so rendering is not blocked by AI research/generation.
window.addEventListener('load', function () {
  window.setTimeout(function () {
    try {
      if (!window.fetch) return;
      var body = new URLSearchParams();
      body.set('csrf', <?= json_encode(csrf_token(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>);
      fetch(<?= json_encode(url('__ai-visitor-tick'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>, {
        method: 'POST',
        credentials: 'same-origin',
        keepalive: true,
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
          'X-Requested-With': 'XMLHttpRequest'
        },
        body: body.toString()
      }).catch(function () {});
    } catch (e) {}
  }, 2500);
});
</script>
<script src="<?= e(base_url()) ?>/assets/js/app.js?v=1.0.0" defer></script>
</body></html>
<?php
}

function article_card(array $article, string $class = ''): void
{
    $image = article_image($article['featured_image'] ?? null);
    ?>
<article class="story-card <?= e($class) ?>">
  <a class="story-image" href="<?= e(url('article/' . $article['slug'])) ?>"><img src="<?= e($image) ?>" alt="<?= e($article['image_alt'] ?: $article['title']) ?>" width="720" height="405" loading="lazy"></a>
  <div class="story-copy"><span class="category-label"><?= e($article['category_name'] ?? 'Viral') ?></span><h3><a href="<?= e(url('article/' . $article['slug'])) ?>"><?= e($article['title']) ?></a></h3><?php if (!empty($article['excerpt'])): ?><p><?= e($article['excerpt']) ?></p><?php endif; ?><div class="meta"><time datetime="<?= e($article['published_at'] ?? '') ?>"><?= e(date('d M Y', strtotime($article['published_at'] ?? 'now'))) ?></time><span><?= reading_time($article['body'] ?? '') ?> মিনিট</span></div></div>
</article>
<?php
}

function pagination_links(array $pager, array $query = []): void
{
    if ($pager['pages'] <= 1) return;
    if (!cfg('app.pretty_urls', true)) $query = ['route' => route_path()] + $query;
    echo '<nav class="pagination" aria-label="পৃষ্ঠা">';
    for ($i = 1; $i <= $pager['pages']; $i++) {
        $query['page'] = $i;
        $class = $i === $pager['page'] ? 'active' : '';
        echo '<a class="' . $class . '" href="?' . e(http_build_query($query)) . '"' . ($i === $pager['page'] ? ' aria-current="page"' : '') . '>' . $i . '</a>';
    }
    echo '</nav>';
}

function article_select(): string
{
    return 'SELECT a.*, c.name category_name, c.slug category_slug, au.name author_name, au.bio author_bio, au.avatar author_avatar FROM articles a JOIN categories c ON c.id=a.category_id JOIN authors au ON au.id=a.author_id ';
}

if ($route === '' || $route === 'home') {
    $featured = query_one(article_select() . ' WHERE ' . published_where() . ' AND a.is_featured=1 ORDER BY a.published_at DESC LIMIT 1');
    $secondary = query_all(article_select() . ' WHERE ' . published_where() . ' AND a.id<>? ORDER BY a.is_trending DESC, a.published_at DESC LIMIT 4', [$featured['id'] ?? 0]);
    $latest = query_all(article_select() . ' WHERE ' . published_where() . ' ORDER BY a.published_at DESC LIMIT 8');
    $fact = query_all(article_select() . " WHERE " . published_where() . " AND c.slug='fact-check' ORDER BY a.published_at DESC LIMIT 3");
    $most = query_all(article_select() . ' WHERE ' . published_where() . ' ORDER BY a.views_count DESC, a.published_at DESC LIMIT 5');
    $sections = query_all('SELECT * FROM categories WHERE is_active=1 ORDER BY sort_order LIMIT 4');
    render_header(['jsonld' => [['@context'=>'https://schema.org','@type'=>'Organization','name'=>$siteName,'url'=>base_url()],['@context'=>'https://schema.org','@type'=>'WebSite','name'=>$siteName,'url'=>base_url(),'potentialAction'=>['@type'=>'SearchAction','target'=>url('search') . '?q={search_term_string}','query-input'=>'required name=search_term_string']]]]);
    echo '<div class="container">' . render_ad('header') . '</div>';
    ?>
<section class="hero container">
  <?php if ($featured): ?><article class="hero-lead"><a href="<?= e(url('article/' . $featured['slug'])) ?>"><img src="<?= e(article_image($featured['featured_image'])) ?>" alt="<?= e($featured['image_alt'] ?: $featured['title']) ?>" width="1000" height="600"></a><div class="hero-copy"><span class="category-label"><?= e($featured['category_name']) ?></span><h1><a href="<?= e(url('article/' . $featured['slug'])) ?>"><?= e($featured['title']) ?></a></h1><p><?= e($featured['excerpt']) ?></p><div class="meta"><span><?= e($featured['author_name']) ?></span><time><?= e(date('d M Y', strtotime($featured['published_at']))) ?></time></div></div></article><?php endif; ?>
  <div class="hero-secondary"><?php foreach ($secondary as $item) article_card($item, 'compact'); ?></div>
</section>
<div class="container content-layout"><div class="content-column">
  <section class="section"><div class="section-head"><div><span class="eyebrow">NOW ON THE INTERNET</span><h2>সর্বশেষ ভাইরাল</h2></div><a href="<?= e(url('latest')) ?>">সব দেখুন →</a></div><div class="story-grid"><?php foreach ($latest as $i=>$item) { article_card($item); if ($i === 2) echo render_ad('mobile_infeed'); } ?></div></section>
  <?php foreach ($sections as $section): $items = query_all(article_select() . ' WHERE ' . published_where() . ' AND c.id=? ORDER BY a.published_at DESC LIMIT 4', [$section['id']]); if (!$items) continue; ?>
  <section class="section category-section"><div class="section-head"><div><span class="eyebrow"><?= e(strtoupper($section['slug'])) ?></span><h2><?= e($section['name']) ?></h2></div><a href="<?= e(url('category/' . $section['slug'])) ?>">আরও পড়ুন →</a></div><div class="horizontal-stories"><?php foreach ($items as $item) article_card($item, 'horizontal'); ?></div></section>
  <?php endforeach; ?>
  <?php if ($fact): ?><section class="section fact-section"><div class="section-head"><div><span class="eyebrow">VERIFY BEFORE SHARE</span><h2>Fact Check</h2></div></div><div class="fact-grid"><?php foreach ($fact as $item): ?><article><span class="rating"><?= e($item['fact_check_rating'] ?: 'যাচাই চলছে') ?></span><?php article_card($item, 'fact-card'); ?></article><?php endforeach; ?></div></section><?php endif; ?>
</div><aside class="sidebar"><div class="sidebar-sticky"><?= render_ad('sidebar') ?><section class="trend-panel"><div class="section-head"><div><span class="eyebrow">READER PICKS</span><h2>সবচেয়ে পঠিত</h2></div></div><ol><?php foreach ($most as $item): ?><li><a href="<?= e(url('article/' . $item['slug'])) ?>"><?= e($item['title']) ?></a><span><?= number_format((int)$item['views_count']) ?> views</span></li><?php endforeach; ?></ol></section></div></aside></div>
<?php
    render_footer();
    exit;
}

if ($route === 'latest' || str_starts_with($route, 'category/') || in_array($route, ['social-media','entertainment','creators-influencers','technology-ai','strange-but-true','fact-check'], true)) {
    $categorySlug = str_starts_with($route, 'category/') ? substr($route, 9) : ($route === 'latest' ? '' : $route);
    $category = $categorySlug ? query_one('SELECT * FROM categories WHERE slug=? AND is_active=1', [$categorySlug]) : null;
    if ($categorySlug && !$category) { http_response_code(404); $route = '__404__'; }
    else {
        $page = max(1, (int)($_GET['page'] ?? 1));
        $where = published_where(); $params = [];
        if ($category) { $where .= ' AND c.id=?'; $params[] = $category['id']; }
        $count = query_one('SELECT COUNT(*) n FROM articles a JOIN categories c ON c.id=a.category_id WHERE ' . $where, $params)['n'] ?? 0;
        $pager = paginate($page, 9, (int)$count);
        $items = query_all(article_select() . ' WHERE ' . $where . ' ORDER BY a.published_at DESC LIMIT ' . $pager['per_page'] . ' OFFSET ' . $pager['offset'], $params);
        $heading = $category['name'] ?? 'Latest Viral';
        $archiveCanonical = url($route, $pager['page'] > 1 ? ['page'=>$pager['page']] : []);
        render_header(['title'=>$heading . ($pager['page'] > 1 ? ' — Page '.$pager['page'] : '') . ' | ' . $siteName,'description'=>$category['description'] ?? 'ViralKeno-এর সর্বশেষ ভাইরাল প্রতিবেদন।','canonical'=>$archiveCanonical]);
        ?><section class="archive-head"><div class="container"><span class="eyebrow">VIRALKENO ARCHIVE</span><h1><?= e($heading) ?></h1><p><?= e($category['description'] ?? 'ইন্টারনেটে আলোচিত নতুন গল্প, উৎস ও প্রয়োজনীয় প্রসঙ্গ এক জায়গায়।') ?></p></div></section><div class="container archive-grid"><?php foreach ($items as $item) article_card($item); ?></div><?php if (!$items): ?><div class="container empty-state">এখনো কোনো প্রকাশিত প্রতিবেদন নেই।</div><?php endif; ?><div class="container"><?php pagination_links($pager); ?></div><?php
        render_footer(); exit;
    }
}

if ($route === 'search') {
    $q = trim((string)($_GET['q'] ?? ''));
    $page = max(1, (int)($_GET['page'] ?? 1));
    $params = ['%' . $q . '%', '%' . $q . '%', '%' . $q . '%'];
    $where = published_where() . ' AND (a.title LIKE ? OR a.excerpt LIKE ? OR a.body LIKE ?)';
    $count = $q === '' ? 0 : (int)(query_one('SELECT COUNT(*) n FROM articles a WHERE ' . $where, $params)['n'] ?? 0);
    $pager = paginate($page, 9, $count);
    $items = $q === '' ? [] : query_all(article_select() . ' WHERE ' . $where . ' ORDER BY a.published_at DESC LIMIT ' . $pager['per_page'] . ' OFFSET ' . $pager['offset'], $params);
    $searchQuery=['q'=>$q]; if($pager['page']>1)$searchQuery['page']=$pager['page'];
    render_header(['title'=>'Search: ' . $q . ($pager['page'] > 1 ? ' — Page '.$pager['page'] : '') . ' | ' . $siteName,'canonical'=>url('search',$searchQuery)]);
    ?><section class="archive-head"><div class="container"><span class="eyebrow">SEARCH</span><h1>“<?= e($q ?: '…') ?>” এর ফলাফল</h1><p><?= number_format($count) ?>টি প্রতিবেদন পাওয়া গেছে</p></div></section><div class="container archive-grid"><?php foreach ($items as $item) article_card($item); ?></div><?php if (!$items): ?><div class="container empty-state">অন্য কোনো শব্দ দিয়ে খুঁজে দেখুন।</div><?php endif; ?><div class="container"><?php pagination_links($pager, ['q'=>$q]); ?></div><?php
    render_footer(); exit;
}

if (str_starts_with($route, 'article/')) {
    $slug = substr($route, 8);
    $article = query_one(article_select() . ' WHERE a.slug=? AND ' . published_where() . ' LIMIT 1', [$slug]);
    if (!$article) { http_response_code(404); $route = '__404__'; }
    else {
        $visitorKey = hash('sha256', session_id() . '|' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
        $stmt = db()->prepare('INSERT IGNORE INTO article_views(article_id,visitor_key,viewed_on,created_at) VALUES(?,?,CURDATE(),NOW())');
        $stmt->execute([$article['id'], $visitorKey]);
        if ($stmt->rowCount() > 0) db()->prepare('UPDATE articles SET views_count=views_count+1 WHERE id=?')->execute([$article['id']]);
        $tags = query_all('SELECT t.name,t.slug FROM tags t JOIN article_tags at ON at.tag_id=t.id WHERE at.article_id=? ORDER BY t.name', [$article['id']]);
        $related = query_all(article_select() . ' WHERE ' . published_where() . ' AND a.category_id=? AND a.id<>? ORDER BY a.published_at DESC LIMIT 3', [$article['category_id'],$article['id']]);
        $prev = query_one('SELECT title,slug FROM articles WHERE status="published" AND published_at<? ORDER BY published_at DESC LIMIT 1', [$article['published_at']]);
        $next = query_one('SELECT title,slug FROM articles WHERE status="published" AND published_at>? AND published_at<=NOW() ORDER BY published_at ASC LIMIT 1', [$article['published_at']]);
        $schema = ['@context'=>'https://schema.org','@type'=>'NewsArticle','headline'=>$article['title'],'description'=>$article['excerpt'],'image'=>[article_image($article['featured_image'])],'datePublished'=>date(DATE_ATOM,strtotime($article['published_at'])),'dateModified'=>date(DATE_ATOM,strtotime($article['updated_at'])),'author'=>['@type'=>'Person','name'=>$article['author_name']],'publisher'=>['@type'=>'Organization','name'=>$siteName,'url'=>base_url()],'mainEntityOfPage'=>url('article/'.$article['slug'])];
        render_header(['title'=>($article['seo_title'] ?: $article['title']) . ' | ' . $siteName,'description'=>$article['meta_description'] ?: $article['excerpt'],'canonical'=>url('article/'.$article['slug']),'image'=>article_image($article['featured_image']),'type'=>'article','jsonld'=>[$schema,['@context'=>'https://schema.org','@type'=>'BreadcrumbList','itemListElement'=>[['@type'=>'ListItem','position'=>1,'name'=>'Home','item'=>base_url()],['@type'=>'ListItem','position'=>2,'name'=>$article['category_name'],'item'=>url('category/'.$article['category_slug'])],['@type'=>'ListItem','position'=>3,'name'=>$article['title'],'item'=>url('article/'.$article['slug'])]]]]]);
        ?>
<div class="container article-layout"><article class="article"><nav class="breadcrumbs" aria-label="Breadcrumb"><a href="<?= e(url()) ?>">Home</a><span>›</span><a href="<?= e(url('category/'.$article['category_slug'])) ?>"><?= e($article['category_name']) ?></a></nav><span class="category-label"><?= e($article['content_label'] ?: $article['category_name']) ?></span><h1><?= e($article['title']) ?></h1><p class="article-excerpt"><?= e($article['excerpt']) ?></p><div class="article-byline"><div><strong><?= e($article['author_name']) ?></strong><span>প্রকাশ: <time datetime="<?= e($article['published_at']) ?>"><?= e(date('d M Y, h:i A', strtotime($article['published_at']))) ?></time></span><?php if ($article['updated_at'] !== $article['created_at']): ?><span>আপডেট: <?= e(date('d M Y, h:i A', strtotime($article['updated_at']))) ?></span><?php endif; ?></div><div><span><?= reading_time($article['body']) ?> মিনিট পড়া</span><span><?= number_format((int)$article['views_count'] + 1) ?> views</span></div></div><figure class="article-figure"><img src="<?= e(article_image($article['featured_image'])) ?>" alt="<?= e($article['image_alt'] ?: $article['title']) ?>" width="1200" height="675"><?php if ($article['image_caption']): ?><figcaption><?= e($article['image_caption']) ?></figcaption><?php endif; ?></figure>
<?php if ($article['fact_check_rating']): ?><div class="fact-rating"><span>FACT CHECK RATING</span><strong><?= e($article['fact_check_rating']) ?></strong></div><?php endif; ?>
<div class="share-row" data-share><strong>Share</strong><a href="https://www.facebook.com/sharer/sharer.php?u=<?= rawurlencode(url('article/'.$article['slug'])) ?>" target="_blank" rel="noopener">Facebook</a><a href="https://wa.me/?text=<?= rawurlencode($article['title'].' '.url('article/'.$article['slug'])) ?>" target="_blank" rel="noopener">WhatsApp</a><button type="button" data-copy-link="<?= e(url('article/'.$article['slug'])) ?>">Link copy</button></div>
<div class="article-body"><?php $paragraphs = preg_split('/(<\/p>)/i', $article['body'], -1, PREG_SPLIT_DELIM_CAPTURE); $countP=0; foreach ($paragraphs as $part) { echo $part; if (str_contains(strtolower($part), '</p>')) { $countP++; if ($countP === (int)setting('in_article_after_paragraph', 3)) echo render_ad('in_article'); } } ?></div>
<?php $embed=safe_embed($article['embed_url']); if ($embed): ?><div class="video-wrap"><iframe src="<?= e($embed) ?>" title="Embedded video" loading="lazy" allowfullscreen></iframe></div><?php elseif ($article['embed_url']): ?><p class="source-box"><strong>মূল ভিডিও:</strong> <a href="<?= e($article['embed_url']) ?>" target="_blank" rel="nofollow noopener">Original platform-এ দেখুন</a></p><?php endif; ?>
<div class="source-box"><strong>মূল উৎস</strong><span><?= e($article['source_name'] ?: 'উৎস উল্লেখ করা হয়নি') ?></span><?php if ($article['source_url']): ?><a href="<?= e($article['source_url']) ?>" target="_blank" rel="nofollow noopener">Original source ↗</a><?php endif; ?></div>
<?php if ($article['reporter_disclosure']): ?><div class="disclosure"><strong>Reporter / Editor disclosure</strong><p><?= nl2br(e($article['reporter_disclosure'])) ?></p></div><?php endif; ?><?php if ($article['correction_note']): ?><div class="correction"><strong>Correction note</strong><p><?= nl2br(e($article['correction_note'])) ?></p></div><?php endif; ?>
<?php if ($tags): ?><div class="tags"><?php foreach($tags as $tag): ?><a href="<?= e(url('search',['q'=>$tag['name']])) ?>">#<?= e($tag['name']) ?></a><?php endforeach; ?></div><?php endif; ?>
<nav class="prev-next"><?php if($prev): ?><a href="<?= e(url('article/'.$prev['slug'])) ?>"><span>← আগের প্রতিবেদন</span><strong><?= e($prev['title']) ?></strong></a><?php endif; ?><?php if($next): ?><a href="<?= e(url('article/'.$next['slug'])) ?>"><span>পরের প্রতিবেদন →</span><strong><?= e($next['title']) ?></strong></a><?php endif; ?></nav></article><aside class="sidebar"><div class="sidebar-sticky"><?= render_ad('sidebar') ?><div class="author-box"><span class="eyebrow">AUTHOR</span><h3><?= e($article['author_name']) ?></h3><p><?= e($article['author_bio'] ?: 'ViralKeno editorial team-এর একজন লেখক।') ?></p></div></div></aside></div>
<?php if($related): ?><section class="container section"><div class="section-head"><div><span class="eyebrow">KEEP READING</span><h2>সম্পর্কিত প্রতিবেদন</h2></div></div><div class="related-grid"><?php foreach($related as $item) article_card($item); ?></div></section><?php endif; ?>
<?php render_footer(); exit;
    }
}

if ($route === 'contact') {
    $sent = false; $errors=[];
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();
        $name=trim((string)($_POST['name']??'')); $email=trim((string)($_POST['email']??'')); $subject=trim((string)($_POST['subject']??'')); $message=trim((string)($_POST['message']??''));
        if (mb_strlen($name)<2) $errors[]='নাম লিখুন।'; if(!filter_var($email,FILTER_VALIDATE_EMAIL)) $errors[]='সঠিক ইমেইল দিন।'; if(mb_strlen($message)<10) $errors[]='মেসেজটি একটু বিস্তারিত লিখুন।';
        if(!$errors){db()->prepare('INSERT INTO contact_messages(name,email,subject,message,ip_hash,status,created_at) VALUES(?,?,?,?,?,"new",NOW())')->execute([$name,$email,$subject,$message,hash('sha256',$_SERVER['REMOTE_ADDR']??'')]); $sent=true;}
    }
    render_header(['title'=>'Contact Us | '.$siteName,'description'=>'ViralKeno সম্পাদকীয় টিমের সঙ্গে যোগাযোগ করুন।']);
    ?><section class="archive-head"><div class="container"><span class="eyebrow">CONTACT</span><h1>আমাদের সঙ্গে কথা বলুন</h1><p>Story tip, correction বা business inquiry পাঠাতে পারেন।</p></div></section><div class="container contact-layout"><div><h2>যোগাযোগের আগে</h2><p>কোনো দাবির fact-check চাইলে original post URL ও প্রয়োজনীয় screenshot/source যোগ করুন। ব্যক্তিগত বা leaked তথ্য পাঠাবেন না।</p><p><strong>Email:</strong> <?= e(setting('contact_email','editor@viralkeno.com')) ?></p></div><form class="contact-form" method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><?php if($sent): ?><div class="alert success">মেসেজ পাওয়া গেছে। ধন্যবাদ!</div><?php endif; ?><?php foreach($errors as $error): ?><div class="alert error"><?= e($error) ?></div><?php endforeach; ?><label>নাম<input name="name" required maxlength="100" value="<?= e($_POST['name']??'') ?>"></label><label>ইমেইল<input type="email" name="email" required maxlength="190" value="<?= e($_POST['email']??'') ?>"></label><label>বিষয়<input name="subject" maxlength="190" value="<?= e($_POST['subject']??'') ?>"></label><label>মেসেজ<textarea name="message" required rows="7" maxlength="5000"><?= e($_POST['message']??'') ?></textarea></label><button class="primary-btn" type="submit">মেসেজ পাঠান</button></form></div><?php
    render_footer(); exit;
}

if (str_starts_with($route, 'page/')) {
    $slug=substr($route,5); $page=query_one('SELECT * FROM pages WHERE slug=? AND is_published=1',[$slug]);
    if(!$page){http_response_code(404);$route='__404__';}else{render_header(['title'=>($page['seo_title']?:$page['title']).' | '.$siteName,'description'=>$page['meta_description']?:mb_substr(strip_tags($page['body']),0,155)]);?><article class="container static-page"><span class="eyebrow">VIRALKENO</span><h1><?= e($page['title']) ?></h1><div class="article-body"><?= $page['body'] ?></div></article><?php render_footer();exit;}
}

if ($route === 'sitemap.xml') {
    header('Content-Type: application/xml; charset=utf-8');
    $urls=query_all('SELECT slug,updated_at FROM articles WHERE status="published" AND published_at<=NOW() ORDER BY updated_at DESC');
    echo '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>'.e(base_url()).'</loc></url>';
    foreach($urls as $item) echo '<url><loc>'.e(url('article/'.$item['slug'])).'</loc><lastmod>'.e(date('c',strtotime($item['updated_at']))).'</lastmod></url>';
    echo '</urlset>'; exit;
}

if ($route === 'rss.xml') {
    header('Content-Type: application/rss+xml; charset=utf-8');
    $items=query_all(article_select().' WHERE '.published_where().' ORDER BY a.published_at DESC LIMIT 20');
    echo '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0"><channel><title>'.e($siteName).'</title><link>'.e(base_url()).'</link><description>'.e($tagline).'</description>';
    foreach($items as $item) echo '<item><title>'.e($item['title']).'</title><link>'.e(url('article/'.$item['slug'])).'</link><guid>'.e(url('article/'.$item['slug'])).'</guid><pubDate>'.e(date(DATE_RSS,strtotime($item['published_at']))).'</pubDate><description>'.e($item['excerpt']).'</description></item>';
    echo '</channel></rss>'; exit;
}

http_response_code(404);
render_header(['title'=>'404 — পেজ পাওয়া যায়নি | '.$siteName,'description'=>'আপনি যে পেজটি খুঁজছেন সেটি পাওয়া যায়নি।']);
?><section class="not-found container"><span>404</span><h1>এই লিংকটা আর Viral নেই</h1><p>পেজটি সরানো হয়েছে, ঠিকানা বদলেছে অথবা লিংকে ভুল আছে।</p><a class="primary-btn" href="<?= e(url()) ?>">হোমে ফিরে যান</a></section><?php
render_footer();
