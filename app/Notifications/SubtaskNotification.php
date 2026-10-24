<?php

namespace App\Notifications;

use App\Models\Subtask;
use App\Models\Task;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class SubtaskNotification extends Notification
{
    use Queueable;

    public function __construct(
        public Task $task,
        public Subtask $subtask,
        public ?User $actor,
        public string $event,
        public string $title,
        public string $message,
        public ?string $deduplicationKey = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        $actorRole = $this->actor?->getRoleNames()->first()
            ?? $this->actor?->primaryRole?->name
            ?? $this->actor?->designation
            ?? ($this->actor === null ? 'System' : null);

        return array_filter([
            'type' => 'subtask',
            'event' => $this->event,
            'title' => $this->title,
            'body' => $this->message,
            'actor_name' => $this->actor?->name ?? 'TaskFlow',
            'actor_role' => $actorRole,
            'occurred_at' => now()->toIso8601String(),
            'task_id' => $this->task->id,
            'task_code' => $this->task->task_code,
            'task_reference' => $this->task->task_code,
            'task_title' => $this->task->task_code.' — '.$this->task->title,
            'subtask_id' => $this->subtask->id,
            'subtask_title' => $this->subtask->title,
            'deduplication_key' => $this->deduplicationKey,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
