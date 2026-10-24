<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const WORKFLOW_STATUSES = [
        'notified',
        'unassigned',
        'assigned',
        'in_progress',
        'on_hold',
        'submitted_for_review',
        'rework',
        'completed',
        'cancelled',
    ];

    private const PREVIOUS_STATUSES = [
        'notified',
        'unassigned',
        'assigned',
        'in_progress',
        'on_hold',
        'submitted_for_review',
        'completed',
        'cancelled',
    ];

    public function up(): void
    {
        $this->setSupportedStatuses(self::WORKFLOW_STATUSES);
    }

    public function down(): void
    {
        DB::table('tasks')->where('status', 'rework')->update(['status' => 'in_progress']);
        DB::table('task_status_histories')->where('old_status', 'rework')->update(['old_status' => 'in_progress']);
        DB::table('task_status_histories')->where('new_status', 'rework')->update(['new_status' => 'in_progress']);

        $this->setSupportedStatuses(self::PREVIOUS_STATUSES);
    }

    /**
     * @param  list<string>  $statuses
     */
    private function setSupportedStatuses(array $statuses): void
    {
        $driver = DB::connection()->getDriverName();
        $quotedStatuses = "'".implode("','", $statuses)."'";

        if ($driver === 'mysql' || $driver === 'mariadb') {
            DB::statement(
                "ALTER TABLE `tasks` MODIFY `status` ENUM({$quotedStatuses}) NOT NULL DEFAULT 'unassigned'",
            );

            return;
        }

        if ($driver === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS tasks_status_supported_insert');
            DB::unprepared('DROP TRIGGER IF EXISTS tasks_status_supported_update');

            $values = implode(',', array_map(
                static fn (string $status): string => "'".str_replace("'", "''", $status)."'",
                $statuses,
            ));

            DB::unprepared("CREATE TRIGGER tasks_status_supported_insert BEFORE INSERT ON tasks FOR EACH ROW WHEN NEW.status NOT IN ({$values},'todo') BEGIN SELECT RAISE(ABORT, 'Unsupported task status'); END");
            DB::unprepared("CREATE TRIGGER tasks_status_supported_update BEFORE UPDATE OF status ON tasks FOR EACH ROW WHEN NEW.status NOT IN ({$values}) BEGIN SELECT RAISE(ABORT, 'Unsupported task status'); END");

            return;
        }

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE tasks DROP CONSTRAINT IF EXISTS tasks_status_supported_check');
        } elseif ($driver === 'sqlsrv') {
            DB::statement(
                "IF OBJECT_ID('tasks_status_supported_check', 'C') IS NOT NULL ALTER TABLE tasks DROP CONSTRAINT tasks_status_supported_check",
            );
        } else {
            throw new RuntimeException("Unsupported database driver [{$driver}] for task status migration.");
        }

        DB::statement(
            "ALTER TABLE tasks ADD CONSTRAINT tasks_status_supported_check CHECK (status IN ({$quotedStatuses}))",
        );
    }
};
