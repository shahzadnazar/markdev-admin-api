<?php

namespace Tests\Feature\Console;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\PendingCommand;
use Spatie\Backup\Config\Config as SpatieBackupConfig;
use Spatie\Backup\Tasks\Backup\DbDumperFactory;
use Tests\TestCase;

/**
 * The nightly backup, as configured rather than as hoped.
 *
 * Three faults shipped here and each one is silent from cron: no
 * dump_binary_path so mysqldump is called bare, a placeholder recipient so every
 * failure notice went to a stranger, and a single local disk so the backup sat
 * on the disk it exists to protect. All three look identical to a working
 * backup right up to the moment somebody needs a restore.
 */
class BackupConfigurationTest extends TestCase
{
    /**
     * config/backup.php with the environment of this test, freshly evaluated.
     *
     * The application's config was built at boot, so the only way to test what
     * an env variable does is to run the file again with it set. `require` is
     * not `require_once`; it re-evaluates, and env() reads $_SERVER live.
     *
     * @param  array<string, string|null>  $env
     * @return array<string, mixed>
     */
    protected function configWith(array $env): array
    {
        $restore = [];

        foreach ($env as $key => $value) {
            $restore[$key] = $_SERVER[$key] ?? null;

            if ($value === null) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $value;
            }
        }

