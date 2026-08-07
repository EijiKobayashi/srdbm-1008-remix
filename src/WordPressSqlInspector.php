<?php

declare(strict_types=1);

namespace Srdbm;

use Generator;
use RuntimeException;

final class WordPressSqlInspector
{
    /**
     * @return array{
     *   table_prefixes:list<array{value:string,tables:int}>,
     *   admin_emails:list<array{value:string,labels:list<string>}>,
     *   image_paths:list<array{value:string,occurrences:int}>,
     *   plugin_groups:list<array{id:string,label:string,original:string,associative:bool,plugins:list<array{path:string,active:bool,value:mixed}>}>
     * }
     */
    public function inspect(string $inputPath): array
    {
        $input = fopen($inputPath, 'rb');
        if ($input === false) {
            throw new RuntimeException('SQLファイルを解析できません。');
        }

        $users = [];
        $administratorIds = [];
        $tablePrefixes = [];
        $emailLabels = [];
        $imagePaths = [];
        $pluginCandidates = [];
        $pluginGroups = [];

        try {
            foreach ($this->statements($input) as $statement) {
                $table = $this->dmlTargetTable($statement);
                if ($table !== null) {
                    $this->collectTablePrefix($table, $tablePrefixes);
                }

                $insert = $this->parseInsert($statement);
                if ($insert === null) {
                    continue;
                }
                [$table, $columns, $rows] = $insert;
                if (preg_match('/(users|usermeta|options|sitemeta)$/i', $table, $match) !== 1) {
                    continue;
                }
                $type = strtolower($match[1]);
                foreach ($rows as $row) {
                    foreach ($row as $value) {
                        if (is_string($value)) {
                            $this->collectImagePaths($value, $imagePaths);
                        }
                    }

                    if ($type === 'users') {
                        $id = $this->field($row, $columns, 'ID', 0);
                        $login = $this->field($row, $columns, 'user_login', 1);
                        $email = $this->field($row, $columns, 'user_email', 4);
                        if ($id !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
                            $users[$id] = ['login' => $login, 'email' => $email];
                        }
                        continue;
                    }

                    if ($type === 'usermeta') {
                        $userId = $this->field($row, $columns, 'user_id', 1);
                        $key = $this->field($row, $columns, 'meta_key', 2);
                        $value = $this->field($row, $columns, 'meta_value', 3);
                        if (($this->endsWith($key, '_capabilities') && $this->hasAdministratorRole($value))
                            || ($this->endsWith($key, '_user_level') && (int) $value >= 10)) {
                            $administratorIds[$userId] = true;
                        }
                        continue;
                    }

                    $keyName = $type === 'options' ? 'option_name' : 'meta_key';
                    $valueName = $type === 'options' ? 'option_value' : 'meta_value';
                    $keyDefault = $type === 'options' ? 1 : 2;
                    $valueDefault = $type === 'options' ? 2 : 3;
                    $key = $this->field($row, $columns, $keyName, $keyDefault);
                    $value = $this->field($row, $columns, $valueName, $valueDefault);

                    if ($type === 'options' && $key === 'admin_email' && filter_var($value, FILTER_VALIDATE_EMAIL) !== false) {
                        $emailLabels[$value]['サイト管理者設定'] = true;
                    }
                    if ($type === 'options' && in_array($key, ['upload_path', 'upload_url_path'], true) && $value !== '') {
                        $imagePaths[$value] = ($imagePaths[$value] ?? 0) + 1;
                    }

                    if (in_array($key, ['active_plugins', 'sitewide_plugins'], true)) {
                        $plugins = $this->pluginArray($value);
                        if ($plugins !== null) {
                            $associative = $key === 'sitewide_plugins';
                            $active = $associative ? array_keys($plugins) : array_values($plugins);
                            $active = array_values(array_filter($active, [$this, 'isPluginPath']));
                            foreach ($active as $path) {
                                $pluginCandidates[$path] = true;
                            }
                            $id = substr(hash('sha256', $table . "\0" . $key . "\0" . $value), 0, 16);
                            $pluginGroups[$id] = [
                                'id' => $id,
                                'label' => ($key === 'sitewide_plugins' ? 'ネットワーク有効' : '有効プラグイン') . '（' . $table . '）',
                                'original' => $value,
                                'associative' => $associative,
                                'active' => array_fill_keys($active, true),
                                'values' => $plugins,
                            ];
                        }
                    } elseif (in_array($key, ['recently_activated', 'auto_update_plugins', 'uninstall_plugins'], true)) {
                        $plugins = $this->pluginArray($value);
                        if ($plugins !== null) {
                            foreach (array_merge(array_keys($plugins), array_values($plugins)) as $path) {
                                if (is_string($path) && $this->isPluginPath($path)) {
                                    $pluginCandidates[$path] = true;
                                }
                            }
                        }
                    }
                }
            }
        } finally {
            fclose($input);
        }

        foreach (array_keys($administratorIds) as $id) {
            if (!isset($users[$id])) {
                continue;
            }
            $user = $users[$id];
            $emailLabels[$user['email']]['管理者ユーザー: ' . $user['login']] = true;
        }

        ksort($emailLabels, SORT_NATURAL | SORT_FLAG_CASE);
        ksort($imagePaths, SORT_NATURAL | SORT_FLAG_CASE);
        ksort($pluginCandidates, SORT_NATURAL | SORT_FLAG_CASE);
        uksort($tablePrefixes, static function (string $left, string $right) use ($tablePrefixes): int {
            $byCount = count($tablePrefixes[$right]) <=> count($tablePrefixes[$left]);
            return $byCount !== 0 ? $byCount : strnatcasecmp($left, $right);
        });

        $publicGroups = [];
        foreach ($pluginGroups as $group) {
            $plugins = [];
            foreach (array_keys($pluginCandidates) as $path) {
                $plugins[] = [
                    'path' => $path,
                    'active' => isset($group['active'][$path]),
                    'value' => $group['values'][$path] ?? null,
                ];
            }
            $publicGroups[] = [
                'id' => $group['id'], 'label' => $group['label'], 'original' => $group['original'],
                'associative' => $group['associative'], 'plugins' => $plugins,
            ];
        }

        return [
            'table_prefixes' => array_map(static function (string $prefix, array $tables): array {
                return ['value' => $prefix, 'tables' => count($tables)];
            }, array_keys($tablePrefixes), array_values($tablePrefixes)),
            'admin_emails' => array_map(static function (string $email, array $labels): array {
                return ['value' => $email, 'labels' => array_keys($labels)];
            }, array_keys($emailLabels), array_values($emailLabels)),
            'image_paths' => array_map(static function (string $path, int $occurrences): array {
                return ['value' => $path, 'occurrences' => $occurrences];
            }, array_keys($imagePaths), array_values($imagePaths)),
            'plugin_groups' => $publicGroups,
        ];
    }

