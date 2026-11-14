<?php

namespace App\Http\Requests;

use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreMilestoneRequest extends FormRequest
{
    public function authorize(): bool
    {
        $task = $this->route('task');

        return $task instanceof Task
            && $this->user()?->can('createMilestone', $task) === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'milestone_date' => ['nullable', 'date_format:Y-m-d'],
            'assignee_ids' => ['nullable', 'array', 'max:50'],
            'assignee_ids.*' => ['integer', 'distinct', 'exists:users,id'],
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

                $assigneeIds = array_values((array) $this->input('assignee_ids', []));
                if ($assigneeIds !== [] && User::query()
                    ->whereIn('id', $assigneeIds)
                    ->where('status', 'active')
                    ->where('company_id', $task->company_id)
                    ->where('department_id', $task->department_id)
                    ->count() !== count($assigneeIds)) {
                    $validator->errors()->add('assignee_ids', 'Milestone assignees must be active employees in the main task department.');
                }
            },
        ];
    }
}
