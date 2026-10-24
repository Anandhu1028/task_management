<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Models\Company;
use App\Models\Department;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\TaskStatusHistory;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpFoundation\StreamedResponse;

class UserController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', User::class);

        $filters = $this->validatedUserFilters($request);

        $counts = User::query()
            ->selectRaw('count(*) as all_count')
            ->selectRaw("sum(case when status = 'active' then 1 else 0 end) as active_count")
            ->selectRaw("sum(case when status = 'inactive' then 1 else 0 end) as inactive_count")
            ->first();

        $query = $this->filteredUsersQuery($filters);
        $tab = $filters['tab'] ?? 'all';

        $users = $query
            ->orderBy('name')
            ->paginate((int) ($filters['per_page'] ?? 10))
            ->appends($filters);

        $companies = Company::query()->orderBy('name')->get();
        $departments = Department::query()
            ->with('designations.roles:id,name')
            ->where('status', 'active')
            ->orderBy('name')
            ->get();
        $departmentFilterOptions = Department::query()
            ->orderBy('name')
            ->get(['id', 'name', 'company_id']);
        $editingUser = null;

        if ($request->filled('edit')) {
            $editingUser = User::query()->findOrFail($request->integer('edit'));
            $this->authorize('update', $editingUser);
        }

        $editingUserData = $editingUser ? [
            'id' => $editingUser->id,
            'name' => $editingUser->name,
            'email' => $editingUser->email,
            'phone' => $editingUser->phone,
            'employee_id' => $editingUser->employee_id,
            'company_id' => $editingUser->company_id,
            'department_id' => $editingUser->department_id,
            'designation' => $editingUser->designation,
            'role_id' => $editingUser->role_id,
            'status' => $editingUser->status,
        ] : null;
        $activeRate = (int) $counts->all_count > 0
            ? (int) round(((int) $counts->active_count / (int) $counts->all_count) * 100)
            : 0;

        return view('users.index', compact('users', 'companies', 'departments', 'departmentFilterOptions', 'counts', 'tab', 'filters', 'editingUser', 'editingUserData', 'activeRate'));
    }

    public function export(Request $request): StreamedResponse
    {
        $this->authorize('viewAny', User::class);

        $filters = $this->validatedUserFilters($request);
        $users = $this->filteredUsersQuery($filters)->orderBy('name');

        return response()->streamDownload(function () use ($users): void {
            $output = fopen('php://output', 'w');

            if ($output === false) {
                throw new \RuntimeException('Unable to open the CSV output stream.');
            }

            fputcsv($output, ['User', 'Employee ID', 'Company', 'Department', 'Designation', 'Phone', 'Email', 'Status']);

            $users->chunk(500, function ($chunk) use ($output): void {
                foreach ($chunk as $user) {
                    fputcsv($output, [
                        $this->safeCsvValue($user->name),
                        $this->safeCsvValue($user->employee_id),
                        $this->safeCsvValue($user->company?->name),
                        $this->safeCsvValue($user->department?->name),
                        $this->safeCsvValue($user->designation),
                        $this->safeCsvValue($user->phone),
                        $this->safeCsvValue($user->email),
                        $this->safeCsvValue(ucfirst($user->status)),
                    ]);
                }
            });

            fclose($output);
        }, 'users-'.now()->format('Y-m-d').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function bulkDestroy(Request $request): RedirectResponse
    {
        $this->authorize('viewAny', User::class);
        $this->authorize('users.delete');

        $validated = $request->validate([
            'user_ids' => ['required', 'array', 'min:1', 'max:100'],
            'user_ids.*' => ['required', 'integer', 'distinct', 'exists:users,id'],
        ]);

        $users = User::query()->whereIn('id', $validated['user_ids'])->get();
        $currentUser = $request->user();

        foreach ($users as $user) {
            $this->authorize('delete', $user);

            if ($currentUser !== null && $user->id === $currentUser->id) {
                return back()->with('error', 'You cannot delete your own account.');
            }

            if ($user->hasRole('Admin')) {
                return back()->with('error', 'Protected Admin accounts cannot be deleted.');
            }
        }

        DB::transaction(function () use ($users): void {
            foreach ($users as $user) {
                $user->delete();
            }
        });

        return back()->with('success', $users->count().' user account(s) deleted successfully.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedUserFilters(Request $request): array
    {
        return $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'employee_id' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'phone_presence' => ['nullable', Rule::in(['with', 'without'])],
            'tasks' => ['nullable', Rule::in(['with', 'without'])],
            'designation' => ['nullable', 'string', 'max:255'],
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'tab' => ['nullable', Rule::in(['all', 'active', 'inactive'])],
            'per_page' => ['nullable', Rule::in([10, 25, 50, 100])],
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<User>
     */
    private function filteredUsersQuery(array $filters): Builder
    {
        $query = User::query()
            ->with(['company', 'department', 'roles'])
            ->withCount('assignedTasks');

        if (! empty($filters['search'])) {
            $search = trim($filters['search']);
            $query->where(function (Builder $builder) use ($search): void {
                $builder->where('name', 'like', "%{$search}%")
                    ->orWhere('employee_id', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        foreach (['employee_id', 'email', 'phone', 'designation'] as $column) {
            if (! empty($filters[$column])) {
                $query->where($column, 'like', '%'.$filters[$column].'%');
            }
        }

        foreach (['company_id', 'department_id'] as $column) {
            if (! empty($filters[$column])) {
                $query->where($column, $filters[$column]);
            }
        }

        if (($filters['phone_presence'] ?? null) === 'with') {
            $query->whereNotNull('phone')->where('phone', '!=', '');
        } elseif (($filters['phone_presence'] ?? null) === 'without') {
            $query->where(function (Builder $builder): void {
                $builder->whereNull('phone')->orWhere('phone', '');
            });
        }

        if (($filters['tasks'] ?? null) === 'with') {
            $query->has('assignedTasks');
        } elseif (($filters['tasks'] ?? null) === 'without') {
            $query->doesntHave('assignedTasks');
        }

        $tab = $filters['tab'] ?? 'all';

        if ($tab === 'active') {
            $query->where('status', 'active');
        } elseif ($tab === 'inactive') {
            $query->where('status', 'inactive');
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query;
    }

    private function safeCsvValue(mixed $value): string
    {
        $value = (string) ($value ?? '');

        return preg_match('/^[\t\r ]*[=+\-@]/', $value) === 1 ? "'".$value : $value;
    }

    public function create(): RedirectResponse
    {
        $this->authorize('create', User::class);

        return redirect()->route('users.index', ['create' => 1]);
    }

    public function store(StoreUserRequest $request): RedirectResponse
    {
        $this->authorize('create', User::class);

        $validated = $request->validated();

        $department = $this->resolveUserDepartment(
            $validated['department_id'],
            $validated['company_id'],
            $validated['designation'],
            $validated['role_id'],
        );

        if (! $department) {
            return back()->withErrors(['department_id' => 'Select an active department with a designation configured for the chosen company.'])->withInput();
        }

        $validated['designation'] = trim($validated['designation']);
        $validated['password'] = Hash::make($validated['password']);
        $role = Role::query()->where('guard_name', 'web')->findOrFail($validated['role_id']);

        $user = DB::transaction(function () use ($validated, $role): User {
            $user = User::create($validated);
            $user->syncRoles([$role]);

            return $user;
        });

        return redirect()->route('users.index')->with('success', 'User created successfully.');
    }

    public function show(User $user): View
    {
        $this->authorize('view', $user);

        $user->load(['company', 'department', 'roles', 'primaryRole']);

        $today = today()->toDateString();
        $weekStart = now()->startOfWeek()->toDateString();
        $weekEnd = now()->endOfWeek()->toDateString();
        $assignedTasksQuery = Task::query()->assignedTo($user->id);
        $taskCounts = (clone $assignedTasksQuery)
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed")
            ->selectRaw("SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress")
            ->selectRaw("SUM(CASE WHEN status IN ('notified', 'unassigned', 'assigned') THEN 1 ELSE 0 END) as pending_assignment")
            ->selectRaw("SUM(CASE WHEN status NOT IN ('completed', 'cancelled') AND COALESCE(due_date, original_deadline) < ? THEN 1 ELSE 0 END) as overdue", [$today])
            ->selectRaw("SUM(CASE WHEN status = 'cancelled' OR (status = 'on_hold' AND (COALESCE(due_date, original_deadline) IS NULL OR COALESCE(due_date, original_deadline) >= ?)) THEN 1 ELSE 0 END) as other", [$today])
            ->selectRaw("SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled")
            ->first();

        $taskCounts->total = (int) $taskCounts->total;
        $taskCounts->completed = (int) $taskCounts->completed;
        $taskCounts->in_progress = (int) $taskCounts->in_progress;
        $taskCounts->pending_assignment = (int) $taskCounts->pending_assignment;
        $taskCounts->overdue = (int) $taskCounts->overdue;
        $taskCounts->other = (int) $taskCounts->other;
        $taskCounts->open = max(0, $taskCounts->total - $taskCounts->completed - (int) $taskCounts->cancelled);

        $taskCounts->completion_rate = $taskCounts->total > 0
            ? (int) round(($taskCounts->completed / $taskCounts->total) * 100)
            : 0;
        $taskCounts->completed_width = $taskCounts->total > 0
            ? ($taskCounts->completed / $taskCounts->total) * 100
            : 0;
        $taskCounts->in_progress_width = $taskCounts->total > 0
            ? ($taskCounts->in_progress / $taskCounts->total) * 100
            : 0;
        $taskCounts->pending_assignment_width = $taskCounts->total > 0
            ? ($taskCounts->pending_assignment / $taskCounts->total) * 100
            : 0;
        $taskCounts->overdue_width = $taskCounts->total > 0
            ? ($taskCounts->overdue / $taskCounts->total) * 100
            : 0;
        $taskCounts->other_width = $taskCounts->total > 0
            ? ($taskCounts->other / $taskCounts->total) * 100
            : 0;

        $assignedTasks = (clone $assignedTasksQuery)
            ->with(['company:id,name', 'department:id,name'])
            ->orderByRaw('CASE WHEN COALESCE(due_date, original_deadline) IS NULL THEN 1 ELSE 0 END')
            ->orderByRaw('COALESCE(due_date, original_deadline) ASC')
            ->orderByDesc('created_at')
            ->limit(6)
            ->get();

        $workloadHours = (float) (clone $assignedTasksQuery)
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->where(function (Builder $query) use ($weekEnd): void {
                $query->whereNull('start_date')
                    ->orWhereDate('start_date', '<=', $weekEnd);
            })
            ->where(function (Builder $query) use ($weekStart): void {
                $query->whereRaw('COALESCE(due_date, original_deadline) IS NULL')
                    ->orWhereRaw('COALESCE(due_date, original_deadline) >= ?', [$weekStart]);
            })
            ->sum('estimated_hours');
        $workloadCapacity = 40;
        $workloadPercentage = min(100, (int) round(($workloadHours / $workloadCapacity) * 100));

        $statusActivity = TaskStatusHistory::query()
            ->whereHas('task', fn (Builder $query) => $query->assignedTo($user->id))
            ->with(['task:id,task_code,title', 'changedBy:id,name'])
            ->latest()
            ->limit(8)
            ->get()
            ->map(fn (TaskStatusHistory $history): array => [
                'at' => $history->created_at,
                'icon' => 'bi-arrow-repeat',
                'tone' => 'progress',
                'task' => $history->task,
                'title' => 'Status updated',
                'description' => $history->old_status
                    ? 'Moved from '.ucwords(str_replace('_', ' ', $history->old_status)).' to '.ucwords(str_replace('_', ' ', $history->new_status))
                    : 'Marked '.ucwords(str_replace('_', ' ', $history->new_status)),
                'actor' => $history->changedBy?->name,
            ]);

        $assignmentActivity = TaskAssignment::query()
            ->where('user_id', $user->id)
            ->with(['task:id,task_code,title', 'assignedBy:id,name'])
            ->latest('assigned_at')
            ->limit(8)
            ->get()
            ->map(fn (TaskAssignment $assignment): array => [
                'at' => $assignment->assigned_at,
                'icon' => 'bi-person-plus',
                'tone' => 'assignment',
                'task' => $assignment->task,
                'title' => 'Task assigned',
                'description' => $assignment->assignedBy
                    ? 'Assigned by '.$assignment->assignedBy->name
                    : 'Assigned to '.$user->name,
                'actor' => null,
            ]);

        $recentActivity = $statusActivity
            ->concat($assignmentActivity)
            ->sortByDesc('at')
            ->take(5)
            ->values();

        return view('users.show', compact(
            'user',
            'taskCounts',
            'assignedTasks',
            'workloadHours',
            'workloadCapacity',
            'workloadPercentage',
            'recentActivity',
        ));
    }

    public function edit(User $user): RedirectResponse
    {
        $this->authorize('update', $user);

        return redirect()->route('users.index', ['edit' => $user->id]);
    }

    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        $this->authorize('update', $user);

        $validated = $request->validated();

        $department = $this->resolveUserDepartment(
            $validated['department_id'],
            $validated['company_id'],
            $validated['designation'],
            $validated['role_id'],
            $user->department_id
        );

        if (! $department) {
            return back()->withErrors(['department_id' => 'Select an active department with a designation configured for the chosen company.'])->withInput();
        }

        $validated['designation'] = trim($validated['designation']);

        if (! empty($validated['password'])) {
            $validated['password'] = Hash::make($validated['password']);
        } else {
            unset($validated['password']);
        }

        if ($user->requires_approval && $validated['status'] === 'active') {
            $validated['requires_approval'] = false;
        }

        $role = Role::query()->where('guard_name', 'web')->findOrFail($validated['role_id']);

        DB::transaction(function () use ($user, $validated, $role): void {
            $user->update($validated);
            $user->syncRoles([$role]);
        });

        return redirect()->route('users.index')
            ->with('success', 'User updated successfully.')
            ->with('operation_status', [
                'kind' => 'update',
                'user' => $user->name,
                'message' => $user->name.' information was updated successfully.',
            ]);
    }

    public function toggleStatus(User $user): RedirectResponse
    {
        $this->authorize('changeStatus', $user);

        if ($user->id === auth()->id()) {
            return back()->with('error', 'You cannot change your own account status.');
        }

        if ($user->hasRole('Admin')) {
            return back()->with('error', 'Protected Admin account status cannot be changed.');
        }

        $previousStatus = $user->status;
        $newStatus = $previousStatus === 'active' ? 'inactive' : 'active';

        $user->update([
            'status' => $newStatus,
            'requires_approval' => $newStatus === 'active' ? false : $user->requires_approval,
        ]);

        return back()
            ->with('success', $user->name.' account is now '.$newStatus.'.')
            ->with('operation_status', [
                'kind' => 'status',
                'user' => $user->name,
                'from' => $previousStatus,
                'to' => $newStatus,
                'message' => $user->name.' is now '.$newStatus.'.',
            ]);
    }

    public function destroy(User $user): RedirectResponse
    {
        $this->authorize('delete', $user);

        if ($user->id === auth()->id()) {
            return back()->with('error', 'You cannot delete your own account while signed in.');
        }

        if ($user->hasRole('Admin')) {
            return back()->with('error', 'Protected Admin accounts cannot be deleted.');
        }

        $user->delete();

        return redirect()->route('users.index')->with('success', 'User deleted successfully.');
    }

    private function resolveUserDepartment(
        int $departmentId,
        int $companyId,
        string $designation,
        int $roleId,
        ?int $currentDepartmentId = null,
    ): ?Department {
        $department = Department::query()
            ->with('designations.roles:id')
            ->where('company_id', $companyId)
            ->find($departmentId);

        if (! $department) {
            return null;
        }

        $selectedDesignation = $department->designations->firstWhere('name', $designation);
        $designationIsConfigured = $selectedDesignation?->roles->contains('id', $roleId) ?? false;

        if (! $designationIsConfigured) {
            return null;
        }

        $isCurrentInactiveDepartment = (int) $department->id === $currentDepartmentId
            && $department->status === 'inactive';

        if ($department->status !== 'active' && ! $isCurrentInactiveDepartment) {
            return null;
        }

        return $department;
    }
}
