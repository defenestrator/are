<?php

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Copies every application table from one connection into another, for the
 * move from the production SQLite file to PostgreSQL.
 *
 * The target takes its schema from our own migrations. This command only
 * moves rows. It reads the table list, the columns and the foreign keys from
 * the target schema, so tables added by later migrations are copied without
 * any change here. The source is only ever read.
 */
class DbCopy extends Command
{
    protected $signature = 'db:copy
        {--from=sqlite : Connection to copy from}
        {--to=pgsql : Connection to copy into. It must be fully migrated and every copied table empty}
        {--source-path= : SQLite file to read instead of the database configured on --from}
        {--skip=* : Tables to leave out, wildcards allowed. Defaults to cache*, sessions, jobs and job_batches; giving --skip replaces that list, and --skip= copies them all}
        {--chunk=500 : Rows per insert}
        {--dry-run : Check the guards and print the plan without writing anything}';

    protected $description = 'Copies the application data from one database connection into another (SQLite to PostgreSQL).';

    /** Transient tables a fresh database can do without. `migrations` is always skipped. */
    public const DEFAULT_SKIP = ['cache*', 'sessions', 'jobs', 'job_batches'];

    /** Name of the runtime connection used for --source-path. */
    public const SOURCE_CONNECTION = 'db_copy_source';

    /** PostgreSQL allows 65535 bind parameters per statement; stay well under it. */
    private const MAX_BINDINGS = 60000;

