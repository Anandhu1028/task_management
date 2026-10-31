<?php

namespace App\Http\Controllers;

use App\Models\DailyAccountabilityExemption;
use App\Models\DailyAccountabilitySetting;
use App\Models\User;
use App\Services\DailyTaskAccountabilityService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class DailyAccountabilitySettingsController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorizeAdmin($request);

        $settings = DailyAccountabilitySetting::current();
        $employees = User::query()
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'name']);
        $exemptions = DailyAccountabilityExemption::query()
            ->with(['employee:id,name', 'createdBy:id,name'])
            ->orderByDesc('starts_on')
            ->orderByDesc('id')
            ->paginate(15);

        return view('daily-accountability.settings', compact('settings', 'employees', 'exemptions'));
    }

    public function update(Request $request): RedirectResponse
    {
        $this->authorizeAdmin($request);

        $validated = $request->validate([
            'cutoff_time' => ['required', 'date_format:H:i'],
            'working_days' => ['required', 'array', 'min:1'],
            'working_days.*' => ['required', Rule::in(DailyAccountabilitySetting::DEFAULT_WORKING_DAYS)],
        ]);
        $validated['working_days'] = array_values(array_unique($validated['working_days']));

        $settings = DailyAccountabilitySetting::current();
        $settings->fill($validated)->save();

        return back()->with('success', 'Missing-update warning policy saved.');
    }

    public function storeExemption(
        Request $request,
        DailyTaskAccountabilityService $accountability,
    ): RedirectResponse {
        $this->authorizeAdmin($request);

        $validated = $request->validate([
            'employee_id' => ['nullable', 'integer', 'exists:users,id'],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'reason' => ['required', 'string', 'max:500'],
        ]);
        $validated['created_by'] = $request->user()->id;
        $validated['active'] = true;

        DB::transaction(function () use ($validated, $accountability): void {
            $exemption = DailyAccountabilityExemption::query()->create($validated);
            $accountability->applyExemptionToDueReports($exemption);
        });

        return back()->with('success', 'Missing-update warning exemption added.');
    }

    public function deactivateExemption(Request $request, DailyAccountabilityExemption $exemption): RedirectResponse
    {
        $this->authorizeAdmin($request);

        $exemption->update(['active' => false]);

        return back()->with('success', 'Missing-update warning exemption deactivated.');
    }

    private function authorizeAdmin(Request $request): void
    {
        abort_unless($request->user()->hasAssignedRole('Admin'), 403);
    }
}
