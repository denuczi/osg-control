<?php

namespace App\Services\Reports;

use App\Models\SystemSetting;

final class ReportSettings
{
    public const AI_KEY = 'report_ai_conclusion_enabled';

    public function enabled(): bool
    {
        return (int) (SystemSetting::query()->whereKey(self::AI_KEY)->value('value') ?? 0) === 1;
    }

    public function provider(): string
    {
        return (string) config('reports.ai.provider', 'groq');
    }

    public function configured(): bool
    {
        $ai = config('reports.ai');
        $provider = $this->provider();
        return match ($provider) {
            'openai' => filled($ai['openai_key'] ?? null) && filled($ai['model'] ?? null),
            'groq' => filled($ai['groq_key'] ?? null) && filled($ai['model'] ?? null),
            'local' => $this->safeLocalUrl($ai['local_url'] ?? null) && filled($ai['model'] ?? null),
            'compatible' => filled($ai['openai_compatible_key'] ?? null) &&
                $this->safeCompatibleUrl($ai['compatible_url'] ?? null) && filled($ai['model'] ?? null),
            default => false,
        };
    }

    /**
     * Local inference is accepted only on this server's loopback interface.
     * This prevents a deployment setting from silently becoming an SSRF gateway
     * to arbitrary LAN/cloud services. Remote servers use the HTTPS provider.
     */
    public function safeLocalUrl(?string $url): bool
    {
        if (! is_string($url) || strlen($url) > 180) return false;
        $parts = parse_url($url);
        if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'http' ||
            ! in_array($parts['host'] ?? null, ['127.0.0.1', '[::1]'], true) ||
            isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) || isset($parts['query'])) {
            return false;
        }
        $path = rtrim((string) ($parts['path'] ?? ''), '/');
        return $path === '/v1' &&
            (! isset($parts['port']) || ($parts['port'] >= 1 && $parts['port'] <= 65535));
    }

    public function safeCompatibleUrl(?string $url): bool
    {
        if (! is_string($url) || strlen($url) > 250) {
            return false;
        }
        $parts = parse_url($url);
        if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https' ||
            ! isset($parts['host']) || isset($parts['user']) || isset($parts['pass']) ||
            isset($parts['fragment']) || isset($parts['query'])) {
            return false;
        }
        // A custom HTTPS endpoint is a trusted deployment-level setting,
        // not user-supplied. IPs, loopback and local-only names aren't allowed.
        $host = strtolower($parts['host']);
        if (filter_var($host, FILTER_VALIDATE_IP) || ! str_contains($host, '.') ||
            str_ends_with($host, '.local') || str_ends_with($host, '.internal') ||
            in_array($host, ['localhost', 'metadata.google.internal'], true)) {
            return false;
        }
        return true;
    }
}
