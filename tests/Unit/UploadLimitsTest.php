<?php

namespace Tests\Unit;

use App\Support\UploadLimits;
use PHPUnit\Framework\TestCase;

/**
 * What a size chip is allowed to promise.
 *
 * The point of this class is that a validator rule is not the whole answer.
 * PHP drops an oversized upload before Laravel is called at all — the request
 * arrives with an empty $_FILES and the user is told the field is required for
 * a file they definitely chose — so a field advertising `max:20480` on a box
 * where upload_max_filesize is 2M is lying, and the lie is the confusing kind.
 */
class UploadLimitsTest extends TestCase
{
    public function test_it_reads_php_shorthand_as_powers_of_1024(): void
    {
        // "2M" is 2 MiB, not 2,000,000 — a detail worth pinning because
        // getting it wrong makes the chip almost right, which is worse.
        $this->assertSame(2 * 1024 ** 2, $this->iniBytesOf('2M'));
        $this->assertSame(512 * 1024, $this->iniBytesOf('512K'));
        $this->assertSame(1024 ** 3, $this->iniBytesOf('1G'));
        $this->assertSame(1234, $this->iniBytesOf('1234'));
    }

    public function test_no_limit_reads_as_null_so_it_does_not_win_the_minimum(): void
    {
        // 0 and -1 both mean "unlimited". Returning 0 would make min() pick it
        // and every chip would read "0 B".
        $this->assertNull($this->iniBytesOf('0'));
        $this->assertNull($this->iniBytesOf('-1'));
        $this->assertNull($this->iniBytesOf(''));
    }

    public function test_the_ceiling_is_the_smallest_of_the_rule_and_php(): void
    {
        $upload = UploadLimits::iniBytes('upload_max_filesize');
        $this->assertNotNull($upload, 'this environment has no upload_max_filesize to test against');

        // A rule far above PHP's limit is clamped to PHP's limit …
        $huge = (int) ($upload / 1024) * 100;
        $this->assertSame(min($upload, UploadLimits::iniBytes('post_max_size') ?? PHP_INT_MAX), UploadLimits::maxBytes($huge));
        $this->assertTrue(UploadLimits::cappedByPhp($huge));

        // … and a rule below it is left alone.
        $small = 64;
        $this->assertSame(64 * 1024, UploadLimits::maxBytes($small));
        $this->assertFalse(UploadLimits::cappedByPhp($small));
    }

    public function test_no_rule_still_yields_phps_own_ceiling(): void
    {
        // A field with no max: of its own is still bounded by the server.
        $this->assertGreaterThan(0, UploadLimits::maxBytes(null));
    }

    public function test_the_label_is_worded_for_a_chip(): void
    {
        $this->assertSame('64 KB', UploadLimits::maxLabel(64));
    }

    /** ini_get() reads real php.ini, so shorthand parsing is tested through a stub directive. */
    private function iniBytesOf(string $value): ?int
    {
        // `precision` is a harmless directive to borrow: it is settable at
        // runtime and nothing here reads it for its real purpose.
        $original = ini_get('precision');
        ini_set('precision', $value);

        try {
            return UploadLimits::iniBytes('precision');
        } finally {
            ini_set('precision', $original);
        }
    }
}