    /** @param resource $input @return Generator<int, string> */
    private function statements($input): Generator
    {
        $buffer = '';
        while (($line = fgets($input)) !== false) {
            if ($buffer === '' && preg_match('/^\s*(?:INSERT(?:\s+IGNORE)?\s+INTO|REPLACE\s+INTO|UPDATE|DELETE\s+FROM)\s+/i', $line) !== 1) {
                continue;
            }
            $buffer .= $line;
            if (preg_match('/;\s*$/', $line) === 1) {
                yield $buffer;
                $buffer = '';
            }
        }
        if ($buffer !== '') {
            yield $buffer;
        }
    }

    /** @return array{string,list<string>,Generator<int,list<string|null>>}|null */
    private function parseInsert(string $statement): ?array
    {
        if (preg_match('/^\s*(?:INSERT(?:\s+IGNORE)?|REPLACE)\s+INTO\s+`?([^`\s(]+)`?\s*(?:\((.*?)\))?\s+VALUES\s*(.*);\s*$/is', $statement, $match) !== 1) {
            return null;
        }
        $columns = [];
        if (($match[2] ?? '') !== '') {
            foreach (explode(',', $match[2]) as $column) {
                $columns[] = trim($column, " \t\n\r\0\x0B`");
            }
        }
        return [$match[1], $columns, $this->rows($match[3])];
    }

    private function dmlTargetTable(string $statement): ?string
    {
        if (preg_match(
            '/^\s*(?:INSERT(?:\s+IGNORE)?\s+INTO|REPLACE\s+INTO|UPDATE|DELETE\s+FROM)\s+(?:(?:`[^`]+`|[^\s.`]+)\.)?`?([^`\s(]+)`?/i',
            $statement,
            $match
        ) !== 1) {
            return null;
        }

        return $match[1];
    }

