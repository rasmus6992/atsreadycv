<?php
declare(strict_types=1);

namespace CvTailor\Support;

final class Text
{
    public static function characterCount(string $value): int
    {
        return function_exists('mb_strlen')
            ? mb_strlen($value, 'UTF-8')
            : strlen($value);
    }

    public static function stripOuterMarkdownFence(string $value): string
    {
        $value = trim($value);
        $value = preg_replace('/^```(?:markdown|md)?\s*/i', '', $value) ?? $value;
        $value = preg_replace('/\s*```$/', '', $value) ?? $value;

        return trim($value);
    }
}
