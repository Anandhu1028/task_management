<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const STATUS_CHECK_CONSTRAINT = 'tasks_status_supported_check';

    private const SUPPORTED_STATUSES = [
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

    private const LEGACY_STATUSES = [
        'todo',
        'in_progress',
        'in_review',
        'completed',
        'blocked',
        'cancelled',
    ];

    public function up(): void
    {
        $this->addWorkflowColumns();
        $this->ensureWorkflowForeignKeys();
        $this->addHistoryEventType();
        $this->setStatusColumnAsString();
        $this->backfillMissingAssignments();
        $this->convertLegacyStatuses();
        $this->assertStatusesAreSupported();
        $this->assertHistoryStatusesAreSupported();
        $this->setStatusColumnToSupportedStatuses();
    }

    public function down(): void
    {
        $this->assertWorkflowMetadataCanBeRemoved();
        $this->removeSqliteStatusTriggers();
        $this->dropStatusCheckConstraint();
        $this->setStatusColumnAsString();
        $this->dropWorkflowForeignKeys();

        $this->mapHistoryStatuses([
            'notified' => 'todo',
            'unassigned' => 'todo',
            'assigned' => 'todo',
            'submitted_for_review' => 'in_review',
            'on_hold' => 'blocked',
            'at_risk' => 'in_progress',
            'overdue' => 'in_progress',
        ], 'Workflow status');

        DB::table('tasks')
            ->whereIn('status', ['notified', 'unassigned', 'assigned'])
            ->update(['status' => 'todo']);
        DB::table('tasks')->where('status', 'submitted_for_review')->update(['status' => 'in_review']);
        DB::table('tasks')->where('status', 'on_hold')->update(['status' => 'blocked']);
        DB::table('tasks')->where('status', 'at_risk')->update(['status' => 'in_progress']);
        DB::table('tasks')->where('status', 'overdue')->update(['status' => 'in_progress']);

        Schema::table('task_status_histories', function (Blueprint $table): void {
            if (Schema::hasColumn('task_status_histories', 'event_type')) {
                $table->dropColumn('event_type');
            }
        });

        Schema::table('tasks', function (Blueprint $table): void {
            foreach ([
                'hold_reason',
                'risk_reason',
                'risk_flagged_by',
                'risk_flagged_at',
                'cancellation_reason',
                'cancelled_by',
                'cancelled_at',
                'submitted_at',
                'submission_comment',
                'reviewed_by',
                'reviewed_at',
                'review_comment',
            ] as $column) {
                if (Schema::hasColumn('tasks', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        $this->setStatusColumnToLegacyStatuses();
    }

    private function addWorkflowColumns(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            if (! Schema::hasColumn('tasks', 'hold_reason')) {
                $table->text('hold_reason')->nullable();
            }
            if (! Schema::hasColumn('tasks', 'risk_reason')) {
                $table->text('risk_reason')->nullable();
            }
            if (! Schema::hasColumn('tasks', 'risk_flagged_by')) {
                $table->foreignId('risk_flagged_by')->nullable();
            }
            if (! Schema::hasColumn('tasks', 'risk_flagged_at')) {
                $table->timestamp('risk_flagged_at')->nullable();
            }
            if (! Schema::hasColumn('tasks', 'cancellation_reason')) {
                $table->text('cancellation_reason')->nullable();
            }
            if (! Schema::hasColumn('tasks', 'cancelled_by')) {
                $table->foreignId('cancelled_by')->nullable();
            }
            if (! Schema::hasColumn('tasks', 'cancelled_at')) {
                $table->timestamp('cancelled_at')->nullable();
            }
            if (! Schema::hasColumn('tasks', 'submitted_at')) {
                $table->timestamp('submitted_at')->nullable();
            }
            if (! Schema::hasColumn('tasks', 'submission_comment')) {
                $table->text('submission_comment')->nullable();
            }
            if (! Schema::hasColumn('tasks', 'reviewed_by')) {
                $table->foreignId('reviewed_by')->nullable();
            }
            if (! Schema::hasColumn('tasks', 'reviewed_at')) {
                $table->timestamp('reviewed_at')->nullable();
            }
            if (! Schema::hasColumn('tasks', 'review_comment')) {
                $table->text('review_comment')->nullable();
            }
        });
    }

    private function ensureWorkflowForeignKeys(): void
    {
        foreach (['risk_flagged_by', 'cancelled_by', 'reviewed_by'] as $column) {
            $foreignKey = collect(Schema::getForeignKeys('tasks'))
                ->first(fn (array $key): bool => in_array($column, $key['columns'], true));

            if ($foreignKey !== null
                && $foreignKey['foreign_table'] === 'users'
                && strtolower((string) $foreignKey['on_delete']) === 'set null') {
                continue;
            }

            if ($foreignKey !== null) {
                Schema::table('tasks', function (Blueprint $table) use ($foreignKey): void {
                    $table->dropForeign($foreignKey['name']);
                });
            }

            if (Schema::hasColumn('tasks', $column)) {
                Schema::table('tasks', function (Blueprint $table) use ($column): void {
                    $table->foreign($column)->references('id')->on('users')->nullOnDelete();
                });
            }
        }
    }

    private function dropWorkflowForeignKeys(): void
    {
        foreach (['risk_flagged_by', 'cancelled_by', 'reviewed_by'] as $column) {
            $foreignKey = collect(Schema::getForeignKeys('tasks'))
                ->first(fn (array $key): bool => in_array($column, $key['columns'], true));

            if ($foreignKey !== null) {
                Schema::table('tasks', function (Blueprint $table) use ($foreignKey): void {
                    $table->dropForeign($foreignKey['name']);
                });
            }
        }
    }

    private function addHistoryEventType(): void
    {
        if (! Schema::hasColumn('task_status_histories', 'event_type')) {
            Schema::table('task_status_histories', function (Blueprint $table): void {
                $table->string('event_type', 30)->default('status');
            });
        }
    }

    private function setStatusColumnAsString(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql' || $driver === 'mariadb') {
            DB::statement("ALTER TABLE `tasks` MODIFY `status` VARCHAR(32) NOT NULL DEFAULT 'unassigned'");

            return;
        }

        if ($driver === 'sqlite') {
            return;
        }

        Schema::table('tasks', function (Blueprint $table): void {
            $table->string('status', 32)->default('unassigned')->change();
        });
    }

    private function backfillMissingAssignments(): void
    {
        DB::table('tasks')
            ->select(['tasks.id', 'tasks.assigned_to', 'tasks.created_by', 'tasks.created_at', 'tasks.updated_at'])
            ->whereNotNull('tasks.assigned_to')
            ->whereNotIn('tasks.status', ['completed', 'cancelled'])
            ->orderBy('tasks.id')
            ->chunkById(500, function ($tasks): void {
                $now = now();
                $taskIds = $tasks->pluck('id');
                $activeAssignments = DB::table('task_assignments')
                    ->whereIn('task_id', $taskIds)
                    ->where('is_active', true)
                    ->whereNull('unassigned_at')
                    ->get(['task_id', 'user_id'])
                    ->mapWithKeys(static fn (object $assignment): array => [
                        $assignment->task_id.':'.$assignment->user_id => true,
                    ]);

                $assignments = $tasks
                    ->reject(static fn (object $task): bool => $activeAssignments->has(
                        $task->id.':'.$task->assigned_to,
                    ))
                    ->map(static function (object $task) use ($now): array {
                        $assignedAt = $task->created_at ?? $now;

                        return [
                            'task_id' => $task->id,
                            'user_id' => $task->assigned_to,
                            'assigned_by' => $task->created_by,
                            'reason' => 'Initial assignment',
                            'assigned_at' => $assignedAt,
                            'is_active' => true,
                            'created_at' => $assignedAt,
                            'updated_at' => $task->updated_at ?? $assignedAt,
                        ];
                    })->all();

                if ($assignments !== []) {
                    DB::table('task_assignments')->insert($assignments);
                }
            });
    }

    private function convertLegacyStatuses(): void
    {
        DB::table('tasks')
            ->where('status', 'todo')
            ->where(function ($query): void {
                $query->whereNotNull('assigned_to')
                    ->orWhereExists(function ($assignmentQuery): void {
                        $assignmentQuery->selectRaw('1')
                            ->from('task_assignments')
                            ->whereColumn('task_assignments.task_id', 'tasks.id')
                            ->where('task_assignments.is_active', true)
                            ->whereNull('task_assignments.unassigned_at');
                    });
            })
            ->update(['status' => 'assigned']);

        DB::table('tasks')->where('status', 'todo')->update(['status' => 'unassigned']);
        DB::table('tasks')->where('status', 'in_review')->update(['status' => 'submitted_for_review']);
        DB::table('tasks')->where('status', 'blocked')->update([
            'status' => 'on_hold',
            'hold_reason' => 'Reason not recorded before the status workflow update.',
        ]);

        $this->mapHistoryStatuses([
            'todo' => 'unassigned',
            'in_review' => 'submitted_for_review',
            'blocked' => 'on_hold',
        ], 'Original status', true);
    }

    private function assertStatusesAreSupported(): void
    {
        $unknownStatuses = DB::table('tasks')
            ->whereNotIn('status', self::SUPPORTED_STATUSES)
            ->distinct()
            ->pluck('status')
            ->all();

        if ($unknownStatuses !== []) {
            throw new RuntimeException(
                'Cannot update task statuses. Unsupported values remain: '.implode(', ', $unknownStatuses),
            );
        }
    }

    private function assertHistoryStatusesAreSupported(): void
    {
        foreach (['old_status', 'new_status'] as $column) {
            $unknownStatuses = DB::table('task_status_histories')
                ->whereNotNull($column)
                ->whereNotIn($column, self::SUPPORTED_STATUSES)
                ->distinct()
                ->pluck($column)
                ->all();

            if ($unknownStatuses !== []) {
                throw new RuntimeException(
                    "Cannot update task status history. Unsupported {$column} values remain: ".implode(', ', $unknownStatuses),
                );
            }
        }
    }

    /**
     * @param  array<string, string>  $mapping
     */
    private function mapHistoryStatuses(array $mapping, string $annotation, bool $mapTodoByAssignment = false): void
    {
        $query = DB::table('task_status_histories')
            ->select('task_status_histories.*');

        if ($mapTodoByAssignment) {
            $query->selectRaw(
                "CASE WHEN EXISTS (SELECT 1 FROM task_assignments WHERE task_assignments.task_id = task_status_histories.task_id AND task_assignments.is_active = 1 AND task_assignments.unassigned_at IS NULL) THEN 'assigned' ELSE 'unassigned' END as todo_mapped_status",
            );
        }

        $query
            ->where(function ($query) use ($mapping): void {
                $query->whereIn('old_status', array_keys($mapping))
                    ->orWhereIn('new_status', array_keys($mapping));
            })
            ->orderBy('id')
            ->chunkById(500, function ($histories) use ($mapping, $annotation, $mapTodoByAssignment): void {
                foreach ($histories as $history) {
                    $oldStatus = $history->old_status;
                    $newStatus = $history->new_status;
                    $changedStatuses = [];

                    if (isset($mapping[$oldStatus])) {
                        $changedStatuses[$oldStatus] = true;
                        $oldStatus = $mapTodoByAssignment && $oldStatus === 'todo'
                            ? $history->todo_mapped_status
                            : $mapping[$oldStatus];
                    }
                    if (isset($mapping[$newStatus])) {
                        $changedStatuses[$newStatus] = true;
                        $newStatus = $mapTodoByAssignment && $newStatus === 'todo'
                            ? $history->todo_mapped_status
                            : $mapping[$newStatus];
                    }

                    $remarks = $history->remarks;
                    foreach (array_keys($changedStatuses) as $originalStatus) {
                        $note = "[{$annotation}: {$originalStatus}]";
                        if (! str_contains((string) $remarks, $note)) {
                            $remarks = trim((string) $remarks);
                            $remarks = $remarks === '' ? $note : $remarks.' '.$note;
                        }
                    }

                    DB::table('task_status_histories')
                        ->where('id', $history->id)
                        ->update([
                            'old_status' => $oldStatus,
                            'new_status' => $newStatus,
                            'remarks' => $remarks,
                        ]);
                }
            });
    }

    private function setStatusColumnToSupportedStatuses(): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver === 'mysql' || $driver === 'mariadb') {
            $enumValues = $this->enumValues(self::SUPPORTED_STATUSES);
            DB::statement("ALTER TABLE `tasks` MODIFY `status` ENUM({$enumValues}) NOT NULL DEFAULT 'unassigned'");

            return;
        }

        if ($driver === 'sqlite') {
            $this->createSqliteStatusTriggers(self::SUPPORTED_STATUSES, true);

            return;
        }

        Schema::table('tasks', function (Blueprint $table): void {
            $table->string('status', 32)->default('unassigned')->change();
        });

        $this->createStatusCheckConstraint();
    }

    private function setStatusColumnToLegacyStatuses(): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver === 'mysql' || $driver === 'mariadb') {
            $enumValues = $this->enumValues(self::LEGACY_STATUSES);
            DB::statement("ALTER TABLE `tasks` MODIFY `status` ENUM({$enumValues}) NOT NULL DEFAULT 'todo'");

            return;
        }

        if ($driver === 'sqlite') {
            $this->createSqliteStatusTriggers(self::LEGACY_STATUSES);

            return;
        }

        Schema::table('tasks', function (Blueprint $table): void {
            $table->string('status', 32)->default('todo')->change();
        });
    }

    /**
     * @param  list<string>  $statuses
     */
    private function createSqliteStatusTriggers(array $statuses, bool $normalizeLegacyDefault = false): void
    {
        $this->removeSqliteStatusTriggers();
        $values = implode(',', array_map(
            static fn (string $status): string => "'".str_replace("'", "''", $status)."'",
            $statuses,
        ));
        $insertValues = $normalizeLegacyDefault ? "{$values},'todo'" : $values;
        DB::unprepared("CREATE TRIGGER tasks_status_supported_insert BEFORE INSERT ON tasks FOR EACH ROW WHEN NEW.status NOT IN ({$insertValues}) BEGIN SELECT RAISE(ABORT, 'Unsupported task status'); END");
        DB::unprepared("CREATE TRIGGER tasks_status_supported_update BEFORE UPDATE OF status ON tasks FOR EACH ROW WHEN NEW.status NOT IN ({$values}) BEGIN SELECT RAISE(ABORT, 'Unsupported task status'); END");

        if ($normalizeLegacyDefault) {
            DB::unprepared("CREATE TRIGGER tasks_status_default_unassigned AFTER INSERT ON tasks FOR EACH ROW WHEN NEW.status = 'todo' BEGIN UPDATE tasks SET status = 'unassigned' WHERE id = NEW.id; END");
        }
    }

    private function removeSqliteStatusTriggers(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS tasks_status_supported_insert');
            DB::unprepared('DROP TRIGGER IF EXISTS tasks_status_supported_update');
            DB::unprepared('DROP TRIGGER IF EXISTS tasks_status_default_unassigned');
        }
    }

    private function createStatusCheckConstraint(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['pgsql', 'sqlsrv'], true)) {
            return;
        }

        $enumValues = $this->enumValues(self::SUPPORTED_STATUSES);
        DB::statement(
            'ALTER TABLE tasks ADD CONSTRAINT '.self::STATUS_CHECK_CONSTRAINT." CHECK (status IN ({$enumValues}))",
        );
    }

    private function dropStatusCheckConstraint(): void
    {
        if (in_array(DB::connection()->getDriverName(), ['pgsql', 'sqlsrv'], true)) {
            DB::statement('ALTER TABLE tasks DROP CONSTRAINT '.self::STATUS_CHECK_CONSTRAINT);
        }
    }

    /**
     * @param  list<string>  $statuses
     */
    private function enumValues(array $statuses): string
    {
        return implode(',', array_map(
            static fn (string $status): string => "'".str_replace("'", "''", $status)."'",
            $statuses,
        ));
    }

    private function assertWorkflowMetadataCanBeRemoved(): void
    {
        $hasWorkflowMetadata = DB::table('tasks')
            ->where(function ($query): void {
                foreach ([
                    'risk_reason',
                    'risk_flagged_by',
                    'risk_flagged_at',
                    'cancellation_reason',
                    'cancelled_by',
                    'cancelled_at',
                    'submitted_at',
                    'submission_comment',
                    'reviewed_by',
                    'reviewed_at',
                    'review_comment',
                ] as $column) {
                    if (Schema::hasColumn('tasks', $column)) {
                        $query->orWhereNotNull($column);
                    }
                }
                if (Schema::hasColumn('tasks', 'hold_reason')) {
                    $query->orWhere(function ($query): void {
                        $query->whereNotNull('hold_reason')
                            ->where('hold_reason', '!=', 'Reason not recorded before the status workflow update.');
                    });
                }
            })
            ->exists();
        $hasNonStatusEvents = Schema::hasColumn('task_status_histories', 'event_type')
            && DB::table('task_status_histories')->where('event_type', '!=', 'status')->exists();

        if ($hasWorkflowMetadata || $hasNonStatusEvents) {
            throw new RuntimeException(
                'Task workflow data has been recorded. Export or migrate that data before rolling back this migration.',
            );
        }

        $nonReversibleStatuses = DB::table('tasks')
            ->whereIn('status', ['notified', 'at_risk', 'overdue'])
            ->distinct()
            ->pluck('status')
            ->all();

        if ($nonReversibleStatuses !== []) {
            throw new RuntimeException(
                'Task statuses cannot be safely rolled back while these states are present: '.implode(', ', $nonReversibleStatuses),
            );
        }
    }
};
