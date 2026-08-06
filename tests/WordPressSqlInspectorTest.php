<?php

declare(strict_types=1);

use Srdbm\SqlDumpReplacer;
use Srdbm\WordPressSqlInspector;

require __DIR__ . '/../src/SqlDumpReplacer.php';
require __DIR__ . '/../src/WordPressSqlInspector.php';

$input = tempnam(sys_get_temp_dir(), 'srdbm-inspect-in-');
$output = tempnam(sys_get_temp_dir(), 'srdbm-inspect-out-');
if ($input === false || $output === false) {
    throw new RuntimeException('テスト用ファイルを作成できません。');
}

$escape = static function (string $value): string {
    return strtr($value, ['\\' => '\\\\', "'" => "\\'"]);
};
$capabilities = serialize(['administrator' => true]);
$activePlugins = serialize(['akismet/akismet.php']);
$recentlyActivated = serialize(['hello-dolly/hello.php' => 1710000000]);
$nestedEmail = serialize(['notification' => 'admin@example.com']);
$sql = "INSERT INTO `wp_sembawpusers` VALUES\n"
    . "(1,'admin','hash','admin','admin@example.com','','','2026-01-01 00:00:00','',0,'Admin'),\n"
    . "(2,'editor','hash','editor','editor@example.com','','','2026-01-01 00:00:00','',0,'Editor');\n"
    . "INSERT INTO `wp_sembawpusermeta` VALUES (1,1,'wp_sembawp_capabilities','" . $escape($capabilities) . "');\n"
    . "INSERT INTO `wp_sembawpoptions` (`option_id`,`option_name`,`option_value`,`autoload`) VALUES\n"
    . "(1,'admin_email','admin@example.com','yes'),\n"
    . "(2,'upload_path','wp-content/uploads','yes'),\n"
    . "(3,'asset_url','https://old.example/wp-content/uploads/2026/image.jpg','yes'),\n"
    . "(4,'active_plugins','" . $escape($activePlugins) . "','yes'),\n"
    . "(5,'recently_activated','" . $escape($recentlyActivated) . "','yes'),\n"
    . "(6,'mail_settings','" . $escape($nestedEmail) . "','yes');\n";
file_put_contents($input, $sql);

try {
    $inspection = (new WordPressSqlInspector())->inspect($input);
    assert(count($inspection['admin_emails']) === 1);
    assert($inspection['admin_emails'][0]['value'] === 'admin@example.com');
    assert(in_array('管理者ユーザー: admin', $inspection['admin_emails'][0]['labels'], true));
    assert(count($inspection['plugin_groups']) === 1);

    $plugins = [];
    foreach ($inspection['plugin_groups'][0]['plugins'] as $plugin) {
        $plugins[$plugin['path']] = $plugin['active'];
    }
    assert($plugins['akismet/akismet.php'] === true);
    assert($plugins['hello-dolly/hello.php'] === false);

    $imageValues = array_column($inspection['image_paths'], 'value');
    assert(in_array('wp-content/uploads', $imageValues, true));
    assert(in_array('https://old.example/wp-content/uploads', $imageValues, true));

    $newPlugins = serialize(['akismet/akismet.php', 'hello-dolly/hello.php']);
    $report = (new SqlDumpReplacer())->process(
        $input,
        $output,
        'http://unused.example',
        'https://unused.example',
        '',
        '',
        ['admin@example.com' => 'new-admin@example.com'],
        ['wp-content/uploads' => 'content/media'],
        [$activePlugins => $newPlugins]
    );
    $actual = (string) file_get_contents($output);
    assert($report['email_changes'] === 3);
    assert($report['image_changes'] === 2);
    assert($report['plugin_changes'] === 1);
    assert(strpos($actual, 'new-admin@example.com') !== false);
    assert(strpos($actual, 'content/media') !== false);
    assert(strpos($actual, $escape($newPlugins)) !== false);
    echo "WordPressSqlInspectorTest: OK\n";
} finally {
    @unlink($input);
    @unlink($output);
}
