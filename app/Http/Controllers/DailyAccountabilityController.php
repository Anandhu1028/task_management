<?php

namespace App\Http\Controllers;

use App\Models\DailyAccountabilityEvent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DailyAccountabilityController extends Controller
{
    public function index(Request $request): View
    {
        $request->merge([
            'from_date' => $request->input('from_date', today()->subDays(29)->toDateString()),
            'to_date' => $request->input('to_date', today()->toDateString()),
        ]);

        $filters = $request->validate([
            'from_date' => ['required', 'date_format:Y-m-d'],
            'to_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:from_date'],
        ]);

        $user = $request->user();
        $canViewTeam = $user->hasAssignedRole('Admin')
            || $user->hasAssignedRole('CEO')
            || $user->hasAssignedRole('Manager')
            || $user->hasAssignedRole('Team Leader')
            || $user->can('daily_tasks.view_team');

        $eventsQuery = DailyAccountabilityEvent::query()
            ->with(['task', 'dailyTask', 'employee'])
            ->whereBetween('report_date', [$filters['from_date'], $filters['to_date']])
            ->where('event_type', 'missed')
            ->whereHas('task', fn (Builder $query) => $query->visibleTo($user));

        if (! $canViewTeam) {
            $eventsQuery->where('employee_id', $user->id);
        }

        $summary = (clone $eventsQuery)
            ->reorder()
            ->selectRaw('COUNT(*) as missed_count')
            ->first();

        $events = $eventsQuery
            ->orderByDesc('report_date')
            ->orderByDesc('created_at')
            ->paginate(30)
            ->withQueryString();

        return view('daily-accountability.index', compact('events', 'filters', 'summary', 'canViewTeam'));
    }
}
