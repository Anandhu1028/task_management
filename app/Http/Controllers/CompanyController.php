<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCompanyRequest;
use App\Http\Requests\UpdateCompanyRequest;
use App\Models\Company;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CompanyController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', Company::class);

        $filters = $this->validatedFilters($request);

        $companies = $this->filteredCompaniesQuery($filters)
            ->withCount(['users', 'departments', 'tasks'])
            ->withExists([
                'users as has_any_users' => fn (Builder $query) => $query->withTrashed(),
                'departments as has_any_departments' => fn (Builder $query) => $query->withTrashed(),
            ])
            ->orderBy('created_at', 'desc')
            ->paginate((int) ($filters['per_page'] ?? 10))
            ->appends($request->query());
        $companyOptions = Company::query()->orderBy('name')->get(['id', 'name']);
        $counts = Company::query()
            ->selectRaw("count(*) as all_count, sum(case when status = 'active' then 1 else 0 end) as active_count, sum(case when status = 'inactive' then 1 else 0 end) as inactive_count")
            ->first();
        $activeRate = (int) $counts->all_count > 0
            ? (int) round(((int) $counts->active_count / (int) $counts->all_count) * 100)
            : 0;
        $editingCompany = null;

        if ($request->filled('edit')) {
            $editingCompany = Company::query()->findOrFail($request->integer('edit'));
            $this->authorize('update', $editingCompany);
        }

        return view('companies.index', compact('companies', 'companyOptions', 'counts', 'activeRate', 'editingCompany'));
    }

    public function export(Request $request): StreamedResponse
    {
        $this->authorize('viewAny', Company::class);

        $companies = $this->filteredCompaniesQuery($this->validatedFilters($request))
            ->withCount(['users', 'departments'])
            ->orderBy('name');

        return response()->streamDownload(function () use ($companies): void {
            $output = fopen('php://output', 'w');

            if ($output === false) {
                throw new \RuntimeException('Unable to open the CSV output stream.');
            }

            fputcsv($output, ['Company', 'Description', 'Departments', 'Users', 'Status', 'Created Date']);

            $companies->chunk(500, function ($chunk) use ($output): void {
                foreach ($chunk as $company) {
                    fputcsv($output, [
                        $this->safeCsvValue($company->name),
                        $this->safeCsvValue($company->description),
                        $company->departments_count,
                        $company->users_count,
                        $this->safeCsvValue(ucfirst($company->status)),
                        $company->created_at?->format('Y-m-d'),
                    ]);
                }
            });

            fclose($output);
        }, 'companies-'.now()->format('Y-m-d').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function bulkDestroy(Request $request): RedirectResponse
    {
        $this->authorize('viewAny', Company::class);
        $this->authorize('companies.delete');

        $validated = $request->validate([
            'company_ids' => ['required', 'array', 'min:1', 'max:100'],
            'company_ids.*' => ['required', 'integer', 'distinct', Rule::exists('companies', 'id')->whereNull('deleted_at')],
        ]);

        $companies = Company::query()->whereIn('id', $validated['company_ids'])->get();

        foreach ($companies as $company) {
            if ($company->users()->withTrashed()->exists() || $company->departments()->withTrashed()->exists()) {
                return back()->with('error', $company->name.' cannot be deleted because it has associated users or departments.');
            }
        }

        DB::transaction(function () use ($companies): void {
            foreach ($companies as $company) {
                $company->delete();
            }
        });

        return back()->with('success', $companies->count().' company account(s) deleted successfully.');
    }

    public function create(): RedirectResponse
    {
        $this->authorize('create', Company::class);

        return redirect()->route('companies.index', ['create' => 1]);
    }

    public function store(StoreCompanyRequest $request): RedirectResponse
    {
        $this->authorize('create', Company::class);

        $company = Company::create($request->validated());

        return redirect()->route('companies.index')->with('success', 'Company created successfully.');
    }

    public function show(Company $company): View
    {
        $this->authorize('view', $company);

        $company->loadCount([
            'users',
            'users as active_users_count' => fn ($query) => $query->where('status', 'active'),
            'users as inactive_users_count' => fn ($query) => $query->where('status', 'inactive'),
        ]);
        $companyUsers = $company->users()->with('department', 'roles')->paginate(10);

        return view('companies.show', compact('company', 'companyUsers'));
    }

    public function edit(Company $company): RedirectResponse
    {
        $this->authorize('update', $company);

        return redirect()->route('companies.index', ['edit' => $company->id]);
    }

    public function update(UpdateCompanyRequest $request, Company $company): RedirectResponse
    {
        $this->authorize('update', $company);

        $company->update($request->validated());

        return redirect()->route('companies.index')->with('success', 'Company updated successfully.');
    }

    public function toggleStatus(Company $company): RedirectResponse
    {
        $this->authorize('companies.change_status');

        $status = $company->status === 'active' ? 'inactive' : 'active';
        $company->update(['status' => $status]);

        return back()
            ->with('success', $company->name.' is now '.$status.'.')
            ->with('operation_status', [
                'kind' => 'status',
                'user' => $company->name,
                'from' => $status === 'active' ? 'inactive' : 'active',
                'to' => $status,
                'message' => $company->name.' is now '.$status.'.',
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedFilters(Request $request): array
    {
        return $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'status' => ['nullable', 'in:active,inactive'],
            'departments' => ['nullable', Rule::in(['with', 'without'])],
            'users' => ['nullable', Rule::in(['with', 'without'])],
            'tasks' => ['nullable', Rule::in(['with', 'without'])],
            'per_page' => ['nullable', 'in:10,25,50,100'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Company>
     */
    private function filteredCompaniesQuery(array $filters): Builder
    {
        $query = Company::query();

        if (! empty($filters['search'])) {
            $search = trim($filters['search']);
            $query->where(function (Builder $builder) use ($search): void {
                $builder->where('name', 'like', '%'.$search.'%')
                    ->orWhere('description', 'like', '%'.$search.'%');
            });
        }

        if (! empty($filters['company_id'])) {
            $query->whereKey($filters['company_id']);
        }

        foreach (['departments', 'users', 'tasks'] as $relation) {
            if (($filters[$relation] ?? null) === 'with') {
                $query->whereHas($relation);
            } elseif (($filters[$relation] ?? null) === 'without') {
                $query->whereDoesntHave($relation);
            }
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

    public function destroy(Company $company): RedirectResponse
    {
        $this->authorize('delete', $company);

        if ($company->users()->exists() || $company->departments()->exists()) {
            return back()->with('error', 'This company cannot be deleted because it still has associated users or departments. Deactivate it instead.');
        }

        $company->delete();

        return redirect()->route('companies.index')->with('success', 'Company deleted successfully.');
    }
}
