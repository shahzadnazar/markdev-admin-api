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

    public static function reset(): void
    {
        static::$identifiers = [];
    }

    public function build()
    {
        parent::build();

        foreach ($this->getCommands() as $command) {
            $name = $command->index ?? null;

            if (! in_array($command->name, self::NAMED, true) || ! is_string($name) || $name === '') {
                continue;
            }

            static::$identifiers[] = [
                'file' => $this->callingMigration(),
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
