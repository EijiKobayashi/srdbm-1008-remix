<?php

declare(strict_types=1);

use Srdbm\SqlDumpReplacer;
use Srdbm\WordPressSqlInspector;

session_name('SRDBMSESSID');
$scriptDirectory = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/')));
$cookiePath = $scriptDirectory === '/' ? '/' : rtrim($scriptDirectory, '/') . '/';
session_set_cookie_params(['lifetime' => 0, 'path' => $cookiePath, 'httponly' => true, 'samesite' => 'Strict', 'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off']);
session_start();
set_time_limit(0);

const APP_ROOT = __DIR__ . '/..';
const INPUT_DIR = APP_ROOT . '/sql/input';
const BACKUP_DIR = APP_ROOT . '/sql/backups';
const OUTPUT_DIR = APP_ROOT . '/sql/output';
const MAX_SQL_UPLOAD_BYTES = 524288000;

require APP_ROOT . '/src/SqlDumpReplacer.php';
require APP_ROOT . '/src/WordPressSqlInspector.php';

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

foreach ([INPUT_DIR, BACKUP_DIR, OUTPUT_DIR] as $directory) {
    if (!is_dir($directory)) {
        @mkdir($directory, 0775, true);
    }
}

if (isset($_GET['chunk_upload'])) {
    handleChunkUpload();
}

if (isset($_GET['inspect_sql'])) {
    handleSqlInspection();
}

if (isset($_GET['download']) && is_string($_GET['download'])) {
    downloadOutput($_GET['download']);
}

$config = loadConfig();
$sourceUrl = postValue('source_url', (string) ($config['source_url'] ?? 'http://'));
$destinationUrl = postValue('destination_url', (string) ($config['destination_url'] ?? 'https://'));
$sourcePrefix = postValue('source_prefix', (string) ($config['source_prefix'] ?? ''));
$destinationPrefix = postValue('destination_prefix', (string) ($config['destination_prefix'] ?? ''));
$selectedFile = postValue('sql_file', '');
$errors = [];
$result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($_POST === [] && $contentLength > 0) {
        $errors[] = '送信容量がサーバー上限を超えました。PHPとWebサーバーの上限を500MB以上にしてください。';
    } elseif (!hash_equals((string) $_SESSION['csrf_token'], (string) ($_POST['csrf_token'] ?? ''))) {
        $errors[] = '画面の有効期限が切れました。再読み込みしてください。';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'execute-confirmed') {
            try {
                $result = runConfirmedReplacement((string) ($_POST['job_token'] ?? ''));
            } catch (Throwable $error) {
                $errors[] = $error->getMessage();
            }
        } elseif ($action === 'dry-run') {
            $forceHttps = isset($_POST['force_https']);
            if ($sourceUrl === '' || filter_var($sourceUrl, FILTER_VALIDATE_URL) === false) {
                $errors[] = '置換元URLを正しく入力してください。';
            }
            if ($destinationUrl === '' || filter_var($destinationUrl, FILTER_VALIDATE_URL) === false) {
                $errors[] = '置換後URLを正しく入力してください。';
            }
            if ($forceHttps) {
                $destinationUrl = preg_replace('#^http://#i', 'https://', $destinationUrl) ?? $destinationUrl;
            }
            if ($sourceUrl === $destinationUrl) {
                $errors[] = '置換元と置換後が同じです。';
            }
            if (($sourcePrefix === '') !== ($destinationPrefix === '')) {
                $errors[] = '接頭辞を変更する場合は、変更前と変更後の両方を入力してください。';
            }
            foreach (['変更前の接頭辞' => $sourcePrefix, '変更後の接頭辞' => $destinationPrefix] as $label => $prefix) {
                if ($prefix !== '' && preg_match('/^[A-Za-z0-9_]+$/', $prefix) !== 1) {
                    $errors[] = "{$label}は半角英数字とアンダースコアで入力してください。";
                }
            }
            if ($sourcePrefix !== '' && $sourcePrefix === $destinationPrefix) {
                $errors[] = '変更前と変更後の接頭辞が同じです。';
            }

            $inputPath = resolveChunkedSql(postValue('chunked_sql_file', ''), $errors);
            if ($inputPath === '') {
                $inputPath = receiveSqlUpload($errors);
            }
            if ($inputPath === '') {
                $inputPath = resolveSelectedSql($selectedFile, $errors);
            } else {
                $selectedFile = basename($inputPath);
            }

            if ($errors === []) {
                try {
                    $inspection = inspectSqlFile($inputPath);
                    validateDetectedTablePrefix($inspection, $sourcePrefix, $errors);
                    [$emailReplacements, $imagePathReplacements, $pluginOverrides] = requestedWordPressChanges($inspection, $errors);
                    if ($errors === []) {
                        $result = runDryRunAndBackup(
                            $inputPath,
                            $sourceUrl,
                            $destinationUrl,
                            $sourcePrefix,
                            $destinationPrefix,
                            $emailReplacements,
                            $imagePathReplacements,
                            $pluginOverrides
                        );
                    }
                } catch (Throwable $error) {
                    $errors[] = $error->getMessage();
                }
            }
        } else {
            $errors[] = '実行モードが正しくありません。';
        }
    }
}

