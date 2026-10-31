<?php
declare(strict_types=1);

function osb_app_label(): string
{
    static $label = null;
    if (is_string($label)) {
        return $label;
    }
    $label = 'Shadow Report';
    try {
        if (!function_exists('get_db_connection')) {
            require_once dirname(__DIR__) . '/db.php';
        }
        $pdo = get_db_connection();
        $stmt = $pdo->prepare('SELECT `value` FROM app_settings WHERE `key` = ? LIMIT 1');
        $stmt->execute(['report_app_name']);
        $stored = trim((string)($stmt->fetchColumn() ?: ''));
        if ($stored !== '') {
            $label = $stored;
        }
    } catch (Throwable $e) {
        $label = 'Shadow Report';
    }
    return $label;
}

function osb_file_stem(): string
{
    $slug = preg_replace('/[^\p{L}\p{N}]+/u', '-', osb_app_label());
    $slug = trim(is_string($slug) ? $slug : '', '-');
    if ($slug === '') {
        $slug = 'Shadow-Report';
    }
    $slug = function_exists('mb_substr') ? mb_substr($slug, 0, 40) : substr($slug, 0, 40);
    return trim($slug, '-') ?: 'Shadow-Report';
}

function osb_backup_filename(?string $stamp = null): string
{
    return osb_file_stem() . '-' . ($stamp ?: date('Y-m-d')) . '.sql.gz';
}

function osb_is_backup_filename(string $name): bool
{
    return (bool)preg_match('/^[\p{L}\p{N}][\p{L}\p{N}-]{0,80}-\d{4}-\d{2}-\d{2}(?:-\d{6})?\.sql\.gz$/u', $name);
}

function osb_backup_dir(): string
{
    $dir = 'C:\\xampp\\backups';
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('Unable to create C:\\xampp\\backups.');
    }
    return $dir;
}

function osb_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        throw new RuntimeException('PHP SQLite is not enabled.');
    }
    $pdo = new PDO('sqlite:' . osb_backup_dir() . DIRECTORY_SEPARATOR . 'backup_management.sqlite');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS servers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            kind TEXT NOT NULL,
            target TEXT NOT NULL DEFAULT "",
            important INTEGER NOT NULL DEFAULT 1,
            enabled INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL
        )
    ');
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS runs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            server_id INTEGER NOT NULL,
            started_at TEXT NOT NULL,
            finished_at TEXT NOT NULL,
            status TEXT NOT NULL,
            size_bytes INTEGER NOT NULL DEFAULT 0,
            file_path TEXT NOT NULL DEFAULT "",
            error_text TEXT NOT NULL DEFAULT "",
            alerted INTEGER NOT NULL DEFAULT 0
        )
    ');
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS report_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            sent_at TEXT NOT NULL,
            body TEXT NOT NULL,
            ok INTEGER NOT NULL DEFAULT 0,
            error_text TEXT NOT NULL DEFAULT ""
        )
    ');
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS settings (
            id INTEGER PRIMARY KEY CHECK (id = 1),
            bot_token TEXT NOT NULL DEFAULT "",
            chat_id TEXT NOT NULL DEFAULT "",
            thread_id TEXT NOT NULL DEFAULT "",
            morning_time TEXT NOT NULL DEFAULT "07:00",
            send_file INTEGER NOT NULL DEFAULT 0,
            report_token TEXT NOT NULL DEFAULT ""
        )
    ');
    $exists = (int)$pdo->query('SELECT COUNT(*) FROM settings')->fetchColumn();
    if ($exists === 0) {
        $pdo->prepare('INSERT INTO settings (id, report_token) VALUES (1, ?)')->execute([bin2hex(random_bytes(16))]);
    }
    $serverCount = (int)$pdo->query('SELECT COUNT(*) FROM servers')->fetchColumn();
    if ($serverCount === 0) {
        $now = date('Y-m-d H:i:s');
        $insert = $pdo->prepare('INSERT INTO servers (name, kind, target, important, enabled, created_at) VALUES (?, ?, ?, 1, 1, ?)');
        $insert->execute(['Database', 'database', (string)($GLOBALS['DB_NAME'] ?? 'shadowdata'), $now]);
        $uploads = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads';
        $insert->execute(['Uploads', 'uploads', $uploads, $now]);
    } else {
        $rename = $pdo->prepare('UPDATE servers SET name = ? WHERE name = ?');
        $rename->execute(['Database', 'OrderShadow Database']);
        $rename->execute(['Uploads', 'OrderShadow Uploads']);
    }
    return $pdo;
}

