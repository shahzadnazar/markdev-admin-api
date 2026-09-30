<?php

namespace Tests\Concerns;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Tests\Support\RecordingBlueprint;

/**
 * Run every migration again and write down what it declared.
 *
 * Shared by the two guards that ask the migrations a question instead of asking
 * the database: IdentifierLengthGuardTest, which wants the index names, and
 * StatusColumnWidthTest, which wants the column widths. Both need the same
 * awkward setup, and two copies of it would drift.
 *
 * THE HOOK IS A CONTAINER BINDING, not Schema::blueprintResolver. The resolver
 * is the obvious choice and it does not work: the Schema facade is not cached,
 * so every Schema::create in every migration resolves a brand-new builder and a
 * resolver set on one of them is thrown away with it. Builder::createBlueprint
 * falls back to resolving Blueprint out of the container, which reaches every
 * builder there will ever be.
 *
 * IT MIGRATES ITS OWN THROWAWAY DATABASE, not the suite's. A migration only
 * declares anything when it actually runs, so pointing this at a database
 * something else has already migrated records nothing -- which is what happens
 * against a persistent test database, where the tables survive between runs.
 * Both callers keep a floor on what they expect to find for exactly that reason.
 * The driver does not matter: index names come from Blueprint::createIndexName
 * and widths from the column definitions, neither of which involves a grammar.
 */
trait ReplaysMigrations
{
    /**
     * The connection the migrations are replayed onto.
     *
     * Its own name so nothing can mistake this for a test against the
     * application's own database.
     */
    protected const REPLAY_CONNECTION = 'migration_replay';

    protected function replayMigrations(): void
    {
        RecordingBlueprint::reset();

        $this->app->bind(Blueprint::class, fn ($app, array $parameters) => new RecordingBlueprint(
            $parameters['connection'],
            $parameters['table'],
            $parameters['callback'] ?? null,
        ));

        $original = config('database.default');

        config(['database.connections.'.self::REPLAY_CONNECTION => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            // Matches the mysql connection, so a prefixed install generates the
            // same index names here as it would there.
            'prefix_indexes' => true,
            'foreign_key_constraints' => true,
        ]]);

        try {
            $this->artisan('migrate', [
                '--database' => self::REPLAY_CONNECTION,
                '--force' => true,
            ])->assertExitCode(0);
        } finally {
            // migrate --database swaps the DEFAULT connection for the run.
            DB::setDefaultConnection($original);
        }
    }
}
