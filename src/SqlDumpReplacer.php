<?php

declare(strict_types=1);

namespace Srdbm;

use RuntimeException;

final class SqlDumpReplacer
{
    /**
     * MySQLダンプ内の文字列リテラルを解析し、シリアライズデータを保ったまま置換する。
     *
     * @return array{changes:int, url_changes:int, prefix_changes:int, bytes:int, elapsed:float}
     */
    public function process(string $inputPath, string $outputPath, string $search, string $replace, string $prefixSearch = '', string $prefixReplace = ''): array
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
                        $this->writeLiteral($output, $literal, $search, $replace, $prefixSearch, $prefixReplace, $urlChanges, $prefixChanges);
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
                                $carryLength = min(max(strlen($prefixSearch) - 1, 0), strlen($outside));
                                $outsideCarry = $carryLength > 0 ? substr($outside, -$carryLength) : '';
                                $outside = $carryLength > 0 ? substr($outside, 0, -$carryLength) : $outside;
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

                    $this->writeLiteral($output, $literal, $search, $replace, $prefixSearch, $prefixReplace, $urlChanges, $prefixChanges);
                    fwrite($output, "'");
                    $literal = '';
                    $inLiteral = false;
                    $offset++;
                }
            }

            if ($pendingQuote) {
                $this->writeLiteral($output, $literal, $search, $replace, $prefixSearch, $prefixReplace, $urlChanges, $prefixChanges);
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

        $changes = $urlChanges + $prefixChanges;
        return ['changes' => $changes, 'url_changes' => $urlChanges, 'prefix_changes' => $prefixChanges, 'bytes' => $bytes, 'elapsed' => microtime(true) - $startedAt];
    }

    /** @param resource $output */
    private function writeLiteral($output, string $raw, string $search, string $replace, string $prefixSearch, string $prefixReplace, int &$urlChanges, int &$prefixChanges): void
    {
        $decoded = $this->sqlUnescape($raw);
        $before = $urlChanges + $prefixChanges;
        $converted = $this->replaceValue($decoded, $search, $replace, $urlChanges);
        if ($prefixSearch !== '') {
            $converted = $this->replaceValue($converted, $prefixSearch, $prefixReplace, $prefixChanges);
        }
        fwrite($output, $urlChanges + $prefixChanges === $before ? $raw : $this->sqlEscape($converted));
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
