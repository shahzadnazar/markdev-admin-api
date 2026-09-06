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

    /**
     * Open the academy seven days a week for this test.
     *
     * The real default is Mon–Fri, which is what stops a student on no slot
     * collecting an absence every Saturday. Tests written before that existed
     * measure something else — leave mechanics, fines, the close — with
     * relative dates like `today()` and `today()->addDays(3)`, and a weekend
     * landing inside one of those ranges would change what they measure rather
     * than test the week. They say so here instead of drifting.
     *
     * Anything actually about which days count sets its own week; see
     * AcademyCalendarTest and LeaveWorkingDaysTest.
     */
    protected function academyOpensEveryDay(): void
    {
        Setting::updateOrCreate(
            ['key' => 'academy_working_days'],
            ['value' => array_keys(\App\Models\AttendanceSlot::DAYS), 'group' => 'general'],
        );

        Setting::forgetCached();
    }
}
