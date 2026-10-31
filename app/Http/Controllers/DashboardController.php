<?php

namespace App\Services;

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\DashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __construct(
        protected DashboardService $dashboardService
    ) {}

    /**
     * Render role-based dashboard or return filtered JSON statistics.
     */
    public function index(Request $request): View|JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        // Server-side role resolution - never trust client input
        $role = DashboardService::resolveUserRole($user);

        // Filter and sanitize request parameters according to authorized role
        $validatedFilters = $this->validateAndAuthorizeFilters($request, $user, $role);

        // Fetch authoritative database statistics
        $data = $this->dashboardService->getDashboardData($user, $role, $validatedFilters);

        if ($request->wantsJson() || $request->ajax() || $request->has('json')) {
            return response()->json([
                'success' => true,
                'role' => $role,
                'data' => $data,
            ]);
        }

        return match ($role) {
            'Admin' => view('dashboard.admin', $data),
            'CEO' => view('dashboard.ceo', $data),
            'Team Leader' => view('dashboard.team-leader', $data),
            default => view('dashboard.employee', $data),
        };
    }

    /**
     * Dedicated JSON endpoint for asynchronous dashboard updates and chart filtering.
     */
    public function data(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $role = DashboardService::resolveUserRole($user);
        $validatedFilters = $this->validateAndAuthorizeFilters($request, $user, $role);

        $data = $this->dashboardService->getDashboardData($user, $role, $validatedFilters);

        return response()->json([
            'success' => true,
            'role' => $role,
            'data' => $data,
        ]);
    }

    /**
     * Validate and strictly scope filter inputs based on server-side role authorization.
     *
     * @return array<string, mixed>
     */
    private function validateAndAuthorizeFilters(Request $request, User $user, string $role): array
    {
        $allowedDateRanges = ['today', 'this_week', 'last_7_days', 'this_month', 'last_30_days', 'custom', 'daily', 'weekly', 'monthly'];
        $allowedPeriods = ['daily', 'weekly', 'monthly'];

        $validated = $request->validate([
            'date_range' => ['nullable', 'string', 'in:'.implode(',', $allowedDateRanges)],
            'period_type' => ['nullable', 'string', 'in:'.implode(',', $allowedPeriods)],
            'period_date' => ['nullable', 'string', 'max:50'],
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date'],
            'company_id' => ['nullable', 'integer'],
            'department_id' => ['nullable', 'integer'],
            'team_leader_id' => ['nullable', 'integer'],
            'employee_id' => ['nullable', 'integer'],
        ]);

        $defaultRange = $role === 'CEO' ? 'this_month' : 'last_7_days';
        $defaultPeriod = $role === 'CEO' ? 'monthly' : null;

        $filters = [
            'date_range' => $validated['date_range'] ?? $defaultRange,
            'period_type' => $validated['period_type'] ?? $defaultPeriod,
            'period_date' => $validated['period_date'] ?? null,
            'from_date' => $validated['from_date'] ?? null,
            'to_date' => $validated['to_date'] ?? null,
        ];

        // Synchronize period_type and date_range if one is provided
        if (! empty($filters['period_type']) && empty($validated['date_range'])) {
            $filters['date_range'] = match ($filters['period_type']) {
                'daily' => 'today',
                'weekly' => 'this_week',
                'monthly' => 'this_month',
                default => $filters['date_range'],
            };
        } elseif (! empty($filters['date_range']) && empty($filters['period_type'])) {
            $filters['period_type'] = match ($filters['date_range']) {
                'today', 'daily' => 'daily',
                'this_week', 'weekly' => 'weekly',
                'this_month', 'monthly' => 'monthly',
                default => 'monthly',
            };
        }

        if (in_array($role, ['Admin', 'CEO'], true)) {
            $filters['company_id'] = ! empty($validated['company_id']) ? (int) $validated['company_id'] : null;
            $filters['department_id'] = ! empty($validated['department_id']) ? (int) $validated['department_id'] : null;
            $filters['team_leader_id'] = ! empty($validated['team_leader_id']) ? (int) $validated['team_leader_id'] : null;
            $filters['employee_id'] = ! empty($validated['employee_id']) ? (int) $validated['employee_id'] : null;
        } elseif ($role === 'Team Leader') {
            // Team Leader is restricted strictly to their own department
            $filters['department_id'] = $user->department_id;
            $filters['company_id'] = $user->company_id;

            // TL can only filter by an employee inside their own department
            if (! empty($validated['employee_id'])) {
                $isDeptMember = User::query()
                    ->where('id', $validated['employee_id'])
                    ->where('department_id', $user->department_id)
                    ->exists();

                $filters['employee_id'] = $isDeptMember ? (int) $validated['employee_id'] : null;
            }
        } else {
            // Employee is strictly restricted to themselves
            $filters['employee_id'] = $user->id;
        }

        return $filters;
    }
}
