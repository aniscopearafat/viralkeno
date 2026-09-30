<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/ai.php';

ai_schema();
$result = ai_run_due_slots();
fwrite(STDOUT, '[' . date('Y-m-d H:i:s') . '] ' . strtoupper((string)$result['status']) . ': ' . (string)$result['message'] . PHP_EOL);
exit($result['status'] === 'failed' ? 1 : 0);