$sqlFiles = listFiles(INPUT_DIR);
$outputFiles = array_slice(listFiles(OUTPUT_DIR), 0, 5);
$preflight = [
    ['label' => 'PHP 7.4+', 'ready' => version_compare(PHP_VERSION, '7.4.0', '>=')],
    ['label' => 'SQL入力フォルダ', 'ready' => is_writable(INPUT_DIR)],
    ['label' => 'バックアップフォルダ', 'ready' => is_writable(BACKUP_DIR)],
    ['label' => '出力フォルダ', 'ready' => is_writable(OUTPUT_DIR)],
    ['label' => '分割アップロード 500MB', 'ready' => is_writable(INPUT_DIR)],
];
$readyCount = count(array_filter($preflight, static function (array $item): bool { return $item['ready']; }));

/** @return array<string, mixed> */
function loadConfig(): array
{
    $path = APP_ROOT . '/config/srdbm.php';
    if (!is_file($path)) {
        return [];
    }
    $config = require $path;
    return is_array($config) ? $config : [];
}

function postValue(string $key, string $default): string
{
    $value = $_POST[$key] ?? $default;
    return is_string($value) ? trim($value) : $default;
}

function handleChunkUpload(): void
{
    header('Content-Type: application/json; charset=UTF-8');
    $fail = static function (string $message, int $status = 400): void {
        http_response_code($status);
        echo json_encode(['ok' => false, 'message' => $message], JSON_UNESCAPED_UNICODE);
        exit;
    };

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        $fail('POST required.', 405);
    }
    if (!hash_equals((string) $_SESSION['csrf_token'], (string) ($_POST['csrf_token'] ?? ''))) {
        $fail('画面を再読み込みしてください。', 403);
    }
    $uploadId = (string) ($_POST['upload_id'] ?? '');
    $filename = (string) ($_POST['filename'] ?? '');
    $offset = filter_var($_POST['offset'] ?? null, FILTER_VALIDATE_INT);
    $isLast = ($_POST['is_last'] ?? '') === '1';
    if (preg_match('/^[a-f0-9]{32}$/', $uploadId) !== 1 || $offset === false || $offset < 0) {
        $fail('アップロード情報が正しくありません。');
    }
    if (strtolower((string) pathinfo($filename, PATHINFO_EXTENSION)) !== 'sql') {
        $fail('.sql ファイルを選択してください。');
    }
    if (!isset($_FILES['chunk']) || (int) $_FILES['chunk']['error'] !== UPLOAD_ERR_OK) {
        $fail('分割データを受信できませんでした。');
    }

    $chunkDir = INPUT_DIR . '/.chunks';
    if (!is_dir($chunkDir) && !mkdir($chunkDir, 0775, true) && !is_dir($chunkDir)) {
        $fail('一時保存先を作成できません。', 500);
    }
    $partPath = $chunkDir . '/' . $uploadId . '.part';
    $currentSize = is_file($partPath) ? (int) filesize($partPath) : 0;
    if ($currentSize !== $offset) {
        $fail('アップロード位置が一致しません。最初からやり直してください。', 409);
    }

    $source = fopen((string) $_FILES['chunk']['tmp_name'], 'rb');
    $target = fopen($partPath, 'ab');
    if ($source === false || $target === false || !flock($target, LOCK_EX)) {
        if (is_resource($source)) fclose($source);
        if (is_resource($target)) fclose($target);
        $fail('分割データを保存できません。', 500);
    }
    stream_copy_to_stream($source, $target);
    fflush($target);
    flock($target, LOCK_UN);
    fclose($source);
    fclose($target);

    $newSize = (int) filesize($partPath);
    if ($newSize > MAX_SQL_UPLOAD_BYTES) {
        @unlink($partPath);
        $fail('SQLファイルが500MBを超えています。');
    }
    if (!$isLast) {
        echo json_encode(['ok' => true, 'offset' => $newSize]);
        exit;
    }

    $base = safeName(pathinfo($filename, PATHINFO_FILENAME));
    $finalName = $base . '-' . date('Ymd-His') . '-' . bin2hex(random_bytes(2)) . '.sql';
    if (!rename($partPath, INPUT_DIR . '/' . $finalName)) {
        $fail('SQLファイルを確定できません。', 500);
    }
    echo json_encode(['ok' => true, 'filename' => $finalName], JSON_UNESCAPED_UNICODE);
    exit;
}

