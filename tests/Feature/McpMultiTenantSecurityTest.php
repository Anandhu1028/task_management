<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Department;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\User;
use App\Services\Mcp\OAuthService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class McpMultiTenantSecurityTest extends TestCase
{
    use RefreshDatabase;

    private Company $companyA;

    private Company $companyB;

    private Company $companyC;

    private Department $deptA;

    private Department $deptB;

    private Department $deptC;

    private User $ceoA;

    private User $teamLeaderA;

    private User $employeeA;

    private User $ceoB;

    private User $customerC;

    private string $ceoAToken;

    private string $teamLeaderAToken;

    private string $employeeAToken;

    private string $ceoBToken;

    private string $customerCToken;

    private Task $taskA;

    private Task $taskB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        // Setup Companies
        $this->companyA = Company::firstOrCreate(['name' => 'FOCUZ Academy'], ['status' => 'active']);
        $this->companyB = Company::firstOrCreate(['name' => 'Beta Global'], ['status' => 'active']);
        $this->companyC = Company::firstOrCreate(['name' => 'External Client Corp'], ['status' => 'active']);

        // Setup Departments
        $this->deptA = Department::firstOrCreate(['company_id' => $this->companyA->id, 'name' => 'Operations'], ['status' => 'active']);
        $this->deptB = Department::firstOrCreate(['company_id' => $this->companyB->id, 'name' => 'Product'], ['status' => 'active']);
        $this->deptC = Department::firstOrCreate(['company_id' => $this->companyC->id, 'name' => 'Client Team'], ['status' => 'active']);

        // Roles
        $roleCeo = Role::firstOrCreate(['name' => 'CEO', 'guard_name' => 'web']);
        $roleAdmin = Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);
        $roleTL = Role::firstOrCreate(['name' => 'Team Leader', 'guard_name' => 'web']);
        $roleEmp = Role::firstOrCreate(['name' => 'Employee', 'guard_name' => 'web']);

        // Company A: CEO, Team Leader, Employee
        $this->ceoA = User::factory()->create([
            'name' => 'CEO Anandhu',
            'email' => 'ceo.a@focuzacademy.in',
            'company_id' => $this->companyA->id,
            'department_id' => $this->deptA->id,
            'role_id' => $roleCeo->id,
            'status' => 'active',
        ]);
        $this->ceoA->assignRole($roleCeo);

        $this->teamLeaderA = User::factory()->create([
            'name' => 'Team Leader A',
            'email' => 'tl.a@focuzacademy.in',
            'company_id' => $this->companyA->id,
            'department_id' => $this->deptA->id,
            'role_id' => $roleTL->id,
            'status' => 'active',
        ]);
        $this->teamLeaderA->assignRole($roleTL);

        $this->employeeA = User::factory()->create([
            'name' => 'Employee A',
            'email' => 'emp.a@focuzacademy.in',
            'company_id' => $this->companyA->id,
            'department_id' => $this->deptA->id,
            'role_id' => $roleEmp->id,
            'status' => 'active',
        ]);
        $this->employeeA->assignRole($roleEmp);

        // Company B: CEO
        $this->ceoB = User::factory()->create([
            'name' => 'CEO Beta',
            'email' => 'ceo.b@betaglobal.test',
            'company_id' => $this->companyB->id,
            'department_id' => $this->deptB->id,
            'role_id' => $roleCeo->id,
            'status' => 'active',
        ]);
        $this->ceoB->assignRole($roleCeo);

        // Company C: External Customer
        $this->customerC = User::factory()->create([
            'name' => 'External Customer C',
            'email' => 'customer.c@clientcorp.test',
            'company_id' => $this->companyC->id,
            'department_id' => $this->deptC->id,
            'role_id' => $roleEmp->id,
            'status' => 'active',
        ]);
        $this->customerC->assignRole($roleEmp);

        // Tasks in Company A and Company B
        $this->taskA = Task::create([
            'task_code' => Task::generateCode(),
            'title' => 'FOCUZ Internal Operations Strategy',
            'description' => 'Restricted confidential FOCUZ operational playbook',
            'project' => 'FOCUZ Internal',
            'company_id' => $this->companyA->id,
            'department_id' => $this->deptA->id,
            'created_by' => $this->ceoA->id,
            'assigned_to' => $this->employeeA->id,
            'status' => 'in_progress',
            'priority' => 'critical',
            'estimated_hours' => 12.0,
            'expected_output' => 'Strategy deliverable document',
            'due_date' => Carbon::now()->addDays(5)->toDateString(),
        ]);

        TaskAssignment::create([
            'task_id' => $this->taskA->id,
            'user_id' => $this->employeeA->id,
            'is_active' => true,
            'assigned_at' => now(),
        ]);

        $this->taskB = Task::create([
            'task_code' => Task::generateCode(),
            'title' => 'Beta Global Confidential Product Roadmap',
            'project' => 'Beta Roadmap',
            'company_id' => $this->companyB->id,
            'department_id' => $this->deptB->id,
            'created_by' => $this->ceoB->id,
            'assigned_to' => $this->ceoB->id,
            'status' => 'assigned',
            'priority' => 'high',
            'estimated_hours' => 8.0,
            'expected_output' => 'Roadmap specifications deliverable',
            'due_date' => Carbon::now()->addDays(7)->toDateString(),
        ]);

        // OAuth tokens
        $oauthService = app(OAuthService::class);
        $client = $oauthService->createClient('ChatGPT Production Test', ['https://chatgpt.com/connector/oauth/callback']);

        $scopes = ['tasks:read', 'tasks:write', 'reports:read', 'offline_access'];
        $this->ceoAToken = $oauthService->issueToken($client->client_id, $this->ceoA, $scopes)['access_token'];
        $this->teamLeaderAToken = $oauthService->issueToken($client->client_id, $this->teamLeaderA, $scopes)['access_token'];
        $this->employeeAToken = $oauthService->issueToken($client->client_id, $this->employeeA, $scopes)['access_token'];
        $this->ceoBToken = $oauthService->issueToken($client->client_id, $this->ceoB, $scopes)['access_token'];
        $this->customerCToken = $oauthService->issueToken($client->client_id, $this->customerC, $scopes)['access_token'];
    }

    public function test_company_b_ceo_cannot_access_company_a_tasks(): void
    {
        // CEO B attempts to search for Company A's task
        $response = $this->withHeader('Authorization', 'Bearer '.$this->ceoBToken)
            ->postJson('/mcp', [
                'jsonrpc' => '2.0',
                'id' => 101,
                'method' => 'tools/call',
                'params' => [
                    'name' => 'search_tasks',
                    'arguments' => ['query' => 'FOCUZ'],
                ],
            ]);

        $response->assertOk();
        $content = json_decode($response->json('result.content.0.text'), true);
        $this->assertSame(0, $content['total_found']);
        $this->assertEmpty($content['tasks']);

        // CEO B attempts to get details of Company A's task ID
        $detailResponse = $this->withHeader('Authorization', 'Bearer '.$this->ceoBToken)
            ->postJson('/mcp', [
                'jsonrpc' => '2.0',
                'id' => 102,
                'method' => 'tools/call',
                'params' => [
                    'name' => 'get_task_details',
                    'arguments' => ['task_id' => $this->taskA->id],
                ],
            ]);

        $detailResponse->assertOk();
        $detailContent = json_decode($detailResponse->json('result.content.0.text'), true);
        $this->assertFalse($detailContent['found']);
    }

    public function test_external_customer_c_cannot_access_internal_focuz_company_a_records(): void
    {
        // Customer C searches tasks
        $response = $this->withHeader('Authorization', 'Bearer '.$this->customerCToken)
            ->postJson('/mcp', [
                'jsonrpc' => '2.0',
                'id' => 103,
                'method' => 'tools/call',
                'params' => [
                    'name' => 'get_my_tasks',
                    'arguments' => [],
                ],
            ]);

        $response->assertOk();
        $content = json_decode($response->json('result.content.0.text'), true);
        $this->assertSame(0, $content['pagination']['total_tasks']);
        $this->assertEmpty($content['tasks']);

        // Direct task details query for FOCUZ task returns not found
        $directQuery = $this->withHeader('Authorization', 'Bearer '.$this->customerCToken)
            ->postJson('/mcp', [
                'jsonrpc' => '2.0',
                'id' => 104,
                'method' => 'tools/call',
                'params' => [
                    'name' => 'get_task_details',
                    'arguments' => ['task_id' => $this->taskA->id],
                ],
            ]);

        $directContent = json_decode($directQuery->json('result.content.0.text'), true);
        $this->assertFalse($directContent['found']);
    }

    public function test_employee_without_company_context_cannot_retrieve_assigned_company_tasks(): void
    {
        $employeeRole = Role::findByName('Employee', 'web');
        $employeeWithoutCompany = User::factory()->create([
            'company_id' => null,
            'department_id' => null,
            'role_id' => $employeeRole->id,
            'status' => 'active',
        ]);
        $employeeWithoutCompany->assignRole($employeeRole);

        TaskAssignment::create([
            'task_id' => $this->taskA->id,
            'user_id' => $employeeWithoutCompany->id,
            'is_active' => true,
            'assigned_at' => now(),
        ]);

        $oauthService = app(OAuthService::class);
        $client = $oauthService->createClient('Company context test', ['https://chatgpt.com/callback']);
        $token = $oauthService->issueToken($client->client_id, $employeeWithoutCompany, ['tasks:read'])['access_token'];

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/mcp', [
                'jsonrpc' => '2.0',
                'id' => 107,
                'method' => 'tools/call',
                'params' => [
                    'name' => 'get_my_tasks',
                    'arguments' => [],
                ],
            ]);

        $response->assertOk()->assertJsonPath('result.isError', false);
        $content = json_decode($response->json('result.content.0.text'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(0, $content['pagination']['total_tasks']);
        $this->assertSame([], $content['tasks']);
    }

    public function test_chatgpt_company_id_argument_cannot_override_tenant_context(): void
    {
        // CEO B explicitly passes company_id = companyA->id in arguments
        $overrideResponse = $this->withHeader('Authorization', 'Bearer '.$this->ceoBToken)
            ->postJson('/mcp', [
                'jsonrpc' => '2.0',
                'id' => 105,
                'method' => 'tools/call',
                'params' => [
                    'name' => 'get_my_tasks',
                    'arguments' => [
                        'company_id' => $this->companyA->id,
                    ],
                ],
            ]);

        $overrideResponse->assertOk();
        $overrideContent = json_decode($overrideResponse->json('result.content.0.text'), true);
        $this->assertSame(0, $overrideContent['pagination']['total_tasks']);
        $this->assertEmpty($overrideContent['tasks']);
    }

    public function test_employee_cannot_retrieve_ceo_only_company_report(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer '.$this->employeeAToken)
            ->postJson('/mcp', [
                'jsonrpc' => '2.0',
                'id' => 106,
                'method' => 'tools/call',
                'params' => [
                    'name' => 'get_company_report',
                    'arguments' => [],
                ],
            ]);

        $response->assertOk();
        $this->assertTrue($response->json('result.isError'));
        $this->assertStringContainsString('Permission denied', $response->json('result.content.0.text'));
    }

    public function test_ceo_can_retrieve_their_own_company_report(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer '.$this->ceoAToken)
            ->postJson('/mcp', [
                'jsonrpc' => '2.0',
                'id' => 107,
                'method' => 'tools/call',
                'params' => [
                    'name' => 'get_company_report',
                    'arguments' => [],
                ],
            ]);

        $response->assertOk();
        $this->assertFalse($response->json('result.isError'));
        $content = json_decode($response->json('result.content.0.text'), true);
        $this->assertSame('FOCUZ Academy', $content['company']['name']);
        $this->assertArrayHasKey('executive_summary', $content);
        $this->assertGreaterThanOrEqual(1, $content['executive_summary']['total_tasks']);
    }

    public function test_authorized_user_can_create_task_via_mcp(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer '.$this->ceoAToken)
            ->postJson('/mcp', [
                'jsonrpc' => '2.0',
                'id' => 108,
                'method' => 'tools/call',
                'params' => [
                    'name' => 'create_task',
                    'arguments' => [
                        'title' => 'Automated Integration Task Created by MCP',
                        'description' => 'Task payload created through MCP client',
                        'priority' => 'high',
                        'due_date' => Carbon::now()->addDays(10)->toDateString(),
                        'assignee_id' => $this->employeeA->id,
                    ],
                ],
            ]);

        $response->assertOk();
        $this->assertFalse($response->json('result.isError'));
        $content = json_decode($response->json('result.content.0.text'), true);
        $this->assertTrue($content['success']);
        $this->assertSame('Automated Integration Task Created by MCP', $content['task']['title']);
        $this->assertSame($this->companyA->id, $content['task']['company_id']);

        $this->assertDatabaseHas('tasks', [
            'title' => 'Automated Integration Task Created by MCP',
            'company_id' => $this->companyA->id,
        ]);
    }

    public function test_unauthorized_user_without_permission_cannot_create_task(): void
    {
        // Employee without tasks.create permission cannot create task
        $response = $this->withHeader('Authorization', 'Bearer '.$this->employeeAToken)
            ->postJson('/mcp', [
                'jsonrpc' => '2.0',
                'id' => 109,
                'method' => 'tools/call',
                'params' => [
                    'name' => 'create_task',
                    'arguments' => [
                        'title' => 'Malicious Task Injection',
                    ],
                ],
            ]);

        $response->assertOk();
        $this->assertTrue($response->json('result.isError'));
        $this->assertStringContainsString('Permission denied', $response->json('result.content.0.text'));
    }

    public function test_assignee_can_update_task_status_within_policy(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer '.$this->employeeAToken)
            ->postJson('/mcp', [
                'jsonrpc' => '2.0',
                'id' => 110,
                'method' => 'tools/call',
                'params' => [
                    'name' => 'update_task_status',
                    'arguments' => [
                        'task_id' => $this->taskA->id,
                        'status' => 'submitted_for_review',
                        'progress_percentage' => 95,
                        'reason' => 'Completed drafting operational playbook',
                    ],
                ],
            ]);

        $response->assertOk();
        $this->assertFalse($response->json('result.isError'));
        $content = json_decode($response->json('result.content.0.text'), true);
        $this->assertTrue($content['success']);
        $this->assertSame('submitted_for_review', $content['task']['new_status']);

        $this->assertDatabaseHas('tasks', [
            'id' => $this->taskA->id,
            'status' => 'submitted_for_review',
        ]);
    }

    public function test_revoked_oauth_connection_cannot_execute_mcp_tools(): void
    {
        $oauthService = app(OAuthService::class);
        $oauthService->revokeToken($this->employeeAToken);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->employeeAToken)
            ->postJson('/mcp', [
                'jsonrpc' => '2.0',
                'id' => 111,
                'method' => 'tools/call',
                'params' => [
                    'name' => 'get_my_tasks',
                    'arguments' => [],
                ],
            ]);

        $response->assertStatus(401);
    }
}
