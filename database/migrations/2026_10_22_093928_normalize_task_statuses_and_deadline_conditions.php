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
        'completed',
        'cancelled',
    ];

    private const DEADLINE_CONDITION_STATUSES = ['at_risk', 'overdue'];

    private const PREVIOUS_STATUSES = [
        'notified',
        'unassigned',
        'assigned',
        'in_progress',
        'on_hold',
        'at_risk',
        'overdue',
        'submitted_for_review',
        'completed',
        'cancelled',
    ];

    public function up(): void
    {
        $this->setStatusColumnAsString();
        $taskStatuses = $this->normalizeDeadlineConditionStatuses();
        $this->normalizeStatusHistory($taskStatuses);
        $this->setStatusColumnToWorkflowStatuses();
    }

    public function down(): void
    {
        $this->setStatusColumnAsString();
        $this->setStatusColumnToPreviousStatuses();
    }

    private function setStatusColumnAsString(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql' || $driver === 'mariadb') {
            DB::statement("ALTER TABLE `tasks` MODIFY `status` VARCHAR(32) NOT NULL DEFAULT 'unassigned'");

            return;
        }

        if ($driver === 'sqlite') {
            $this->removeSqliteStatusTriggers();

            return;
        }

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE tasks DROP CONSTRAINT IF EXISTS tasks_status_supported_check');

            DB::statement("ALTER TABLE tasks ALTER COLUMN status TYPE VARCHAR(32), ALTER COLUMN status SET DEFAULT 'unassigned', ALTER COLUMN status SET NOT NULL");

            return;
        }

        if ($driver === 'sqlsrv') {
            DB::statement(
                "DECLARE @statusDefault sysname; SELECT @statusDefault = dc.name FROM sys.default_constraints dc INNER JOIN sys.columns c ON c.default_object_id = dc.object_id INNER JOIN sys.tables t ON t.object_id = c.object_id WHERE t.name = 'tasks' AND c.name = 'status'; IF @statusDefault IS NOT NULL EXEC('ALTER TABLE tasks DROP CONSTRAINT ' + QUOTENAME(@statusDefault)); IF OBJECT_ID('tasks_status_supported_check', 'C') IS NOT NULL ALTER TABLE tasks DROP CONSTRAINT tasks_status_supported_check; ALTER TABLE tasks ALTER COLUMN status VARCHAR(32) NOT NULL; ALTER TABLE tasks ADD CONSTRAINT tasks_status_default DEFAULT ('unassigned') FOR status",
            );

            return;
        }

        throw new RuntimeException("Unsupported database driver [{$driver}] for task status migration.");
    }

    /**
     * @return array<int, string>
     */
    private function normalizeDeadlineConditionStatuses(): array
    {
        $normalizedStatuses = [];

        DB::table('tasks')
            ->whereIn('status', self::DEADLINE_CONDITION_STATUSES)
            ->orderBy('id')
            ->chunkById(500, function ($tasks) use (&$normalizedStatuses): void {
                foreach ($tasks as $task) {
                    $status = $this->previousWorkflowStatus($task);
                    $normalizedStatuses[(int) $task->id] = $status;
                    DB::table('tasks')->where('id', $task->id)->update(['status' => $status]);
                }
            });

        return $normalizedStatuses;
    }

    private function previousWorkflowStatus(object $task): string
    {
        $riskHistoryStatus = DB::table('task_status_histories')
            ->where('task_id', $task->id)
            ->where('event_type', 'risk')
            ->whereIn('old_status', self::WORKFLOW_STATUSES)
            ->orderByDesc('id')
            ->value('old_status');

        if (is_string($riskHistoryStatus)) {
            return $riskHistoryStatus;
        }

        $lastWorkflowStatus = DB::table('task_status_histories')
            ->where('task_id', $task->id)
            ->whereIn('new_status', self::WORKFLOW_STATUSES)
            ->orderByDesc('id')
            ->value('new_status');

        if (is_string($lastWorkflowStatus)) {
            return $lastWorkflowStatus;
        }

        if ($task->start_date !== null) {
            return 'in_progress';
        }

        $hasActiveAssignment = DB::table('task_assignments')
            ->where('task_id', $task->id)
            ->where('is_active', true)
            ->whereNull('unassigned_at')
            ->exists();

        if ($task->assigned_to !== null || $hasActiveAssignment) {
            return 'assigned';
        }

        $hasDepartmentPoc = DB::table('task_pocs')
            ->where('task_id', $task->id)
            ->exists();

        return $hasDepartmentPoc ? 'notified' : 'unassigned';
    }

    /**
     * @param  array<int, string>  $normalizedStatuses
     */
    private function normalizeStatusHistory(array $normalizedStatuses): void
    {
        DB::table('task_status_histories')
            ->where(function ($query): void {
                $query->whereIn('old_status', self::DEADLINE_CONDITION_STATUSES)
                    ->orWhereIn('new_status', self::DEADLINE_CONDITION_STATUSES);
            })
            ->orderBy('id')
            ->chunkById(500, function ($histories) use ($normalizedStatuses): void {
                foreach ($histories as $history) {
                    $oldStatus = $history->old_status;
                    $newStatus = $history->new_status;
                    $legacyStatuses = array_values(array_filter(
                        [$oldStatus, $newStatus],
                        static fn (?string $status): bool => in_array($status, self::DEADLINE_CONDITION_STATUSES, true),
                    ));
                    $fallbackStatus = $normalizedStatuses[(int) $history->task_id]
                        ?? DB::table('tasks')->where('id', $history->task_id)->value('status')
                        ?? 'unassigned';

                    if (in_array($newStatus, self::DEADLINE_CONDITION_STATUSES, true)) {
                        $newStatus = in_array($oldStatus, self::WORKFLOW_STATUSES, true)
                            ? $oldStatus
                            : $fallbackStatus;
                    }

                    if (in_array($oldStatus, self::DEADLINE_CONDITION_STATUSES, true)) {
                        $oldStatus = in_array($newStatus, self::WORKFLOW_STATUSES, true)
                            ? $newStatus
                            : $fallbackStatus;
                    }

                    $remarks = trim((string) $history->remarks);
                    foreach (array_unique($legacyStatuses) as $legacyStatus) {
                        $annotation = "[Original deadline condition: {$legacyStatus}]";
                        if (! str_contains($remarks, $annotation)) {
                            $remarks = trim($remarks.' '.$annotation);
                        }
                    }

                    DB::table('task_status_histories')
                        ->where('id', $history->id)
                        ->update([
                            'old_status' => $oldStatus,
                            'new_status' => $newStatus,
                            'remarks' => $remarks === '' ? null : $remarks,
                        ]);
                }
            });
    }

    private function setStatusColumnToWorkflowStatuses(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql' || $driver === 'mariadb') {
            $statuses = $this->quotedStatuses(self::WORKFLOW_STATUSES);
            DB::statement(
                "ALTER TABLE `tasks` MODIFY `status` ENUM({$statuses}) NOT NULL DEFAULT 'unassigned'",
            );

            return;
        }

        if ($driver === 'sqlite') {
            $this->createSqliteStatusTriggers(self::WORKFLOW_STATUSES, true);

            return;
        }

        $statuses = $this->quotedStatuses(self::WORKFLOW_STATUSES);
        DB::statement("ALTER TABLE tasks ADD CONSTRAINT tasks_status_supported_check CHECK (status IN ({$statuses}))");
    }

    private function setStatusColumnToPreviousStatuses(): void
    {
        $driver = DB::connection()->getDriverName();
        $enumValues = $this->quotedStatuses(self::PREVIOUS_STATUSES);

        if ($driver === 'mysql' || $driver === 'mariadb') {
            DB::statement(
                "ALTER TABLE `tasks` MODIFY `status` ENUM({$enumValues}) NOT NULL DEFAULT 'unassigned'",
            );

            return;
        }

        if ($driver === 'sqlite') {
            $this->createSqliteStatusTriggers(self::PREVIOUS_STATUSES, true);

            return;
        }

        DB::statement(
            "ALTER TABLE tasks ADD CONSTRAINT tasks_status_supported_check CHECK (status IN ({$enumValues}))",
        );
        if ($driver === 'sqlsrv') {
            DB::statement("ALTER TABLE tasks ADD CONSTRAINT tasks_status_default DEFAULT ('unassigned') FOR status");
        }
    }

    /**
     * @param  list<string>  $statuses
     */
    private function quotedStatuses(array $statuses): string
    {
        return "'".implode("','", $statuses)."'";
    }

    /**
     * @param  list<string>  $statuses
     */
    private function createSqliteStatusTriggers(array $statuses, bool $normalizeLegacyDefault): void
    {
        $this->removeSqliteStatusTriggers();
        $values = implode(',', array_map(
            static fn (string $status): string => "'".str_replace("'", "''", $status)."'",
            $statuses,
        ));
        $insertValues = $normalizeLegacyDefault ? "{$values},'todo'" : $values;

        DB::unprepared("CREATE TRIGGER tasks_status_supported_insert BEFORE INSERT ON tasks FOR EACH ROW WHEN NEW.status NOT IN ({$insertValues}) BEGIN SELECT RAISE(ABORT, 'Unsupported task status'); END");
        DB::unprepared("CREATE TRIGGER tasks_status_supported_update BEFORE UPDATE OF status ON tasks FOR EACH ROW WHEN NEW.status NOT IN ({$values}) BEGIN SELECT RAISE(ABORT, 'Unsupported task status'); END");
        DB::unprepared("CREATE TRIGGER tasks_status_default_unassigned AFTER INSERT ON tasks FOR EACH ROW WHEN NEW.status = 'todo' BEGIN UPDATE tasks SET status = 'unassigned' WHERE id = NEW.id; END");
    }

    private function removeSqliteStatusTriggers(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            return;
        }

        DB::unprepared('DROP TRIGGER IF EXISTS tasks_status_supported_insert');
        DB::unprepared('DROP TRIGGER IF EXISTS tasks_status_supported_update');
        DB::unprepared('DROP TRIGGER IF EXISTS tasks_status_default_unassigned');
    }
};
