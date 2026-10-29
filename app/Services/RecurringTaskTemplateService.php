<?php

namespace App\Services;

use App\Models\RecurringTaskTemplate;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class RecurringTaskTemplateService
{
    public function __construct(
        private readonly RecurringTaskGenerationService $generation,
    ) {}

    /**
     * @param  array<int, int|string>  $pocIds
     * @param  array<int, int|string>  $assigneeIds
     * @param  list<array{path: string, original_name: string, mime_type: ?string, size_bytes: int}>  $attachments
     * @param  array<string, mixed>  $data
     */
    public function create(
        array $data,
        array $pocIds,
        array $assigneeIds,
        array $attachments,
        User $creator,
    ): RecurringTaskTemplate {
        return DB::transaction(function () use ($data, $pocIds, $assigneeIds, $attachments, $creator): RecurringTaskTemplate {
            $template = RecurringTaskTemplate::query()->create([
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'company_id' => $data['company_id'],
                'department_id' => $data['department_id'],
                'created_by' => $creator->id,
                'task_type' => $data['task_type'] === 'recurring' ? 'process' : $data['task_type'],
                'priority' => $data['priority'],
                'expected_output' => $data['expected_output'],
                'original_deadline' => null,
                'estimated_hours' => null,
                'repeat_frequency' => $data['repeat_frequency'],
                'start_date' => $data['recurring_start_date'],
                'end_date' => $data['recurring_end_date'],
                'start_time' => $data['recurring_start_time'],
                'end_time' => $data['recurring_end_time'],
                'timezone' => config('app.timezone'),
                'status' => 'active',
                'attachments' => $attachments === [] ? null : $attachments,
            ]);

            $template->pocs()->sync($pocIds);
            $template->assignees()->sync($assigneeIds);

            $this->generation->generateDueOccurrencesForTemplate($template);

            return $template;
        });
    }
}
