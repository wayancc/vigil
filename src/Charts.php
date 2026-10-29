<?php

declare(strict_types=1);

namespace Vigil;

/**
 * Server-rendered SVG: sparklines, response-time charts and README badges. No chart library.
 */
final class Charts
{
    /** Small response-time line for table rows. Failed checks show as red ticks on the baseline. */
    public static function sparkline(array $checks, int $width = 120, int $height = 28): string
    {
        if (count($checks) < 2) {
            return '<svg class="wyn-spark" width="' . $width . '" height="' . $height . '" aria-hidden="true"></svg>';
        }

        $max = max(1, ...array_map(fn ($c) => (int) $c['response_ms'], $checks));
        $n = count($checks);
        $step = $width / ($n - 1);
        $points = [];
        $fails = '';
        foreach (array_values($checks) as $i => $c) {
            $x = round($i * $step, 1);
            if (!$c['ok']) {
                $fails .= '<line x1="' . $x . '" x2="' . $x . '" y1="' . ($height - 8) . '" y2="' . $height . '" class="wyn-spark-fail"/>';
            }
            $y = round($height - 3 - ((int) $c['response_ms'] / $max) * ($height - 8), 1);
            $points[] = $x . ',' . $y;
        }

        return '<svg class="wyn-spark" viewBox="0 0 ' . $width . ' ' . $height . '" width="' . $width . '" height="' . $height . '" aria-hidden="true">'
            . '<polyline points="' . implode(' ', $points) . '" fill="none" class="wyn-spark-line"/>' . $fails . '</svg>';
    }

    /** Response-time area chart with a time axis, for the monitor detail page. */
    public static function responseChart(array $checks, int $from, int $to, int $width = 760, int $height = 200): string
    {
        $padL = 46;
        $padB = 22;
        $padT = 10;
        $w = $width - $padL - 8;
        $h = $height - $padB - $padT;

        $ok = array_filter($checks, fn ($c) => $c['ok'] && $c['response_ms'] !== null);
        $max = $ok ? max(array_map(fn ($c) => (int) $c['response_ms'], $ok)) : 100;
        $max = self::niceCeil(max(50, (int) ($max * 1.15)));
        $span = max(1, $to - $from);
        $x = fn (int $t) => round($padL + ($t - $from) / $span * $w, 1);
        $y = fn (int $ms) => round($padT + $h - $ms / $max * $h, 1);

        $svg = '<svg class="wyn-chart" viewBox="0 0 ' . $width . ' ' . $height . '" role="img" aria-label="Response time chart">';

        // Grid + y labels
        for ($i = 0; $i <= 4; $i++) {
            $val = (int) ($max * $i / 4);
            $yy = $y($val);
            $svg .= '<line x1="' . $padL . '" x2="' . ($padL + $w) . '" y1="' . $yy . '" y2="' . $yy . '" class="wyn-grid"/>';
            $svg .= '<text x="' . ($padL - 8) . '" y="' . ($yy + 4) . '" class="wyn-axis" text-anchor="end">' . ($val >= 1000 ? round($val / 1000, 1) . 's' : $val . 'ms') . '</text>';
        }
        // X labels (4 ticks)
        for ($i = 0; $i <= 4; $i++) {
            $t = $from + (int) ($span * $i / 4);
            $anchor = $i === 0 ? 'start' : ($i === 4 ? 'end' : 'middle');
            $svg .= '<text x="' . $x($t) . '" y="' . ($height - 4) . '" class="wyn-axis" text-anchor="' . $anchor . '">' . date($span > 172800 ? 'M j' : 'H:i', $t) . '</text>';
        }

        // Downtime bands
        foreach ($checks as $c) {
            if (!$c['ok']) {
                $svg .= '<rect x="' . ($x((int) $c['checked_at']) - 1.5) . '" y="' . $padT . '" width="3" height="' . $h . '" class="wyn-down-band"/>';
            }
        }

        // Line + area, broken at gaps longer than 3× the typical spacing
        $segments = [];
        $current = [];
        $prev = null;
        $times = array_map(fn ($c) => (int) $c['checked_at'], array_values($ok));
        $gaps = [];
        for ($i = 1; $i < count($times); $i++) {
            $gaps[] = $times[$i] - $times[$i - 1];
        }
        sort($gaps);
        $typical = $gaps ? $gaps[intdiv(count($gaps), 2)] : 60;
        foreach ($ok as $c) {
            $t = (int) $c['checked_at'];
            if ($prev !== null && $t - $prev > max(180, 3 * $typical)) {
                $segments[] = $current;
                $current = [];
            }
            $current[] = [$x($t), $y((int) $c['response_ms'])];
            $prev = $t;
        }
        $segments[] = $current;

        $base = $padT + $h;
        foreach ($segments as $seg) {
            if (count($seg) < 2) {
                continue;
            }
            $line = implode(' ', array_map(fn ($p) => $p[0] . ',' . $p[1], $seg));
            $svg .= '<polygon class="wyn-area" points="' . $seg[0][0] . ',' . $base . ' ' . $line . ' ' . end($seg)[0] . ',' . $base . '"/>';
            $svg .= '<polyline class="wyn-line" points="' . $line . '"/>';
        }

        if ($ok === []) {
            $svg .= '<text x="' . ($padL + $w / 2) . '" y="' . ($padT + $h / 2) . '" class="wyn-empty" text-anchor="middle">No successful checks in this period</text>';
        }

        return $svg . '</svg>';
    }

