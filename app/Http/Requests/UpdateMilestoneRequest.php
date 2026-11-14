<?php

namespace App\Http\Requests;

use App\Models\Milestone;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateMilestoneRequest extends FormRequest
{
    public function authorize(): bool
    {
        $task = $this->route('task');
        $milestone = $this->route('milestone');

        return $task instanceof Task
            && $milestone instanceof Milestone
            && $this->user()?->can('updateMilestone', [$task, $milestone]) === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'milestone_date' => ['sometimes', 'required', 'date_format:Y-m-d'],
            'status' => ['sometimes', 'required', Rule::in(Milestone::STATUSES)],
            'assignee_ids' => ['sometimes', 'nullable', 'array', 'max:50'],
            'assignee_ids.*' => ['integer', 'distinct', 'exists:users,id'],
            'remarks' => ['nullable', 'string', 'max:5000'],
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
