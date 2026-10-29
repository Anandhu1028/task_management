<?php

namespace App\Http\Requests;

use App\Models\RecurringTask;
use App\Models\Task;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (! $this->filled('due_date') && $this->filled('original_deadline')) {
            $this->merge(['due_date' => $this->input('original_deadline')]);
        }

        if ($this->input('task_type') === 'recurring') {
            $this->merge([
                'original_deadline' => null,
                'due_date' => null,
                'estimated_hours' => null,
            ]);
        }

        $assigneeIds = $this->input('assigned_to');
        if (is_numeric($assigneeIds)) {
            $this->merge(['assigned_to' => [$assigneeIds]]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'department_id' => ['required', 'integer', 'exists:departments,id'],
            'task_type' => ['required', Rule::in(Task::TYPES)],
            'priority' => ['required', Rule::in(Task::PRIORITIES)],
            'assigned_to' => ['sometimes', 'nullable', 'array'],
            'assigned_to.*' => ['required', 'integer', 'distinct', 'exists:users,id'],
            'poc_ids' => ['required', 'array', 'min:1'],
            'poc_ids.*' => ['required', 'integer', 'distinct', 'exists:users,id'],
            'original_deadline' => ['exclude_if:task_type,recurring', 'nullable', 'date', 'after_or_equal:today'],
            'due_date' => [
                'exclude_if:task_type,recurring',
                'nullable',
                'date',
                ...($this->filled('original_deadline') ? ['after_or_equal:original_deadline'] : []),
            ],
            'start_date' => ['nullable', 'date'],
            'estimated_hours' => ['exclude_if:task_type,recurring', 'required', 'numeric', 'min:0.1', 'max:9999.99'],
            'repeat_frequency' => [
                'exclude_unless:task_type,recurring',
                'required',
                Rule::in(RecurringTask::FREQUENCIES),
            ],
            'recurring_start_date' => [
                'exclude_unless:task_type,recurring',
                'required',
                'date_format:Y-m-d',
            ],
            'recurring_end_date' => [
                'exclude_unless:task_type,recurring',
                'required',
                'date_format:Y-m-d',
                'after_or_equal:recurring_start_date',
            ],
            'recurring_start_time' => [
                'exclude_unless:task_type,recurring',
                'required',
                'date_format:H:i',
            ],
            'recurring_end_time' => [
                'exclude_unless:task_type,recurring',
                'required',
                'date_format:H:i',
                'after:recurring_start_time',
            ],
            'expected_output' => ['required', 'string', 'max:5000'],
            'description' => ['nullable', 'string', 'max:10000'],
            'attachments' => ['nullable', 'array', 'max:10'],
            'attachments.*' => ['file', 'max:10240', 'mimes:pdf,doc,docx,xls,xlsx,csv,txt,jpg,jpeg,png,webp'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'original_deadline.after_or_equal' => 'The original deadline must be today or a future date.',
            'due_date.after_or_equal' => 'The due date must be on or after the original deadline.',
            'recurring_end_date.after_or_equal' => 'The recurring end date must be on or after the start date.',
            'recurring_end_time.after' => 'The recurring end time must be later than the start time.',
            'attachments.max' => 'You can upload a maximum of 10 files at a time.',
            'attachments.*.max' => 'Each attachment must be 10 MB or smaller.',
            'attachments.*.mimes' => 'Attachments must be PDF, Office document, CSV, text, or image files.',
        ];
    }
}
