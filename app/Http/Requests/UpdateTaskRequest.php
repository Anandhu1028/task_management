<?php

namespace App\Http\Requests;

use App\Models\Task;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $assigneeIds = $this->input('assigned_to');

        if (is_numeric($assigneeIds)) {
            $this->merge(['assigned_to' => [$assigneeIds]]);
        } elseif ($this->exists('assigned_to_present') && ! is_array($assigneeIds)) {
            $this->merge(['assigned_to' => []]);
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
            'progress_percentage' => ['nullable', 'integer', 'min:0', 'max:100'],
            'assigned_to' => ['sometimes', 'nullable', 'array'],
            'assigned_to.*' => ['required', 'integer', 'distinct', 'exists:users,id'],
            'poc_ids' => ['nullable', 'array'],
            'poc_ids.*' => ['integer', 'exists:users,id'],
            'due_date' => ['nullable', 'date'],
            'start_date' => ['nullable', 'date'],
            'estimated_hours' => ['nullable', 'numeric', 'min:0.01', 'max:9999.99'],
            'expected_output' => ['nullable', 'string', 'max:5000'],
            'description' => ['nullable', 'string', 'max:10000'],
            'attachments' => ['nullable', 'array', 'max:10'],
            'attachments.*' => ['file', 'max:10240', 'mimes:pdf,doc,docx,xls,xlsx,csv,txt,jpg,jpeg,png,webp'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'attachments.max' => 'You can upload a maximum of 10 files at a time.',
            'attachments.*.max' => 'Each attachment must be 10 MB or smaller.',
            'attachments.*.mimes' => 'Attachments must be PDF, Office document, CSV, text, or image files.',
        ];
    }
}
