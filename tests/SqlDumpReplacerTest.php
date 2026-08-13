<?php

declare(strict_types=1);

use Srdbm\SqlDumpReplacer;

require __DIR__ . '/../src/SqlDumpReplacer.php';

$input = tempnam(sys_get_temp_dir(), 'srdbm-test-in-');
$output = tempnam(sys_get_temp_dir(), 'srdbm-test-out-');
if ($input === false || $output === false) {
    throw new RuntimeException('テスト用ファイルを作成できません。');
}

$serialized = serialize(['url' => 'http://old.example/path', 'wp_capabilities' => "owner's site"]);
$serializedObject = 'O:3:"Foo":1:{s:1:"x";s:23:"http://old.example/item";}';
$sql = "INSERT INTO `wp_options` VALUES (1, 'http://old.example/plain'), (2, '" . str_replace("'", "\\'", $serialized) . "'), (3, '" . $serializedObject . "');\n";
file_put_contents($input, $sql);

try {
    $report = (new SqlDumpReplacer())->process($input, $output, 'http://old.example', 'https://new.example', 'wp_', 'renewal_');
    $actual = file_get_contents($output);
    $expectedSerialized = serialize(['url' => 'https://new.example/path', 'renewal_capabilities' => "owner's site"]);

    assert($report['url_changes'] === 3);
    assert($report['prefix_changes'] === 2);
    assert($report['changes'] === 5);
    assert(strpos((string) $actual, '`renewal_options`') !== false);
    assert(strpos((string) $actual, 'https://new.example/plain') !== false);
    assert(strpos((string) $actual, str_replace("'", "\\'", $expectedSerialized)) !== false);
    assert(strpos((string) $actual, 'O:3:"Foo":1:{s:1:"x";s:24:"https://new.example/item";}') !== false);
    assert(strpos((string) $actual, 'http://old.example') === false);

    $domainSerialized = serialize([
        'url' => 'http://semba-stg.bizproject.biz/serialized',
        'email' => 'serialized@semba-stg.bizproject.biz',
    ]);
    $domainSql = "INSERT INTO `wp_posts` VALUES "
        . "(1, 'https://semba-stg.bizproject.biz/plain'), "
        . "(2, 'https:\\\\/\\\\/semba-stg.bizproject.biz/escaped'), "
        . "(3, 'contact@semba-stg.bizproject.biz'), "
        . "(4, 'semba-stg.bizproject.biz'), "
        . "(5, '" . str_replace("'", "\\'", $domainSerialized) . "'), "
        . "(6, '@semba-stg.bizproject.biz'), "
        . "(7, 'bad..name@semba-stg.bizproject.biz'), "
        . "(8, 'other@example.com'), "
        . "(9, 'alternate@www.semba-stg.bizproject.biz');\n"
        . "INSERT INTO `wp_logs` VALUES (1, 'https://semba-stg.bizproject.biz/keep');\n";
    file_put_contents($input, $domainSql);
    $domainReport = (new SqlDumpReplacer())->process(
        $input,
        $output,
        '__unused__',
        '__unused_target__',
        '',
        '',
        [],
        [],
        [],
        ['source' => 'https://semba-stg.bizproject.biz', 'target' => 'https://www.semba1008.co.jp'],
        ['wp_posts']
    );
    $domainActual = (string) file_get_contents($output);
    $domainLines = explode("\n", $domainActual);
    assert($domainReport['url_changes'] === 6);
    assert(strpos($domainLines[0], 'https://semba-stg.bizproject.biz/plain') === false);
    assert(strpos($domainLines[0], 'contact@semba-stg.bizproject.biz') === false);
    assert(strpos($domainLines[0], 'contact@www.semba1008.co.jp') !== false);
    assert(strpos($domainLines[0], 'https://www.semba1008.co.jp/plain') !== false);
    assert(strpos($domainLines[0], '@semba-stg.bizproject.biz') !== false);
    assert(strpos($domainLines[0], 'bad..name@semba-stg.bizproject.biz') !== false);
    assert(strpos($domainLines[0], 'other@example.com') !== false);
    assert(strpos($domainLines[0], 'alternate@www.semba-stg.bizproject.biz') !== false);
    assert(strpos($domainLines[1], 'https://semba-stg.bizproject.biz/keep') !== false);
    echo "SqlDumpReplacerTest: OK\n";
} finally {
    @unlink($input);
    @unlink($output);
}
