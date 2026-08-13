<?php

declare(strict_types=1);

namespace Srdbm;

use InvalidArgumentException;

final class DomainNormalizer
{
    /** @var list<string> */
    private $sourceHosts;

    /** @var string */
    private $targetOrigin;

    /** @var string */
    private $sourceEmailHost;

    /** @var string */
    private $targetHost;

    public function __construct(string $sourceOrigin, string $targetOrigin)
    {
        $source = $this->urlParts($sourceOrigin);
        $target = $this->urlParts($targetOrigin);
        if ($source === null || $target === null) {
            throw new InvalidArgumentException('ドメイン置換URLの形式が正しくありません。');
        }

        $alternateSource = $this->startsWith($source['host'], 'www.')
            ? substr($source['host'], 4)
            : 'www.' . $source['host'];
        $hosts = array_values(array_unique([$source['host'], $alternateSource]));
        usort($hosts, static function (string $left, string $right): int {
            return strlen($right) <=> strlen($left);
        });

        $this->sourceHosts = $hosts;
        $this->sourceEmailHost = $source['host'];
        $this->targetOrigin = $target['origin'];
        $this->targetHost = $target['host'];
    }

    /** @return array{value:string,replacements:int,kinds:array{url:int,host:int,email:int}} */
    public function transform(string $value): array
    {
        $count = 0;
        $kinds = $this->emptyKinds();
        $converted = $this->replaceValue($value, $count, $kinds);
        return ['value' => $converted, 'replacements' => $count, 'kinds' => $kinds];
    }

    /** @param array{url:int,host:int,email:int} $kinds */
    private function replaceValue(string $value, int &$count, array &$kinds): string
    {
        if ($this->isSerialized($value)) {
            return $this->replaceSerialized($value, $count, $kinds);
        }
        return $this->replacePlain($value, $count, $kinds);
    }

    /** @param array{url:int,host:int,email:int} $kinds */
    private function replaceSerialized(string $serialized, int &$count, array &$kinds): string
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
            $converted = $this->replaceValue($data, $count, $kinds);
            $result .= 's:' . strlen($converted) . ':"' . $converted . '";';
            $offset = $dataEnd + 2;
        }

        return $result;
    }

    /** @param array{url:int,host:int,email:int} $kinds */
    private function replacePlain(string $value, int &$count, array &$kinds): string
    {
        foreach ($this->sourceHosts as $host) {
            $quotedHost = preg_quote($host, '~');
            $hostBoundary = '(?![A-Za-z0-9.:-])';

            $value = preg_replace_callback(
                '~(?:(?:https?:)?\\\\/\\\\/)' . $quotedHost . $hostBoundary . '~i',
                function () use (&$count, &$kinds): string {
                    $count++;
                    $kinds['url']++;
                    return str_replace('/', '\\/', $this->targetOrigin);
                },
                $value
            ) ?? $value;

            $value = preg_replace_callback(
                '~(?:(?:https?:)?//)' . $quotedHost . $hostBoundary . '~i',
                function () use (&$count, &$kinds): string {
                    $count++;
                    $kinds['url']++;
                    return $this->targetOrigin;
                },
                $value
            ) ?? $value;

            $value = preg_replace_callback(
                '~(?:(?:https?%3A)?%2F%2F)' . $quotedHost . $hostBoundary . '~i',
                function () use (&$count, &$kinds): string {
                    $count++;
                    $kinds['url']++;
                    return str_replace([':', '/'], ['%3A', '%2F'], $this->targetOrigin);
                },
                $value
            ) ?? $value;

            $value = preg_replace_callback(
                '~(?:(?:https?:)?(?:\\\\u002[fF]){2})' . $quotedHost . $hostBoundary . '~i',
                function () use (&$count, &$kinds): string {
                    $count++;
                    $kinds['url']++;
                    return 'https:\\u002F\\u002F' . $this->targetHost;
                },
                $value
            ) ?? $value;

            if (strcasecmp($host, $this->sourceEmailHost) === 0) {
                $value = preg_replace_callback(
                    '~(?<![A-Za-z0-9_+.-])([A-Za-z0-9_+-]+(?:\.[A-Za-z0-9_+-]+)*)@' . $quotedHost . $hostBoundary . '~i',
                    function (array $match) use (&$count, &$kinds): string {
                        $count++;
                        $kinds['email']++;
                        return $match[1] . '@' . $this->targetHost;
                    },
                    $value
                ) ?? $value;
            }

            $value = preg_replace_callback(
                '~(?<![A-Za-z0-9.@_-])' . $quotedHost . $hostBoundary . '~i',
                function () use (&$count, &$kinds): string {
                    $count++;
                    $kinds['host']++;
                    return $this->targetHost;
                },
                $value
            ) ?? $value;
        }

        return $value;
    }

    /** @return array{origin:string,host:string}|null */
    private function urlParts(string $url): ?array
    {
        $parts = parse_url(trim($url));
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])
            || !in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true)) {
            return null;
        }
        $scheme = strtolower((string) $parts['scheme']);
        $host = strtolower((string) $parts['host']);
        if (isset($parts['port'])) {
            $host .= ':' . (int) $parts['port'];
        }
        return ['origin' => $scheme . '://' . $host, 'host' => $host];
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

    /** @return array{url:int,host:int,email:int} */
    private function emptyKinds(): array
    {
        return ['url' => 0, 'host' => 0, 'email' => 0];
    }

    private function startsWith(string $value, string $prefix): bool
    {
        return strncmp($value, $prefix, strlen($prefix)) === 0;
    }
}
