<?php

namespace App\Http\Controllers;

use App\Models\Task;
use App\Support\NotificationPresenter;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

class NotificationController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        $unreadOnly = $request->query('filter') === 'unread';

        $query = $unreadOnly ? $user->unreadNotifications() : $user->notifications();

        $notifications = $query->latest()->paginate(20)->withQueryString();
        $items = $notifications->getCollection()
            ->map(fn (DatabaseNotification $notification): array => NotificationPresenter::present($notification));

        return view('notifications.index', [
            'items' => $items,
            'paginator' => $notifications,
            'unreadOnly' => $unreadOnly,
            'unreadCount' => $user->unreadNotifications()->count(),
        ]);
    }

    public function poll(Request $request): JsonResponse
    {
        $user = $request->user();
        $notifications = $user->notifications()->latest()->limit(5)->get();

        return response()->json([
            'unread_count' => $user->unreadNotifications()->count(),
            'notifications' => $notifications
                ->map(fn (DatabaseNotification $notification): array => NotificationPresenter::present($notification))
                ->values(),
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
