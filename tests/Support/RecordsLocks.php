<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Tests\Support;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use RoundlyConsulting\Testing\Database\DriverMatrix;
use RoundlyConsulting\Testing\Fixtures\LockRecorder;
use RoundlyConsulting\Testing\Fixtures\LockRecordingGrammar;

/**
 * LockRecorder variant B: the locks sit in package actions — on the final TagLock, on the
 * host's taggable row and on swappable tag rows — which a model-trait recorder cannot see.
 * SQLite only: on a real engine the engine locks for real and the race tests prove it.
 */
final class RecordsLocks
{
    /** @var list<string> */
    private static array $statements = [];

    public static function start(): void
    {
        $connection = DB::connection();
        $connection->setQueryGrammar(new LockRecordingGrammar($connection));

        LockRecorder::flush();
        LockRecorder::listenForMarkers();

        self::$statements = [];
        DB::listen(static function (QueryExecuted $query): void {
            self::$statements[] = $query->sql;
        });
    }

    public static function available(): bool
    {
        return DriverMatrix::driver() === 'sqlite';
    }

    /**
     * The tables locked `FOR UPDATE`, in order.
     *
     * @return list<string>
     */
    public static function tables(): array
    {
        return array_map(
            static fn (array $record): string => (string) preg_replace('/^.*?from\s+"?([a-z_]+)"?.*$/is', '$1', $record['sql']),
            LockRecorder::recorded(),
        );
    }

    /**
     * Every statement since start(), as a short label: `lock:<table>`, `insert:<table>`,
     * `delete:<table>`, `update:<table>`; reads that lock nothing are left out.
     *
     * @return list<string>
     */
    public static function writes(): array
    {
        $labels = [];

        foreach (self::$statements as $sql) {
            if (preg_match('#/\* lock-for-update \*/#', $sql) === 1) {
                $labels[] = 'lock:'.preg_replace('/^.*?from\s+"?([a-z_]+)"?.*$/is', '$1', $sql);
            } elseif (preg_match('/^(insert into|delete from|update)\s+"?([a-z_]+)"?/i', $sql, $matches) === 1) {
                $labels[] = strtolower(strtok($matches[1], ' ') ?: '').':'.$matches[2];
            }
        }

        return $labels;
    }
}
