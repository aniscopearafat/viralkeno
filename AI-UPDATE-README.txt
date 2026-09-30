ViralKeno AI Newsroom Update
============================

What this update adds
- AI Control Center under Admin Settings.
- Gemini / OpenAI / Groq provider selection.
- Editable model name and encrypted API credential storage.
- Five configurable daily draft slots (Asia/Dhaka).
- AI creates DRAFTS only; it never adds images or publishes.
- Featured image is mandatory before an article can be Scheduled or Published.
- AI Generated badge, private research notes and source links for editors.
- Run history and manual Create Draft / Run Due Slot controls.
- CLI cron runner for cron-capable hosting.

Important hosting note
InfinityFree Free has Cron Jobs disabled. You can use manual AI runs there, but exact automatic five-slot execution requires cron-capable hosting. Also use Test Connection after deployment to confirm your host permits outbound HTTPS calls to the selected AI provider.

Install from project root
1. Back up the two files this update overwrites:
   cp admin/index.php admin/index.php.before-ai
   cp assets/css/admin.css assets/css/admin.css.before-ai

2. Extract viralkeno-ai-update.zip into the project root:
   unzip -o viralkeno-ai-update.zip -d .

3. Optional local PHP syntax check:
   find app admin cron -name '*.php' -exec php -l {} \;

4. Upload the changed/new files to the same paths on your hosting.
5. Log in to Admin -> Settings -> AI Control.
6. Save provider/model/API key, author/fallback category and schedules.
7. Click Test Connection first, then Create 1 Draft Now.

No SQL import is required. The AI tables are created automatically on first authenticated admin load.

Cron-capable hosting example (replace /absolute/path/to/site):
*/5 * * * * /usr/bin/php /absolute/path/to/site/cron/ai.php >/dev/null 2>&1