    /** shields.io-style badge. */
    public static function badge(string $label, string $value, string $color): string
    {
        $lw = self::textWidth($label) + 12;
        $vw = self::textWidth($value) + 12;
        $w = $lw + $vw;
        $e = fn (string $s) => htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');

        return <<<SVG
            <svg xmlns="http://www.w3.org/2000/svg" width="{$w}" height="20" role="img" aria-label="{$e($label)}: {$e($value)}">
              <title>{$e($label)}: {$e($value)}</title>
              <linearGradient id="s" x2="0" y2="100%"><stop offset="0" stop-color="#bbb" stop-opacity=".1"/><stop offset="1" stop-opacity=".1"/></linearGradient>
              <clipPath id="r"><rect width="{$w}" height="20" rx="3" fill="#fff"/></clipPath>
              <g clip-path="url(#r)"><rect width="{$lw}" height="20" fill="#555"/><rect x="{$lw}" width="{$vw}" height="20" fill="{$color}"/><rect width="{$w}" height="20" fill="url(#s)"/></g>
              <g fill="#fff" text-anchor="middle" font-family="Verdana,Geneva,DejaVu Sans,sans-serif" font-size="11">
                <text x="{$e((string) ($lw / 2))}" y="15" fill="#010101" fill-opacity=".3">{$e($label)}</text><text x="{$e((string) ($lw / 2))}" y="14">{$e($label)}</text>
                <text x="{$e((string) ($lw + $vw / 2))}" y="15" fill="#010101" fill-opacity=".3">{$e($value)}</text><text x="{$e((string) ($lw + $vw / 2))}" y="14">{$e($value)}</text>
              </g>
            </svg>
            SVG;
    }

    private static function textWidth(string $text): int
    {
        // Approximate Verdana 11px widths: good enough for short badge text.
        $width = 0.0;
        foreach (mb_str_split($text) as $ch) {
            $width += match (true) {
                str_contains('il.:|!\'', $ch) => 3.5,
                str_contains('mwMW%', $ch)    => 10.5,
                ctype_upper($ch)              => 8.0,
                ctype_digit($ch)              => 7.0,
                $ch === ' '                   => 3.8,
                default                       => 6.6,
            };
        }

        return (int) ceil($width);
    }

    private static function niceCeil(int $value): int
    {
        $magnitude = 10 ** max(0, strlen((string) $value) - 1);

        return (int) (ceil($value / $magnitude) * $magnitude);
    }
}