        try {
            return require config_path('backup.php');
        } finally {
            foreach ($restore as $key => $value) {
                if ($value === null) {
                    unset($_SERVER[$key]);
                } else {
                    $_SERVER[$key] = $value;
                }
            }
        }
    }

    /* ---------------------------- (a) mysqldump ----------------------------- */

    /**
     * The dump path reaches the mysqldump command line.
     *
     * Asserted on the rendered command rather than on the config value, because
     * the config value being present proves nothing: spatie maps a `dump` key to
     * a setter by name, and a key it does not recognise is dropped in silence.
     */
    public function test_the_dump_binary_path_reaches_the_mysqldump_command(): void
    {
        $this->assertArrayHasKey(
            'dump_binary_path',
            config('database.connections.mysql.dump', []),
            'Without this the backup calls a bare mysqldump and dies on any host that has not got one on PATH.',
        );

        config(['database.connections.mysql.dump.dump_binary_path' => '/opt/alt/mysql80/usr/bin']);

        $command = DbDumperFactory::createFromConnection('mysql')
            ->getDumpCommand('dump.sql', 'credentials.cnf');

        $this->assertStringContainsString('/opt/alt/mysql80/usr/bin/mysqldump', $command);
    }

    /** Empty is the old behaviour, so a machine with mysqldump on PATH is unaffected. */
    public function test_an_empty_dump_path_still_calls_mysqldump(): void
    {
        config(['database.connections.mysql.dump.dump_binary_path' => '']);

        $command = DbDumperFactory::createFromConnection('mysql')
            ->getDumpCommand('dump.sql', 'credentials.cnf');

        $this->assertStringContainsString('mysqldump', $command);
        $this->assertStringNotContainsString('/mysqldump', $command);
    }

    /* --------------------------- (b) the recipient -------------------------- */

    public function test_the_failure_notice_goes_where_the_environment_says(): void
    {
        $config = $this->configWith(['BACKUP_NOTIFICATION_EMAIL' => 'ops@markdev.test']);

        $this->assertSame('ops@markdev.test', $config['notifications']['mail']['to']);
    }

    /**
     * Unset, the recipient is loudly broken and takes nothing down with it.
     *
     * Three separate requirements, and the middle one is why this is not simply
     * `@invalid`: spatie validates the address when the config is READ, so an
     * address filter_var rejects throws before the backup starts. That would
     * mean no notification AND no backup, which is worse than the fault being
     * fixed. The address has to be undeliverable but well-formed.
     */
    public function test_an_unset_recipient_is_undeliverable_and_says_so(): void
    {
        $to = $this->configWith(['BACKUP_NOTIFICATION_EMAIL' => null])['notifications']['mail']['to'];

        $this->assertStringEndsWith('.invalid', $to, 'RFC 2606 reserves .invalid so that it can never resolve.');
        $this->assertStringContainsString('not-set', $to, 'The log line has to say what is wrong, and the address is the log line.');
        $this->assertNotFalse(filter_var($to, FILTER_VALIDATE_EMAIL), 'A malformed address would abort backup:run itself.');
    }

    /** The same requirement, stated the way spatie enforces it. */
    public function test_an_unset_recipient_does_not_abort_the_backup(): void
    {
        $config = $this->configWith(['BACKUP_NOTIFICATION_EMAIL' => null]);

        SpatieBackupConfig::fromArray($config);

        $this->assertTrue(true, 'Reading the config threw nothing, so backup:run still starts.');
    }

    public function test_nobody_elses_inbox_is_in_the_repository(): void
    {
        $this->assertNotSame(
            'your@example.com',
            $this->configWith(['BACKUP_NOTIFICATION_EMAIL' => null])['notifications']['mail']['to'],
            'That is a real domain belonging to a stranger, and it was receiving every failure notice.',
        );
    }

    /* --------------------------- (c) the second disk ------------------------ */

    public function test_local_is_the_only_destination_until_one_is_named(): void
    {
        $config = $this->configWith(['BACKUP_OFFSITE_DISK' => null]);

        $this->assertSame(['local'], $config['backup']['destination']['disks']);
    }

    public function test_naming_a_disk_is_all_it_takes_to_go_off_server(): void
    {
        $config = $this->configWith(['BACKUP_OFFSITE_DISK' => 's3']);

        $this->assertSame(['local', 's3'], $config['backup']['destination']['disks']);
        $this->assertSame('local', $config['backup']['destination']['disks'][0], 'Local stays first: it is the fast one.');
    }

    /** Naming `local` twice must not mean writing it twice. */
    public function test_naming_local_as_the_offsite_disk_changes_nothing(): void
    {
        $config = $this->configWith(['BACKUP_OFFSITE_DISK' => 'local']);

        $this->assertSame(['local'], $config['backup']['destination']['disks']);
    }

    /**
     * AN UNCONFIGURED DESTINATION DEGRADES TO LOCAL.
     *
     * The behavioural one. A disk name with no driver behind it stands in for
     * every way the off-server half can be wrong — an empty bucket, expired
     * credentials, a host that has stopped answering. The nightly job still has
     * to finish and the local copy still has to be written, because otherwise
     * adding an off-server destination is strictly more dangerous than not
     * having one.
     */
    public function test_a_broken_offsite_disk_still_leaves_a_local_backup(): void
    {
        $this->assertTrue(config('backup.backup.destination.continue_on_failure'));

        $this->backupOnly(['local', 'no-such-disk-exists'])->assertExitCode(0);

        $this->assertCount(1, Storage::disk('local')->allFiles(), 'The local copy is the whole point of degrading.');
    }

    /**
     * The same run without the flag, to show which line does the work.
     *
     * The local copy survives here only because local is listed first and had
     * already been written when the broken disk threw. The job still reports
     * failure, which from cron is a backup that says it did not happen.
     */
    public function test_without_continue_on_failure_the_broken_disk_fails_the_whole_job(): void
    {
        config(['backup.backup.destination.continue_on_failure' => false]);

        $this->backupOnly(['local', 'no-such-disk-exists'])->assertFailed();
    }

    /**
     * And the loss that does not depend on the order they happen to be listed in.
     *
     * spatie walks the destinations in order and, without the flag, the first
     * failure throws out of the whole loop — so a broken off-server disk ahead of
     * local means no local copy at all. This test states it with the order
     * reversed because the config's own order must not be the only thing standing
     * between a misconfigured bucket and a night with no backup.
     */
    public function test_a_broken_disk_listed_first_would_lose_the_local_copy_entirely(): void
    {
        config(['backup.backup.destination.continue_on_failure' => false]);

        $this->backupOnly(['no-such-disk-exists', 'local'])->assertFailed();

        $this->assertCount(0, Storage::disk('local')->allFiles(), 'This is the nightly backup that would have been lost.');
    }

    /** With the flag, that same order still leaves the local copy. */
    public function test_with_continue_on_failure_the_order_no_longer_matters(): void
    {
        $this->backupOnly(['no-such-disk-exists', 'local'])->assertExitCode(0);

        $this->assertCount(1, Storage::disk('local')->allFiles());
    }

    /** Degrading is not the same as never failing. */
    public function test_a_backup_that_reaches_no_destination_at_all_still_fails(): void
    {
        $this->backupOnly(['no-such-disk-exists', 'nor-this-one'])->assertFailed();
    }

    /* ------------------------- What is in the archive ----------------------- */

    /**
     * The uploads ARE in the backup, and this pins it.
     *
     * Worth an assertion rather than a reading of the config, because the answer
     * is not obvious and it is one edit away from changing: the include list is
     * base_path(), the exclude list names vendor, node_modules and
     * storage/framework, and the private disk lives at storage/app/private —
     * inside the one and outside the others. Add storage_path() to the excludes
     * and student photographs, lesson resources and team files stop being
     * restorable, with nothing failing to say so.
     */
    public function test_the_uploaded_files_on_the_private_disk_are_in_the_backup(): void
    {
        $uploads = config('filesystems.disks.local.root');
        $include = config('backup.backup.source.files.include');
        $exclude = config('backup.backup.source.files.exclude');

        $this->assertTrue(
            collect($include)->contains(fn ($path) => Str::startsWith($uploads, $path)),
            $uploads.' is not inside anything the backup includes.',
        );

        foreach ($exclude as $excluded) {
            $this->assertFalse(
                Str::startsWith($uploads, $excluded),
                'The uploads are excluded by '.$excluded.', so they are not restorable.',
            );
        }
    }

    /** The database is in there too, by connection name rather than by guess. */
    public function test_the_database_is_in_the_backup(): void
    {
        $this->assertContains(
            config('database.default'),
            config('backup.backup.source.databases'),
        );
    }

    /* ------------------------------- Helpers -------------------------------- */

    /**
     * Run a files-only backup of one small directory to the named disks.
     *
     * Files rather than the database because the suite runs on sqlite in memory,
     * which has nothing to dump; a temporary directory rather than base_path()
     * because what is being tested is the destination, not the source.
     */
    protected function backupOnly(array $disks): PendingCommand
    {
        Storage::fake('local');

        $source = storage_path('app/backup-source-under-test');
        File::ensureDirectoryExists($source);
        File::put($source.'/subject.txt', 'something worth keeping');

        config([
            'backup.backup.source.files.include' => [$source],
            'backup.backup.source.files.exclude' => [],
            'backup.backup.destination.disks' => $disks,
        ]);

        return $this->artisan('backup:run', ['--only-files' => true, '--disable-notifications' => true]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('app/backup-source-under-test'));

        parent::tearDown();
    }
}
