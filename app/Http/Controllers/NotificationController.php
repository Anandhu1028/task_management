<?php

namespace App\Http\Controllers;

use App\Models\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

class NotificationController extends Controller
{
    public function poll(Request $request): JsonResponse
    {
        $user = $request->user();
        $notifications = $user->notifications()->latest()->limit(5)->get();

        return response()->json([
            'unread_count' => $user->unreadNotifications()->count(),
            'notifications' => $notifications->map(fn (DatabaseNotification $notification): array => [
                'id' => $notification->id,
                'title' => $notification->data['title'] ?? 'Task update',
                'task_title' => $notification->data['task_title'] ?? null,
                'body' => $notification->data['body'] ?? null,
                'actor_name' => $notification->data['actor_name'] ?? null,
                'actor_role' => $notification->data['actor_role'] ?? null,
                'subtask_title' => $notification->data['subtask_title'] ?? null,
                'url' => isset($notification->data['task_id'])
                    ? route('notifications.open', $notification->id)
                    : null,
                'created_at' => $notification->created_at?->diffForHumans(),
                'is_unread' => $notification->read_at === null,
            ])->values(),
        ]);
    }

    public function open(Request $request, string $notification): RedirectResponse
    {
        $notificationRecord = $request->user()
            ->notifications()
            ->findOrFail($notification);
        $taskId = $notificationRecord->data['task_id'] ?? null;
        abort_unless(is_int($taskId) || (is_string($taskId) && ctype_digit($taskId)), 404);
        $task = Task::query()->findOrFail($taskId);
        $this->authorize('view', $task);
        $notificationRecord->markAsRead();

        $subtaskId = $notificationRecord->data['subtask_id'] ?? null;
        $url = route('tasks.show', $task);
        if ($subtaskId && $task->subtasks()->whereKey($subtaskId)->exists()) {
            $url .= '?subtask='.$subtaskId.'#subtask-'.$subtaskId;
        }

        return redirect()->to($url);
    }

    public function markAllRead(Request $request): RedirectResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return back();
    }
}