function osb_settings(): array
{
    $row = osb_db()->query('SELECT * FROM settings WHERE id = 1')->fetch();
    return is_array($row) ? $row : [];
}

function osb_public_settings(): array
{
    $row = osb_settings();
    $token = (string)($row['bot_token'] ?? '');
    return [
        'token_set' => $token !== '',
        'token_hint' => $token !== '' ? ('••••' . substr($token, -4)) : '',
        'chat_id' => (string)($row['chat_id'] ?? ''),
        'thread_id' => (string)($row['thread_id'] ?? ''),
        'morning_time' => (string)($row['morning_time'] ?? '07:00'),
        'send_file' => (int)($row['send_file'] ?? 0) === 1,
        'report_token' => (string)($row['report_token'] ?? ''),
        'night_time' => '02:00',
        'run_command' => 'C:\\xampp\\php\\php.exe C:\\xampp\\htdocs\\OrderShadow\\scripts\\backup_management_daily.php auto',
        'summary_command' => 'C:\\xampp\\php\\php.exe C:\\xampp\\htdocs\\OrderShadow\\scripts\\backup_management_daily.php summary',
        'schedule' => osb_schedule_status(),
        'file_example' => osb_backup_filename(),
        'app_name' => osb_app_label(),
    ];
}

function osb_save_settings(array $input): array
{
    $current = osb_settings();
    $token = trim((string)($input['bot_token'] ?? ''));
    if ($token === '') {
        $token = (string)($current['bot_token'] ?? '');
    }
    $morning = trim((string)($input['morning_time'] ?? '07:00'));
    if (!preg_match('/^\d{2}:\d{2}$/', $morning)) {
        $morning = '07:00';
    }
    osb_db()->prepare('
        UPDATE settings
        SET bot_token = ?, chat_id = ?, thread_id = ?, morning_time = ?, send_file = ?
        WHERE id = 1
    ')->execute([
        $token,
        trim((string)($input['chat_id'] ?? '')),
        trim((string)($input['thread_id'] ?? '')),
        $morning,
        !empty($input['send_file']) ? 1 : 0,
    ]);
    osb_sync_schedule($morning);
    return osb_public_settings();
}

function osb_needs_backup(): bool
{
    $checked = 0;
    foreach (osb_servers() as $server) {
        if ((int)$server['enabled'] !== 1 || (string)$server['kind'] === 'device') {
            continue;
        }
        $checked++;
        $run = osb_latest_run((int)$server['id'], true);
        if (!$run || !in_array((string)$run['status'], ['success', 'warning'], true)) {
            return true;
        }
    }
    return $checked === 0;
}

function osb_sync_schedule(string $morning): void
{
    if (!preg_match('/^\d{2}:\d{2}$/', $morning)) {
        $morning = '07:00';
    }
    $php = 'C:\\xampp\\php\\php.exe';
    $script = 'C:\\xampp\\htdocs\\OrderShadow\\scripts\\backup_management_daily.php';
    $jobs = [
        ['Backup Management\\Night backup', '/SC DAILY /ST 02:00', 'auto'],
        ['Backup Management\\Morning summary', '/SC DAILY /ST ' . $morning, 'summary'],
    ];
    foreach ($jobs as [$name, $when, $mode]) {
        $command = 'schtasks /Create /F /TN ' . escapeshellarg($name)
            . ' ' . $when
            . ' /TR ' . escapeshellarg($php . ' ' . $script . ' ' . $mode);
        @exec($command);
    }
}

