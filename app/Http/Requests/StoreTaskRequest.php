<?php

namespace App\Http\Requests;

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
            'original_deadline' => ['required', 'date', 'after_or_equal:today'],
            'due_date' => ['nullable', 'date', 'after_or_equal:original_deadline'],
            'start_date' => ['nullable', 'date'],
            'estimated_hours' => ['required', 'numeric', 'min:0.1', 'max:9999.99'],
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
            'attachments.max' => 'You can upload a maximum of 10 files at a time.',
            'attachments.*.max' => 'Each attachment must be 10 MB or smaller.',
            'attachments.*.mimes' => 'Attachments must be PDF, Office document, CSV, text, or image files.',
        ];
    }
}
