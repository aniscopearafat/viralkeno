# 📰 ViralKeno

**ViralKeno** is a Bengali viral news and internet culture website built with PHP and MySQL.

The platform is designed to publish trending stories, social media news, entertainment updates, technology and AI news, creator/influencer stories, unusual events, internet culture, international news, and fact-checking content.

The project includes a custom administration panel, article management system, categories, tags, authors, advertising management, SEO tools, static pages, messages, and security settings.

---

## 🌐 Website

**Website:**  
https://viralkeno.com

**GitHub Repository:**  
https://github.com/aniscopearafat/viralkeno

---

# ✨ Features

## 📰 Article Management

ViralKeno allows administrators and authorized users to manage news articles with:

- Article title
- Slug
- Article body
- Featured image
- Category
- Tags
- Author
- Publication date
- Publication status
- SEO title
- Meta description
- Source links
- Trending status
- Featured status
- Fact-check labels
- Related content

---

## 📂 Categories

The site can organize content into categories such as:

- Social Media
- Entertainment
- Creators & Influencers
- Technology & AI
- Strange but True
- Fact Check
- Internet Culture
- International

Additional categories can be added through the administration system.

---

## 🏷️ Tags

Articles can contain multiple tags to improve:

- Content organization
- Related article discovery
- Search
- SEO
- Topic navigation

---

## 👤 Authors

The admin panel supports author management.

Article information can include:

- Author name
- Author profile
- Author information
- Published articles

---

# 🎛️ Admin Panel

ViralKeno includes a custom administration area.

Main sections include:

- Dashboard
- Articles
- Categories
- Tags
- Authors
- Static Pages
- Advertisements
- Messages
- Settings
- Security

---

# 📊 Dashboard

The dashboard can provide quick access to site information such as:

- Total articles
- Published articles
- Draft articles
- Categories
- Authors
- Recent content
- Messages
- Site management shortcuts

---

# 📝 Publication System

Articles may support different publication states such as:

```text
Draft
Published
Scheduled
```

This allows content to be prepared before publication.

---

# 🔥 Trending Content

ViralKeno can highlight popular or important stories using a trending system.

Trending articles may appear in dedicated sections of the website to make current stories easier to discover.

---

# ✅ Fact Checking

The site includes a dedicated **Fact Check** category.

Fact-check articles should clearly distinguish between:

```text
Verified
False
Misleading
Unconfirmed
Needs Context
```

Claims should be checked against reliable sources before publication.

---

# 🔗 Source-Based Publishing

ViralKeno is designed around source-based news publishing.

For important claims, editors should provide source URLs whenever possible.

Recommended editorial workflow:

1. Find the original source.
2. Confirm the date of publication.
3. Verify the account or publisher.
4. Check a second reliable source when necessary.
5. Avoid publishing unsupported rumors.
6. Clearly identify uncertain information.
7. Add the source links to the article.

---

# 🕒 News Freshness

For trending content, priority should generally be given to recent stories.

Recommended target:

```text
Last 24–48 hours
```

Older stories may still be published when they remain relevant or require explanation.

---

# 🧭 Timezone

The website editorial timezone is intended to use:

```text
Asia/Dhaka
```

Make sure PHP, database timestamps, scheduled publishing, and article dates are configured consistently.

---

# 🖼️ Images

Articles can use:

- Featured images
- News images
- Illustrative images
- Source images where permitted

When an image is not an original photograph of the event, it should be identified as an illustrative image when appropriate.

Example:

```text
প্রতীকী ছবি
```

Editors should ensure that images are legally usable and properly attributed when required.

---

# 📢 Advertisement System

ViralKeno includes advertisement management.

Advertising placements can be configured through the admin panel.

The site may support advertising formats such as:

- Banner advertisements
- Native advertisements
- Social bar advertisements
- Header/footer placements
- In-content advertisements

Example advertising networks may include services such as Adsterra.

Advertisement code should be added only from trusted providers.

---

# 📬 Messages

The administration panel includes message management for website communication or contact submissions.

Messages should be reviewed regularly and handled securely.

---

# 📄 Static Pages

Static informational pages can be managed separately from news articles.

Examples:

- About Us
- Contact
- Privacy Policy
- Disclaimer
- Terms and Conditions

---

# 🔍 Search

ViralKeno includes a search system so visitors can find content by keyword.

Search may cover:

- Article titles
- Article content
- Categories
- Tags

---

# 🔎 SEO

ViralKeno includes SEO-related features such as:

- SEO-friendly URLs
- Article slugs
- SEO titles
- Meta descriptions
- `robots.txt`
- Sitemap
- Category pages
- Tag pages
- Search-engine-friendly article pages

A sitemap should be kept updated when new articles are published.

---

# 🤖 robots.txt

The project includes a `robots.txt` file for search engine crawler instructions.

