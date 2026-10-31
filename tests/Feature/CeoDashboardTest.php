<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Department;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\RoleBasedDashboardSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CeoDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected User $ceo;

    protected Company $companyA;

    protected Company $companyB;

    protected Department $deptA1;

    protected Department $deptA2;

    protected Department $deptB1;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleBasedDashboardSeeder::class);

        $this->ceo = User::query()->where('email', 'ceo@example.com')->firstOrFail();

        $this->companyA = Company::query()->where('name', 'UGI')->firstOrFail();
        $this->companyB = Company::query()->where('name', 'Northstar Digital')->firstOrFail();

        $this->deptA1 = Department::query()->where('company_id', $this->companyA->id)->firstOrFail();
        $this->deptA2 = Department::create([
            'name' => 'High Exec Team',
            'company_id' => $this->companyA->id,
            'status' => 'active',
        ]);

        $this->deptB1 = Department::query()->where('company_id', $this->companyB->id)->firstOrFail();
    }

    private function createTask(array $attributes): Task
    {
        return Task::create(array_merge([
            'task_code' => Task::generateCode(),
            'task_type' => 'process',
            'priority' => 'medium',
            'status' => 'assigned',
            'expected_output' => 'Verified standard output for tests',
            'created_by' => $this->ceo->id,
            'original_deadline' => today()->addDays(3)->toDateString(),
            'due_date' => today()->addDays(3)->toDateString(),
        ], $attributes));
    }

    public function test_ceo_dashboard_renders_company_overview_cards_with_all_metrics(): void
    {
        $response = $this->actingAs($this->ceo)->get(route('dashboard'));

        $response->assertOk();
        $response->assertViewIs('dashboard.ceo');
        $response->assertSee('Company Overview');
        $response->assertSee('UGI');
        $response->assertSee('Northstar Digital');
        $response->assertSee('Completion Rate');
        $response->assertSee('Total Tasks');
        $response->assertSee('Completed');
        $response->assertSee('Pending');
        $response->assertSee('In Progress');
        $response->assertSee('Overdue');
    }

    public function test_high_performing_departments_require_completed_tasks_and_zero_pending(): void
    {
        $now = now();

        // Dept A2: 3 tasks, all completed in current month -> High Performing
        for ($i = 0; $i < 3; $i++) {
            $this->createTask([
                'title' => "Completed Task {$i}",
                'company_id' => $this->companyA->id,
                'department_id' => $this->deptA2->id,
                'status' => 'completed',
                'completed_at' => $now,
            ]);
        }

        // Dept A1: 2 completed, 2 pending -> Low Performing
        for ($i = 0; $i < 2; $i++) {
            $this->createTask([
                'title' => "Dept A1 Completed {$i}",
                'company_id' => $this->companyA->id,
                'department_id' => $this->deptA1->id,
                'status' => 'completed',
                'completed_at' => $now,
            ]);
        }
        for ($i = 0; $i < 2; $i++) {
            $this->createTask([
                'title' => "Dept A1 Pending {$i}",
                'company_id' => $this->companyA->id,
                'department_id' => $this->deptA1->id,
                'status' => 'in_progress',
            ]);
        }

        $response = $this->actingAs($this->ceo)->get(route('dashboard', [
            'period_type' => 'monthly',
            'period_date' => $now->format('Y-m'),
        ]));

        $response->assertOk();
        $response->assertViewHas('highPerformingDepartments', function (array $depts): bool {
            foreach ($depts as $d) {
                // Must have total > 0, pending === 0, completed === total
                if ($d['total_tasks'] <= 0 || $d['pending'] !== 0 || $d['completed'] !== $d['total_tasks']) {
                    return false;
                }
            }

            $names = array_column($depts, 'name');

            return in_array('High Exec Team', $names, true) && ! in_array($this->deptA1->name, $names, true);
        });

        // Company Name must be visible for high-performing departments
        $response->assertSee('High Exec Team');
        $response->assertSee('UGI');
        $response->assertSee('High Performance');
    }

    public function test_departments_with_zero_tasks_are_not_classified_as_high_performing(): void
    {
        $emptyDept = Department::create([
            'name' => 'Empty Dormant Dept',
            'company_id' => $this->companyB->id,
            'status' => 'active',
        ]);

        $response = $this->actingAs($this->ceo)->get(route('dashboard'));

        $response->assertOk();
        $response->assertViewHas('highPerformingDepartments', function (array $depts) use ($emptyDept): bool {
            $ids = array_column($depts, 'id');

            return ! in_array($emptyDept->id, $ids, true);
        });
    }

    public function test_low_performing_departments_are_ranked_by_pending_count_descending(): void
    {
        $now = now();

        // Dept A1: 2 pending
        for ($i = 0; $i < 2; $i++) {
            $this->createTask([
                'title' => "A1 Pending {$i}",
                'company_id' => $this->companyA->id,
                'department_id' => $this->deptA1->id,
                'status' => 'in_progress',
            ]);
        }

        // Dept B1: 5 pending
        for ($i = 0; $i < 5; $i++) {
            $this->createTask([
                'title' => "B1 Pending {$i}",
                'company_id' => $this->companyB->id,
                'department_id' => $this->deptB1->id,
                'status' => 'assigned',
            ]);
        }

        $response = $this->actingAs($this->ceo)->get(route('dashboard', [
            'period_type' => 'monthly',
            'period_date' => $now->format('Y-m'),
        ]));

        $response->assertOk();
        $response->assertViewHas('lowPerformingDepartments', function (array $depts): bool {
            $this->assertNotEmpty($depts);
            // Verify descending order by pending count
            $lastPending = PHP_INT_MAX;
            foreach ($depts as $d) {
                $this->assertGreaterThan(0, $d['pending']);
                $this->assertLessThanOrEqual($lastPending, $d['pending']);
                $lastPending = $d['pending'];
            }

            return true;
        });
    }

    public function test_cancelled_tasks_are_not_counted_as_completed_nor_as_pending(): void
    {
        $now = now();
        $cleanDept = Department::create([
            'name' => 'Clean Cancel Test Dept',
            'company_id' => $this->companyA->id,
            'status' => 'active',
        ]);

        // 1 completed task
        $this->createTask([
            'title' => 'Clean Completed',
            'company_id' => $this->companyA->id,
            'department_id' => $cleanDept->id,
            'status' => 'completed',
            'completed_at' => $now,
        ]);

        // 2 cancelled tasks
        for ($i = 0; $i < 2; $i++) {
            $this->createTask([
                'title' => "Cancelled Task {$i}",
                'company_id' => $this->companyA->id,
                'department_id' => $cleanDept->id,
                'status' => 'cancelled',
                'cancelled_at' => $now,
            ]);
        }

        $response = $this->actingAs($this->ceo)->get(route('dashboard', [
            'period_type' => 'monthly',
            'period_date' => $now->format('Y-m'),
        ]));

        $response->assertOk();
        // Since cancelled is neither completed nor pending, completed=1, pending=0, rate=100%
        $response->assertViewHas('highPerformingDepartments', function (array $depts) use ($cleanDept): bool {
            $target = collect($depts)->firstWhere('id', $cleanDept->id);
            if (! $target) {
                return false;
            }

            return $target['completed'] === 1 && $target['pending'] === 0 && $target['total_tasks'] === 1;
        });
    }

    public function test_company_filter_restricts_department_performance(): void
    {
        $response = $this->actingAs($this->ceo)->get(route('dashboard', [
            'company_id' => $this->companyB->id,
        ]));

        $response->assertOk();
        $response->assertViewHas('companyTaskAnalytics', fn (array $analytics): bool => $analytics['company_id'] === $this->companyB->id);
        $response->assertViewHas('lowPerformingDepartments', function (array $depts): bool {
            foreach ($depts as $d) {
                if ($d['company_id'] !== $this->companyB->id) {
                    return false;
                }
            }

            return true;
        });
    }

    public function test_daily_weekly_monthly_reporting_period_filters_work_via_ajax_endpoint(): void
    {
        $now = now();

        // 1. Daily
        $dailyRes = $this->actingAs($this->ceo)->get(route('dashboard.data', [
            'period_type' => 'daily',
            'period_date' => $now->toDateString(),
        ]));
        $dailyRes->assertOk();
        $dailyData = $dailyRes->json('data');
        $this->assertEquals('daily', $dailyData['period']['type']);
        $this->assertEquals($now->toDateString(), $dailyData['period']['date']);
        $this->assertArrayHasKey('companyCards', $dailyData);
        $this->assertArrayHasKey('highPerformingDepartments', $dailyData);
        $this->assertArrayHasKey('lowPerformingDepartments', $dailyData);

        // 2. Weekly
        $weeklyRes = $this->actingAs($this->ceo)->get(route('dashboard.data', [
            'period_type' => 'weekly',
            'period_date' => $now->startOfWeek()->format('o-\WW'),
        ]));
        $weeklyRes->assertOk();
        $weeklyData = $weeklyRes->json('data');
        $this->assertEquals('weekly', $weeklyData['period']['type']);

        // 3. Monthly
        $monthlyRes = $this->actingAs($this->ceo)->get(route('dashboard.data', [
            'period_type' => 'monthly',
            'period_date' => $now->format('Y-m'),
        ]));
        $monthlyRes->assertOk();
        $monthlyData = $monthlyRes->json('data');
        $this->assertEquals('monthly', $monthlyData['period']['type']);
        $this->assertEquals($now->format('Y-m'), $monthlyData['period']['date']);
    }
}