    public function handle(): int
    {
        try {
            [$source, $target] = $this->connections();
            $plan = $this->plan($source, $target);
        } catch (RuntimeException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info(sprintf(
            'Copying %s (%s) into %s (%s).',
            $source->getName(), $source->getDatabaseName(), $target->getName(), $target->getDatabaseName(),
        ));

        if ($plan['skipped'] !== []) {
            $this->components->twoColumnDetail('<fg=gray>Skipped</>', implode(', ', array_map(
                fn (string $table) => $table.' ('.($plan['inSource'][$table] ? $source->table($table)->count() : 0).' rows in source)',
                $plan['skipped'],
            )));
        }

        if ($this->option('dry-run')) {
            $this->table(['Order', 'Table', 'Rows in source'], array_map(
                fn (string $table, int $i) => [$i + 1, $table, $plan['inSource'][$table] ? $source->table($table)->count() : 'not in source'],
                $plan['order'], array_keys($plan['order']),
            ));
            $this->components->info('Dry run: the guards passed and nothing was written.');

            return self::SUCCESS;
        }

        foreach ($plan['order'] as $table) {
            if (! $plan['inSource'][$table]) {
                continue;
            }

            try {
                $copied = $this->copyTable($source, $target, $table, $plan['columns'][$table]);
            } catch (Throwable $e) {
                $this->components->error("Copying {$table} failed and its transaction was rolled back: ".$e->getMessage());
                $this->line("  Tables before {$table} were already committed. Empty the target with");
                $this->line("  `php artisan migrate:fresh --database={$target->getName()} --force`, fix the cause, then run db:copy again.");

                return self::FAILURE;
            }

            $this->components->twoColumnDetail($table, "{$copied} rows");
        }

        return $this->verify($source, $target, $plan) ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array{Connection, Connection}
     */
    private function connections(): array
    {
        $from = (string) $this->option('from');
        $to = (string) $this->option('to');

        foreach ([$from, $to] as $name) {
            if (! is_array(config("database.connections.{$name}"))) {
                throw new RuntimeException("There is no database connection named [{$name}].");
            }
        }

        $sourceName = $from;
        $path = $this->option('source-path');

        if (is_string($path) && $path !== '') {
            if (! is_file($path)) {
                throw new RuntimeException("The source file {$path} does not exist.");
            }

            config(['database.connections.'.self::SOURCE_CONNECTION => array_merge(
                (array) config("database.connections.{$from}"),
                ['url' => null, 'database' => realpath($path)],
            )]);
            DB::purge(self::SOURCE_CONNECTION);
            $sourceName = self::SOURCE_CONNECTION;
        }

        /** @var Connection $source */
        $source = DB::connection($sourceName);
        /** @var Connection $target */
        $target = DB::connection($to);

        if ($sourceName === $to || (
            $source->getDriverName() === $target->getDriverName()
            && $source->getDatabaseName() === $target->getDatabaseName()
            && $source->getDatabaseName() !== ':memory:'
        )) {
            throw new RuntimeException('The source and the target are the same database.');
        }

        return [$source, $target];
    }

    /**
     * Runs every guard and works out what to copy, in foreign-key order.
     *
     * @return array{order: list<string>, skipped: list<string>, inSource: array<string, bool>, columns: array<string, array<string, 'bool'|'date'|'datetime'|null>>}
     */
    private function plan(Connection $source, Connection $target): array
    {
        $targetSchema = $target->getSchemaBuilder();
        $sourceSchema = $source->getSchemaBuilder();

        if (! $targetSchema->hasTable('migrations')) {
            throw new RuntimeException("The target has not been migrated. Run `php artisan migrate --database={$target->getName()} --force` first.");
        }
        if (! $sourceSchema->hasTable('migrations')) {
            throw new RuntimeException('The source has no migrations table, so it is not an ARE database.');
        }

        $this->guardMigrations($source, $target);

        $patterns = $this->skipPatterns();
        $isSkipped = fn (string $table) => $table === 'migrations' || Str::is($patterns, $table);

        /** @var list<string> $targetTables */
        $targetTables = $targetSchema->getTableListing(schemaQualified: false);
        /** @var list<string> $sourceTables */
        $sourceTables = $sourceSchema->getTableListing(schemaQualified: false);

        $copy = array_values(array_filter($targetTables, fn (string $t) => ! $isSkipped($t)));
        $skipped = array_values(array_filter($targetTables, fn (string $t) => $t !== 'migrations' && $isSkipped($t)));

        $missing = array_diff(array_filter($sourceTables, fn (string $t) => ! $isSkipped($t)), $targetTables);
        if ($missing !== []) {
            throw new RuntimeException('The target has no table for: '.implode(', ', $missing).'. Their rows would be lost.');
        }

        $notEmpty = array_values(array_filter($copy, fn (string $t) => $target->table($t)->exists()));
        if ($notEmpty !== []) {
            throw new RuntimeException('The target already holds rows in: '.implode(', ', $notEmpty).'. db:copy only fills an empty database.');
        }

        $inSource = [];
        $columns = [];
        foreach (array_merge($copy, $skipped) as $table) {
            $inSource[$table] = in_array($table, $sourceTables, true);
        }

        $tooLong = [];
        foreach ($copy as $table) {
            if (! $inSource[$table]) {
                continue;
            }

            $sourceColumns = $sourceSchema->getColumnListing($table);
            $kinds = [];
            foreach ($targetSchema->getColumns($table) as $column) {
                $kinds[$column['name']] = $this->kindOf($column['type_name'], $column['type']);

                // SQLite ignores varchar lengths; PostgreSQL rejects the whole insert.
                if (in_array($column['name'], $sourceColumns, true)
                    && preg_match('/^(?:character varying|varchar|character|char)\((\d+)\)$/i', $column['type'], $m)) {
                    $longest = (int) $source->table($table)
                        ->selectRaw('max(length('.$source->getQueryGrammar()->wrap($column['name']).')) as longest')
                        ->value('longest');
                    if ($longest > (int) $m[1]) {
                        $tooLong[] = "{$table}.{$column['name']} (limit {$m[1]}, longest {$longest})";
                    }
                }
            }

            $lost = array_diff($sourceColumns, array_keys($kinds));
            if ($lost !== []) {
                throw new RuntimeException("The target's {$table} table has no column ".implode(', ', $lost).'. Its values would be lost.');
            }

            $columns[$table] = array_intersect_key($kinds, array_flip($sourceColumns));
        }

        if ($tooLong !== []) {
            throw new RuntimeException('Source values are longer than the target columns allow: '.implode('; ', $tooLong).'. Widen those columns with a migration first.');
        }

        return [
            'order' => $this->foreignKeyOrder($target, $copy),
            'skipped' => $skipped,
            'inSource' => $inSource,
            'columns' => $columns,
        ];
    }

    private function guardMigrations(Connection $source, Connection $target): void
    {
        /** @var Migrator $migrator */
        $migrator = $this->laravel->make('migrator');
        $files = array_keys($migrator->getMigrationFiles(array_merge($migrator->paths(), [database_path('migrations')])));

        $targetRan = $target->table('migrations')->pluck('migration')->all();
        $sourceRan = $source->table('migrations')->pluck('migration')->all();

        $pending = array_diff($files, $targetRan);
        if ($pending !== []) {
            throw new RuntimeException('The target has pending migrations ('.implode(', ', $pending)."). Run `php artisan migrate --database={$target->getName()} --force` first.");
        }

        $onlySource = array_diff($sourceRan, $targetRan);
        $onlyTarget = array_diff($targetRan, $sourceRan);
        if ($onlySource !== [] || $onlyTarget !== []) {
            throw new RuntimeException('The source and the target are at different migrations. Only in the source: '
                .(implode(', ', $onlySource) ?: 'none').'. Only in the target: '.(implode(', ', $onlyTarget) ?: 'none').'.');
        }
    }

    /**
     * @return list<string>
     */
    private function skipPatterns(): array
    {
        /** @var list<string> $given */
        $given = (array) $this->option('skip');

        if ($given === []) {
            return self::DEFAULT_SKIP;
        }

        $patterns = [];
        foreach ($given as $value) {
            foreach (explode(',', (string) $value) as $pattern) {
                if (trim($pattern) !== '') {
                    $patterns[] = trim($pattern);
                }
            }
        }

        return $patterns;
    }

    /**
     * How a value has to be converted for the target column, or null to pass it through.
     *
     * @return 'bool'|'date'|'datetime'|null
     */
    private function kindOf(string $typeName, string $type): ?string
    {
        if (strtolower($type) === 'tinyint(1)') {
            return 'bool'; // How Laravel declares a boolean on SQLite and MySQL.
        }

        return match (strtolower($typeName)) {
            'bool', 'boolean' => 'bool',
            'date' => 'date',
            'timestamp', 'timestamptz', 'datetime' => 'datetime',
            default => null,
        };
    }

    /**
     * Orders the tables so each comes after every table it references.
     *
     * @param  list<string>  $tables
     * @return list<string>
     */
    private function foreignKeyOrder(Connection $target, array $tables): array
    {
        $schema = $target->getSchemaBuilder();
        $dependsOn = [];

        foreach ($tables as $table) {
            $dependsOn[$table] = [];
            foreach ($schema->getForeignKeys($table) as $foreignKey) {
                $parent = $foreignKey['foreign_table'];
                // Self-references are copied in primary-key order; skipped tables are not ours to order.
                if ($parent !== $table && in_array($parent, $tables, true)) {
                    $dependsOn[$table][$parent] = true;
                }
            }
        }

        $order = [];
        while ($dependsOn !== []) {
            $ready = array_keys(array_filter($dependsOn, fn (array $parents) => $parents === []));
            if ($ready === []) {
                throw new RuntimeException('These tables reference each other in a cycle, so no copy order satisfies their foreign keys: '.implode(', ', array_keys($dependsOn)).'.');
            }

            sort($ready);
            foreach ($ready as $table) {
                $order[] = $table;
                unset($dependsOn[$table]);
            }
            foreach ($dependsOn as $table => $parents) {
                $dependsOn[$table] = array_diff_key($parents, array_flip($ready));
            }
        }

        return $order;
    }

    /**
     * @param  array<string, 'bool'|'date'|'datetime'|null>  $columns  column name => conversion kind
     */
    private function copyTable(Connection $source, Connection $target, string $table, array $columns): int
    {
        $names = array_keys($columns);
        $chunk = max(1, min((int) $this->option('chunk'), intdiv(self::MAX_BINDINGS, max(1, count($names)))));

        $primary = [];
        foreach ($target->getSchemaBuilder()->getIndexes($table) as $index) {
            if ($index['primary']) {
                $primary = $index['columns'];
            }
        }

        $query = $source->table($table)->select($names);
        foreach ($primary as $column) {
            $query->orderBy($column);
        }
        $rows = $primary === [] ? $query->cursor() : $query->lazy($chunk);

        return $target->transaction(function () use ($target, $table, $columns, $rows, $chunk) {
            $copied = 0;
            $batch = [];

            foreach ($rows as $row) {
                $batch[] = $this->normalise($table, (array) $row, $columns);

                if (count($batch) >= $chunk) {
                    $target->table($table)->insert($batch);
                    $copied += count($batch);
                    $batch = [];
                }
            }

            if ($batch !== []) {
                $target->table($table)->insert($batch);
                $copied += count($batch);
            }

            if ($target->getDriverName() === 'pgsql') {
                $this->resetSequences($target, $table);
            }

            return $copied;
        });
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, 'bool'|'date'|'datetime'|null>  $columns
     * @return array<string, mixed>
     */
    private function normalise(string $table, array $row, array $columns): array
    {
        foreach ($row as $column => $value) {
            $kind = $columns[$column] ?? null;

            if ($value === null || $kind === null) {
                continue;
            }

            $row[$column] = match ($kind) {
                'bool' => $this->toBool($table, $column, $value),
                'date', 'datetime' => $this->toDate($table, $column, $value, $kind),
            };
        }

        return $row;
    }

    private function toBool(string $table, string $column, mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))) {
            return (int) $value !== 0;
        }

        return match (is_string($value) ? strtolower(trim($value)) : null) {
            't', 'true', 'y', 'yes', 'on' => true,
            'f', 'false', 'n', 'no', 'off', '' => false,
            default => throw new RuntimeException("{$table}.{$column}: cannot read ".var_export($value, true).' as a boolean.'),
        };
    }

