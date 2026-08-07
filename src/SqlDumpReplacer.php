<?php

declare(strict_types=1);

namespace Srdbm;

use RuntimeException;

final class SqlDumpReplacer
{
    /**
     * MySQLダンプ内の文字列リテラルを解析し、シリアライズデータを保ったまま置換する。
     *
     * @param array<string,string> $emailReplacements
     * @param array<string,string> $imagePathReplacements
     * @param array<string,string> $literalOverrides
     * @return array{changes:int, url_changes:int, prefix_changes:int, email_changes:int, image_changes:int, plugin_changes:int, bytes:int, elapsed:float}
     */
    public function process(
        string $inputPath,
        string $outputPath,
        string $search,
        string $replace,
        string $prefixSearch = '',
        string $prefixReplace = '',
        array $emailReplacements = [],
        array $imagePathReplacements = [],
        array $literalOverrides = []
    ): array
    {
        if ($search === '') {
            throw new RuntimeException('置換元は空にできません。');
        }

        $input = fopen($inputPath, 'rb');
        if ($input === false) {
            throw new RuntimeException('入力SQLを開けません。');
        }
        $output = fopen($outputPath, 'wb');
        if ($output === false) {
            fclose($input);
            throw new RuntimeException('出力SQLを作成できません。');
        }

        $startedAt = microtime(true);
        $changes = 0;
        $urlChanges = 0;
        $prefixChanges = 0;
        $emailChanges = 0;
        $imageChanges = 0;
        $pluginChanges = 0;
        $bytes = 0;
        $inLiteral = false;
        $escapeNext = false;
        $pendingQuote = false;
        $literal = '';
        $outsideCarry = '';

        try {
            while (!feof($input)) {
                $chunk = fread($input, 1048576);
                if ($chunk === false) {
                    throw new RuntimeException('入力SQLの読み込みに失敗しました。');
                }
                if ($chunk === '') {
                    continue;
                }
                $bytes += strlen($chunk);
                if (!$inLiteral && $outsideCarry !== '') {
                    $chunk = $outsideCarry . $chunk;
                    $outsideCarry = '';
                }
                $length = strlen($chunk);
                $offset = 0;

                if ($pendingQuote) {
                    if ($chunk[0] === "'") {
                        $literal .= "''";
                        $offset = 1;
                    } else {
                        $this->writeLiteral($output, $literal, $search, $replace, $prefixSearch, $prefixReplace, $emailReplacements, $imagePathReplacements, $literalOverrides, $urlChanges, $prefixChanges, $emailChanges, $imageChanges, $pluginChanges);
                        fwrite($output, "'");
                        $inLiteral = false;
                        $literal = '';
                    }
                    $pendingQuote = false;
                }

                while ($offset < $length) {
                    if (!$inLiteral) {
                        $quote = strpos($chunk, "'", $offset);
                        if ($quote === false) {
                            $outside = substr($chunk, $offset);
                            if ($prefixSearch !== '') {
                                $searchLength = strlen($prefixSearch);
                                $carryLength = min(max($searchLength - 1, 0), strlen($outside));
                                $writeLength = strlen($outside) - $carryLength;
                                $maxOverlap = min(max($searchLength - 1, 0), $writeLength);
                                for ($overlap = $maxOverlap; $overlap > 0; $overlap--) {
                                    if (substr($outside, $writeLength - $overlap, $overlap)
                                        === substr($prefixSearch, 0, $overlap)) {
                                        $writeLength -= $overlap;
                                        break;
                                    }
                                }
                                $outsideCarry = substr($outside, $writeLength);
                                $outside = substr($outside, 0, $writeLength);
                            }
                            $this->writeOutside($output, $outside, $prefixSearch, $prefixReplace, $prefixChanges);
                            break;
                        }
                        $this->writeOutside($output, substr($chunk, $offset, $quote - $offset), $prefixSearch, $prefixReplace, $prefixChanges);
                        fwrite($output, "'");
                        $inLiteral = true;
                        $literal = '';
                        $escapeNext = false;
                        $offset = $quote + 1;
                        continue;
                    }

                    if ($escapeNext) {
                        $literal .= $chunk[$offset];
                        $escapeNext = false;
                        $offset++;
                        continue;
                    }

                    $span = strcspn($chunk, "\\'", $offset);
                    if ($span > 0) {
                        $literal .= substr($chunk, $offset, $span);
                        $offset += $span;
                        if ($offset >= $length) {
                            break;
                        }
                    }

                    if ($chunk[$offset] === '\\') {
                        $literal .= '\\';
                        $offset++;
                        if ($offset < $length) {
                            $literal .= $chunk[$offset];
                            $offset++;
                        } else {
                            $escapeNext = true;
                        }
                        continue;
                    }

                    if ($offset + 1 < $length && $chunk[$offset + 1] === "'") {
                        $literal .= "''";
                        $offset += 2;
                        continue;
                    }
                    if ($offset + 1 === $length) {
                        $pendingQuote = true;
                        $offset++;
                        break;
                    }

                    $this->writeLiteral($output, $literal, $search, $replace, $prefixSearch, $prefixReplace, $emailReplacements, $imagePathReplacements, $literalOverrides, $urlChanges, $prefixChanges, $emailChanges, $imageChanges, $pluginChanges);
                    fwrite($output, "'");
                    $literal = '';
                    $inLiteral = false;
                    $offset++;
                }
            }

            if ($pendingQuote) {
                $this->writeLiteral($output, $literal, $search, $replace, $prefixSearch, $prefixReplace, $emailReplacements, $imagePathReplacements, $literalOverrides, $urlChanges, $prefixChanges, $emailChanges, $imageChanges, $pluginChanges);
                fwrite($output, "'");
                $inLiteral = false;
            }
            if ($inLiteral) {
                // 壊れたSQLは勝手に補正せず、読み取った内容をそのまま戻す。
                fwrite($output, $literal);
            }
            if ($outsideCarry !== '') {
                $this->writeOutside($output, $outsideCarry, $prefixSearch, $prefixReplace, $prefixChanges);
            }
        } finally {
            fclose($input);
            fclose($output);
        }

        if ($prefixSearch !== '' && $prefixSearch !== $prefixReplace) {
            $this->validateOutputOrDiscard($outputPath, $prefixSearch, $prefixReplace);
        }

        $changes = $urlChanges + $prefixChanges + $emailChanges + $imageChanges + $pluginChanges;
        return [
            'changes' => $changes,
            'url_changes' => $urlChanges,
            'prefix_changes' => $prefixChanges,
            'email_changes' => $emailChanges,
            'image_changes' => $imageChanges,
            'plugin_changes' => $pluginChanges,
            'bytes' => $bytes,
            'elapsed' => microtime(true) - $startedAt,
        ];
    }