Example:

```text
User-agent: *
Allow: /
```

Administrative and private paths should not be unnecessarily exposed to search engines.

---

# 🗺️ Sitemap

ViralKeno supports a sitemap to help search engines discover published articles and pages.

Typical sitemap location:

```text
https://viralkeno.com/sitemap.xml
```

---

# 🛠️ Technology Stack

ViralKeno is built using:

- PHP 8+
- MySQL / MariaDB
- HTML5
- CSS3
- Vanilla JavaScript
- PDO
- Apache
- `.htaccess`

The application is designed to work without requiring Node.js for the standard production setup.

---

# 📋 Requirements

## Required

| Component | Minimum | Recommended |
|---|---:|---:|
| PHP | 8.0+ | PHP 8.2+ |
| MySQL | 5.7+ | MySQL 8.x |
| MariaDB | 10.3+ | MariaDB 10.6+ |
| Apache | 2.4+ | Latest stable |
| PDO | Required | Enabled |
| PDO MySQL | Required | Enabled |
| JSON | Required | Enabled |
| `.htaccess` | Required | Enabled |
| `mod_rewrite` | Required | Enabled |

Only **MySQL or MariaDB** is required — not both.

---

# 🔌 PHP Extensions

## Required

```text
PDO
pdo_mysql
json
```

## Recommended

```text
curl
mbstring
openssl
fileinfo
```

`cURL` is useful when communicating with external APIs or services.

---

# 🌐 Recommended Server Environment

```text
Apache 2.4+
PHP 8.2+
MySQL 8.x or MariaDB 10.6+
HTTPS enabled
mod_rewrite enabled
.htaccess enabled
```

---

# 🖥️ Supported Hosting

ViralKeno can run on most PHP/MySQL hosting environments, including:

- InfinityFree
- Shared hosting
- cPanel hosting
- DirectAdmin hosting
- VPS
- Dedicated servers
- Local Apache development environments

The hosting provider must support PHP and MySQL/MariaDB.

---

# 🚀 Installation

## 1. Clone the Repository

```bash
git clone https://github.com/aniscopearafat/viralkeno.git
```

Enter the project directory:

```bash
cd viralkeno
```

---

# ⚙️ Configuration

If the project contains an example configuration file such as:

```text
config.example.php
```

copy it:

```bash
cp config.example.php config.php
```

Then open:

```text
config.php
```

and enter the correct database and website settings.

A typical database configuration may contain:

```php
<?php

return [
    'db_host' => 'localhost',
    'db_name' => 'your_database_name',
    'db_user' => 'your_database_user',
    'db_pass' => 'your_database_password'
];
```

Use the actual configuration keys provided by the project.

---

# 🗄️ Database Setup

Create a database from your hosting control panel.

You normally need:

```text
Database Host
Database Name
Database Username
Database Password
```

A common database host is:

```text
localhost
```

Some hosting companies provide a custom MySQL hostname.

Always use the database hostname supplied by your host.

If the project includes an installer, open the installer in your browser and follow the installation process.

---

# 🔐 Configuration Security

Sensitive configuration files should never be committed publicly.

Recommended `.gitignore` entries:

```gitignore
config.php
.env
.DS_Store
*.log
```

Configuration files may contain:

- Database passwords
- API credentials
- Advertising configuration
- Private application settings

Always verify with:

```bash
git status
```

before pushing files to GitHub.

---

# 📤 Shared Hosting Installation

Upload the project to your public website directory.

Common directories:

```text
htdocs/
```

or:

```text
public_html/
```

Example:

```text
htdocs/
├── admin/
├── assets/
├── uploads/
├── app/
├── .htaccess
├── index.php
├── robots.txt
└── sitemap.xml
```

The actual structure may vary between ViralKeno versions.

---

# 🌍 Domain Configuration

Point your domain to the hosting account and ensure SSL is active.

Production URL:

```text
https://viralkeno.com
```

HTTPS should always be preferred.

---

# 🔒 SSL / HTTPS

ViralKeno should use HTTPS in production.

A free SSL certificate may be available through:

- Let's Encrypt
- Hosting provider SSL
- Cloudflare
- Other certificate providers

All public URLs should preferably use:

```text
https://
```

---

# 🧩 Apache Configuration

The site may use `.htaccess` for routing and clean URLs.

Apache should have:

```text
mod_rewrite
```

enabled.

On supported VPS environments:

```bash
sudo a2enmod rewrite
sudo systemctl restart apache2
```

Apache configuration may also require:

```apache
AllowOverride All
```

These commands are usually unnecessary on standard shared hosting.

---

# 🔧 File Permissions

Typical permissions:

### Files

```text
644
```

### Directories

```text
755
```

Upload or cache directories may require PHP write access.

Avoid using:

```text
777
```

