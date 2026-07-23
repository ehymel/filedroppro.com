<?php

declare(strict_types=1);

namespace App\Twig;

use Twig\Attribute\AsTwigFunction;
use Twig\Attribute\AsTwigFilter;

class AppExtension
{
    #[AsTwigFunction('format_bytes')]
    public function formatBytes(int|string $bytes, int $precision = 2): string
    {
        if (empty($bytes)) return 'None';

        if (!is_int($bytes)) {
            return $bytes;
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max((int) $bytes, 0);
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

    #[AsTwigFilter('truncate_filename')]
    public function truncateFilename(string $text, int $length, string $append = ' ... .'): string
    {
        return mb_strimwidth($text, 0, $length, $append, 'UTF-8') . pathinfo($text, PATHINFO_EXTENSION);
    }
}
