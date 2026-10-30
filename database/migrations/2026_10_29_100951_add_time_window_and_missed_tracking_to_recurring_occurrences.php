<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->timestamp('available_from')->nullable()->after('occurrence_date');
            $table->timestamp('expires_at')->nullable()->after('available_from');
            $table->timestamp('missed_at')->nullable()->after('expires_at');
            $table->index(['recurring_task_id', 'expires_at', 'status'], 'tasks_recurring_expiry_index');
        });

        $this->setSupportedStatuses([...self::PREVIOUS_STATUSES, 'missed']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('tasks')->where('status', 'missed')->update(['status' => 'cancelled']);
        $this->setSupportedStatuses(self::PREVIOUS_STATUSES);

        Schema::table('tasks', function (Blueprint $table): void {
            $table->dropIndex('tasks_recurring_expiry_index');
            $table->dropColumn(['available_from', 'expires_at', 'missed_at']);
        });
    }

    private const PREVIOUS_STATUSES = [
        'notified', 'unassigned', 'assigned', 'in_progress', 'on_hold', 'submitted_for_review', 'rework', 'completed', 'cancelled',
    ];

    /** @param list<string> $statuses */
    private function setSupportedStatuses(array $statuses): void
    {
        $quotedStatuses = "'".implode("','", $statuses)."'";
        $driver = DB::connection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE `tasks` MODIFY `status` ENUM({$quotedStatuses}) NOT NULL DEFAULT 'unassigned'");

            return;
        }

        if ($driver === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS tasks_status_supported_insert');
            DB::unprepared('DROP TRIGGER IF EXISTS tasks_status_supported_update');
            $values = implode(',', array_map(static fn (string $status): string => "'{$status}'", $statuses));
            DB::unprepared("CREATE TRIGGER tasks_status_supported_insert BEFORE INSERT ON tasks FOR EACH ROW WHEN NEW.status NOT IN ({$values},'todo') BEGIN SELECT RAISE(ABORT, 'Unsupported task status'); END");
            DB::unprepared("CREATE TRIGGER tasks_status_supported_update BEFORE UPDATE OF status ON tasks FOR EACH ROW WHEN NEW.status NOT IN ({$values}) BEGIN SELECT RAISE(ABORT, 'Unsupported task status'); END");

            return;
        }

        throw new RuntimeException("Unsupported database driver [{$driver}] for task status migration.");
    }
};
