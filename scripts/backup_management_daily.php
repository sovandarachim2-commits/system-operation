<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/backup_management_lib.php';

$mode = $argv[1] ?? 'daily';
try {
    if ($mode === 'summary') {
        $result = osb_send_daily_report();
        echo ($result['ok'] ? "Summary sent\n" : "Summary failed: " . $result['error'] . "\n");
        exit($result['ok'] ? 0 : 1);
    }
    if ($mode === 'auto' && !osb_needs_backup()) {
        echo "Already backed up today\n";
        exit(0);
    }
    $runs = osb_run_all();
    foreach ($runs as $run) {
        echo ($run['status'] ?? 'failed') . ' ' . ($run['file_path'] ?? '') . ' ' . ($run['error_text'] ?? '') . "\n";
    }
    if ($mode === 'daily') {
        $result = osb_send_daily_report();
        echo ($result['ok'] ? "Summary sent\n" : "Summary failed: " . $result['error'] . "\n");
        exit($result['ok'] ? 0 : 1);
    }
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
