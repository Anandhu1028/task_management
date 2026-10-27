<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDepartmentRequest;
use App\Http\Requests\UpdateDepartmentRequest;
use App\Models\Company;
use App\Models\Department;
use App\Models\DepartmentDesignation;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DepartmentController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('departments.view');

        $filters = $this->validatedFilters($request);
        $departmentOptions = Department::query()->orderBy('name')->get(['id', 'name', 'company_id']);

        $departments = $this->filteredDepartmentsQuery($filters)
            ->with(['company', 'designations.roles'])
            ->withCount(['users', 'tasks'])
            ->withExists([
                'users as has_any_users' => fn (Builder $query) => $query->withTrashed(),
            ])
            ->orderBy('name')
            ->paginate((int) ($filters['per_page'] ?? 10))
            ->appends($request->query());
        $kanbanDepartments = ($filters['view'] ?? 'list') === 'kanban'
            ? $this->filteredDepartmentsQuery($filters)
                ->with(['company', 'designations.roles'])
                ->withCount(['users', 'tasks'])
                ->withExists([
                    'users as has_any_users' => fn (Builder $query) => $query->withTrashed(),
                ])
                ->orderBy('name')
                ->get()
            : collect();
        $companies = Company::query()->orderBy('name')->get(['id', 'name']);
        $counts = Department::query()
            ->selectRaw("count(*) as all_count, sum(case when status = 'active' then 1 else 0 end) as active_count, sum(case when status = 'inactive' then 1 else 0 end) as inactive_count")
            ->first();
        $activeRate = (int) $counts->all_count > 0
            ? (int) round(((int) $counts->active_count / (int) $counts->all_count) * 100)
            : 0;
        $editingDepartment = null;

        if ($request->filled('edit')) {
            $editingDepartment = Department::query()->with('designations.roles')->findOrFail($request->integer('edit'));
            $this->authorize('departments.update');
        }

        $allDesignations = DepartmentDesignation::query()->distinct()->orderBy('name')->pluck('name');
        $designationFilters = $allDesignations
            ->merge(Department::query()->whereNotNull('designation')->distinct()->orderBy('designation')->pluck('designation'))
            ->filter()
            ->unique()
            ->sort()
            ->values();
        $availableRoles = Role::query()->where('guard_name', 'web')->orderBy('name')->get(['id', 'name']);

        return view('departments.index', compact('departments', 'kanbanDepartments', 'companies', 'departmentOptions', 'designationFilters', 'counts', 'activeRate', 'editingDepartment', 'allDesignations', 'availableRoles'));
    }

    public function export(Request $request): StreamedResponse
    {
        $this->authorize('departments.view');

        $departments = $this->filteredDepartmentsQuery($this->validatedFilters($request))
            ->with('company')
            ->withCount('users')
            ->orderBy('name');

        return response()->streamDownload(function () use ($departments): void {
            $output = fopen('php://output', 'w');

            if ($output === false) {
                throw new \RuntimeException('Unable to open the CSV output stream.');
            }

            fputcsv($output, ['Department', 'Company', 'Designation', 'Description', 'Users', 'Status', 'Created Date']);

            $departments->chunk(500, function ($chunk) use ($output): void {
                foreach ($chunk as $department) {
                    fputcsv($output, [
                        $this->safeCsvValue($department->name),
                        $this->safeCsvValue($department->company?->name),
                        $this->safeCsvValue($department->designation),
                        $this->safeCsvValue($department->description),
                        $department->users_count,
                        $this->safeCsvValue(ucfirst($department->status)),
                        $department->created_at?->format('Y-m-d'),
                    ]);
                }
            });

            fclose($output);
        }, 'departments-'.now()->format('Y-m-d').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function bulkDestroy(Request $request): RedirectResponse
    {
        $this->authorize('departments.view');
        $this->authorize('departments.delete');

        $validated = $request->validate([
            'department_ids' => ['required', 'array', 'min:1', 'max:100'],
            'department_ids.*' => ['required', 'integer', 'distinct', Rule::exists('departments', 'id')->whereNull('deleted_at')],
        ]);

        $departments = Department::query()->whereIn('id', $validated['department_ids'])->get();

        foreach ($departments as $department) {
            if ($department->users()->withTrashed()->exists()) {
                return back()->with('error', $department->name.' cannot be deleted because user accounts are assigned to it.');
            }
        }

        DB::transaction(function () use ($departments): void {
            foreach ($departments as $department) {
                $department->delete();
            }
        });

        return back()->with('success', $departments->count().' department(s) deleted successfully.');
    }

    public function create(): RedirectResponse
    {
        $this->authorize('departments.create');

        return redirect()->route('departments.index', ['create' => 1]);
    }

    public function store(StoreDepartmentRequest $request): RedirectResponse
    {
        $this->authorize('departments.create');

        $validated = $request->validated();
        $designations = $validated['designations'];
        unset($validated['designations']);

        DB::transaction(function () use ($validated, $designations): void {
            $department = Department::create($validated);
            $this->syncDesignations($department, $designations);
        });

        return redirect()->route('departments.index')->with('success', 'Department created successfully.');
    }

    public function edit(Department $department): RedirectResponse
    {
        $this->authorize('departments.update');

        return redirect()->route('departments.index', ['edit' => $department->id]);
    }

    public function update(UpdateDepartmentRequest $request, Department $department): RedirectResponse
    {
        $this->authorize('departments.update');

        $validated = $request->validated();
        $designations = $validated['designations'];
        unset($validated['designations']);

        if ((int) $validated['company_id'] !== (int) $department->company_id && $department->users()->withTrashed()->exists()) {
            return back()
                ->withInput()
                ->with('error', 'This department cannot be moved because user accounts are assigned to it.');
        }

        $designationChanged = isset($validated['designation']) && $department->designation !== $validated['designation'];

        DB::transaction(function () use ($department, $validated, $designations, $designationChanged): void {
            $department->update($validated);

            if ($designationChanged) {
                User::withTrashed()
                    ->where('department_id', $department->id)
                    ->update(['designation' => $department->designation]);
            }

            $this->syncDesignations($department, $designations);
        });

        if ($designationChanged) {
            return redirect()->route('departments.index')
                ->with('success', 'Department and assigned user designations updated successfully.');
        }

        return redirect()->route('departments.index')->with('success', 'Department updated successfully.');
    }

    public function toggleStatus(Department $department): RedirectResponse
    {
        $this->authorize('departments.change_status');

        $previousStatus = $department->status;
        $status = $previousStatus === 'active' ? 'inactive' : 'active';
        $department->update(['status' => $status]);
        $message = $department->name.' is now '.$status.'.';

        return back()
            ->with('success', $message)
            ->with('operation_status', [
                'kind' => 'status',
                'user' => $department->name,
                'from' => $previousStatus,
                'to' => $status,
                'message' => $message,
            ]);
    }

    public function destroy(Department $department): RedirectResponse
    {
        $this->authorize('departments.delete');

        if ($department->users()->withTrashed()->exists()) {
            return back()->with('error', 'This department cannot be deleted because user accounts are assigned to it.');
        }

        $department->delete();

        return redirect()->route('departments.index')->with('success', 'Department deleted successfully.');
    }

    public function byCompany(Company $company): JsonResponse
    {
        abort_unless(
            auth()->user()->can('users.view')
            || auth()->user()->can('tasks.view')
            || auth()->user()->can('tasks.create')
            || auth()->user()->can('tasks.update')
            || auth()->user()->can('departments.view'),
            403
        );

        return response()->json(
            $company->departments()
                ->with('designations.roles:id,name')
                ->orderBy('name')
                ->get(['id', 'name', 'designation', 'status'])
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedFilters(Request $request): array
    {
        return $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'designation' => ['nullable', 'string', 'max:255'],
            'users' => ['nullable', Rule::in(['with', 'without'])],
            'tasks' => ['nullable', Rule::in(['with', 'without'])],
            'status' => ['nullable', 'in:active,inactive'],
            'view' => ['nullable', Rule::in(['list', 'kanban'])],
            'per_page' => ['nullable', 'in:10,25,50,100'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Department>
     */
    private function filteredDepartmentsQuery(array $filters): Builder
    {
        $query = Department::query()->with('company');

        if (! empty($filters['search'])) {
            $search = trim($filters['search']);
            $query->where(function (Builder $builder) use ($search): void {
                $builder->where('name', 'like', '%'.$search.'%')
                    ->orWhere('designation', 'like', '%'.$search.'%')
                    ->orWhere('description', 'like', '%'.$search.'%');
            });
        }

        if (! empty($filters['company_id'])) {
            $query->where('company_id', $filters['company_id']);
        }

        if (! empty($filters['department_id'])) {
            $query->whereKey($filters['department_id']);
        }

        if (! empty($filters['designation'])) {
            $designation = $filters['designation'];
            $query->where(function (Builder $builder) use ($designation): void {
                $builder->where('designation', $designation)
                    ->orWhereHas('designations', fn (Builder $designationQuery) => $designationQuery->where('name', $designation));
            });
        }

        if (($filters['users'] ?? null) === 'with') {
            $query->whereHas('users');
        } elseif (($filters['users'] ?? null) === 'without') {
            $query->whereDoesntHave('users');
        }

        if (($filters['tasks'] ?? null) === 'with') {
            $query->whereHas('tasks');
        } elseif (($filters['tasks'] ?? null) === 'without') {
            $query->whereDoesntHave('tasks');
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

    /**
     * @param  array<int, array{name: string, roles?: array<int, int|string>}>  $designations
     */
    private function syncDesignations(Department $department, array $designations): void
    {
        $designationNames = array_column($designations, 'name');
        $department->designations()->whereNotIn('name', $designationNames)->delete();

        foreach ($designations as $designationData) {
            $designation = $department->designations()->firstOrCreate([
                'name' => $designationData['name'],
            ]);
            $roleNames = $designationData['roles'] ?? [];

            $designation->roles()->sync($roleNames);
        }
    }
}
