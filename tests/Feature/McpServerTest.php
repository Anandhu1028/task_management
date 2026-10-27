<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\DeadlineExtensionRequest;
use App\Models\Department;
use App\Models\Subtask;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\User;
use App\Services\Mcp\OAuthService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class McpServerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $manager;

    private User $teamLeader;

    private User $employee1;

    private User $employee2;

    private Company $company1;

    private Company $company2;

    private Department $dept1;

    private Department $dept2;

    private string $employee1Token;

    private string $managerToken;

    private string $teamLeaderToken;

    private string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->company1 = Company::firstOrCreate(['name' => 'Acme Global'], ['status' => 'active']);
        $this->company2 = Company::firstOrCreate(['name' => 'Beta Industries'], ['status' => 'active']);

        $this->dept1 = Department::firstOrCreate(['company_id' => $this->company1->id, 'name' => 'Operations'], ['status' => 'active']);
        $this->dept2 = Department::firstOrCreate(['company_id' => $this->company2->id, 'name' => 'Research'], ['status' => 'active']);

        $roleAdmin = Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);
        $roleManager = Role::firstOrCreate(['name' => 'Manager', 'guard_name' => 'web']);
        $roleTL = Role::firstOrCreate(['name' => 'Team Leader', 'guard_name' => 'web']);
        $roleEmp = Role::firstOrCreate(['name' => 'Employee', 'guard_name' => 'web']);

        $this->admin = User::firstOrCreate(['email' => 'admin@example.com'], [
            'name' => 'Admin User',
            'company_id' => $this->company1->id,
            'department_id' => $this->dept1->id,
            'role_id' => $roleAdmin->id,
            'status' => 'active',
        ]);
        $this->admin->assignRole($roleAdmin);

        $this->manager = User::factory()->create([
            'company_id' => $this->company1->id,
            'department_id' => $this->dept1->id,
            'role_id' => $roleManager->id,
            'status' => 'active',
        ]);
        $this->manager->assignRole($roleManager);

        $this->teamLeader = User::factory()->create([
            'company_id' => $this->company1->id,
            'department_id' => $this->dept1->id,
            'role_id' => $roleTL->id,
            'status' => 'active',
        ]);
        $this->teamLeader->assignRole($roleTL);

        $this->employee1 = User::factory()->create([
            'company_id' => $this->company1->id,
            'department_id' => $this->dept1->id,
            'role_id' => $roleEmp->id,
            'status' => 'active',
        ]);
        $this->employee1->assignRole($roleEmp);

        $this->employee2 = User::factory()->create([
            'company_id' => $this->company2->id,
            'department_id' => $this->dept2->id,
            'role_id' => $roleEmp->id,
            'status' => 'active',
        ]);
        $this->employee2->assignRole($roleEmp);

        $oauthService = app(OAuthService::class);
        $client = $oauthService->createClient('Test Client', ['https://chatgpt.com/callback']);

        $this->employee1Token = $oauthService->issueToken($client->client_id, $this->employee1, ['tasks:read', 'reports:read'])['access_token'];
        $this->managerToken = $oauthService->issueToken($client->client_id, $this->manager, ['tasks:read', 'reports:read'])['access_token'];
        $this->teamLeaderToken = $oauthService->issueToken($client->client_id, $this->teamLeader, ['tasks:read', 'reports:read'])['access_token'];
        $this->adminToken = $oauthService->issueToken($client->client_id, $this->admin, ['tasks:read', 'reports:read'])['access_token'];
    }

    public function test_mcp_info_endpoint_returns_server_metadata(): void
    {
        $response = $this->getJson('/mcp');

        $response->assertOk()
            ->assertJson([
                'status' => 'active',
                'transport' => 'streamable_http',
            ])
            ->assertJsonStructure(['tools_count', 'server', 'protocol_version']);
    }

    public function test_post_mcp_without_token_returns_401(): void
    {
        $response = $this->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'ping',
        ]);

        $response->assertStatus(401)
            ->assertJsonPath('error.code', -32001);
    }

    public function test_post_mcp_with_invalid_token_returns_401(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer invalid_or_forged_token')
            ->postJson('/mcp', [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'ping',
            ]);

        $response->assertStatus(401);
    }

    public function test_mcp_initialize_and_ping(): void
    {
        $initResponse = $this->withHeader('Authorization', 'Bearer '.$this->employee1Token)
            ->postJson('/mcp', [
                'jsonrpc' => '2.0',
                'id' => 10,
                'method' => 'initialize',
                'params' => [
                    'protocolVersion' => '2024-11-05',
                    'clientInfo' => ['name' => 'ChatGPT', 'version' => '1.0'],
                ],
            ]);

        $initResponse->assertOk()
            ->assertJson([
                'jsonrpc' => '2.0',
                'id' => 10,
                'result' => [
                    'protocolVersion' => '2024-11-05',
                    'capabilities' => ['tools' => ['listChanged' => false]],
                ],
            ]);

        $pingResponse = $this->withHeader('Authorization', 'Bearer '.$this->employee1Token)
            ->postJson('/mcp', [
                'jsonrpc' => '2.0',
                'id' => 11,
                'method' => 'ping',
            ]);

        $pingResponse->assertOk()
            ->assertJson([
                'jsonrpc' => '2.0',
                'id' => 11,
                'result' => [],
            ]);
    }

    public function test_mcp_tools_list_returns_all_nine_tools(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer '.$this->employee1Token)
            ->postJson('/mcp', [
                'jsonrpc' => '2.0',
                'id' => 12,
                'method' => 'tools/list',
            ]);

        $response->assertOk();
        $tools = $response->json('result.tools');
        $this->assertIsArray($tools);
        $toolNames = array_column($tools, 'name');

        $expectedTools = [
            'get_my_tasks',
            'search_tasks',
            'get_task_details',
            'get_task_report',
            'get_overdue_tasks',
            'get_team_report',
            'get_subtask_report',
            'get_deadline_requests',
            'get_project_report',
        ];

        foreach ($expectedTools as $expected) {
            $this->assertContains($expected, $toolNames);
        }
    }

    private function createTask(array $attributes = []): Task
    {
        return Task::create(array_merge([
            'task_code' => Task::generateCode(),
            'title' => 'Default Test Task',
            'company_id' => $this->company1->id,
            'department_id' => $this->dept1->id,
            'task_type' => 'process',
            'status' => 'assigned',
            'priority' => 'medium',
            'estimated_hours' => 4.0,
            'expected_output' => 'Completed deliverable',
            'created_by' => $this->admin->id,
        ], $attributes));
    }

    public function test_get_my_tasks_tool(): void
    {
        $task = $this->createTask([
            'title' => 'My Personal Priority Task',
            'project' => 'Titan Project',
            'status' => 'in_progress',
            'priority' => 'high',
            'assigned_to' => $this->employee1->id,
            'due_date' => Carbon::now()->addDays(3)->toDateString(),
        ]);

        TaskAssignment::create([
            'task_id' => $task->id,
            'user_id' => $this->employee1->id,
            'is_active' => true,
            'assigned_at' => now(),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->employee1Token)
            ->postJson('/mcp', [
                'jsonrpc' => '2.0',
                'id' => 20,
                'method' => 'tools/call',
                'params' => [
                    'name' => 'get_my_tasks',
                    'arguments' => [
                        'status' => 'in_progress',
                        'project' => 'Titan',
                    ],
                ],
            ]);

        $response->assertOk();
        $content = json_decode($response->json('result.content.0.text'), true);

        $this->assertNotEmpty($content['tasks']);
        $this->assertSame('My Personal Priority Task', $content['tasks'][0]['title']);
        $this->assertSame('Titan Project', $content['tasks'][0]['project']);
    }

    public function test_search_tasks_tool_and_isolation(): void
    {
        // Task in company 1
        $task1 = $this->createTask([
            'title' => 'Quarterly Operations Audit',
            'description' => 'Review all logistics pipelines',
            'project' => 'FinanceReview',
            'status' => 'assigned',
            'priority' => 'critical',
            'assigned_to' => $this->employee1->id,
            'due_date' => Carbon::now()->addDays(5)->toDateString(),
        ]);

        // Task in company 2 (isolated)
        $task2 = $this->createTask([
            'title' => 'Secret Project Beta Logistics Audit',
            'company_id' => $this->company2->id,
            'department_id' => $this->dept2->id,
            'project' => 'Confidential',
            'status' => 'assigned',
            'priority' => 'medium',
            'assigned_to' => $this->employee2->id,
            'created_by' => $this->employee2->id,
        ]);

        // Employee 1 searches "Logistics" -> must only see task1, never task2
        $response = $this->withHeader('Authorization', 'Bearer '.$this->employee1Token)
            ->postJson('/mcp', [
                'jsonrpc' => '2.0',
                'id' => 21,
                'method' => 'tools/call',
                'params' => [
                    'name' => 'search_tasks',
                    'arguments' => ['query' => 'Logistics'],
                ],
            ]);

        $response->assertOk();
        $content = json_decode($response->json('result.content.0.text'), true);

        $this->assertSame(1, $content['total_found']);
        $this->assertSame('Quarterly Operations Audit', $content['tasks'][0]['title']);
    }

    public function test_get_task_details_tool_respects_permissions(): void
    {
        $task = $this->createTask([
            'title' => 'Detailed Task Specifications',
            'project' => 'Cloud Migration',
            'status' => 'in_progress',
            'priority' => 'high',
            'assigned_to' => $this->employee1->id,
            'due_date' => Carbon::now()->addDays(4)->toDateString(),
        ]);

        Subtask::create([
            'task_id' => $task->id,
            'title' => 'Verify Database Backups',
            'status' => 'completed',
            'is_completed' => true,
            'created_by' => $this->admin->id,
            'assigned_to' => $this->employee1->id,
        ]);

        // Employee 1 can view task details
        $response = $this->withHeader('Authorization', 'Bearer '.$this->employee1Token)
            ->postJson('/mcp', [
                'jsonrpc' => '2.0',
                'id' => 22,
                'method' => 'tools/call',
                'params' => [
                    'name' => 'get_task_details',
                    'arguments' => ['task_id' => $task->id],
                ],
            ]);

        $response->assertOk();
        $content = json_decode($response->json('result.content.0.text'), true);

        $this->assertTrue($content['found']);
        $this->assertSame('Detailed Task Specifications', $content['task']['title']);
        $this->assertCount(1, $content['task']['subtasks']);

        // Employee 2 cannot view task details (belongs to company 1)
        $unauthResponse = $this->withHeader('Authorization', 'Bearer '.$this->teamLeaderToken)
            ->postJson('/mcp', [
                'jsonrpc' => '2.0',
                'id' => 23,
                'method' => 'tools/call',
                'params' => [
                    'name' => 'get_task_details',
                    'arguments' => ['task_id' => 999999],
                ],
            ]);

        $unauthContent = json_decode($unauthResponse->json('result.content.0.text'), true);
        $this->assertFalse($unauthContent['found']);
    }

    public function test_get_task_report_tool(): void
    {
        $this->createTask([
            'title' => 'Completed Work Task',
            'status' => 'completed',
            'priority' => 'medium',
            'assigned_to' => $this->employee1->id,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->adminToken)
            ->postJson('/mcp', [
                'jsonrpc' => '2.0',
                'id' => 24,
                'method' => 'tools/call',
                'params' => [
                    'name' => 'get_task_report',
                    'arguments' => [
                        'period' => 'weekly',
                    ],
                ],
            ]);

        $response->assertOk();
        $content = json_decode($response->json('result.content.0.text'), true);

        $this->assertSame('weekly', $content['report_type']);
        $this->assertGreaterThanOrEqual(1, $content['summary']['total_tasks']);
        $this->assertGreaterThanOrEqual(1, $content['summary']['completed']);
        $this->assertArrayHasKey('completion_percentage', $content['summary']);
    }

    public function test_get_overdue_tasks_tool(): void
    {
        $overdueTask = $this->createTask([
            'title' => 'Critical Overdue Server Patch',
            'status' => 'in_progress',
            'priority' => 'critical',
            'due_date' => Carbon::now()->subDays(4)->toDateString(),
            'assigned_to' => $this->employee1->id,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->employee1Token)
            ->postJson('/mcp', [
                'jsonrpc' => '2.0',
                'id' => 25,
                'method' => 'tools/call',
                'params' => [
                    'name' => 'get_overdue_tasks',
                    'arguments' => [],
                ],
            ]);

        $response->assertOk();
        $content = json_decode($response->json('result.content.0.text'), true);

        $this->assertGreaterThanOrEqual(1, $content['total_overdue']);
        $titles = array_column($content['tasks'], 'title');
        $this->assertContains('Critical Overdue Server Patch', $titles);
    }

    public function test_get_subtask_report_tool(): void
    {
        $parent = $this->createTask([
            'title' => 'Parent Project Pipeline',
            'status' => 'in_progress',
            'assigned_to' => $this->employee1->id,
        ]);

        Subtask::create([
            'task_id' => $parent->id,
            'title' => 'Subtask Step 1',
            'status' => 'completed',
            'is_completed' => true,
            'created_by' => $this->admin->id,
            'assigned_to' => $this->employee1->id,
        ]);

        Subtask::create([
            'task_id' => $parent->id,
            'title' => 'Subtask Step 2',
            'status' => 'todo',
            'is_completed' => false,
            'created_by' => $this->admin->id,
            'assigned_to' => $this->employee1->id,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->employee1Token)
            ->postJson('/mcp', [
                'jsonrpc' => '2.0',
                'id' => 26,
                'method' => 'tools/call',
                'params' => [
                    'name' => 'get_subtask_report',
                    'arguments' => ['task_id' => $parent->id],
                ],
            ]);

        $response->assertOk();
        $content = json_decode($response->json('result.content.0.text'), true);

        $this->assertSame(1, $content['total_parent_tasks']);
        $this->assertSame(2, $content['parent_tasks'][0]['subtask_metrics']['total_subtasks']);
        $this->assertSame(1, $content['parent_tasks'][0]['subtask_metrics']['completed_subtasks']);
        $this->assertSame(1, $content['parent_tasks'][0]['subtask_metrics']['pending_subtasks']);
    }

    public function test_get_deadline_requests_tool(): void
    {
        $task = $this->createTask([
            'title' => 'Deadline Extension Task',
            'status' => 'in_progress',
            'assigned_to' => $this->employee1->id,
            'due_date' => Carbon::now()->addDays(2)->toDateString(),
        ]);

        DeadlineExtensionRequest::create([
            'task_id' => $task->id,
            'requested_by' => $this->employee1->id,
            'task_title' => $task->title,
            'current_deadline' => Carbon::now()->addDays(2)->toDateString(),
            'requested_deadline' => Carbon::now()->addDays(10)->toDateString(),
            'reason' => 'Vendor API delay',
            'status' => 'pending',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->employee1Token)
            ->postJson('/mcp', [
                'jsonrpc' => '2.0',
                'id' => 27,
                'method' => 'tools/call',
                'params' => [
                    'name' => 'get_deadline_requests',
                    'arguments' => ['status' => 'pending'],
                ],
            ]);

        $response->assertOk();
        $content = json_decode($response->json('result.content.0.text'), true);

        $this->assertGreaterThanOrEqual(1, $content['total_requests']);
        $this->assertSame('Vendor API delay', $content['requests'][0]['reason']);
    }

    public function test_get_project_report_tool(): void
    {
        $this->createTask([
            'title' => 'Project Phoenix Architecture',
            'project' => 'Project Phoenix',
            'status' => 'completed',
            'assigned_to' => $this->employee1->id,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->adminToken)
            ->postJson('/mcp', [
                'jsonrpc' => '2.0',
                'id' => 28,
                'method' => 'tools/call',
                'params' => [
                    'name' => 'get_project_report',
                    'arguments' => ['project' => 'Phoenix'],
                ],
            ]);

        $response->assertOk();
        $content = json_decode($response->json('result.content.0.text'), true);

        $this->assertSame(1, $content['total_projects']);
        $this->assertSame('Project Phoenix', $content['projects'][0]['project_name']);
        $this->assertEquals(100.0, $content['projects'][0]['completion_percentage']);
    }

    public function test_get_team_report_tool_and_department_scoping(): void
    {
        $task = $this->createTask([
            'title' => 'Ops Team Member Task',
            'status' => 'assigned',
            'assigned_to' => $this->employee1->id,
        ]);

        TaskAssignment::create([
            'task_id' => $task->id,
            'user_id' => $this->employee1->id,
            'is_active' => true,
            'assigned_at' => now(),
        ]);

        // Team Leader accesses team report
        $response = $this->withHeader('Authorization', 'Bearer '.$this->teamLeaderToken)
            ->postJson('/mcp', [
                'jsonrpc' => '2.0',
                'id' => 29,
                'method' => 'tools/call',
                'params' => [
                    'name' => 'get_team_report',
                    'arguments' => [],
                ],
            ]);

        $response->assertOk();
        $content = json_decode($response->json('result.content.0.text'), true);

        $this->assertArrayHasKey('team_workload', $content);
        $this->assertNotEmpty($content['team_workload']);
    }
}
