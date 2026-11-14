<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->validateLegacyData();
        $this->ensureCreators();
        $shouldBackfillActivities = ! Schema::hasTable('milestone_activities');

        if (! Schema::hasColumn('milestones', 'milestone_date')) {
            Schema::table('milestones', function (Blueprint $table): void {
                $table->date('milestone_date')->nullable();
            });
        }
        DB::statement('UPDATE milestones SET milestone_date = COALESCE(DATE(created_at), CURRENT_DATE) WHERE milestone_date IS NULL');

        if ($shouldBackfillActivities) {
            Schema::create('milestone_activities', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('milestone_id')->constrained()->cascadeOnDelete();
                $table->unsignedBigInteger('task_id');
                $table->enum('type', ['created', 'updated', 'status_changed']);
                $table->string('old_status', 20)->nullable();
                $table->string('new_status', 20)->nullable();
                $table->string('label', 100);
                $table->unsignedBigInteger('created_by');
                $table->dateTime('created_at');
                $table->index('task_id');
            });

            $this->backfillHistory();
            $this->backfillMissingCreationEvents();
            $this->normalizeCompletionMetadata();
            $this->normalizeLegacyCancelledMilestones();
        }

        if (DB::getDriverName() === 'mysql') {
            $this->applyMySqlSchema();

            return;
        }

        Schema::table('milestones', function (Blueprint $table): void {
            $table->date('milestone_date')->nullable(false)->change();
            $table->index(['task_id', 'milestone_date']);
            $table->dropIndex(['task_id', 'sort_order']);
            $table->dropIndex(['task_id', 'status', 'target_date']);
            $table->dropColumn(['expected_outcome', 'target_date', 'sort_order', 'progress_percentage']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('milestone_activities');

        Schema::table('milestones', function (Blueprint $table): void {
            $table->text('expected_outcome')->nullable();
            $table->date('target_date')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->unsignedTinyInteger('progress_percentage')->default(0);
        });

        DB::statement('UPDATE milestones SET target_date = milestone_date, progress_percentage = CASE WHEN status = \'completed\' THEN 100 ELSE 0 END');
        $this->restoreSortOrder();

        if (DB::getDriverName() === 'mysql') {
            Schema::table('milestones', function (Blueprint $table): void {
                $table->dropForeign(['created_by']);
            });

            DB::statement("ALTER TABLE milestones
                MODIFY created_by BIGINT UNSIGNED NULL,
                MODIFY title VARCHAR(500) NOT NULL,
                MODIFY status VARCHAR(30) NOT NULL DEFAULT 'pending',
                MODIFY target_date DATE NOT NULL,
                MODIFY completed_at TIMESTAMP NULL,
                MODIFY created_at TIMESTAMP NULL,
                MODIFY updated_at TIMESTAMP NULL");

            Schema::table('milestones', function (Blueprint $table): void {
                $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
                $table->dropIndex(['task_id', 'milestone_date']);
                $table->dropColumn('milestone_date');
                $table->index(['task_id', 'sort_order']);
                $table->index(['task_id', 'status', 'target_date']);
            });

            return;
        }

        Schema::table('milestones', function (Blueprint $table): void {
            $table->date('target_date')->nullable(false)->change();
            $table->dropIndex(['task_id', 'milestone_date']);
            $table->dropColumn('milestone_date');
            $table->index(['task_id', 'sort_order']);
            $table->index(['task_id', 'status', 'target_date']);
        });
    }

    private function validateLegacyData(): void
    {
        $lengthFunction = DB::getDriverName() === 'mysql' ? 'CHAR_LENGTH' : 'LENGTH';
        if (DB::table('milestones')->whereRaw("{$lengthFunction}(title) > 255")->exists()) {
            throw new RuntimeException('Milestone titles longer than 255 characters must be shortened before migrating.');
        }

        if (DB::table('milestones')
            ->whereNotIn('status', ['pending', 'in_progress', 'completed', 'cancelled'])
            ->exists()) {
            throw new RuntimeException('Milestones contain a status that cannot be represented by the new status enum.');
        }
    }

    private function ensureCreators(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('UPDATE milestones AS milestones
                INNER JOIN tasks ON tasks.id = milestones.task_id
                SET milestones.created_by = tasks.created_by
                WHERE milestones.created_by IS NULL');
        } else {
            DB::table('milestones')->whereNull('created_by')->get(['id', 'task_id'])->each(function (object $milestone): void {
                $creatorId = DB::table('tasks')->where('id', $milestone->task_id)->value('created_by');
                if ($creatorId !== null) {
                    DB::table('milestones')->where('id', $milestone->id)->update(['created_by' => $creatorId]);
                }
            });
        }

        if (DB::table('milestones')->whereNull('created_by')->exists()) {
            throw new RuntimeException('Milestones without a creator cannot be migrated.');
        }
    }

    private function backfillHistory(): void
    {
        $milestoneCreators = DB::table('milestones')->pluck('created_by', 'id');
        $createdDates = DB::table('milestones')->pluck('created_at', 'id');
        $activities = [];

        DB::table('task_status_histories')
            ->whereNotNull('milestone_id')
            ->whereIn('event_type', ['milestone_created', 'milestone_updated', 'milestone_status'])
            ->orderBy('id')
            ->chunk(500, function ($histories) use (&$activities, $milestoneCreators, $createdDates): void {
                foreach ($histories as $history) {
                    $milestoneId = (int) $history->milestone_id;
                    $creatorId = $milestoneCreators->get($milestoneId);
                    if ($creatorId === null) {
                        continue;
                    }

                    $type = match ($history->event_type) {
                        'milestone_created' => 'created',
                        'milestone_updated' => 'updated',
                        'milestone_status' => 'status_changed',
                    };
                    $newStatus = $type === 'status_changed' ? (string) $history->new_status : null;
                    $oldStatus = $type === 'status_changed' ? $history->old_status : null;
                    if (mb_strlen((string) $oldStatus) > 20 || mb_strlen((string) $newStatus) > 20) {
                        throw new RuntimeException("Milestone activity {$history->id} has a status longer than 20 characters.");
                    }

                    $activities[] = [
                        'milestone_id' => $milestoneId,
                        'task_id' => (int) $history->task_id,
                        'type' => $type,
                        'old_status' => $oldStatus,
                        'new_status' => $newStatus,
                        'label' => match ($type) {
                            'created' => 'Milestone created',
                            'updated' => 'Milestone updated',
                            'status_changed' => 'Status updated to: '.match ($newStatus) {
                                'completed' => 'Completed',
                                'in_progress' => 'In Progress',
                                'pending' => 'Pending',
                                default => ucfirst(str_replace('_', ' ', (string) $newStatus)),
                            },
                        },
                        'created_by' => (int) ($history->changed_by ?? $creatorId),
                        'created_at' => $history->created_at ?? $createdDates->get($milestoneId) ?? now()->toDateTimeString(),
                    ];

                    if (count($activities) === 500) {
                        DB::table('milestone_activities')->insert($activities);
                        $activities = [];
                    }
                }
            });

        if ($activities !== []) {
            DB::table('milestone_activities')->insert($activities);
        }
    }

    private function backfillMissingCreationEvents(): void
    {
        $milestones = DB::table('milestones')->get([
            'id',
            'task_id',
            'created_by',
            'created_at',
        ]);

        foreach ($milestones as $milestone) {
            if (DB::table('milestone_activities')
                ->where('milestone_id', $milestone->id)
                ->where('type', 'created')
                ->exists()) {
                continue;
            }

            DB::table('milestone_activities')->insert([
                'milestone_id' => $milestone->id,
                'task_id' => $milestone->task_id,
                'type' => 'created',
                'old_status' => null,
                'new_status' => null,
                'label' => 'Milestone created',
                'created_by' => $milestone->created_by,
                'created_at' => $milestone->created_at ?? now()->toDateTimeString(),
            ]);
        }
    }

    private function normalizeCompletionMetadata(): void
    {
        DB::table('milestones')->where('status', '!=', 'completed')->update([
            'completed_at' => null,
            'completed_by' => null,
        ]);

        DB::table('milestones')->where('status', 'completed')->get([
            'id',
            'task_id',
            'created_by',
            'completed_at',
            'completed_by',
            'created_at',
            'updated_at',
        ])->each(function (object $milestone): void {
            $latestCompletion = DB::table('milestone_activities')
                ->where('milestone_id', $milestone->id)
                ->where('type', 'status_changed')
                ->where('new_status', 'completed')
                ->orderByDesc('created_at')
                ->first(['created_at', 'created_by']);

            $completedAt = $milestone->completed_at
                ?? $latestCompletion?->created_at
                ?? $milestone->updated_at
                ?? $milestone->created_at
                ?? now()->toDateTimeString();
            $completedBy = $milestone->completed_by
                ?? $latestCompletion?->created_by
                ?? $milestone->created_by;

            DB::table('milestones')->where('id', $milestone->id)->update([
                'completed_at' => $completedAt,
                'completed_by' => $completedBy,
            ]);

            if (DB::table('milestone_activities')
                ->where('milestone_id', $milestone->id)
                ->where('type', 'status_changed')
                ->where('new_status', 'completed')
                ->where('created_at', $completedAt)
                ->exists()) {
                return;
            }

            DB::table('milestone_activities')->insert([
                'milestone_id' => $milestone->id,
                'task_id' => $milestone->task_id,
                'type' => 'status_changed',
                'old_status' => 'pending',
                'new_status' => 'completed',
                'label' => 'Status updated to: Completed',
                'created_by' => $completedBy,
                'created_at' => $completedAt,
            ]);
        });
    }

    private function normalizeLegacyCancelledMilestones(): void
    {
        DB::table('milestones')->where('status', 'cancelled')->get([
            'id',
            'task_id',
            'created_by',
        ])->each(function (object $milestone): void {
            $eventAt = now()->toDateTimeString();
            DB::table('milestones')->where('id', $milestone->id)->update([
                'status' => 'pending',
                'completed_at' => null,
                'completed_by' => null,
                'updated_at' => $eventAt,
            ]);
            DB::table('milestone_activities')->insert([
                'milestone_id' => $milestone->id,
                'task_id' => $milestone->task_id,
                'type' => 'status_changed',
                'old_status' => 'cancelled',
                'new_status' => 'pending',
                'label' => 'Status updated to: Pending',
                'created_by' => $milestone->created_by,
                'created_at' => $eventAt,
            ]);
        });
    }

    private function applyMySqlSchema(): void
    {
        if (! $this->indexExists('milestones_task_id_milestone_date_index')) {
            Schema::table('milestones', function (Blueprint $table): void {
                $table->index(['task_id', 'milestone_date']);
            });
        }

        foreach (['milestones_task_id_sort_order_index', 'milestones_task_id_status_target_date_index'] as $indexName) {
            if ($this->indexExists($indexName)) {
                Schema::table('milestones', function (Blueprint $table) use ($indexName): void {
                    $table->dropIndex($indexName);
                });
            }
        }

        if ($this->foreignKeyExists('milestones_created_by_foreign')) {
            Schema::table('milestones', function (Blueprint $table): void {
                $table->dropForeign(['created_by']);
            });
        }

        DB::statement("ALTER TABLE milestones
            MODIFY created_by BIGINT UNSIGNED NOT NULL,
            MODIFY title VARCHAR(255) NOT NULL,
            MODIFY status ENUM('pending', 'in_progress', 'completed') NOT NULL DEFAULT 'pending',
            MODIFY milestone_date DATE NOT NULL,
            MODIFY completed_at DATETIME NULL,
            MODIFY created_at DATETIME NULL,
            MODIFY updated_at DATETIME NULL");

        $legacyColumns = array_values(array_filter(
            ['expected_outcome', 'target_date', 'sort_order', 'progress_percentage'],
            fn (string $column): bool => Schema::hasColumn('milestones', $column),
        ));
        if ($legacyColumns !== []) {
            Schema::table('milestones', function (Blueprint $table) use ($legacyColumns): void {
                $table->dropColumn($legacyColumns);
            });
        }

        if (! $this->foreignKeyExists('milestones_created_by_foreign')) {
            Schema::table('milestones', function (Blueprint $table): void {
                $table->foreign('created_by')->references('id')->on('users')->restrictOnDelete();
            });
        }
    }

    private function indexExists(string $indexName): bool
    {
        return DB::select('SHOW INDEX FROM milestones WHERE Key_name = ?', [$indexName]) !== [];
    }

    private function foreignKeyExists(string $constraintName): bool
    {
        $result = DB::selectOne(
            'SELECT COUNT(*) AS aggregate FROM information_schema.KEY_COLUMN_USAGE
            WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?',
            ['milestones', $constraintName],
        );

        return (int) ($result->aggregate ?? 0) > 0;
    }

    private function restoreSortOrder(): void
    {
        $sortOrders = [];
        DB::table('milestones')
            ->orderBy('task_id')
            ->orderBy('milestone_date')
            ->orderBy('id')
            ->get(['id', 'task_id'])
            ->each(function (object $milestone) use (&$sortOrders): void {
                $sortOrders[$milestone->task_id] = ($sortOrders[$milestone->task_id] ?? 0) + 1;
                DB::table('milestones')->where('id', $milestone->id)->update([
                    'sort_order' => $sortOrders[$milestone->task_id],
                ]);
            });
    }
};
