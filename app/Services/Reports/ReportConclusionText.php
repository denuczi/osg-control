<?php

namespace App\Services\Reports;

use RuntimeException;

/** Normalizes AI prose for the short, two-paragraph report conclusion. */
final class ReportConclusionText
{
    private const MAX_CHARACTERS = [
        'conclusion' => 850,
        'recomendaciones' => 950,
    ];

    public function format(array $content): string
    {
        $paragraphs = [];

        foreach (self::MAX_CHARACTERS as $field => $limit) {
            if (! is_string($content[$field] ?? null)) {
                throw new RuntimeException('ai_invalid_schema');
            }

            $text = trim(preg_replace('/\s+/u', ' ', strip_tags($content[$field])) ?? '');
            if (mb_strlen($text) < 15) {
                throw new RuntimeException('ai_invalid_length');
            }

            $paragraphs[] = $this->shorten($text, $limit);
        }

        return implode("\n\n", $paragraphs);
    }

    /** Prefer complete sentences; handle unusually long, unpunctuated text safely. */
    private function shorten(string $text, int $limit): string
    {
        if (mb_strlen($text) <= $limit) {
            return $text;
        }

        $sentences = preg_split('/(?<=[.!?])\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $shortened = '';

        foreach ($sentences as $sentence) {
            $candidate = $shortened === '' ? $sentence : $shortened.' '.$sentence;
            if (mb_strlen($candidate) > $limit) {
                break;
            }
            $shortened = $candidate;
        }

        if (mb_strlen($shortened) >= min(180, (int) ($limit * 0.35))) {
            return $shortened;
        }

        $fragment = mb_substr($text, 0, $limit - 1);
        $wordBoundary = mb_strrpos($fragment, ' ');
        if ($wordBoundary !== false && $wordBoundary > (int) ($limit * 0.75)) {
            $fragment = mb_substr($fragment, 0, $wordBoundary);
        }

        return rtrim($fragment, " \t\n\r\0\x0B,;:").'…';
    }
}
