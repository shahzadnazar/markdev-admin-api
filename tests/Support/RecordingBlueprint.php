<?php

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;

/**
 * A Blueprint that writes down every index name Laravel generates.
 *
 * Installed through Schema::blueprintResolver by IdentifierLengthGuardTest so
 * the migrations can be run once and asked what identifiers they would create.
 *
 * WHY A BLUEPRINT RATHER THAN READING THE FILES. The name is not in the file:
 * `$table->index(['a', 'b'])` becomes `<table>_a_b_index` inside
 * Blueprint::createIndexName, out of the table name, the columns and the type.
 * Parsing the migrations with a regular expression would be re-implementing
 * that, and a re-implementation is a second thing that can be wrong. This asks
 * the same code the migration asks.
 *
 * The recording happens AFTER parent::build(), because a chained
 * `->unique()` on a column does not become a command until toSql() runs
 * addImpliedCommands. Read the commands before that and half of them are
 * invisible.
 */
class RecordingBlueprint extends Blueprint
{
    /**
     * Command types that carry an identifier a database has to store.
     *
     * `primary` is deliberately absent: MySQL names every primary key PRIMARY
     * and its grammar never emits the generated name, so flagging one would be
     * a false alarm — and a guard that cries wolf is a guard people disable.
     */
    public const NAMED = [
        'index', 'unique', 'fullText', 'spatialIndex', 'foreign',
        'dropIndex', 'dropUnique', 'dropForeign', 'dropFullText', 'dropSpatialIndex',
    ];

    /** @var array<int, array{file: string, table: string, type: string, name: string, columns: array<int, string>}> */
    public static array $identifiers = [];

    /**
     * Every column declaration, in the order the migrations make them.
     *
     * A declaration rather than a schema reading, because the LENGTH does not
     * survive the migration on SQLite: Laravel's SQLite grammar compiles
     * string('behaviour', 30) to a bare `varchar`, and pragma table_info reports
     * no number at all. The Blueprint is the last place the 30 exists, and it is
     * the same 30 the MySQL grammar would have written. A `->change()` shows up
     * as another entry later in the list, so folding the list in order gives the
     * width a column ends up with.
     *
     * @var array<int, array{file: string, table: string, name: string, type: ?string, length: ?int}>
     */
    public static array $declarations = [];

    public static function reset(): void
    {
        static::$identifiers = [];
        static::$declarations = [];
    }

    public function build()
    {
        parent::build();

        $file = $this->callingMigration();

        foreach ($this->getColumns() as $column) {
            static::$declarations[] = [
                'file' => $file,
                'table' => $this->getTable(),
                'name' => (string) $column->name,
                'type' => $column->type === null ? null : (string) $column->type,
                'length' => $column->length === null ? null : (int) $column->length,
            ];
        }

        // A dropped column is a command, not a definition, and it has to cancel
        // the declaration above it or the fold would keep a column the table no
        // longer has.
        foreach ($this->getCommands() as $command) {
            if ($command->name !== 'dropColumn') {
                continue;
            }

            foreach ((array) ($command->columns ?? []) as $dropped) {
                static::$declarations[] = [
                    'file' => $file,
                    'table' => $this->getTable(),
                    'name' => (string) $dropped,
                    'type' => null,
                    'length' => null,
                ];
            }
        }

        foreach ($this->getCommands() as $command) {
            $name = $command->index ?? null;

            if (! in_array($command->name, self::NAMED, true) || ! is_string($name) || $name === '') {
                continue;
            }

            static::$identifiers[] = [
                'file' => $file,
                'table' => $this->getTable(),
                'type' => $command->name,
                'name' => $name,
                'columns' => array_map('strval', (array) ($command->columns ?? [])),
            ];
        }
    }

    /**
     * The migration file that asked for this table.
     *
     * Taken from the stack rather than from a migration event, because the
     * failure message has to name a file somebody can open and the events carry
     * an anonymous class, not a path.
     */
    protected function callingMigration(): string
    {
        $root = base_path().DIRECTORY_SEPARATOR;

        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            $file = $frame['file'] ?? '';

            if (str_contains($file, DIRECTORY_SEPARATOR.'migrations'.DIRECTORY_SEPARATOR)) {
                return str_replace($root, '', $file);
            }
        }

        return 'unknown file';
    }
}
