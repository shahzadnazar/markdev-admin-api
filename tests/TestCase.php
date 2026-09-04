<?php

namespace Tests;

use App\Models\Setting;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Settings are memoised per process, and the whole suite is one process.
     *
     * Without this a test that switches the marking mode or an allowance
     * leaves it switched for everything after it: the database is rolled back
     * but the memo is not, so a later test reads a value that no longer exists
     * anywhere. Found while diagnosing the biometric register — a manual-mode
     * test passed alone and failed in the suite.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Setting::forgetCached();
    }
}
