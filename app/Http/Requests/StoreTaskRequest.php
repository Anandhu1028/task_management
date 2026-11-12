<?php

namespace App\Http\Requests;

use App\Models\RecurringTask;
use App\Models\Task;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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
            $aliases = ['Asia/Calcutta' => 'Asia/Kolkata'];
            $timezone = (string) $this->input('timezone', '');
            $timezone = $aliases[$timezone] ?? $timezone;
            $this->merge(['timezone' => in_array($timezone, \DateTimeZone::listIdentifiers(), true)
                ? $timezone
                : (config('tasks.schedule_timezone', 'Asia/Kolkata') ?: 'Asia/Kolkata')]);
            $this->merge([
                'original_deadline' => null,
                'due_date' => null,
                'estimated_hours' => null,
            ]);
        }

        if ($this->input('task_type') === 'volume') {
            $this->merge([
                'start_date' => $this->input('volume_start_date'),
                'original_deadline' => $this->input('volume_deadline'),
                'due_date' => $this->input('volume_deadline'),
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
            'volume_total' => ['exclude_unless:task_type,volume', 'required', 'integer', 'min:1', 'max:2147483647'],
            'volume_start_date' => ['exclude_unless:task_type,volume', 'required', 'date_format:Y-m-d'],
            'volume_deadline' => ['exclude_unless:task_type,volume', 'required', 'date_format:Y-m-d', 'after_or_equal:volume_start_date'],
            'priority' => ['required', Rule::in(Task::PRIORITIES)],
            'assigned_to' => ['sometimes', 'nullable', 'array'],
            'assigned_to.*' => ['required', 'integer', 'distinct', 'exists:users,id'],
            'poc_ids' => ['required', 'array', 'min:1'],
            'poc_ids.*' => ['required', 'integer', 'distinct', 'exists:users,id'],
            'original_deadline' => ['exclude_if:task_type,recurring,volume', 'nullable', 'date', 'after_or_equal:today'],
            'due_date' => [
                'exclude_if:task_type,recurring,volume',
                'nullable',
                'date',
                ...($this->filled('original_deadline') ? ['after_or_equal:original_deadline'] : []),
            ],
            'phases' => ['exclude_unless:task_type,project', 'required', 'array', 'min:1', 'max:50'],
            'phases.*' => ['required', 'array:title,description,target_date'],
            'phases.*.title' => ['required', 'string', 'max:500'],
            'phases.*.description' => ['nullable', 'string', 'max:5000'],
            'phases.*.target_date' => ['required', 'date_format:Y-m-d'],
            'start_date' => ['nullable', 'date'],
            'estimated_hours' => ['exclude_if:task_type,recurring,volume', 'required', 'numeric', 'min:0.1', 'max:9999.99'],
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
            'timezone' => [
                'exclude_unless:task_type,recurring',
                'nullable',
                'string',
                'timezone:all',
            ],
            'expected_output' => ['required', 'string', 'max:5000'],
            'description' => ['nullable', 'string', 'max:10000'],
            'attachments' => ['nullable', 'array', 'max:10'],
            'attachments.*' => ['file', 'max:10240', 'mimes:pdf,doc,docx,xls,xlsx,csv,txt,jpg,jpeg,png,webp'],
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty() || $this->input('task_type') !== 'project') {
                    return;
                }

                $startDate = $this->filled('start_date')
                    ? Carbon::parse((string) $this->input('start_date'))
                    : null;
                $deadline = $this->filled('due_date')
                    ? Carbon::parse((string) $this->input('due_date'))
                    : ($this->filled('original_deadline')
                        ? Carbon::parse((string) $this->input('original_deadline'))
                        : null);

                foreach ((array) $this->input('phases', []) as $index => $phase) {
                    $targetDate = Carbon::createFromFormat('Y-m-d', (string) $phase['target_date']);

                    if ($startDate !== null && $targetDate?->toDateString() < $startDate->toDateString()) {
                        $validator->errors()->add(
                            "phases.{$index}.target_date",
                            'A phase deadline cannot precede the main task start date.',
                        );
                    }

                    if ($deadline !== null && $targetDate?->toDateString() > $deadline->toDateString()) {
                        $validator->errors()->add(
                            "phases.{$index}.target_date",
                            'A phase deadline cannot exceed the main task deadline.',
                        );
                    }
                }
            },
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