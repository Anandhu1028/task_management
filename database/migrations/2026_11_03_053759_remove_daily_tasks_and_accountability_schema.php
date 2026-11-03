<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('permissions')) {
            $permissionIds = DB::table('permissions')
                ->whereIn('name', [
                    'daily_tasks.create_own',
                    'daily_tasks.create_for_others',
                    'daily_tasks.view_team',
                    'daily_tasks.update_own',
                    'daily_tasks.manage',
                ])
                ->pluck('id');

            if ($permissionIds->isNotEmpty()) {
                DB::table('model_has_permissions')->whereIn('permission_id', $permissionIds)->delete();
                DB::table('role_has_permissions')->whereIn('permission_id', $permissionIds)->delete();
                DB::table('permissions')->whereIn('id', $permissionIds)->delete();
            }
        }

        Schema::dropIfExists('daily_accountability_events');
        Schema::dropIfExists('daily_accountability_exemptions');
        Schema::dropIfExists('daily_accountability_settings');

        if (Schema::hasTable('task_status_histories')) {
            $dailyHistoryQuery = DB::table('task_status_histories')
                ->whereRaw('SUBSTRING(event_type, 1, 11) = ?', ['daily_task_']);

            if (Schema::hasColumn('task_status_histories', 'daily_task_id')) {
                $dailyHistoryQuery->orWhereNotNull('daily_task_id');
            }

            if (Schema::hasTable('task_attachments')) {
                $dailyAttachments = DB::table('task_attachments')
                    ->join('task_status_histories', 'task_attachments.status_history_id', '=', 'task_status_histories.id')
                    ->where(function ($query): void {
                        $query->whereRaw('SUBSTRING(task_status_histories.event_type, 1, 11) = ?', ['daily_task_']);

                        if (Schema::hasColumn('task_status_histories', 'daily_task_id')) {
                            $query->orWhereNotNull('task_status_histories.daily_task_id');
                        }
                    })
                    ->get(['task_attachments.id', 'task_attachments.disk', 'task_attachments.path']);

                foreach ($dailyAttachments->groupBy('disk') as $disk => $attachments) {
                    if (! Storage::disk($disk)->delete($attachments->pluck('path')->all())) {
                        throw new RuntimeException('Unable to remove Daily Tasks attachments from storage.');
                    }
                }

                DB::table('task_attachments')
                    ->whereIn('id', $dailyAttachments->pluck('id'))
                    ->delete();
            }

            $dailyHistoryQuery->delete();

            if (Schema::hasColumn('task_status_histories', 'daily_task_id')) {
                Schema::table('task_status_histories', function (Blueprint $table): void {
                    $table->dropForeign(['daily_task_id']);
                });

                Schema::table('task_status_histories', function (Blueprint $table): void {
                    $table->dropIndex(['daily_task_id', 'created_at']);
                });

                Schema::table('task_status_histories', function (Blueprint $table): void {
                    $table->dropColumn('daily_task_id');
                });
            }
        }

        Schema::dropIfExists('daily_tasks');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new RuntimeException('The removed Daily Tasks data and schema cannot be restored.');
    }
};