    /**
     * SQLite stores dates as whatever text it was given. PostgreSQL's
     * `timestamp without time zone` silently drops a UTC offset, and it
     * rejects an empty string, so every value is rewritten in the app's
     * timezone in the format Laravel itself writes.
     *
     * @param  'date'|'datetime'  $kind
     */
    private function toDate(string $table, string $column, mixed $value, string $kind): ?string
    {
        if (is_string($value) && trim($value) === '') {
            return null;
        }

        $timezone = (string) config('app.timezone');

        try {
            $date = is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))
                ? CarbonImmutable::createFromTimestamp((float) $value, $timezone)
                : CarbonImmutable::parse((string) $value, $timezone)->setTimezone($timezone);
        } catch (Throwable) {
            throw new RuntimeException("{$table}.{$column}: cannot read ".var_export($value, true).' as a date.');
        }

        if ($kind === 'date') {
            return $date->format('Y-m-d');
        }

        return $date->format($date->microsecond === 0 ? 'Y-m-d H:i:s' : 'Y-m-d H:i:s.u');
    }

    /**
     * Rows were inserted with their ids, which does not advance a sequence.
     * Point each one at the highest id so the next insert does not collide.
     */
    private function resetSequences(Connection $target, string $table): void
    {
        $grammar = $target->getQueryGrammar();

        foreach ($target->getSchemaBuilder()->getColumns($table) as $column) {
            if (! $column['auto_increment']) {
                continue;
            }

            $wrapped = $grammar->wrap($column['name']);
            $target->select(
                "select setval(pg_get_serial_sequence(?, ?), coalesce(max({$wrapped}), 1), max({$wrapped}) is not null) from {$grammar->wrapTable($table)}",
                [$target->getTablePrefix().$table, $column['name']],
            );
        }
    }

    /**
     * Compares the row count of every copied table on both sides.
     *
     * @param  array{order: list<string>, skipped: list<string>, inSource: array<string, bool>, columns: array<string, array<string, 'bool'|'date'|'datetime'|null>>}  $plan
     */
    private function verify(Connection $source, Connection $target, array $plan): bool
    {
        $rows = [];
        $mismatches = 0;

        foreach ($plan['order'] as $table) {
            $sourceCount = $plan['inSource'][$table] ? $source->table($table)->count() : 0;
            $targetCount = $target->table($table)->count();
            $ok = $sourceCount === $targetCount;
            $mismatches += $ok ? 0 : 1;
            $rows[] = [$table, $sourceCount, $targetCount, $ok ? 'ok' : '<fg=red>MISMATCH</>'];
        }

        $this->newLine();
        $this->table(['Table', 'Source rows', 'Target rows', 'Check'], $rows);

        if ($mismatches > 0) {
            $this->components->error("{$mismatches} table(s) have different row counts.");

            return false;
        }

        $this->components->info('Every copied table has the same row count on both sides.');

        return true;
    }
}
