<?php

declare(strict_types=1);

namespace App\Twig;

use Twig\Attribute\AsTwigFunction;
use Twig\Attribute\AsTwigFilter;

class AppExtension
{
    #[AsTwigFunction('format_bytes')]
    public function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));

        return round($bytes, $precision) . ' ' . $units[$pow];
    }

    #[AsTwigFilter('sum')]
    public function sum(array $arr): float|int
    {
        return array_sum($arr);
    }
}
