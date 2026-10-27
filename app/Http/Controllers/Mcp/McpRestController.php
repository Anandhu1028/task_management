<?php

namespace App\Http\Controllers\Mcp;

use App\Http\Controllers\Controller;
use App\Services\Mcp\McpServerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class McpRestController extends Controller
{
    public function __construct(
        private readonly McpServerService $mcpServer,
    ) {}

    /**
     * OpenAPI 3.0 Specification for ChatGPT Actions / Connectors.
     * GET /api/mcp/openapi.json
     */
    public function openApi(): JsonResponse
    {
        $serverUrl = url('/');

        return response()->json([
            'openapi' => '3.0.3',
            'info' => [
                'title' => 'TaskFlow Task Management API',
                'description' => 'Real-time task tracking, reporting, overdue management, and team workload analytics.',
                'version' => '1.0.0',
            ],
            'servers' => [
                [
                    'url' => $serverUrl,
                    'description' => 'Production Server',
                ],
            ],
            'paths' => [
                '/api/mcp/my-tasks' => [
                    'get' => [
                        'operationId' => 'getMyTasks',
                        'summary' => 'Get tasks scoped to the authenticated user role',
                        'description' => 'Returns tasks based on role: Admin/CEO see all company tasks, Manager sees company tasks, Team Leader sees department tasks, Employee sees only their own assigned tasks. Admin/CEO can filter by assignee_id, department_id, or company_id.',
                        'parameters' => [
                            ['name' => 'status', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string'], 'description' => 'Filter by task status.'],
                            ['name' => 'condition', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string'], 'description' => 'Deadline condition: normal, at_risk, overdue.'],
                            ['name' => 'priority', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string'], 'description' => 'Filter by priority level.'],
                            ['name' => 'project', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string'], 'description' => 'Filter by project name.'],
                            ['name' => 'assignee_id', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'integer'], 'description' => 'Filter by specific assignee user ID (elevated roles only).'],
                            ['name' => 'department_id', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'integer'], 'description' => 'Filter by department ID (Admin/CEO only).'],
                            ['name' => 'company_id', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'integer'], 'description' => 'Filter by company ID (Admin/CEO only).'],
                            ['name' => 'from_date', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string'], 'description' => 'Start date filter (YYYY-MM-DD).'],
                            ['name' => 'to_date', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string'], 'description' => 'End date filter (YYYY-MM-DD).'],
                            ['name' => 'page', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'integer']],
                            ['name' => 'per_page', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'integer']],
                        ],
                        'responses' => [
                            '200' => ['description' => 'List of tasks scoped by role'],
                        ],
                    ],
                ],
                '/api/mcp/search-tasks' => [
                    'get' => [
                        'operationId' => 'searchTasks',
                        'summary' => 'Search tasks by keyword',
                        'description' => 'Search across task titles, descriptions, projects, and assignees.',
                        'parameters' => [
                            ['name' => 'query', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'string']],
                            ['name' => 'status', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string']],
                            ['name' => 'project', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string']],
                            ['name' => 'priority', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string']],
                            ['name' => 'limit', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'integer']],
                        ],
                        'responses' => [
                            '200' => ['description' => 'Search results'],
                        ],
                    ],
                ],
                '/api/mcp/task-details' => [
                    'get' => [
                        'operationId' => 'getTaskDetails',
                        'summary' => 'Get full task details',
                        'description' => 'Get complete task information by task_id or task_code.',
                        'parameters' => [
                            ['name' => 'task_id', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'integer']],
                            ['name' => 'task_code', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string']],
                        ],
                        'responses' => [
                            '200' => ['description' => 'Task details with subtasks and history'],
                        ],
                    ],
                ],
                '/api/mcp/task-report' => [
                    'get' => [
                        'operationId' => 'getTaskReport',
                        'summary' => 'Generate task summary report',
                        'description' => 'Get task metrics, completion rate, and totals for daily, weekly, monthly, or custom periods.',
                        'parameters' => [
                            ['name' => 'period', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string', 'default' => 'weekly']],
                            ['name' => 'from_date', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string']],
                            ['name' => 'to_date', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string']],
                            ['name' => 'project', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string']],
                        ],
                        'responses' => [
                            '200' => ['description' => 'Task metrics and summary'],
                        ],
                    ],
                ],
                '/api/mcp/overdue-tasks' => [
                    'get' => [
                        'operationId' => 'getOverdueTasks',
                        'summary' => 'List overdue incomplete tasks',
                        'description' => 'Retrieve tasks past their deadline with calculated days overdue.',
                        'parameters' => [
                            ['name' => 'project', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string']],
                            ['name' => 'department_id', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'integer']],
                            ['name' => 'limit', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'integer']],
                        ],
                        'responses' => [
                            '200' => ['description' => 'List of overdue tasks'],
                        ],
                    ],
                ],
                '/api/mcp/team-report' => [
                    'get' => [
                        'operationId' => 'getTeamReport',
                        'summary' => 'Get team workload analytics',
                        'description' => 'Retrieve workload and completion stats by employee scoped to user role.',
                        'parameters' => [
                            ['name' => 'department_id', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'integer']],
                            ['name' => 'company_id', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'integer']],
                            ['name' => 'limit', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'integer']],
                        ],
                        'responses' => [
                            '200' => ['description' => 'Team workload breakdown'],
                        ],
                    ],
                ],
                '/api/mcp/subtask-report' => [
                    'get' => [
                        'operationId' => 'getSubtaskReport',
                        'summary' => 'Get subtask progress report',
                        'description' => 'Retrieve parent tasks with subtask progress and pending/overdue subtasks.',
                        'parameters' => [
                            ['name' => 'task_id', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'integer']],
                            ['name' => 'only_pending', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'boolean']],
                            ['name' => 'limit', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'integer']],
                        ],
                        'responses' => [
                            '200' => ['description' => 'Subtask progress summary'],
                        ],
                    ],
                ],
                '/api/mcp/deadline-requests' => [
                    'get' => [
                        'operationId' => 'getDeadlineRequests',
                        'summary' => 'Get deadline extension requests',
                        'description' => 'Retrieve deadline extension requests and review decisions.',
                        'parameters' => [
                            ['name' => 'status', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string']],
                            ['name' => 'task_id', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'integer']],
                            ['name' => 'limit', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'integer']],
                        ],
                        'responses' => [
                            '200' => ['description' => 'Deadline extension requests'],
                        ],
                    ],
                ],
                '/api/mcp/project-report' => [
                    'get' => [
                        'operationId' => 'getProjectReport',
                        'summary' => 'Get project-level progress report',
                        'description' => 'Retrieve project task totals, status distribution, and completion rates.',
                        'parameters' => [
                            ['name' => 'project', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string']],
                            ['name' => 'limit', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'integer']],
                        ],
                        'responses' => [
                            '200' => ['description' => 'Project progress reports'],
                        ],
                    ],
                ],
            ],
            'components' => [
                'securitySchemes' => [
                    'OAuth2' => [
                        'type' => 'oauth2',
                        'description' => 'OAuth 2.1 authentication',
                        'flows' => [
                            'authorizationCode' => [
                                'authorizationUrl' => url('/oauth/authorize'),
                                'tokenUrl' => url('/oauth/token'),
                                'scopes' => [
                                    'tasks:read' => 'Read tasks and subtasks',
                                    'reports:read' => 'Read reports and workload analytics',
                                    'offline_access' => 'Maintain background connection',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'security' => [
                ['OAuth2' => ['tasks:read', 'reports:read']],
            ],
        ]);
    }

    public function myTasks(Request $request): JsonResponse
    {
        return $this->executeTool('get_my_tasks', $request);
    }

    public function searchTasks(Request $request): JsonResponse
    {
        return $this->executeTool('search_tasks', $request);
    }

    public function taskDetails(Request $request): JsonResponse
    {
        return $this->executeTool('get_task_details', $request);
    }

    public function taskReport(Request $request): JsonResponse
    {
        return $this->executeTool('get_task_report', $request);
    }

    public function overdueTasks(Request $request): JsonResponse
    {
        return $this->executeTool('get_overdue_tasks', $request);
    }

    public function teamReport(Request $request): JsonResponse
    {
        return $this->executeTool('get_team_report', $request);
    }

    public function subtaskReport(Request $request): JsonResponse
    {
        return $this->executeTool('get_subtask_report', $request);
    }

    public function deadlineRequests(Request $request): JsonResponse
    {
        return $this->executeTool('get_deadline_requests', $request);
    }

    public function projectReport(Request $request): JsonResponse
    {
        return $this->executeTool('get_project_report', $request);
    }

    private function executeTool(string $toolName, Request $request): JsonResponse
    {
        $user = $request->user();
        $tools = $this->mcpServer->getTools();

        if (! isset($tools[$toolName])) {
            return response()->json(['error' => "Tool '{$toolName}' not found."], 404);
        }

        try {
            $result = $tools[$toolName]->execute($user, $request->all());

            return response()->json($result);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['error' => 'An error occurred while executing the request.'], 500);
        }
    }
}
