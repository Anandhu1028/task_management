<?php

namespace App\Services;

use App\Models\EmployeeDailyAccountability;
use App\Models\TaskStatusHistory;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class EmployeeDailyAccountabilityService
{
    public function recordActivity(User $employee, string $activityDate, ?\DateTimeInterface $activityAt = null): void
    {
        if (! $this->isActiveEmployee($employee)) {
            return;
        }

        $timestamp = $activityAt === null
            ? now()
            : Carbon::instance($activityAt)->timezone(config('app.timezone'));
        $accountability = EmployeeDailyAccountability::query()
            ->where('employee_id', $employee->id)
            ->whereDate('activity_date', $activityDate)
            ->first();

        if ($accountability === null) {
            $accountability = EmployeeDailyAccountability::query()->create([
                'employee_id' => $employee->id,
                'activity_date' => $activityDate,
                'employee_name' => $employee->name,
                'activity_status' => 'pending',
                'activity_count' => 0,
                'warning_status' => false,
            ]);
        }

        DB::table('employee_daily_accountabilities')
            ->where('id', $accountability->id)
            ->update([
                'employee_name' => $employee->name,
                'activity_status' => 'recorded',
                'activity_count' => DB::raw('activity_count + 1'),
                'last_activity_at' => $timestamp,
                'warning_status' => false,
                'updated_at' => now(),
            ]);
    }

    public function generateWarningsForDate(Carbon $date): int
    {
        $workDate = $date->toDateString();
        $start = $date->copy()->startOfDay()->utc();
        $end = $date->copy()->endOfDay()->utc();
        $activityCounts = TaskStatusHistory::query()
            ->selectRaw('changed_by, COUNT(*) as activity_count, MAX(created_at) as last_activity_at')
            ->whereNotNull('changed_by')
            ->where(function ($query): void {
                $query->whereIn('event_type', [
                    'status',
                    'accepted',
                    'start',
                    'hold',
                    'resume',
                    'work_update',
                    'submission',
                    'review',
                ])->orWhereRaw('SUBSTR(event_type, 1, 8) = ?', ['subtask_']);
            })
            ->where(function ($query) use ($workDate, $start, $end): void {
                $query->whereDate('activity_date', $workDate)
                    ->orWhere(fn ($query) => $query
                        ->whereNull('activity_date')
                        ->whereBetween('created_at', [$start, $end]));
            })
            ->groupBy('changed_by')
            ->get()
            ->keyBy('changed_by');

        $warningCount = 0;
        $this->activeEmployees()->chunkById(200, function ($employees) use ($activityCounts, $workDate, &$warningCount): void {
            foreach ($employees as $employee) {
                $activity = $activityCounts->get($employee->id);
                $hasActivity = $activity !== null;
                $accountability = EmployeeDailyAccountability::query()
                    ->where('employee_id', $employee->id)
                    ->whereDate('activity_date', $workDate)
                    ->first();
                $accountabilityData = [
                    'employee_name' => $employee->name,
                    'activity_status' => $hasActivity ? 'recorded' : 'missed',
                    'activity_count' => (int) ($activity?->activity_count ?? 0),
                    'last_activity_at' => $activity?->last_activity_at,
                    'warning_status' => ! $hasActivity,
                    'cutoff_evaluated_at' => now(),
                ];

                if ($accountability === null) {
                    EmployeeDailyAccountability::query()->create([
                        'employee_id' => $employee->id,
                        'activity_date' => $workDate,
                        ...$accountabilityData,
                    ]);
                } else {
                    $accountability->update($accountabilityData);
                }

                if (! $hasActivity) {
                    $warningCount++;
                }
            }
        });

        return $warningCount;
    }

    public function isActiveEmployee(User $user): bool
    {
        return $user->status === 'active' && $user->hasAssignedRole('Employee');
    }

    private function activeEmployees()
    {
        return User::query()
            ->where('status', 'active')
            ->where(function ($query): void {
                $query->whereHas('roles', fn ($roleQuery) => $roleQuery->where('name', 'Employee'))
                    ->orWhereHas('primaryRole', fn ($roleQuery) => $roleQuery->where('name', 'Employee'));
            })
            ->orderBy('id');
    }
}
