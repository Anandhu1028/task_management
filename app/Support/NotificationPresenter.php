<?php

namespace App\Support;

use Illuminate\Notifications\DatabaseNotification;

/**
 * Turns a stored database notification into clean, human-readable display data.
 * Used by the navbar dropdown, the polling endpoint and the full notifications page.
 */
final class NotificationPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function present(DatabaseNotification $notification): array
    {
        $data = $notification->data;

        $title = (string) ($data['title'] ?? 'Task update');
        $actorName = $data['actor_name'] ?? null;
        $taskCode = $data['task_code'] ?? null;
        $taskName = self::taskName($data['task_title'] ?? null, $taskCode);
        $isSubtask = ($data['type'] ?? null) === 'subtask' || isset($data['subtask_id']);

        $taskLabel = match (true) {
            $taskCode && $taskName => $taskCode.' · '.$taskName,
            (bool) $taskCode => (string) $taskCode,
            (bool) $taskName => $taskName,
            default => null,
        };

        $message = self::tidy((string) ($data['body'] ?? ''));

        return [
            'id' => $notification->id,
            'icon' => self::icon($title, $isSubtask),
            'title' => $title,
            'message' => $message !== '' ? $message : null,
            'body' => $data['body'] ?? null,
            'task_code' => $taskCode,
            'task_title' => $taskName,
            'task_label' => $taskLabel,
            'subtask_title' => $data['subtask_title'] ?? null,
            'actor_name' => $actorName,
            'actor_role' => $data['actor_role'] ?? null,
            'url' => isset($data['task_id']) ? route('notifications.open', $notification->id) : null,
            'created_at' => $notification->created_at?->diffForHumans(),
            'created_at_exact' => $notification->created_at?->format('d M Y, h:i A'),
            'is_unread' => $notification->read_at === null,
        ];
    }

    /**
     * Subtask notifications store the task as "TSK-0003 — volume"; keep only the name.
     */
    private static function taskName(?string $stored, ?string $taskCode): ?string
    {
        if ($stored === null || trim($stored) === '') {
            return null;
        }

        if ($taskCode) {
            $stored = preg_replace('/^'.preg_quote($taskCode, '/').'\s*[—–-]\s*/u', '', $stored) ?? $stored;
        }

        return trim($stored) !== '' ? trim($stored) : null;
    }

    /**
     * Replace workflow jargon with plain wording.
     */
    private static function tidy(string $message): string
    {
        $message = str_replace(', routed through ', ', via ', $message);
        $message = str_replace('; assigned to ', ', assigned to ', $message);

        return trim($message);
    }

    private static function icon(string $title, bool $isSubtask): string
    {
        $title = strtolower($title);

        return match (true) {
            $isSubtask || str_contains($title, 'subtask') => 'bi-list-check',
            str_contains($title, 'deadline') || str_contains($title, 'extension') => 'bi-calendar-event',
            str_contains($title, 're-work') || str_contains($title, 'rework') => 'bi-arrow-repeat',
            str_contains($title, 'approved') || str_contains($title, 'complete') => 'bi-check-circle',
            str_contains($title, 'created') => 'bi-plus-circle',
            str_contains($title, 'status') => 'bi-arrow-left-right',
            default => 'bi-bell',
        };
    }
}
