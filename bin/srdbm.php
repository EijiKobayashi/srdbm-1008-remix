#!/usr/bin/env php
<?php

declare(strict_types=1);

use Srdbm\SqlDumpReplacer;

const SRDBM_ROOT = __DIR__ . '/..';
const SRDBM_MAX_BYTES = 524288000;

require SRDBM_ROOT . '/src/SqlDumpReplacer.php';

$options = getopt('', ['config:', 'input:', 'output:', 'search:', 'replace:', 'prefix-search:', 'prefix-replace:', 'dry-run', 'force-https', 'help']);
if (isset($options['help'])) {
    echo <<<'HELP'
SRDBM 1008 REMIX — SQL file search and replace

Usage:
  php bin/srdbm.php --input=database.sql --search=http://old.example --replace=https://new.example

Options:
  --config=FILE   設定ファイル（既定: config/srdbm.php）
  --input=FILE    入力SQL（未指定時は sql/input 内の1ファイルを自動選択）
  --output=FILE   出力SQL（未指定時は sql/output に生成）
  --search=TEXT   置換元（設定ファイルの source_url より優先）
  --replace=TEXT  置換後（設定ファイルの destination_url より優先）
  --prefix-search=TEXT 変更前のテーブル接頭辞（任意）
  --prefix-replace=TEXT 変更後のテーブル接頭辞（任意）
  --force-https   置換後URLをHTTPSへ補正
  --dry-run       変換結果を保存せず変更件数だけ確認
  --help          ヘルプを表示

HELP;
    exit(0);
}

try {
    $configPath = path((string) ($options['config'] ?? 'config/srdbm.php'));
    $config = is_file($configPath) ? require $configPath : [];
    $config = is_array($config) ? $config : [];

    $input = isset($options['input']) ? path((string) $options['input']) : findInput();
    if (!is_file($input)) {
        throw new RuntimeException("入力SQLがありません: {$input}");
    }
    $size = filesize($input);
    if ($size === false || $size <= 0 || $size > SRDBM_MAX_BYTES) {
        throw new RuntimeException('入力SQLは1バイト以上500MB以下にしてください。');
    }

    $search = trim((string) ($options['search'] ?? $config['source_url'] ?? ''));
    $replace = trim((string) ($options['replace'] ?? $config['destination_url'] ?? ''));
    if ($search === '' || $replace === '') {
        throw new RuntimeException('--search と --replace、または設定ファイルのURLを指定してください。');
    }
    if (isset($options['force-https']) || ($config['force_https'] ?? true) === true) {
        $replace = preg_replace('#^http://#i', 'https://', $replace) ?? $replace;
    }
    if ($search === $replace) {
        throw new RuntimeException('置換元と置換後が同じです。');
    }
    $prefixSearch = (string) ($options['prefix-search'] ?? $config['source_prefix'] ?? '');
    $prefixReplace = (string) ($options['prefix-replace'] ?? $config['destination_prefix'] ?? '');
    if (($prefixSearch === '') !== ($prefixReplace === '')) {
        throw new RuntimeException('接頭辞は変更前と変更後の両方を指定してください。');
    }
    foreach ([$prefixSearch, $prefixReplace] as $prefix) {
        if ($prefix !== '' && preg_match('/^[A-Za-z0-9_]+$/', $prefix) !== 1) {
            throw new RuntimeException('接頭辞は半角英数字とアンダースコアで指定してください。');
        }
    }

    $dryRun = isset($options['dry-run']);
    $outputDir = SRDBM_ROOT . '/sql/output';
    if (!is_dir($outputDir) && !mkdir($outputDir, 0775, true) && !is_dir($outputDir)) {
        throw new RuntimeException('出力ディレクトリを作成できません。');
    }
    $output = isset($options['output'])
        ? path((string) $options['output'])
        : $outputDir . '/' . safeName(pathinfo($input, PATHINFO_FILENAME)) . '-replaced-' . date('Ymd-His') . '.sql';
    $workingOutput = $dryRun ? tempnam(sys_get_temp_dir(), 'srdbm-dry-') : $output . '.part';
    if ($workingOutput === false) {
        throw new RuntimeException('一時ファイルを作成できません。');
    }

    echo "SRDBM 1008 REMIX\n入力: {$input}\n置換: {$search} -> {$replace}\n";
    $report = (new SqlDumpReplacer())->process($input, $workingOutput, $search, $replace, $prefixSearch, $prefixReplace);

    if ($dryRun) {
        unlink($workingOutput);
    } else {
        if (!rename($workingOutput, $output)) {
            @unlink($workingOutput);
            throw new RuntimeException('変換済みSQLを確定できません。');
        }
    }

    echo 'URL変更: ' . number_format($report['url_changes']) . "\n";
    echo '接頭辞変更: ' . number_format($report['prefix_changes']) . "\n";
    echo '処理容量: ' . number_format($report['bytes']) . " bytes\n";
    echo '処理時間: ' . number_format($report['elapsed'], 2) . " 秒\n";
    echo $dryRun ? "ドライラン完了（ファイルは保存していません）\n" : "出力: {$output}\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'エラー: ' . $error->getMessage() . "\n");
    exit(1);
}

function path(string $value): string
{
    return $value !== '' && $value[0] === '/' ? $value : SRDBM_ROOT . '/' . ltrim($value, '/');
}

function findInput(): string
{
    $files = glob(SRDBM_ROOT . '/sql/input/*.[sS][qQ][lL]') ?: [];
    if (count($files) !== 1) {
        throw new RuntimeException('sql/input 内のSQLが0件または複数です。--input で指定してください。');
    }
    return $files[0];
}

function safeName(string $value): string
{
    return preg_replace('/[^A-Za-z0-9_.-]+/', '-', $value) ?: 'database';
}