    private function validateOutputOrDiscard(string $path, string $prefixSearch, string $prefixReplace): void
    {
        try {
            $this->assertNoStaleTablePrefix($path, $prefixSearch, $prefixReplace);
        } catch (\Throwable $e) {
            $discarded = !file_exists($path) || unlink($path);
            $message = rtrim($e->getMessage(), '。') . '。';
            $message .= $discarded
                ? '出力SQLを破棄しました。'
                : '不完全な出力SQLを破棄できませんでした。';
            throw new RuntimeException($message, 0, $e);
        }
    }

    private function assertNoStaleTablePrefix(string $path, string $prefixSearch, string $prefixReplace): void
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('変換後SQLの接頭辞を検証できません。');
        }

        $carry = '';
        $inLiteral = false;
        $escapeNext = false;
        $pendingQuote = false;
        $pattern = '/\b(?:CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?|DROP\s+TABLE(?:\s+IF\s+EXISTS)?|ALTER\s+TABLE|INSERT(?:\s+IGNORE)?\s+INTO|REPLACE\s+INTO|UPDATE|DELETE\s+FROM)\s+(?:(?:`[^`]+`|[A-Za-z0-9_$-]+)\s*\.\s*)?`?([A-Za-z0-9_$-]+)`?(?=[^A-Za-z0-9_$-]|$)/i';
        try {
            while (!feof($handle)) {
                $chunk = fread($handle, 1048576);
                if ($chunk === false) {
                    throw new RuntimeException('変換後SQLの接頭辞検証中に読み込みが失敗しました。');
                }
                if ($chunk === '') {
                    continue;
                }
                $sqlOnly = $this->maskSqlStringLiterals($chunk, $inLiteral, $escapeNext, $pendingQuote);
                $buffer = $carry . $sqlOnly;
                $carryStart = max(0, strlen($buffer) - 4096);
                if (preg_match_all($pattern, $buffer, $matches, PREG_OFFSET_CAPTURE)) {
                    foreach ($matches[1] as $index => [$table, $tableOffset]) {
                        $tableEnd = $tableOffset + strlen($table);
                        if (!feof($handle) && $tableEnd === strlen($buffer)) {
                            // 識別子の途中でチャンクが切れた可能性があるため、次回まで判定を保留する。
                            $carryStart = min($carryStart, $matches[0][$index][1]);
                            continue;
                        }
                        if ($this->startsWith($table, $prefixSearch)
                            && ($prefixReplace === '' || !$this->startsWith($table, $prefixReplace))) {
                            throw new RuntimeException(
                                "テーブル {$table} の接頭辞が変換されていません"
                            );
                        }
                    }
                }
                $carry = substr($buffer, $carryStart);
            }

            // ファイルサイズがチャンク長の整数倍の場合、直前の判定で保留した
            // ファイル末尾の識別子をここで確定する。
            if ($carry !== '' && preg_match_all($pattern, $carry, $matches)) {
                foreach ($matches[1] as $table) {
                    if ($this->startsWith($table, $prefixSearch)
                        && ($prefixReplace === '' || !$this->startsWith($table, $prefixReplace))) {
                        throw new RuntimeException(
                            "テーブル {$table} の接頭辞が変換されていません"
                        );
                    }
                }
            }
        } finally {
            fclose($handle);
        }
    }

    private function maskSqlStringLiterals(
        string $chunk,
        bool &$inLiteral,
        bool &$escapeNext,
        bool &$pendingQuote
    ): string
    {
        $masked = '';
        $length = strlen($chunk);
        $offset = 0;

        if ($pendingQuote) {
            if ($length > 0 && $chunk[0] === "'") {
                $masked .= ' ';
                $offset = 1;
            } else {
                $inLiteral = false;
            }
            $pendingQuote = false;
        }

        while ($offset < $length) {
            if (!$inLiteral) {
                $quote = strpos($chunk, "'", $offset);
                if ($quote === false) {
                    $masked .= substr($chunk, $offset);
                    break;
                }
                $masked .= substr($chunk, $offset, $quote - $offset) . ' ';
                $inLiteral = true;
                $escapeNext = false;
                $offset = $quote + 1;
                continue;
            }

            if ($escapeNext) {
                $masked .= ' ';
                $escapeNext = false;
                $offset++;
                continue;
            }

            $span = strcspn($chunk, "\\'", $offset);
            if ($span > 0) {
                $masked .= str_repeat(' ', $span);
                $offset += $span;
                if ($offset >= $length) {
                    break;
                }
            }

            if ($chunk[$offset] === '\\') {
                $masked .= ' ';
                $offset++;
                if ($offset < $length) {
                    $masked .= ' ';
                    $offset++;
                } else {
                    $escapeNext = true;
                }
                continue;
            }

            $masked .= ' ';
            if ($offset + 1 < $length && $chunk[$offset + 1] === "'") {
                $masked .= ' ';
                $offset += 2;
                continue;
            }
            if ($offset + 1 === $length) {
                $pendingQuote = true;
                $offset++;
                break;
            }

            $inLiteral = false;
            $offset++;
        }

        return $masked;
    }

    private function startsWith(string $value, string $prefix): bool
    {
        return strncmp($value, $prefix, strlen($prefix)) === 0;
    }

    /** @param resource $output */
    private function writeLiteral(
        $output,
        string $raw,
        string $search,
        string $replace,
        string $prefixSearch,
        string $prefixReplace,
        array $emailReplacements,
        array $imagePathReplacements,
        array $literalOverrides,
        int &$urlChanges,
        int &$prefixChanges,
        int &$emailChanges,
        int &$imageChanges,
        int &$pluginChanges
    ): void
    {
        $decoded = $this->sqlUnescape($raw);
        $before = $urlChanges + $prefixChanges + $emailChanges + $imageChanges + $pluginChanges;
        if (array_key_exists($decoded, $literalOverrides) && $literalOverrides[$decoded] !== $decoded) {
            $decoded = $literalOverrides[$decoded];
            $pluginChanges++;
        }
        $converted = $decoded;
        foreach ($emailReplacements as $emailSearch => $emailReplace) {
            $converted = $this->replaceValue($converted, $emailSearch, $emailReplace, $emailChanges);
        }
        foreach ($imagePathReplacements as $pathSearch => $pathReplace) {
            $converted = $this->replaceValue($converted, $pathSearch, $pathReplace, $imageChanges);
        }
        $converted = $this->replaceValue($converted, $search, $replace, $urlChanges);
        if ($prefixSearch !== '') {
            $converted = $this->replaceValue($converted, $prefixSearch, $prefixReplace, $prefixChanges);
        }
        $after = $urlChanges + $prefixChanges + $emailChanges + $imageChanges + $pluginChanges;
        fwrite($output, $after === $before ? $raw : $this->sqlEscape($converted));
    }

    /** @param resource $output */
    private function writeOutside($output, string $value, string $prefixSearch, string $prefixReplace, int &$prefixChanges): void
    {
        if ($value === '' || $prefixSearch === '') {
            fwrite($output, $value);
            return;
        }
        $count = 0;
        $converted = str_replace($prefixSearch, $prefixReplace, $value, $count);
        $prefixChanges += $count;
        fwrite($output, $converted);
    }

    private function replaceValue(string $value, string $search, string $replace, int &$changes): string
    {
        if ($this->isSerialized($value)) {
            return $this->replaceSerialized($value, $search, $replace, $changes);
        }
        $count = 0;
        $result = str_replace($search, $replace, $value, $count);
        $changes += $count;
        return $result;
    }

    private function replaceSerialized(string $serialized, string $search, string $replace, int &$changes): string
    {
        $result = '';
        $length = strlen($serialized);
        $offset = 0;

        while ($offset < $length) {
            $position = strpos($serialized, 's:', $offset);
            if ($position === false) {
                $result .= substr($serialized, $offset);
                break;
            }
            $result .= substr($serialized, $offset, $position - $offset);

            $cursor = $position + 2;
            $digitsStart = $cursor;
            while ($cursor < $length && $serialized[$cursor] >= '0' && $serialized[$cursor] <= '9') {
                $cursor++;
            }
            if ($cursor === $digitsStart || substr($serialized, $cursor, 2) !== ':"') {
                $result .= 's:';
                $offset = $position + 2;
                continue;
            }

            $stringLength = (int) substr($serialized, $digitsStart, $cursor - $digitsStart);
            $dataStart = $cursor + 2;
            $dataEnd = $dataStart + $stringLength;
            if ($dataEnd > $length || substr($serialized, $dataEnd, 2) !== '";') {
                $result .= 's:';
                $offset = $position + 2;
                continue;
            }

            $data = substr($serialized, $dataStart, $stringLength);
            $converted = $this->replaceValue($data, $search, $replace, $changes);
            $result .= 's:' . strlen($converted) . ':"' . $converted . '";';
            $offset = $dataEnd + 2;
        }

        return $result;
    }

    private function isSerialized(string $value): bool
    {
        $value = trim($value);
        if ($value === 'N;') {
            return true;
        }
        if (strlen($value) < 4 || $value[1] !== ':') {
            return false;
        }
        $last = substr($value, -1);
        if ($last !== ';' && $last !== '}') {
            return false;
        }
        switch ($value[0]) {
            case 's': return preg_match('/^s:\d+:".*";$/s', $value) === 1;
            case 'a':
            case 'O':
            case 'C': return preg_match('/^' . $value[0] . ':\d+:/s', $value) === 1;
            case 'b':
            case 'i':
            case 'd': return preg_match('/^' . $value[0] . ':[0-9.E+-]+;$/', $value) === 1;
        }
        return false;
    }

    private function sqlUnescape(string $value): string
    {
        return preg_replace_callback('/\\\\(.)/s', static function (array $match): string {
            $map = ['0' => "\0", 'b' => "\x08", 'n' => "\n", 'r' => "\r", 't' => "\t", 'Z' => "\x1a"];
            return $map[$match[1]] ?? $match[1];
        }, str_replace("''", "'", $value)) ?? $value;
    }

    private function sqlEscape(string $value): string
    {
        return strtr($value, [
            '\\' => '\\\\', "\0" => '\\0', "\x08" => '\\b', "\n" => '\\n',
            "\r" => '\\r', "\t" => '\\t', "\x1a" => '\\Z', "'" => "\\'",
        ]);
    }
}
