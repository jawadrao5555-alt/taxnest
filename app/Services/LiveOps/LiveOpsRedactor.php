<?php

namespace App\Services\LiveOps;

/**
 * Strip secrets / credentials / excessive PII from Live Ops payloads.
 */
class LiveOpsRedactor
{
    private const SECRET_KEY_PATTERN = '/(password|passwd|secret|token|api[_-]?key|authorization|cookie|private[_-]?key|bearer|credential)/i';

    public function redact(mixed $value, string $keyHint = ''): mixed
    {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $key = (string) $k;
                if (preg_match(self::SECRET_KEY_PATTERN, $key)) {
                    $out[$k] = '[REDACTED]';
                    continue;
                }
                $out[$k] = $this->redact($v, $key);
            }

            return $out;
        }

        if (is_string($value)) {
            return $this->redactString($value, $keyHint);
        }

        return $value;
    }

    public function redactString(string $value, string $keyHint = ''): string
    {
        if (preg_match(self::SECRET_KEY_PATTERN, $keyHint)) {
            return '[REDACTED]';
        }

        $value = preg_replace('/Bearer\s+[A-Za-z0-9\-._~+\/=]+/i', 'Bearer [REDACTED]', $value) ?? $value;
        $value = preg_replace('/(api[_-]?key|token|password)\s*[:=]\s*\S+/i', '$1=[REDACTED]', $value) ?? $value;
        $value = preg_replace('/-----BEGIN [A-Z ]*PRIVATE KEY-----[\s\S]*?-----END [A-Z ]*PRIVATE KEY-----/', '[REDACTED_PRIVATE_KEY]', $value) ?? $value;

        $max = (int) config('live_ops.limits.max_log_chars_per_line', 400);
        if (mb_strlen($value) > $max) {
            return mb_substr($value, 0, $max) . '…';
        }

        return $value;
    }

    /** Hash params for audit without storing raw secrets. */
    public function paramsHash(array $params): string
    {
        $safe = $this->redact($params);

        return hash('sha256', json_encode($safe, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public function artifactDigest(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
