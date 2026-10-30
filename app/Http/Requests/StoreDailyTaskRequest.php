<?php

namespace App\Http\Requests;

use App\Models\DailyTask;
use App\Models\Milestone;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreDailyTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        $task = $this->route('task');

        return $task instanceof Task
            && $this->user()?->can('createDailyTask', $task) === true;
    }

    protected function prepareForValidation(): void
    {
        if (! $this->filled('assigned_to') && $this->user() !== null) {
            $this->merge(['assigned_to' => $this->user()->id]);
        }

        if (! $this->filled('work_date')) {
            $this->merge(['work_date' => now()->toDateString()]);
        }

        if ($this->filled('planned_start_time') && $this->filled('planned_end_time') && ! $this->filled('estimated_minutes')) {
            $start = strtotime((string) $this->input('planned_start_time'));
            $end = strtotime((string) $this->input('planned_end_time'));
            if ($start !== false && $end !== false && $end > $start) {
                $this->merge(['estimated_minutes' => (int) (($end - $start) / 60)]);
            }
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:500'],
            'description' => ['nullable', 'string', 'max:5000'],
            'subtask_id' => ['nullable', 'integer', 'exists:subtasks,id'],
            'milestone_id' => ['nullable', 'integer', 'exists:milestones,id'],
            'assigned_to' => ['required', 'integer', 'exists:users,id'],
            'work_date' => ['required', 'date_format:Y-m-d'],
            'planned_start_time' => ['required', 'date_format:H:i'],
            'planned_end_time' => ['required', 'date_format:H:i', 'after:planned_start_time'],
            'priority' => ['required', Rule::in(DailyTask::PRIORITIES)],
            'estimated_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
            'operational_metrics' => ['nullable', 'array', 'max:30'],
            'operational_metrics.*' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'remarks' => ['nullable', 'string', 'max:5000'],
            'attachments' => ['nullable', 'array', 'max:10'],
            'attachments.*' => ['file', 'max:10240', 'mimes:pdf,doc,docx,xls,xlsx,csv,txt,jpg,jpeg,png,webp'],
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $task = $this->route('task');
                if (! $task instanceof Task) {
                    return;
                }

                $subtaskId = $this->integer('subtask_id') ?: null;
                $subtask = $subtaskId === null
                    ? null
                    : $task->subtasks()->whereKey($subtaskId)->first();

                if ($subtaskId !== null && $subtask === null) {
                    $validator->errors()->add('subtask_id', 'The selected subtask does not belong to this main task.');
                }

                $milestoneId = $this->integer('milestone_id') ?: null;
                $milestone = $milestoneId === null
                    ? null
                    : $task->milestones()->whereKey($milestoneId)->first();

                if ($milestoneId !== null && $milestone === null) {
                    $validator->errors()->add('milestone_id', 'The selected milestone does not belong to this main task.');
                }

                $assigneeId = $this->integer('assigned_to');
                $user = $this->user();
                if ($user === null) {
                    return;
                }

                if ($assigneeId !== (int) $user->id && ! $user->can('daily_tasks.create_for_others')) {
                    $validator->errors()->add('assigned_to', 'You can only create a Daily Task for yourself.');
                }

                if ($subtask !== null
                    && $assigneeId === (int) $user->id
                    && $subtask->assigned_to !== null
                    && (int) $subtask->assigned_to !== $assigneeId
                    && ! $user->can('updateSubtask', $task)) {
                    $validator->errors()->add('subtask_id', 'You are not assigned to the selected subtask.');
                }

                if ($milestone instanceof Milestone
                    && $assigneeId === (int) $user->id
                    && $milestone->assignees()->exists()
                    && ! $milestone->assignees()->whereKey($user->id)->exists()
                    && ! $user->can('updateMilestone', [$task, $milestone])) {
                    $validator->errors()->add('milestone_id', 'You are not assigned to the selected milestone.');
                }

                $assignee = User::query()
                    ->whereKey($assigneeId)
                    ->where('status', 'active')
                    ->where('company_id', $task->company_id)
                    ->where('department_id', $task->department_id)
                    ->exists();

                if (! $assignee) {
                    $validator->errors()->add('assigned_to', 'The assignee must be active in the main task department.');
                }

                $metricDefinitions = config('operational_metrics.'.Str::slug((string) $task->department?->name, '_'), []);
                $submittedMetrics = array_keys((array) $this->input('operational_metrics', []));
                $unknownMetrics = array_diff($submittedMetrics, array_keys($metricDefinitions));

                if ($unknownMetrics !== []) {
                    $validator->errors()->add('operational_metrics', 'One or more operational metrics are not configured for this department.');
                }
            },
        ];
    }
}
