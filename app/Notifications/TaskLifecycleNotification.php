<?php

namespace App\Notifications;

use App\Models\Task;
use Illuminate\Notifications\Notification;

class TaskLifecycleNotification extends Notification
{
    public function __construct(
        public Task $task,
        public string $actorName,
        public string $title,
        public string $message,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, int|string>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => $this->title,
            'body' => $this->message,
            'actor_name' => $this->actorName,
            'task_id' => $this->task->id,
            'task_code' => $this->task->task_code,
            'task_title' => $this->task->title,
        ];
    }
}
