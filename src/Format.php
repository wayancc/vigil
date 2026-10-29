<?php

declare(strict_types=1);

namespace Vigil;

final class Format
{
    public static function duration(int $seconds): string
    {
        $seconds = max(0, $seconds);
        if ($seconds < 60) {
            return $seconds . 's';
        }
        $units = ['d' => 86400, 'h' => 3600, 'm' => 60];
        $parts = [];
        foreach ($units as $label => $size) {
            if ($seconds >= $size) {
                $parts[] = intdiv($seconds, $size) . $label;
                $seconds %= $size;
            }
            if (count($parts) === 2) {
                break;
            }
        }

        return implode(' ', $parts);
    }

    public static function ago(int $ts, ?int $now = null): string
    {
        $diff = ($now ?? time()) - $ts;
        if ($diff < 5) {
            return 'just now';
        }

        return self::duration($diff) . ' ago';
    }

    public static function percent(?float $value, int $decimals = 2): string
    {
        if ($value === null) {
            return '—';
        }
        if ($value >= 100) {
            return '100%';
        }

        // Never round 99.996% up to 100%: it would hide real downtime.
        $factor = 10 ** $decimals;

        return number_format(floor($value * $factor) / $factor, $decimals) . '%';
    }

    public static function ms(?int $ms): string
    {
        if ($ms === null) {
            return '—';
        }

        return $ms >= 1000 ? number_format($ms / 1000, 2) . ' s' : $ms . ' ms';
    }

    public static function slug(string $text): string
    {
        $slug = strtolower(trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $text), '-'));

        return $slug !== '' ? $slug : 'monitor';
    }
}
