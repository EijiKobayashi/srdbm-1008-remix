<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/SqlDumpReplacer.php';

use Srdbm\SqlDumpReplacer;

function expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException("FAIL: {$message}");
    }
    echo "PASS: {$message}\n";
}

function contains(string $haystack, string $needle): bool
{
    return strpos($haystack, $needle) !== false;
}

$temporary = sys_get_temp_dir() . '/srdbm-test-' . bin2hex(random_bytes(6));
mkdir($temporary, 0700, true);
try {
    $input = $temporary . '/input.sql';
    $output = $temporary . '/output.sql';
    $beforePrefix = "\nCREATE TABLE `";
    $padding = str_repeat('-', 1048576 - strlen($beforePrefix) - 2);
    file_put_contents(
        $input,
        $padding . $beforePrefix . "wp_sembawpposts` (`ID` bigint);\n"
        . "INSERT INTO `wp_sembawppostmeta` (`post_id`,`meta_key`,`meta_value`) VALUES (53447,'_mw-wp-form_data','wp_sembawp_value');\n"
    );
    (new SqlDumpReplacer())->process(
        $input,
        $output,
        '__no_match__',
        '__still_no_match__',
        'wp_sembawp',
        'wp_sembawp_'
    );
    $converted = (string) file_get_contents($output);
    expect(contains($converted, '`wp_sembawp_posts`'), 'チャンク境界をまたぐテーブル接頭辞を変換');
    expect(contains($converted, '`wp_sembawp_postmeta`'), 'INSERT先のpostmeta接頭辞を変換');
    expect(!contains($converted, '`wp_sembawpposts`') && !contains($converted, '`wp_sembawppostmeta`'), '変換後に旧接頭辞のテーブル名を残さない');

    $exactBoundaryInput = $temporary . '/exact-boundary-input.sql';
    $exactBoundaryOutput = $temporary . '/exact-boundary-output.sql';
    $exactBoundaryPadding = str_repeat(
        '-',
        1048576 - strlen($beforePrefix) - strlen('wp_sembawp')
    );
    file_put_contents(
        $exactBoundaryInput,
        $exactBoundaryPadding . $beforePrefix . "wp_sembawpposts` (`ID` bigint);\n"
    );
    (new SqlDumpReplacer())->process(
        $exactBoundaryInput,
        $exactBoundaryOutput,
        '__no_match__',
        '__still_no_match__',
        'wp_sembawp',
        'wp_sembawp_'
    );
    expect(
        contains((string) file_get_contents($exactBoundaryOutput), '`wp_sembawp_posts`'),
        'チャンク末尾で完結するテーブル接頭辞を変換'
    );

    $stale = $temporary . '/stale.sql';
    file_put_contents($stale, "CREATE TABLE `wp_sembawp_posts` (`ID` bigint);\nINSERT INTO `wp_sembawppostmeta` VALUES (53447,'_mw-wp-form_data','value');\n");
    $method = new ReflectionMethod(SqlDumpReplacer::class, 'validateOutputOrDiscard');
    if (PHP_VERSION_ID < 80100) {
        $method->setAccessible(true);
    }
    $detected = false;
    try {
        $method->invoke(new SqlDumpReplacer(), $stale, 'wp_sembawp', 'wp_sembawp_');
    } catch (ReflectionException $e) {
        throw $e;
    } catch (Throwable $e) {
        $detected = contains($e->getMessage(), 'wp_sembawppostmeta')
            && contains($e->getMessage(), '出力SQLを破棄しました');
    }
    expect($detected, '置換漏れのINSERT先テーブルを検出');
    expect(!file_exists($stale), '検証失敗時に出力SQLを残さない');

    $statements = [
        'CREATE TABLE IF NOT EXISTS `wp_sembawpoptions` (`id` bigint)',
        'DROP TABLE IF EXISTS `wp_sembawpoptions`',
        'ALTER TABLE `wp_sembawpoptions` ADD `name` varchar(20)',
        'INSERT IGNORE INTO `wp_sembawpoptions` VALUES (1)',
        'REPLACE INTO `wp_sembawpoptions` VALUES (1)',
        'UPDATE `wp_sembawpoptions` SET `id` = 2',
        'DELETE FROM `database_name`.`wp_sembawpoptions` WHERE `id` = 1',
    ];
    $allStatementTypesDetected = true;
    foreach ($statements as $index => $statement) {
        $statementOutput = $temporary . "/stale-{$index}.sql";
        file_put_contents($statementOutput, $statement . ";\n");
        try {
            $method->invoke(new SqlDumpReplacer(), $statementOutput, 'wp_sembawp', 'wp_sembawp_');
            $allStatementTypesDetected = false;
        } catch (ReflectionException $e) {
            throw $e;
        } catch (Throwable $e) {
            $allStatementTypesDetected = $allStatementTypesDetected
                && contains($e->getMessage(), 'wp_sembawpoptions')
                && !file_exists($statementOutput);
        }
    }
    expect($allStatementTypesDetected, '対象となる全SQL文の未変換テーブルを検出');

    $literalSql = $temporary . '/literal-sql.sql';
    file_put_contents(
        $literalSql,
        "INSERT INTO `wp_sembawp_options` VALUES ('SELECT * FROM `wp_sembawpposts`');\n"
    );
    $method->invoke(new SqlDumpReplacer(), $literalSql, 'wp_sembawp', 'wp_sembawp_');
    expect(file_exists($literalSql), '文字列リテラル内のSQLをテーブル参照と誤認しない');

    $compatibilityInput = $temporary . '/compatibility-input.sql';
    $compatibilityOutput = $temporary . '/compatibility-output.sql';
    $serialized = serialize([
        'url' => 'https://old.example.test/path',
        'email' => 'old@example.test',
        'image' => '/uploads/old/image.jpg',
        'table' => 'wp_sembawpoptions',
    ]);
    file_put_contents(
        $compatibilityInput,
        "INSERT INTO `wp_sembawpoptions` VALUES ('{$serialized}');\n"
    );
    (new SqlDumpReplacer())->process(
        $compatibilityInput,
        $compatibilityOutput,
        'https://old.example.test',
        'https://new.example.test',
        'wp_sembawp',
        'wp_sembawp_',
        ['old@example.test' => 'new@example.test'],
        ['/uploads/old/' => '/uploads/new/']
    );
    $expectedSerialized = serialize([
        'url' => 'https://new.example.test/path',
        'email' => 'new@example.test',
        'image' => '/uploads/new/image.jpg',
        'table' => 'wp_sembawp_options',
    ]);
    expect(
        contains((string) file_get_contents($compatibilityOutput), $expectedSerialized),
        'URL・メール・画像パス・PHPシリアライズ値の置換を維持'
    );
} finally {
    foreach (glob($temporary . '/*') ?: [] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
    @rmdir($temporary);
}

echo "All tests passed.\n";