    /** @param array<string,array<string,bool>> $tablePrefixes */
    private function collectTablePrefix(string $table, array &$tablePrefixes): void
    {
        $coreSuffixes = [
            'term_relationships', 'term_taxonomy', 'commentmeta', 'postmeta',
            'usermeta', 'comments', 'options', 'sitemeta', 'posts', 'terms', 'users',
        ];

        foreach ($coreSuffixes as $suffix) {
            if (!$this->endsWith(strtolower($table), $suffix)) {
                continue;
            }
            $prefix = substr($table, 0, -strlen($suffix));
            if ($prefix !== '') {
                $tablePrefixes[$prefix][$table] = true;
            }
            return;
        }
    }

    /** @return Generator<int, list<string|null>> */
    private function rows(string $values): Generator
    {
        $length = strlen($values);
        $row = [];
        $token = '';
        $depth = 0;
        $inString = false;
        $escape = false;

        for ($offset = 0; $offset < $length; $offset++) {
            $character = $values[$offset];
            if ($inString) {
                $token .= $character;
                if ($escape) {
                    $escape = false;
                } elseif ($character === '\\') {
                    $escape = true;
                } elseif ($character === "'") {
                    if ($offset + 1 < $length && $values[$offset + 1] === "'") {
                        $token .= "'";
                        $offset++;
                    } else {
                        $inString = false;
                    }
                }
                continue;
            }
            if ($character === "'") {
                $inString = true;
                $token .= $character;
            } elseif ($character === '(') {
                if ($depth > 0) {
                    $token .= $character;
                }
                $depth++;
            } elseif ($character === ')' && $depth > 0) {
                $depth--;
                if ($depth === 0) {
                    $row[] = $this->decodeToken($token);
                    yield $row;
                    $row = [];
                    $token = '';
                } else {
                    $token .= $character;
                }
            } elseif ($character === ',' && $depth === 1) {
                $row[] = $this->decodeToken($token);
                $token = '';
            } elseif ($depth > 0) {
                $token .= $character;
            }
        }
    }

    private function decodeToken(string $token): ?string
    {
        $token = trim($token);
        if (strcasecmp($token, 'NULL') === 0) {
            return null;
        }
        if (strlen($token) >= 2 && $token[0] === "'" && substr($token, -1) === "'") {
            return $this->sqlUnescape(substr($token, 1, -1));
        }
        return $token;
    }

    /** @param list<string|null> $row @param list<string> $columns */
    private function field(array $row, array $columns, string $name, int $default): string
    {
        $index = $default;
        if ($columns !== []) {
            $found = array_search(strtolower($name), array_map('strtolower', $columns), true);
            if ($found === false) {
                return '';
            }
            $index = (int) $found;
        }
        return isset($row[$index]) ? (string) $row[$index] : '';
    }

    private function hasAdministratorRole(string $serialized): bool
    {
        $value = @unserialize($serialized, ['allowed_classes' => false]);
        return is_array($value) && !empty($value['administrator']);
    }

    /** @return array<mixed>|null */
    private function pluginArray(string $serialized): ?array
    {
        $value = @unserialize($serialized, ['allowed_classes' => false]);
        return is_array($value) ? $value : null;
    }

    private function isPluginPath($value): bool
    {
        return is_string($value) && preg_match('~^[^/\s]+(?:/[^/\s]+)+\.php$~i', $value) === 1;
    }

    /** @param array<string,int> $paths */
    private function collectImagePaths(string $value, array &$paths): void
    {
        if (preg_match_all('~https?://[^/\s\"\'<>]+(?:/[^/\s\"\'<>]+)*/wp-content/uploads~i', $value, $matches) > 0) {
            foreach ($matches[0] as $path) {
                $paths[$path] = ($paths[$path] ?? 0) + 1;
            }
        }
        if (preg_match_all('~(?<![A-Za-z0-9._:/-])/?wp-content/uploads~i', $value, $matches) > 0) {
            foreach ($matches[0] as $path) {
                $paths[$path] = ($paths[$path] ?? 0) + 1;
            }
        }
    }

    private function sqlUnescape(string $value): string
    {
        return preg_replace_callback('/\\\\(.)/s', static function (array $match): string {
            $map = ['0' => "\0", 'b' => "\x08", 'n' => "\n", 'r' => "\r", 't' => "\t", 'Z' => "\x1a"];
            return $map[$match[1]] ?? $match[1];
        }, str_replace("''", "'", $value)) ?? $value;
    }

    private function endsWith(string $value, string $suffix): bool
    {
        return $suffix === '' || substr($value, -strlen($suffix)) === $suffix;
    }
}