function handleSqlInspection(): void
{
    header('Content-Type: application/json; charset=UTF-8');
    $fail = static function (string $message, int $status = 400): void {
        http_response_code($status);
        echo json_encode(['ok' => false, 'message' => $message], JSON_UNESCAPED_UNICODE);
        exit;
    };

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        $fail('POST required.', 405);
    }
    if (!hash_equals((string) $_SESSION['csrf_token'], (string) ($_POST['csrf_token'] ?? ''))) {
        $fail('画面を再読み込みしてください。', 403);
    }
    $name = (string) ($_POST['filename'] ?? '');
    $safe = basename($name);
    $path = INPUT_DIR . '/' . $safe;
    if ($name === '' || $safe !== $name || !is_file($path) || strtolower((string) pathinfo($path, PATHINFO_EXTENSION)) !== 'sql') {
        $fail('アップロード済みSQLが見つかりません。', 404);
    }

    try {
        echo json_encode(['ok' => true, 'inspection' => publicInspection(inspectSqlFile($path))], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (Throwable $error) {
        $fail($error->getMessage(), 500);
    }
    exit;
}

/** @return array<string,mixed> */
function inspectSqlFile(string $path): array
{
    $name = basename($path);
    $fingerprint = '3:' . (string) filesize($path) . ':' . (string) filemtime($path);
    $cached = $_SESSION['sql_inspections'][$name] ?? null;
    if (is_array($cached) && ($cached['fingerprint'] ?? '') === $fingerprint && is_array($cached['data'] ?? null)) {
        return $cached['data'];
    }

    $inspection = (new WordPressSqlInspector())->inspect($path);
    $_SESSION['sql_inspections'] = [$name => ['fingerprint' => $fingerprint, 'data' => $inspection]];
    return $inspection;
}

/** @param array<string,mixed> $inspection @return array<string,mixed> */
function publicInspection(array $inspection): array
{
    $groups = [];
    foreach ($inspection['plugin_groups'] ?? [] as $group) {
        $plugins = [];
        foreach ($group['plugins'] ?? [] as $plugin) {
            $plugins[] = ['path' => (string) $plugin['path'], 'active' => (bool) $plugin['active']];
        }
        $groups[] = ['id' => (string) $group['id'], 'label' => (string) $group['label'], 'plugins' => $plugins];
    }
    return [
        'table_prefixes' => $inspection['table_prefixes'] ?? [],
        'admin_emails' => $inspection['admin_emails'] ?? [],
        'image_paths' => $inspection['image_paths'] ?? [],
        'plugin_groups' => $groups,
    ];
}

/** @param array<string,mixed> $inspection @param list<string> $errors */
function validateDetectedTablePrefix(array $inspection, string $sourcePrefix, array &$errors): void
{
    if ($sourcePrefix === '') {
        return;
    }
    $detected = array_map(static function (array $item): string {
        return (string) ($item['value'] ?? '');
    }, $inspection['table_prefixes'] ?? []);
    $detected = array_values(array_filter($detected, static function (string $prefix): bool { return $prefix !== ''; }));
    if ($detected !== [] && !in_array($sourcePrefix, $detected, true)) {
        $errors[] = '変更前の接頭辞がSQL内のWordPressテーブルと一致しません。検出値「' . implode('」「', $detected) . '」を使用してください。';
    }
}

/**
 * @param array<string,mixed> $inspection
 * @param list<string> $errors
 * @return array{array<string,string>,array<string,string>,array<string,string>}
 */
function requestedWordPressChanges(array $inspection, array &$errors): array
{
    $emailReplacements = [];
    $knownEmails = array_fill_keys(array_map(static function (array $item): string { return (string) $item['value']; }, $inspection['admin_emails'] ?? []), true);
    $emailOriginals = is_array($_POST['email_original'] ?? null) ? $_POST['email_original'] : [];
    $emailValues = is_array($_POST['email_replacement'] ?? null) ? $_POST['email_replacement'] : [];
    foreach ($emailOriginals as $index => $originalValue) {
        $original = is_string($originalValue) ? trim($originalValue) : '';
        $replacement = is_string($emailValues[$index] ?? null) ? trim($emailValues[$index]) : '';
        if ($original === '' || $replacement === '' || $original === $replacement) {
            continue;
        }
        if (!isset($knownEmails[$original]) || filter_var($replacement, FILTER_VALIDATE_EMAIL) === false) {
            $errors[] = '管理者メールアドレスの変更内容が正しくありません。';
            continue;
        }
        $emailReplacements[$original] = $replacement;
    }

    $imagePathReplacements = [];
    $knownPaths = array_fill_keys(array_map(static function (array $item): string { return (string) $item['value']; }, $inspection['image_paths'] ?? []), true);
    $pathOriginals = is_array($_POST['image_path_original'] ?? null) ? $_POST['image_path_original'] : [];
    $pathValues = is_array($_POST['image_path_replacement'] ?? null) ? $_POST['image_path_replacement'] : [];
    foreach ($pathOriginals as $index => $originalValue) {
        $original = is_string($originalValue) ? trim($originalValue) : '';
        $replacement = is_string($pathValues[$index] ?? null) ? trim($pathValues[$index]) : '';
        if ($original === '' || $replacement === '' || $original === $replacement) {
            continue;
        }
        if (!isset($knownPaths[$original])) {
            $errors[] = '画像パスの変更内容が正しくありません。';
            continue;
        }
        $imagePathReplacements[$original] = $replacement;
    }

    $pluginOverrides = [];
    $presentGroups = is_array($_POST['plugin_groups_present'] ?? null) ? $_POST['plugin_groups_present'] : [];
    $presentGroups = array_fill_keys(array_filter($presentGroups, 'is_string'), true);
    $postedPlugins = is_array($_POST['plugins'] ?? null) ? $_POST['plugins'] : [];
    foreach ($inspection['plugin_groups'] ?? [] as $group) {
        $groupId = (string) $group['id'];
        if (!isset($presentGroups[$groupId])) {
            continue;
        }
        $known = [];
        $pluginValues = [];
        foreach ($group['plugins'] as $plugin) {
            $known[(string) $plugin['path']] = true;
            $pluginValues[(string) $plugin['path']] = $plugin['value'];
        }
        $selected = is_array($postedPlugins[$groupId] ?? null) ? $postedPlugins[$groupId] : [];
        $selected = array_values(array_unique(array_filter($selected, static function ($path) use ($known): bool {
            return is_string($path) && isset($known[$path]);
        })));
        if ((bool) $group['associative']) {
            $newValue = [];
            foreach ($selected as $path) {
                $newValue[$path] = $pluginValues[$path] ?? (string) time();
            }
        } else {
            $newValue = $selected;
        }
        $serialized = serialize($newValue);
        if ($serialized !== (string) $group['original']) {
            $pluginOverrides[(string) $group['original']] = $serialized;
        }
    }

    return [$emailReplacements, $imagePathReplacements, $pluginOverrides];
}

/** @param list<string> $errors */
function resolveChunkedSql(string $name, array &$errors): string
{
    if ($name === '') {
        return '';
    }
    return resolveSelectedSql($name, $errors);
}

/** @param list<string> $errors */
function receiveSqlUpload(array &$errors): string
{
    if (!isset($_FILES['sql_upload']) || (int) $_FILES['sql_upload']['error'] === UPLOAD_ERR_NO_FILE) {
        return '';
    }
    $upload = $_FILES['sql_upload'];
    if ((int) $upload['error'] !== UPLOAD_ERR_OK) {
        $errors[] = in_array((int) $upload['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
            ? 'SQLファイルが500MBの上限を超えています。'
            : 'SQLファイルをアップロードできませんでした。';
        return '';
    }
    $name = (string) $upload['name'];
    $size = (int) $upload['size'];
    if (strtolower((string) pathinfo($name, PATHINFO_EXTENSION)) !== 'sql') {
        $errors[] = 'アップロードできるファイルは .sql のみです。';
        return '';
    }
    if ($size <= 0 || $size > MAX_SQL_UPLOAD_BYTES) {
        $errors[] = 'SQLファイルは1バイト以上500MB以下にしてください。';
        return '';
    }
    $base = safeName(pathinfo($name, PATHINFO_FILENAME));
    $path = INPUT_DIR . '/' . $base . '-' . date('Ymd-His') . '-' . bin2hex(random_bytes(2)) . '.sql';
    if (!move_uploaded_file((string) $upload['tmp_name'], $path)) {
        $errors[] = 'SQLファイルを保存できませんでした。';
        return '';
    }
    return $path;
}

/** @param list<string> $errors */
function resolveSelectedSql(string $name, array &$errors): string
{
    if ($name === '') {
        $errors[] = 'SQLファイルを選択またはアップロードしてください。';
        return '';
    }
    $safe = basename($name);
    $path = INPUT_DIR . '/' . $safe;
    if ($safe !== $name || !is_file($path) || strtolower((string) pathinfo($path, PATHINFO_EXTENSION)) !== 'sql') {
        $errors[] = '選択されたSQLファイルがありません。';
        return '';
    }
    return $path;
}

/**
 * @param array<string,string> $emailReplacements
 * @param array<string,string> $imagePathReplacements
 * @param array<string,string> $pluginOverrides
 * @return array{changes:int,url_changes:int,prefix_changes:int,email_changes:int,image_changes:int,plugin_changes:int,bytes:int,elapsed:float,dry_run:bool,output:string,backup:string,job_token:string}
 */
function runDryRunAndBackup(
    string $input,
    string $search,
    string $replace,
    string $prefixSearch,
    string $prefixReplace,
    array $emailReplacements,
    array $imagePathReplacements,
    array $pluginOverrides
): array
{
    $base = safeName(pathinfo($input, PATHINFO_FILENAME));
    $suffix = date('Ymd-His') . '-' . bin2hex(random_bytes(2));
    $backupName = $base . '-original-' . $suffix . '.sql';
    $backup = BACKUP_DIR . '/' . $backupName;
    if (!copy($input, $backup) || !is_file($backup) || filesize($backup) !== filesize($input)) {
        @unlink($backup);
        throw new RuntimeException('元SQLのバックアップに失敗したため、ドライランを中止しました。');
    }

    $working = tempnam(sys_get_temp_dir(), 'srdbm-dry-');
    if ($working === false) {
        @unlink($backup);
        throw new RuntimeException('一時ファイルを作成できません。');
    }

    try {
        $report = (new SqlDumpReplacer())->process(
            $input,
            $working,
            $search,
            $replace,
            $prefixSearch,
            $prefixReplace,
            $emailReplacements,
            $imagePathReplacements,
            $pluginOverrides
        );
        unlink($working);
        $jobToken = bin2hex(random_bytes(24));
        $_SESSION['pending_jobs'] = [$jobToken => [
            'created_at' => time(), 'backup' => $backupName, 'search' => $search, 'replace' => $replace,
            'prefix_search' => $prefixSearch, 'prefix_replace' => $prefixReplace,
            'email_replacements' => $emailReplacements, 'image_path_replacements' => $imagePathReplacements,
            'plugin_overrides' => $pluginOverrides,
        ]];
        return $report + ['dry_run' => true, 'output' => '', 'backup' => $backupName, 'job_token' => $jobToken];
    } catch (Throwable $error) {
        @unlink($working);
        @unlink($backup);
        throw $error;
    }
}

/** @return array{changes:int,url_changes:int,prefix_changes:int,email_changes:int,image_changes:int,plugin_changes:int,bytes:int,elapsed:float,dry_run:bool,output:string,backup:string,job_token:string} */
function runConfirmedReplacement(string $jobToken): array
{
    $job = $_SESSION['pending_jobs'][$jobToken] ?? null;
    if (!is_array($job) || preg_match('/^[a-f0-9]{48}$/', $jobToken) !== 1) {
        throw new RuntimeException('ドライラン結果がありません。もう一度ドライランしてください。');
    }
    if ((int) ($job['created_at'] ?? 0) < time() - 3600) {
        unset($_SESSION['pending_jobs'][$jobToken]);
        throw new RuntimeException('ドライラン結果の有効期限が切れました。もう一度実行してください。');
    }

    $backupName = basename((string) ($job['backup'] ?? ''));
    $backup = BACKUP_DIR . '/' . $backupName;
    if ($backupName === '' || !is_file($backup)) {
        throw new RuntimeException('元SQLのバックアップが見つかりません。');
    }
    $base = safeName(pathinfo($backupName, PATHINFO_FILENAME));
    $outputName = $base . '-replaced-' . date('Ymd-His') . '-' . bin2hex(random_bytes(2)) . '.sql';
    $output = OUTPUT_DIR . '/' . $outputName;
    $working = $output . '.part';

    try {
        $report = (new SqlDumpReplacer())->process(
            $backup, $working, (string) $job['search'], (string) $job['replace'],
            (string) $job['prefix_search'], (string) $job['prefix_replace'],
            is_array($job['email_replacements'] ?? null) ? $job['email_replacements'] : [],
            is_array($job['image_path_replacements'] ?? null) ? $job['image_path_replacements'] : [],
            is_array($job['plugin_overrides'] ?? null) ? $job['plugin_overrides'] : []
        );
        if (!rename($working, $output)) {
            @unlink($working);
            throw new RuntimeException('変換済みSQLを確定できません。');
        }
        unset($_SESSION['pending_jobs'][$jobToken]);
        return $report + ['dry_run' => false, 'output' => $outputName, 'backup' => $backupName, 'job_token' => ''];
    } catch (Throwable $error) {
        @unlink($working);
        throw $error;
    }
}

function downloadOutput(string $name): void
{
    $safe = basename($name);
    $path = OUTPUT_DIR . '/' . $safe;
    if ($safe !== $name || !is_file($path) || strtolower((string) pathinfo($path, PATHINFO_EXTENSION)) !== 'sql') {
        http_response_code(404);
        exit('File not found.');
    }
    header('Content-Type: application/sql');
    header('Content-Disposition: attachment; filename="' . addcslashes($safe, '"\\') . '"');
    header('Content-Length: ' . filesize($path));
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;
}

/** @return list<string> */
function listFiles(string $directory): array
{
    $files = glob($directory . '/*.[sS][qQ][lL]') ?: [];
    usort($files, static function (string $a, string $b): int { return filemtime($b) <=> filemtime($a); });
    return $files;
}

function safeName(string $value): string { return preg_replace('/[^A-Za-z0-9_.-]+/', '-', $value) ?: 'database'; }
function e(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function fileSizeLabel(string $path): string { $bytes = filesize($path) ?: 0; return $bytes >= 1048576 ? number_format($bytes / 1048576, 1) . ' MB' : number_format($bytes / 1024, 1) . ' KB'; }
function iniBytes(string $value): int { $number = (float) $value; $unit = strtolower(substr(trim($value), -1)); return $unit === 'g' ? (int) ($number * 1073741824) : ($unit === 'm' ? (int) ($number * 1048576) : ($unit === 'k' ? (int) ($number * 1024) : (int) $number)); }
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>SRDBM 1008 REMIX</title>
<link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/gen-interface-jp@0.8.0/cdn/400.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/gen-interface-jp@0.8.0/cdn/500.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/gen-interface-jp@0.8.0/cdn/600.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/gen-interface-jp@0.8.0/cdn/700.css">
<script src="https://cdn.tailwindcss.com"></script>
<script>tailwind.config={theme:{extend:{colors:{wp:{ink:'#1d2327',blue:'#3858e9',canvas:'#f0f0f1',line:'#dcdcde',muted:'#646970',success:'#008a20',danger:'#d63638'}},boxShadow:{wp:'0 1px 1px rgba(0,0,0,.04)'},fontFamily:{sans:['Gen Interface JP','-apple-system','BlinkMacSystemFont','Segoe UI','sans-serif']}}}}</script>
<style type="text/tailwindcss">@layer base{body{@apply bg-wp-canvas font-sans text-wp-ink antialiased}input[type=text],input[type=url],input[type=email],select{@apply h-10 w-full rounded-sm border border-[#8c8f94] bg-white px-3 text-sm shadow-inner outline-none focus:border-wp-blue focus:ring-1 focus:ring-wp-blue}input[type=checkbox]{@apply h-4 w-4 rounded-sm border-[#8c8f94] text-wp-blue focus:ring-wp-blue}}@layer components{.panel{@apply border border-wp-line bg-white shadow-wp}.label{@apply mb-1.5 block text-sm font-medium}.help{@apply mt-1.5 text-xs leading-5 text-wp-muted}.btn{@apply inline-flex h-10 items-center justify-center rounded-sm border px-4 text-sm font-semibold focus:outline-none focus:ring-2 focus:ring-wp-blue focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50}.primary{@apply border-wp-blue bg-wp-blue text-white hover:bg-[#2145e6]}.secondary{@apply border-wp-blue bg-white text-wp-blue hover:bg-[#f0f3ff]}}</style>
</head>
<body class="min-h-screen text-[14px]">
<div id="loading" class="fixed inset-0 z-50 hidden items-center justify-center bg-white/80 backdrop-blur-sm"><div class="text-center"><div class="mx-auto mb-4 h-8 w-8 animate-spin rounded-full border-2 border-wp-line border-t-wp-blue"></div><p class="font-semibold">SQLを処理しています</p><p class="mt-1 text-xs text-wp-muted">画面を閉じずにお待ちください。</p></div></div>
<header class="fixed inset-x-0 top-0 z-40 flex h-12 items-center bg-wp-ink text-white"><div class="flex h-12 w-60 items-center gap-3 border-r border-white/10 px-4"><span class="grid h-7 w-7 place-items-center rounded-full border border-white/70 font-bold">S</span><span class="font-semibold">SRDBM 1008</span></div><p class="hidden px-4 text-xs text-white/60 sm:block">SQL File Migration Workspace</p><a class="ml-auto flex h-12 items-center gap-2 border-l border-white/10 px-4 text-sm font-medium hover:bg-white/10 sm:px-5" href="<?= e($cookiePath) ?>" title="入力内容を破棄して初期画面へ戻る"><span aria-hidden="true">↶</span>最初から</a></header>
<aside class="fixed bottom-0 left-0 top-12 hidden w-60 bg-[#23282d] text-[#c3c4c7] lg:block"><nav class="py-3"><a class="flex h-11 items-center gap-3 border-l-4 border-[#72aee6] bg-white/10 px-4 text-white" href="#replace"><span>↔</span><span class="font-medium">SQL置換</span></a><a class="flex h-11 items-center gap-3 px-5 hover:bg-white/5" href="#files"><span>▤</span><span>SQLファイル</span></a><a class="flex h-11 items-center gap-3 px-5 hover:bg-white/5" href="#outputs"><span>⇩</span><span>変換済みSQL</span></a></nav><div class="absolute bottom-0 p-4 text-xs leading-5 text-white/50">DB接続なし<br>SRDBM 1008 REMIX v0.0.4</div></aside>
<main class="pt-12 lg:pl-60"><div class="mx-auto max-w-[1180px] px-4 py-8 sm:px-8">
<div class="mb-7"><p class="mb-2 text-xs text-wp-muted">ツール › SQLファイル変換</p><h1 class="text-[28px] font-semibold tracking-tight">SQLを安全に置換</h1><p class="mt-2 leading-6 text-wp-muted">データベースへ接続せず、SQLダンプを直接変換します。元SQLは保持され、シリアライズデータの文字数も自動調整されます。</p></div>
<?php if ($errors !== []): ?><div class="mb-6 border-l-4 border-wp-danger bg-white p-4" role="alert"><p class="font-semibold text-wp-danger">確認が必要です</p><ul class="mt-2 list-disc space-y-1 pl-5"><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<?php if ($result !== null): ?><section class="panel mb-6 p-5"><p class="text-xs font-semibold uppercase tracking-wide text-wp-success">Completed</p><h2 class="mt-1 text-lg font-semibold"><?= $result['dry_run'] ? 'ドライラン・バックアップ完了' : '変換完了' ?></h2><div class="mt-4 grid gap-3 sm:grid-cols-3 lg:grid-cols-4"><div class="bg-[#f6f7f7] p-3"><span class="block text-xs text-wp-muted">URL変更</span><strong class="mt-1 block text-xl"><?= number_format($result['url_changes']) ?></strong></div><div class="bg-[#f6f7f7] p-3"><span class="block text-xs text-wp-muted">接頭辞変更</span><strong class="mt-1 block text-xl"><?= number_format($result['prefix_changes']) ?></strong></div><div class="bg-[#f6f7f7] p-3"><span class="block text-xs text-wp-muted">メール変更</span><strong class="mt-1 block text-xl"><?= number_format($result['email_changes']) ?></strong></div><div class="bg-[#f6f7f7] p-3"><span class="block text-xs text-wp-muted">画像パス変更</span><strong class="mt-1 block text-xl"><?= number_format($result['image_changes']) ?></strong></div><div class="bg-[#f6f7f7] p-3"><span class="block text-xs text-wp-muted">プラグイン設定</span><strong class="mt-1 block text-xl"><?= number_format($result['plugin_changes']) ?></strong></div><div class="bg-[#f6f7f7] p-3"><span class="block text-xs text-wp-muted">処理容量</span><strong class="mt-1 block text-xl"><?= e(fileSizeLabelFromBytes($result['bytes'])) ?></strong></div><div class="bg-[#f6f7f7] p-3"><span class="block text-xs text-wp-muted">処理時間</span><strong class="mt-1 block text-xl"><?= number_format($result['elapsed'], 2) ?>秒</strong></div></div><?php if ($result['dry_run']): ?><div class="mt-4 border-l-4 border-[#f0b849] bg-[#fff8e5] p-4"><p class="font-semibold">元SQLをバックアップしました</p><p class="mt-1 text-xs text-wp-muted"><?= e($result['backup']) ?></p><form method="post" data-processing-form class="mt-4"><input type="hidden" name="csrf_token" value="<?= e((string) $_SESSION['csrf_token']) ?>"><input type="hidden" name="action" value="execute-confirmed"><input type="hidden" name="job_token" value="<?= e($result['job_token']) ?>"><button class="btn primary" type="submit">変換する</button></form></div><?php else: ?><div class="mt-4 flex flex-wrap items-center gap-3"><a class="btn primary" href="?download=<?= rawurlencode($result['output']) ?>">変換済みSQLをダウンロード</a><span class="text-xs text-wp-muted">元SQLバックアップ: <?= e($result['backup']) ?></span></div><?php endif; ?></section><?php endif; ?>
<form id="replace" method="post" enctype="multipart/form-data" class="grid items-start gap-6 xl:grid-cols-[minmax(0,1fr)_310px]"><input type="hidden" name="csrf_token" value="<?= e((string) $_SESSION['csrf_token']) ?>"><input type="hidden" name="MAX_FILE_SIZE" value="<?= MAX_SQL_UPLOAD_BYTES ?>"><input type="hidden" id="chunked_sql_file" name="chunked_sql_file" value="">
<div class="space-y-6">
<section id="files" class="panel"><div class="border-b border-wp-line px-6 py-4"><div class="flex items-center gap-3"><span class="grid h-7 w-7 place-items-center rounded-full bg-[#e9ecff] text-xs font-bold text-wp-blue">1</span><h2 class="font-semibold">SQLファイルをアップロード</h2></div></div><div class="space-y-5 p-6"><div><label class="label" for="sql_file">アップロード済みSQL</label><select id="sql_file" name="sql_file"><option value="">SQLファイルを選択</option><?php foreach ($sqlFiles as $file): $name = basename($file); ?><option value="<?= e($name) ?>" <?= $selectedFile === $name ? 'selected' : '' ?>><?= e($name) ?> — <?= e(fileSizeLabel($file)) ?></option><?php endforeach; ?></select><p class="help">以前アップロードしたファイルと `sql/input/` に配置したファイルが表示されます。</p></div><div class="flex items-center gap-3 text-xs text-wp-muted"><span class="h-px flex-1 bg-wp-line"></span>新しいSQLをアップロード<span class="h-px flex-1 bg-wp-line"></span></div><label id="drop-zone" for="sql_upload" class="flex cursor-pointer flex-col items-center border-2 border-dashed border-[#a7aaad] bg-[#fafafa] px-5 py-8 text-center hover:border-wp-blue"><span class="mb-3 grid h-10 w-10 place-items-center rounded-full bg-white text-xl shadow-sm">⇧</span><span id="upload-label" class="font-semibold text-wp-blue">SQLファイルを選択</span><span class="mt-1 text-xs text-wp-muted">またはドロップ（.sql / 最大500MB）</span><input id="sql_upload" name="sql_upload" type="file" accept=".sql,application/sql,text/plain" class="sr-only"></label><div class="flex flex-col gap-3 border-t border-wp-line pt-5 sm:flex-row sm:items-center"><button id="upload-sql" class="btn secondary shrink-0" type="button" disabled>アップロードする</button><p id="upload-status" class="text-xs text-wp-muted" role="status" aria-live="polite"><?= $selectedFile !== '' ? 'アップロード済みSQLを選択しています。' : 'ファイルを選択するとアップロードできます。' ?></p></div></div></section>
<section class="panel"><div class="border-b border-wp-line px-6 py-4"><div class="flex items-center gap-3"><span class="grid h-7 w-7 place-items-center rounded-full bg-[#e9ecff] text-xs font-bold text-wp-blue">2</span><h2 class="font-semibold">置換ルール</h2></div></div><div class="grid gap-5 p-6 sm:grid-cols-[1fr_auto_1fr] sm:items-end"><div><label class="label" for="source_url">置換元URL</label><input id="source_url" name="source_url" type="url" value="<?= e($sourceUrl) ?>" required></div><span class="hidden h-10 items-center text-xl text-wp-muted sm:flex">→</span><div><label class="label" for="destination_url">置換後URL</label><input id="destination_url" name="destination_url" type="url" value="<?= e($destinationUrl) ?>" required></div><label class="flex items-start gap-3 sm:col-span-3"><input name="force_https" type="checkbox" class="mt-0.5" <?= !isset($_POST['csrf_token']) || isset($_POST['force_https']) ? 'checked' : '' ?>><span><span class="block font-medium">置換後URLをHTTPSに統一</span><span class="help mt-0">http:// の場合もhttps://へ補正します。</span></span></label><div class="border-t border-wp-line pt-5 sm:col-span-3"><div class="mb-3 flex items-center gap-2"><span class="font-medium">テーブル接頭辞の変更</span><span class="rounded-full bg-[#f0f0f1] px-2 py-0.5 text-[11px] text-wp-muted">任意</span></div><div class="grid gap-5 sm:grid-cols-[1fr_auto_1fr] sm:items-end"><div><label class="label" for="source_prefix">変更前</label><input id="source_prefix" name="source_prefix" type="text" value="<?= e($sourcePrefix) ?>" placeholder="wp_" pattern="[A-Za-z0-9_]+"></div><span class="hidden h-10 items-center text-xl text-wp-muted sm:flex">→</span><div><label class="label" for="destination_prefix">変更後</label><input id="destination_prefix" name="destination_prefix" type="text" value="<?= e($destinationPrefix) ?>" placeholder="renewal_" pattern="[A-Za-z0-9_]+"></div></div><p class="help">テーブル名、user_roles、capabilities、シリアライズ済み設定内の接頭辞をまとめて変更します。</p></div></div></section>
<section id="wordpress-settings" class="panel"><div class="border-b border-wp-line px-6 py-4"><div class="flex items-center gap-3"><span class="grid h-7 w-7 place-items-center rounded-full bg-[#e9ecff] text-xs font-bold text-wp-blue">3</span><div><h2 class="font-semibold">WordPress設定</h2><p class="mt-0.5 text-xs text-wp-muted">管理者メール・画像パス・プラグイン（任意）</p></div></div></div><div class="p-6"><div id="inspection-empty" class="border border-dashed border-wp-line bg-[#fafafa] p-5 text-sm text-wp-muted">SQLのアップロード後に、検出したWordPress設定を表示します。</div><div id="inspection-loading" class="hidden items-center gap-3 text-sm text-wp-muted"><span class="h-5 w-5 animate-spin rounded-full border-2 border-wp-line border-t-wp-blue"></span>SQL内のWordPress設定を解析しています…</div><div id="inspection-results" class="hidden space-y-6"></div></div></section>
<div class="sticky bottom-0 z-20 flex flex-col-reverse justify-between gap-3 border border-wp-line bg-white/95 p-4 shadow-lg sm:flex-row sm:items-center"><p class="text-xs text-wp-muted">ドライラン時に元SQLをバックアップします。</p><button class="btn primary" type="submit" name="action" value="dry-run">ドライランしてバックアップ</button></div>
</div>
<aside class="space-y-6 xl:sticky xl:top-20"><section class="panel p-5"><h2 class="font-semibold">処理フロー</h2><ol class="mt-4 space-y-3 text-sm"><li><strong class="mr-2 text-wp-blue">1</strong>SQLをアップロード</li><li><strong class="mr-2 text-wp-blue">2</strong>置換条件を指定</li><li><strong class="mr-2 text-wp-blue">3</strong>WordPress設定を確認 <span class="text-xs text-wp-muted">任意</span></li><li><strong class="mr-2 text-wp-blue">4</strong>ドライラン・バックアップ</li><li><strong class="mr-2 text-wp-blue">5</strong>変更件数を確認</li><li><strong class="mr-2 text-wp-blue">6</strong>変換する</li></ol></section><section class="panel p-5"><div class="mb-4 flex justify-between"><h2 class="font-semibold">実行環境</h2><span class="text-xs font-semibold <?= $readyCount === count($preflight) ? 'text-wp-success' : 'text-[#996800]' ?>"><?= $readyCount ?>/<?= count($preflight) ?> READY</span></div><ul class="space-y-3"><?php foreach ($preflight as $item): ?><li class="flex justify-between"><span><?= e($item['label']) ?></span><span class="grid h-5 w-5 place-items-center rounded-full text-xs <?= $item['ready'] ? 'bg-[#edfaef] text-wp-success' : 'bg-[#fcf0f1] text-wp-danger' ?>"><?= $item['ready'] ? '✓' : '!' ?></span></li><?php endforeach; ?></ul></section><section id="outputs" class="panel p-5"><h2 class="font-semibold">最近の変換済みSQL</h2><?php if ($outputFiles === []): ?><p class="mt-3 text-xs text-wp-muted">まだ出力はありません。</p><?php else: ?><ul class="mt-3 divide-y divide-wp-line"><?php foreach ($outputFiles as $file): ?><li class="py-3"><a class="block truncate text-sm font-medium text-wp-blue" href="?download=<?= rawurlencode(basename($file)) ?>"><?= e(basename($file)) ?></a><span class="text-xs text-wp-muted"><?= e(fileSizeLabel($file)) ?></span></li><?php endforeach; ?></ul><?php endif; ?></section><div class="border border-[#c5d9ed] bg-[#f0f6fc] p-4 text-xs leading-5"><strong class="block">DB接続は不要です</strong>SQLファイルだけを読み書きし、データベースには接続しません。</div></aside>
</form></div></main><script src="assets/app.js?v=<?= (int) filemtime(__DIR__ . '/assets/app.js') ?>"></script></body></html>
<?php
function fileSizeLabelFromBytes(int $bytes): string { return $bytes >= 1048576 ? number_format($bytes / 1048576, 1) . ' MB' : number_format($bytes / 1024, 1) . ' KB'; }