unless your hosting provider specifically requires it.

---

# 🔐 Admin Security

The admin panel should be protected carefully.

Recommended practices:

- Use a strong password
- Do not expose credentials publicly
- Regenerate compromised passwords immediately
- Restrict moderator permissions
- Validate login sessions
- Use HTTPS
- Keep PHP updated
- Log out from shared devices
- Protect private configuration files

---

# 👥 Roles

ViralKeno can support multiple administrative roles.

Examples:

```text
Administrator
Moderator
Author
```

Administrators may control the entire site while moderators or authors should only receive the permissions they require.

---

# 📰 Editorial Guidelines

For news publishing, editors should prioritize accuracy over speed.

Recommended rules:

- Use original sources where possible.
- Prefer verified official accounts.
- Check publication dates.
- Confirm important claims with multiple sources.
- Avoid rumors.
- Avoid misleading headlines.
- Clearly identify unknown numbers or facts.
- Separate opinion from factual reporting.
- Correct errors when discovered.
- Add source links.

---

# 🔍 Two-Source Verification

For major claims, use at least two independent reliable sources when possible.

Example workflow:

```text
Original source
        ↓
Second reliable source
        ↓
Cross-check key facts
        ↓
Publish
```

A social media post alone should not automatically be treated as confirmation of a factual claim.

---

# 🧾 Fact-Check Workflow

When publishing a fact-check:

1. Identify the exact claim.
2. Find the original source.
3. Check reliable documentation.
4. Compare multiple sources.
5. Determine what is known.
6. Explain uncertainty clearly.
7. Provide source links.
8. Apply the appropriate fact-check label.

---

# 💾 Backup

Regular backups should include both website files and the database.

Important files may include:

```text
admin/
app/
assets/
uploads/
config.php
.htaccess
robots.txt
sitemap.xml
```

Database backups can be created using:

- phpMyAdmin
- cPanel
- Hosting backup tools
- MySQL command-line utilities

---

# 🔄 Updating ViralKeno

Pull the latest repository changes:

```bash
git pull origin main
```

Before updating a production site, back up:

- Database
- Uploaded media
- Configuration
- Modified files

---

# 🧰 Git Workflow

Check changes:

```bash
git status
```

Add files:

```bash
git add .
```

Commit:

```bash
git commit -m "Update ViralKeno"
```

Push:

```bash
git push origin main
```

---

# 🐙 Repository

Repository:

```text
https://github.com/aniscopearafat/viralkeno
```

Clone:

```text
https://github.com/aniscopearafat/viralkeno.git
```

---

# 📱 Browser Support

ViralKeno is intended to support modern browsers including:

- Google Chrome
- Mozilla Firefox
- Microsoft Edge
- Safari
- Mobile Chrome
- Mobile Safari

---

# 📲 Responsive Design

The public website and admin interface should work across:

```text
Desktop
Laptop
Tablet
Mobile
```

---

# 📈 Future Improvements

Possible future improvements include:

- Advanced analytics
- Better article recommendations
- Enhanced search
- Automatic sitemap updates
- Improved moderation tools
- Social sharing integration
- Push notifications
- Newsletter support
- Performance optimization
- Structured data
- Better image optimization

---

# 🛡️ Security Recommendations

For production:

- Keep PHP updated.
- Keep dependencies updated.
- Use HTTPS.
- Protect admin accounts.
- Store credentials outside public source control.
- Validate all user input.
- Escape HTML output.
- Use prepared PDO statements.
- Protect against CSRF.
- Secure file uploads.
- Restrict executable uploads.
- Limit login attempts.
- Back up the database.
- Disable detailed production errors.
- Review moderator permissions.

---

# ⚠️ Disclaimer

ViralKeno is a news publishing and content management project.

Publishers and site administrators are responsible for ensuring that:

- Published information is accurate.
- Sources are properly verified.
- Copyrighted content is used lawfully.
- Images are used with appropriate rights or attribution.
- Advertisements comply with applicable policies.
- User privacy is respected.
- Content complies with applicable laws.

ViralKeno does not guarantee the accuracy of third-party sources linked from published articles.

---

# 📄 License

This project is maintained by the repository owner.

Unless a separate `LICENSE` file is provided, no permission for redistribution, modification, or commercial distribution should be assumed.

---

# 👤 Project Information

**Project:** ViralKeno  
**Website:** https://viralkeno.com  
**Repository:** `aniscopearafat/viralkeno`  
**Language:** Bengali  
**Backend:** PHP / MySQL  
**Status:** Active Development

---

## 📰 ViralKeno

**বাংলা ইন্টারনেটের ভাইরাল খবর, ট্রেন্ড, প্রযুক্তি, বিনোদন, সোশ্যাল মিডিয়া ও ফ্যাক্ট-চেক — এক জায়গায়।**
