<?php

namespace App\Support;

use Illuminate\Support\Number;

/**
 * The size an upload field may honestly promise.
 *
 * A validator rule is only half the story. PHP refuses a file larger than
 * `upload_max_filesize` before Laravel ever sees it — the request arrives with
 * an empty $_FILES and the user gets "the field is required" for a file they
 * definitely chose — and `post_max_size` caps the whole request on top of
 * that. So a field advertising the rule alone can promise more than the server
 * can keep: on the box this was written on, upload_max_filesize is 2M while
 * several rules say max:20480.
 *
 * Resolved at render time rather than baked in, because the answer is a
 * property of the deployment. A server with a generous php.ini shows the
 * rule; a stingy one shows what it will really take.
 */
final class UploadLimits
{
    /**
     * The real ceiling for one uploaded file, in bytes.
     *
     * @param  int|null  $ruleKb  the validator's own `max:` in kilobytes, if any
     */
    public static function maxBytes(?int $ruleKb = null): int
    {
        $candidates = array_filter([
            $ruleKb !== null ? $ruleKb * 1024 : null,
            self::iniBytes('upload_max_filesize'),
            self::iniBytes('post_max_size'),
        ], static fn (?int $bytes) => $bytes !== null && $bytes > 0);

        return $candidates === [] ? 0 : (int) min($candidates);
    }

    /** The same ceiling, worded for a chip: "2 MB", "512 KB". */
    public static function maxLabel(?int $ruleKb = null): string
    {
        return Number::fileSize(self::maxBytes($ruleKb), precision: 0);
    }

    /** True when PHP, not the validator, is the binding limit. */
    public static function cappedByPhp(?int $ruleKb): bool
    {
        return $ruleKb !== null && self::maxBytes($ruleKb) < $ruleKb * 1024;
    }

    /**
     * A php.ini size directive in bytes.
     *
     * These are written in shorthand — "2M", "8M", "512K", "1G" — and the
     * suffix is a power of 1024, not 1000. An empty value, 0 or -1 all mean
     * "no limit here", which is null rather than zero so it drops out of the
     * min() above instead of winning it.
     */
    public static function iniBytes(string $directive): ?int
    {
        $raw = trim((string) ini_get($directive));

        if ($raw === '' || $raw === '0' || $raw === '-1') {
            return null;
        }

        $number = (int) $raw;

        return match (strtolower(substr($raw, -1))) {
            'g' => $number * 1024 ** 3,
            'm' => $number * 1024 ** 2,
            'k' => $number * 1024,
            default => $number,
        };
    }
}
