<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Department;
use App\Models\Subtask;
use App\Models\Task;
use App\Models\User;
use App\Support\DesktopApiPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DesktopMetaController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->authorize('viewAny', Task::class);

        $isGlobalViewer = $user->hasAssignedRole('Admin') || $user->hasAssignedRole('CEO');

        $companies = Company::query()
            ->where('status', 'active')
            ->when(! $isGlobalViewer && $user->company_id, fn ($query) => $query->whereKey($user->company_id))
            ->orderBy('name')
            ->get(['id', 'name']);

        $departments = Department::query()
            ->where('status', 'active')
            ->when(! $isGlobalViewer && $user->company_id, fn ($query) => $query->where('company_id', $user->company_id))
            ->when(
                ($user->hasAssignedRole('Team Leader') || $user->hasAssignedRole('Employee'))
                    && $user->department_id
                    && ! $isGlobalViewer,
                fn ($query) => $query->whereKey($user->department_id),
            )
            ->orderBy('name')
            ->get(['id', 'name', 'company_id']);

        $people = User::query()
            ->where('status', 'active')
            ->when(! $isGlobalViewer && $user->company_id, fn ($query) => $query->where('company_id', $user->company_id))
            ->when(
                ($user->hasAssignedRole('Team Leader') || $user->hasAssignedRole('Employee'))
                    && $user->department_id
                    && ! $isGlobalViewer,
                fn ($query) => $query->where('department_id', $user->department_id),
            )
            ->orderBy('name')
            ->get(['id', 'name', 'designation', 'company_id', 'department_id']);

        return response()->json([
            'user' => DesktopApiPresenter::user($user),
            'companies' => $companies,
            'departments' => $departments,
            'people' => DesktopApiPresenter::people($people),
            'statuses' => collect(Task::STATUSES)->map(fn (string $status): array => [
                'value' => $status,
                'label' => Task::STATUS_LABELS[$status],
            ])->values()->all(),
            'priorities' => collect(Task::PRIORITIES)->map(fn (string $priority): array => [
                'value' => $priority,
                'label' => ucfirst($priority),
            ])->values()->all(),
            'task_types' => collect(Task::TYPES)->map(fn (string $type): array => [
                'value' => $type,
                'label' => ucfirst($type),
            ])->values()->all(),
            'deadline_conditions' => collect(Task::DEADLINE_CONDITIONS)->map(fn (string $condition): array => [
                'value' => $condition,
                'label' => Task::DEADLINE_CONDITION_LABELS[$condition],
            ])->values()->all(),
            'subtask_statuses' => collect(Subtask::STATUSES)->map(fn (string $status): array => [
                'value' => $status,
                'label' => Subtask::STATUS_LABELS[$status],
            ])->values()->all(),
            'subtask_priorities' => Subtask::PRIORITIES,
        ]);
    }
}