function osb_schedule_status(): array
{
    $jobs = [
        'night' => 'Backup Management\\Night backup',
        'morning' => 'Backup Management\\Morning summary',
    ];
    $status = [];
    foreach ($jobs as $key => $name) {
        $out = [];
        $code = 1;
        @exec('schtasks /Query /TN ' . escapeshellarg($name) . ' /FO LIST', $out, $code);
        $next = '';
        $state = 'Not scheduled';
        if ($code === 0) {
            $state = 'Ready';
            foreach ($out as $line) {
                if (preg_match('/Next Run Time:\s*(.+)$/', $line, $match)) {
                    $next = trim($match[1]);
                }
                if (preg_match('/Status:\s*(.+)$/', $line, $match)) {
                    $state = trim($match[1]);
                }
            }
        }
        $status[$key] = ['status' => $state, 'next_run' => $next];
    }
    return $status;
}

function osb_servers(): array
{
    return osb_db()->query('SELECT * FROM servers ORDER BY id')->fetchAll();
}

function osb_server(int $id): ?array
{
    $stmt = osb_db()->prepare('SELECT * FROM servers WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

function osb_save_server(array $input): array
{
    $name = trim((string)($input['name'] ?? ''));
    $kind = (string)($input['kind'] ?? 'database');
    $target = trim((string)($input['target'] ?? ''));
    if ($name === '') {
        throw new InvalidArgumentException('Server name is required.');
    }
    if (!in_array($kind, ['database', 'uploads', 'device'], true)) {
        throw new InvalidArgumentException('Choose Database, Uploads, or Device.');
    }
    if ($kind === 'database' && !preg_match('/^[A-Za-z0-9_]+$/', $target)) {
        throw new InvalidArgumentException('Database name can use letters, numbers, and underscore.');
    }
    if ($kind === 'uploads' && ($target === '' || str_contains($target, '..'))) {
        throw new InvalidArgumentException('Uploads needs a folder path.');
    }
    $id = (int)($input['id'] ?? 0);
    $important = !empty($input['important']) ? 1 : 0;
    $enabled = !array_key_exists('enabled', $input) || !empty($input['enabled']) ? 1 : 0;
    if ($id > 0) {
        osb_db()->prepare('UPDATE servers SET name = ?, kind = ?, target = ?, important = ?, enabled = ? WHERE id = ?')
            ->execute([$name, $kind, $target, $important, $enabled, $id]);
    } else {
        osb_db()->prepare('INSERT INTO servers (name, kind, target, important, enabled, created_at) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$name, $kind, $target, $important, $enabled, date('Y-m-d H:i:s')]);
        $id = (int)osb_db()->lastInsertId();
    }
    $server = osb_server($id);
    if (!$server) {
        throw new RuntimeException('Unable to save the server.');
    }
    return $server;
}

function osb_delete_server(int $id): void
{
    $db = osb_db();
    $db->prepare('DELETE FROM runs WHERE server_id = ?')->execute([$id]);
    $db->prepare('DELETE FROM servers WHERE id = ?')->execute([$id]);
}

function osb_format_bytes(int $bytes): string
{
    if ($bytes < 1024) {
        return $bytes . ' B';
    }
    if ($bytes < 1048576) {
        return number_format($bytes / 1024, 1) . ' KB';
    }
    if ($bytes < 1073741824) {
        return number_format($bytes / 1048576, 1) . ' MB';
    }
    return number_format($bytes / 1073741824, 2) . ' GB';
}

function osb_latest_run(int $serverId, bool $todayOnly = false): ?array
{
    $sql = 'SELECT * FROM runs WHERE server_id = ?';
    if ($todayOnly) {
        $sql .= ' AND started_at >= ?';
    }
    $sql .= ' ORDER BY id DESC LIMIT 1';
    $stmt = osb_db()->prepare($sql);
    $params = [$serverId];
    if ($todayOnly) {
        $params[] = date('Y-m-d') . ' 00:00:00';
    }
    $stmt->execute($params);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

function osb_previous_good_size(int $serverId): int
{
    $stmt = osb_db()->prepare('SELECT size_bytes FROM runs WHERE server_id = ? AND status IN ("success", "warning") ORDER BY id DESC LIMIT 1');
    $stmt->execute([$serverId]);
    return (int)$stmt->fetchColumn();
}

function osb_record_run(int $serverId, string $status, int $size, string $filePath, string $error, string $startedAt): array
{
    $finished = date('Y-m-d H:i:s');
    osb_db()->prepare('
        INSERT INTO runs (server_id, started_at, finished_at, status, size_bytes, file_path, error_text, alerted)
        VALUES (?, ?, ?, ?, ?, ?, ?, 0)
    ')->execute([$serverId, $startedAt, $finished, $status, $size, $filePath, $error]);
    $id = (int)osb_db()->lastInsertId();
    $stmt = osb_db()->prepare('SELECT * FROM runs WHERE id = ?');
    $stmt->execute([$id]);
    $run = $stmt->fetch();
    return is_array($run) ? $run : [];
}

function osb_folder_size(string $path): int
{
    $size = 0;
    if (!is_dir($path)) {
        return 0;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $size += (int)$file->getSize();
        }
    }
    return $size;
}

function osb_dump_database(string $database): array
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $database)) {
        throw new InvalidArgumentException('Database name is not valid.');
    }
    $dump = 'C:\\xampp\\mysql\\bin\\mysqldump.exe';
    if (!is_file($dump)) {
        throw new RuntimeException('mysqldump was not found.');
    }
    $dir = osb_backup_dir();
    $stamp = date('Y-m-d');
    $name = osb_backup_filename($stamp);
    $gzPath = $dir . DIRECTORY_SEPARATOR . $name;
    if (is_file($gzPath)) {
        $name = osb_file_stem() . '-' . $stamp . '-' . date('His') . '.sql.gz';
        $gzPath = $dir . DIRECTORY_SEPARATOR . $name;
    }
    $sqlPath = $dir . DIRECTORY_SEPARATOR . 'backup-tmp-' . bin2hex(random_bytes(4)) . '.sql';
    $cnfPath = tempnam(sys_get_temp_dir(), 'osb');
    if ($cnfPath === false) {
        throw new RuntimeException('Unable to prepare the backup.');
    }
    $cnf = "[client]\n"
        . 'user=' . osb_cnf_value((string)($GLOBALS['DB_USER'] ?? 'root')) . "\n"
        . 'password=' . osb_cnf_value((string)($GLOBALS['DB_PASS'] ?? '')) . "\n"
        . 'host=' . osb_cnf_value((string)($GLOBALS['DB_HOST'] ?? 'localhost')) . "\n";
    file_put_contents($cnfPath, $cnf);
    try {
        $cmd = [
            $dump,
            '--defaults-extra-file=' . $cnfPath,
            '--single-transaction',
            '--quick',
            '--routines',
            '--triggers',
            '--default-character-set=utf8mb4',
            $database,
        ];
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['file', $sqlPath, 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($cmd, $descriptors, $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($proc)) {
            throw new RuntimeException('Unable to start mysqldump.');
        }
        fclose($pipes[0]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        if ($code !== 0 || !is_file($sqlPath) || filesize($sqlPath) < 200) {
            throw new RuntimeException(trim((string)$error) !== '' ? trim((string)$error) : 'mysqldump failed.');
        }
        $in = fopen($sqlPath, 'rb');
        $out = gzopen($gzPath, 'wb9');
        if ($in === false || $out === false) {
            throw new RuntimeException('Unable to compress the SQL file.');
        }
        while (!feof($in)) {
            gzwrite($out, (string)fread($in, 1024 * 512));
        }
        fclose($in);
        gzclose($out);
    } finally {
        @unlink($cnfPath);
        if (is_file($sqlPath)) {
            @unlink($sqlPath);
        }
    }
    return ['path' => $gzPath, 'size' => (int)filesize($gzPath), 'name' => $name];
}

function osb_cnf_value(string $value): string
{
    return '"' . str_replace(['\\', '"', "\n", "\r"], ['\\\\', '\\"', '', ''], $value) . '"';
}

function osb_cleanup_old_files(): void
{
    $cutoff = time() - (14 * 86400);
    foreach (glob(osb_backup_dir() . DIRECTORY_SEPARATOR . '*.sql.gz') ?: [] as $file) {
        if (!osb_is_backup_filename(basename($file))) {
            continue;
        }
        if (is_file($file) && filemtime($file) < $cutoff) {
            @unlink($file);
        }
    }
}

function osb_run_server(int $id): array
{
    $server = osb_server($id);
    if (!$server) {
        throw new InvalidArgumentException('Server was not found.');
    }
    $started = date('Y-m-d H:i:s');
    $previous = osb_latest_run($id);
    $status = 'success';
    $size = 0;
    $path = '';
    $error = '';
    try {
        if ((string)$server['kind'] === 'database') {
            $dump = osb_dump_database((string)$server['target']);
            $size = (int)$dump['size'];
            $path = (string)$dump['path'];
            $good = osb_previous_good_size($id);
            if ($size < 200) {
                $status = 'failed';
                $error = 'The SQL file is empty.';
            } elseif ($good > 1024 && $size < (int)floor($good * 0.7)) {
                $status = 'warning';
                $error = 'File is smaller than the last good backup.';
            }
        } elseif ((string)$server['kind'] === 'uploads') {
            $folder = (string)$server['target'];
            if (!is_dir($folder)) {
                throw new RuntimeException('Uploads folder was not found.');
            }
            $size = osb_folder_size($folder);
            $path = $folder;
            if ($size <= 0) {
                $status = 'warning';
                $error = 'Uploads folder is empty.';
            }
        } else {
            $today = osb_latest_run($id, true);
            if (!$today) {
                $status = 'failed';
                $error = 'No report from this device today.';
            } else {
                return $today;
            }
        }
    } catch (Throwable $e) {
        $status = 'failed';
        $error = $e->getMessage();
    }
    $run = osb_record_run($id, $status, $size, $path, $error, $started);
    osb_cleanup_old_files();
    if ($status === 'failed' && (int)$server['important'] === 1) {
        osb_send_failure_alert($server, $run);
    } elseif ($status === 'success' && $previous && (string)$previous['status'] === 'failed') {
        osb_send_text("Backup recovered\n" . $server['name'] . "\n" . date('d M Y, h:i A'));
    }
    return $run;
}

function osb_run_all(): array
{
    $runs = [];
    foreach (osb_servers() as $server) {
        if ((int)$server['enabled'] !== 1 || (string)$server['kind'] === 'device') {
            continue;
        }
        $runs[] = osb_run_server((int)$server['id']);
    }
    return $runs;
}

function osb_build_summary(): string
{
    $lines = [
        osb_app_label() . ' backup',
        date('d M Y, h:i A'),
        '',
    ];
    foreach (osb_servers() as $server) {
        if ((int)$server['enabled'] !== 1) {
            continue;
        }
        $run = osb_latest_run((int)$server['id'], true);
        if (!$run) {
            $lines[] = $server['name'] . '    Failed    no backup today';
            continue;
        }
        $size = osb_format_bytes((int)$run['size_bytes']);
        $file = (string)$run['file_path'];
        $fileName = $file !== '' && str_ends_with(strtolower($file), '.sql.gz') ? basename($file) : '';
        $detail = trim($size . ($fileName !== '' ? '    ' . $fileName : ''));
        $lines[] = $server['name'] . '    ' . ucfirst((string)$run['status']) . '    ' . $detail;
        if ((string)$run['error_text'] !== '') {
            $lines[] = '    ' . $run['error_text'];
        }
    }
    return implode("\n", $lines);
}

function osb_telegram_request(string $method, array $fields, ?string $filePath = null): array
{
    $settings = osb_settings();
    $token = trim((string)($settings['bot_token'] ?? ''));
    $chatId = trim((string)($settings['chat_id'] ?? ''));
    if ($token === '' || $chatId === '') {
        throw new RuntimeException('Save the Telegram bot and group chat first.');
    }
    $fields['chat_id'] = $chatId;
    $thread = trim((string)($settings['thread_id'] ?? ''));
    if ($thread !== '') {
        $fields['message_thread_id'] = $thread;
    }
    if ($filePath !== null) {
        $fields['document'] = new CURLFile($filePath);
    }
    $ch = curl_init('https://api.telegram.org/bot' . $token . '/' . $method);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $fields,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $filePath !== null ? 120 : 20,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $raw = curl_exec($ch);
    $errno = curl_errno($ch);
    curl_close($ch);
    if ($errno !== 0 || !is_string($raw)) {
        throw new RuntimeException('Telegram did not respond.');
    }
    $data = json_decode($raw, true);
    if (!is_array($data) || empty($data['ok'])) {
        $description = is_array($data) ? (string)($data['description'] ?? 'Telegram rejected the message.') : 'Telegram rejected the message.';
        throw new RuntimeException($description);
    }
    return $data;
}

function osb_send_text(string $text): void
{
    osb_telegram_request('sendMessage', ['text' => $text]);
}

function osb_send_failure_alert(array $server, array $run): void
{
    $good = osb_db()->prepare('SELECT finished_at, size_bytes FROM runs WHERE server_id = ? AND status IN ("success", "warning") AND id <> ? ORDER BY id DESC LIMIT 1');
    $good->execute([(int)$server['id'], (int)($run['id'] ?? 0)]);
    $last = $good->fetch();
    $text = "Backup failed\n"
        . $server['name'] . ' · ' . date('d M Y, h:i A') . "\n"
        . ((string)($run['error_text'] ?? '') !== '' ? $run['error_text'] : 'Backup failed.');
    if (is_array($last)) {
        $text .= "\nLast good backup: " . $last['finished_at'] . ', ' . osb_format_bytes((int)$last['size_bytes']);
    }
    try {
        osb_send_text($text);
        if (!empty($run['id'])) {
            osb_db()->prepare('UPDATE runs SET alerted = 1 WHERE id = ?')->execute([(int)$run['id']]);
        }
    } catch (Throwable $e) {
        if (!empty($run['id'])) {
            osb_db()->prepare('UPDATE runs SET error_text = ? WHERE id = ?')->execute([
                trim((string)$run['error_text'] . ' Telegram alert: ' . $e->getMessage()),
                (int)$run['id'],
            ]);
        }
    }
}

function osb_send_daily_report(): array
{
    $body = osb_build_summary();
    $error = '';
    $ok = 1;
    try {
        osb_send_text($body);
        $settings = osb_settings();
        if ((int)($settings['send_file'] ?? 0) === 1) {
            foreach (osb_servers() as $server) {
                if ((string)$server['kind'] !== 'database' || (int)$server['enabled'] !== 1) {
                    continue;
                }
                $run = osb_latest_run((int)$server['id'], true);
                $path = (string)($run['file_path'] ?? '');
                if ($path === '' || !is_file($path)) {
                    continue;
                }
                $size = (int)filesize($path);
                if ($size > 50 * 1024 * 1024) {
                    osb_send_text($server['name'] . ' file is ' . osb_format_bytes($size) . ' and stays on this PC.');
                    continue;
                }
                osb_telegram_request('sendDocument', [
                    'caption' => $server['name'] . ' · ' . basename($path),
                ], $path);
            }
        }
    } catch (Throwable $e) {
        $ok = 0;
        $error = $e->getMessage();
    }
    osb_db()->prepare('INSERT INTO report_log (sent_at, body, ok, error_text) VALUES (?, ?, ?, ?)')
        ->execute([date('Y-m-d H:i:s'), $body, $ok, $error]);
    return ['ok' => $ok === 1, 'body' => $body, 'error' => $error];
}

function osb_status_rows(): array
{
    $rows = [];
    foreach (osb_servers() as $server) {
        $run = osb_latest_run((int)$server['id'], true) ?: osb_latest_run((int)$server['id']);
        $today = $run && str_starts_with((string)$run['started_at'], date('Y-m-d'));
        $displayStatus = !$run ? 'waiting' : ($today ? (string)$run['status'] : 'failed');
        $displayError = !$run ? 'No backup yet' : ($today ? (string)($run['error_text'] ?? '') : 'No backup today');
        $rows[] = [
            'server' => $server,
            'run' => $run,
            'today' => $today,
            'display_status' => $displayStatus,
            'display_error' => $displayError,
            'size_label' => $run ? osb_format_bytes((int)$run['size_bytes']) : '-',
            'file_name' => $run && str_ends_with(strtolower((string)$run['file_path']), '.sql.gz') ? basename((string)$run['file_path']) : '',
            'file_ready' => $run && is_file((string)$run['file_path']),
        ];
    }
    return $rows;
}

function osb_history(int $limit = 100): array
{
    $stmt = osb_db()->prepare('
        SELECT runs.*, servers.name AS server_name, servers.kind AS server_kind
        FROM runs
        JOIN servers ON servers.id = runs.server_id
        ORDER BY runs.id DESC
        LIMIT ?
    ');
    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = [];
    foreach ($stmt->fetchAll() as $row) {
        $path = (string)$row['file_path'];
        $rows[] = [
            'id' => (int)$row['id'],
            'server_name' => (string)$row['server_name'],
            'server_kind' => (string)$row['server_kind'],
            'started_at' => (string)$row['started_at'],
            'finished_at' => (string)$row['finished_at'],
            'status' => (string)$row['status'],
            'size_label' => osb_format_bytes((int)$row['size_bytes']),
            'file_name' => str_ends_with(strtolower($path), '.sql.gz') ? basename($path) : '',
            'file_ready' => $path !== '' && is_file($path) && str_ends_with(strtolower($path), '.sql.gz'),
            'error_text' => (string)$row['error_text'],
        ];
    }
    return $rows;
}

function osb_report_log(int $limit = 30): array
{
    $stmt = osb_db()->prepare('SELECT * FROM report_log ORDER BY id DESC LIMIT ?');
    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function osb_download_path(int $runId): string
{
    $stmt = osb_db()->prepare('SELECT file_path FROM runs WHERE id = ?');
    $stmt->execute([$runId]);
    $path = (string)$stmt->fetchColumn();
    $real = $path !== '' ? realpath($path) : false;
    $root = realpath(osb_backup_dir());
    $realNorm = strtolower(str_replace('/', '\\', (string)$real));
    $rootNorm = strtolower(str_replace('/', '\\', (string)$root));
    if ($real === false || $root === false || !str_starts_with($realNorm, $rootNorm . '\\')) {
        throw new RuntimeException('Backup file is not on this PC.');
    }
    if (!osb_is_backup_filename(basename($real))) {
        throw new RuntimeException('Backup file is not on this PC.');
    }
    return $real;
}

function osb_ingest(string $token, string $name, string $status, int $size, string $error): array
{
    $settings = osb_settings();
    if ($token === '' || !hash_equals((string)$settings['report_token'], $token)) {
        throw new RuntimeException('Backup report token was rejected.');
    }
    if (!in_array($status, ['success', 'warning', 'failed'], true)) {
        throw new InvalidArgumentException('Status must be success, warning, or failed.');
    }
    $stmt = osb_db()->prepare('SELECT * FROM servers WHERE name = ? AND kind = "device" LIMIT 1');
    $stmt->execute([$name]);
    $server = $stmt->fetch();
    if (!is_array($server)) {
        throw new InvalidArgumentException('Device server was not found.');
    }
    $previous = osb_latest_run((int)$server['id']);
    $run = osb_record_run((int)$server['id'], $status, $size, '', $error, date('Y-m-d H:i:s'));
    if ($status === 'failed' && (int)$server['important'] === 1) {
        osb_send_failure_alert($server, $run);
    } elseif ($status === 'success' && $previous && (string)$previous['status'] === 'failed') {
        osb_send_text("Backup recovered\n" . $server['name'] . "\n" . date('d M Y, h:i A'));
    }
    return $run;
}
