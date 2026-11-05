<?php

namespace App\Domain\AI;

use App\Models\SystemSetting;
use RuntimeException;

/**
 * Per-request budget and aggregate, privacy-preserving usage counters.
 * Remote APIs and local inference have independent, explainable defaults.
 */
final class AssistantRuntime
{
    public const FIELDS = [
        'total_timeout_seconds' => [10, 300],
        'provider_timeout_seconds' => [3, 120],
        'max_provider_calls' => [1, 6],
        'max_output_tokens' => [256, 8192],
    ];

    private ?array $settings = null;
    private ?float $started = null;
    private int $calls = 0;
    private ?int $inputTokens = null;
    private ?int $outputTokens = null;

    public function mode(): string
    {
        return config('reports.ai.provider') === 'local' ? 'local' : 'remote';
    }

    /** @return array<string,int> */
    public function settings(): array
    {
        if ($this->settings !== null) return $this->settings;
        $defaults = $this->mode() === 'local'
            ? ['total_timeout_seconds' => 120, 'provider_timeout_seconds' => 45, 'max_provider_calls' => 4, 'max_output_tokens' => 2048]
            : ['total_timeout_seconds' => 30, 'provider_timeout_seconds' => 9, 'max_provider_calls' => 4, 'max_output_tokens' => 2048];
        $prefix = $this->mode() === 'local' ? 'assistant_local_' : 'assistant_remote_';
        $stored = SystemSetting::query()->whereIn('key', array_map(static fn (string $key): string => $prefix.$key, array_keys($defaults)))
            ->pluck('value', 'key')->all();
        foreach ($defaults as $key => $value) {
            $raw = $stored[$prefix.$key] ?? $value;
            [$min, $max] = self::FIELDS[$key];
            $defaults[$key] = max($min, min($max, (int) $raw));
        }
        // A per-call timeout cannot be greater than the complete request budget.
        $defaults['provider_timeout_seconds'] = min($defaults['provider_timeout_seconds'], $defaults['total_timeout_seconds']);
        return $this->settings = $defaults;
    }

    public function begin(): void
    {
        $this->started ??= microtime(true);
    }

    /** Reserve an external model call, returning its maximum HTTP timeout. */
    public function reserveCall(): float
    {
        $this->begin();
        $settings = $this->settings();
        $remaining = $settings['total_timeout_seconds'] - (microtime(true) - $this->started);
        if ($remaining < 1.0) throw new RuntimeException('assistant_total_time_budget_exceeded');
        if ($this->calls >= $settings['max_provider_calls']) throw new RuntimeException('assistant_provider_call_budget_exceeded');
        $this->calls++;
        return min((float) $settings['provider_timeout_seconds'], $remaining);
    }

    public function recordUsage(mixed $usage): void
    {
        if (! is_array($usage)) return;
        foreach (['prompt_tokens' => 'input', 'completion_tokens' => 'output'] as $source => $type) {
            $value = $usage[$source] ?? null;
            if (! is_int($value) || $value < 0 || $value > 10000000) continue;
            if ($type === 'input') $this->inputTokens = ($this->inputTokens ?? 0) + $value;
            else $this->outputTokens = ($this->outputTokens ?? 0) + $value;
        }
    }

    /** @return array<string,int|null> */
    public function stats(): array
    {
        return [
            'duration_ms' => $this->started === null ? 0 : (int) round((microtime(true) - $this->started) * 1000),
            'model_requests' => $this->calls,
            'input_tokens_reported' => $this->inputTokens,
            'output_tokens_reported' => $this->outputTokens,
        ];
    }
}
