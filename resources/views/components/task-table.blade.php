@php
    $priorityLabel = [
        'critical' => 'Critical',
        'high' => 'High',
        'medium' => 'Medium',
        'low' => 'Low',
    ];

    $statusLabel = \App\Models\Task::STATUS_LABELS;
@endphp


<div class="tf-table-wrap">

    <table class="tf-table">

        <thead>
            <tr>
                <th>Task</th>
                <th>Project</th>
                <th>Priority</th>
                <th>Status</th>
                <th>Due Date</th>
                <th>Assignee</th>
                <th></th>
            </tr>
        </thead>

        <tbody id="taskTableBody">

            @foreach ($tasks as $task)

                <tr
                    data-tabs="{{ implode(' ', $task['tabs']) }}"
                    data-priority="{{ $task['priority'] }}"
                    data-status="{{ $task['status'] }}"
                >

                    <td>

                        <label class="tf-task">

                            <input
                                type="checkbox"
                                class="tf-check"
                            >

                            <span class="tf-task__info">

                                <span class="tf-task__title">
                                    {{ $task['title'] }}
                                </span>

                                <span class="tf-task__id">
                                    {{ $task['id'] }}
                                </span>

                            </span>

                        </label>

                    </td>


                    <td>
                        <span class="tf-project">
                            {{ $task['project'] }}
                        </span>
                    </td>


                    <td>

                        <span class="tf-priority-badge tf-priority-badge--{{ $task['priority'] }}">

                            <span class="tf-dot tf-dot--{{ $task['priority'] }}"></span>

                            {{ $priorityLabel[$task['priority']] }}

                        </span>

                    </td>


                    <td>

                        <span class="tf-status-badge tf-status-badge--{{ $task['status'] }}">
                            {{ $statusLabel[$task['status']] }}
                        </span>

                    </td>


                    <td>

                        <span class="tf-due tf-due--{{ $task['due'] }}">
                            {{ $task['due_label'] }}
                        </span>

                    </td>


                    <td>

                        <span class="tf-assignee">

                            <span class="tf-avatar tf-avatar--{{ strtolower($task['initials']) }}">
                                {{ $task['initials'] }}
                            </span>

                            <span class="tf-assignee__name">{{ $task['assignee'] }}</span>

                        </span>

                    </td>


                    <td class="tf-actions">

                        <div class="dropdown">

                            <button
                                class="tf-icon-btn tf-icon-btn--sm tf-icon-btn--more"
                                type="button"
                                data-bs-toggle="dropdown"
                                aria-label="Task actions"
                            >
                                <i class="bi bi-three-dots"></i>
                            </button>

                            <ul class="dropdown-menu dropdown-menu-end tf-menu">

                                <li>
                                    <a class="dropdown-item" href="#">
                                        Open
                                    </a>
                                </li>

                                <li>
                                    <a class="dropdown-item" href="#">
                                        Edit
                                    </a>
                                </li>

                                <li>
                                    <a class="dropdown-item" href="#">
                                        Assign
                                    </a>
                                </li>

                                <li>
                                    <hr class="dropdown-divider">
                                </li>

                                <li>
                                    <a class="dropdown-item text-danger" href="#">
                                        Delete
                                    </a>
                                </li>

                            </ul>

                        </div>

                    </td>

                </tr>

            @endforeach

        </tbody>

    </table>

</div>


{{-- Mobile --}}
<div class="tf-task-cards d-md-none" id="taskCardList">

    @foreach ($tasks as $task)

        <article
            class="tf-task-card"
            data-tabs="{{ implode(' ', $task['tabs']) }}"
            data-priority="{{ $task['priority'] }}"
            data-status="{{ $task['status'] }}"
        >

            <div class="tf-task-card__top">

                <label class="tf-task">

                    <input
                        type="checkbox"
                        class="tf-check"
                    >

                    <span>

                        <span class="tf-task__id">
                            {{ $task['id'] }}
                        </span>

                        <span class="tf-task__title">
                            {{ $task['title'] }}
                        </span>

                    </span>

                </label>


                <span class="tf-priority tf-priority--{{ $task['priority'] }}">
                    <span class="tf-dot"></span>
                    {{ $priorityLabel[$task['priority']] }}
                </span>

            </div>


            <div class="tf-task-card__meta">

                <span>
                    {{ $task['project'] }}
                </span>

                <span class="tf-status tf-status--{{ $task['status'] }}">
                    {{ $statusLabel[$task['status']] }}
                </span>

                <span class="tf-due tf-due--{{ $task['due'] }}">
                    {{ $task['due_label'] }}
                </span>

            </div>


            <div class="tf-assignee">

                <span class="tf-avatar tf-avatar--sm">
                    {{ $task['initials'] }}
                </span>

                {{ $task['assignee'] }}

            </div>

        </article>

    @endforeach

</div>


<div class="tf-empty-wrap" id="taskEmpty" hidden>

    @include('components.empty-state', [
        'icon' => 'bi-check2-square',
        'title' => 'No tasks',
        'message' => 'Nothing matches this view. Create a task or switch tabs.',
        'action' => 'Create Task',
    ])

</div>