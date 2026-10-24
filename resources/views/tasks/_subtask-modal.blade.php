{{-- Subtask Details & Edit Modal --}}
<div
    class="modal fade tf-subtask-modal"
    id="subtaskDetailsModal"
    tabindex="-1"
    aria-labelledby="subtaskDetailsModalLabel"
    aria-hidden="true"
>
    <div class="modal-dialog modal-dialog-centered modal-lg tf-subtask-modal__dialog">
        <div class="modal-content tf-subtask-modal__content">
            <form id="subtaskDetailsForm" method="POST" action="">
                @csrf
                @method('PATCH')
                <input type="hidden" id="subtaskModalId" name="subtask_id" value="">

                <div class="modal-header tf-subtask-modal__header">
                    <div class="tf-subtask-modal__heading">
                        <span class="tf-subtask-modal__icon" aria-hidden="true">
                            <i class="bi bi-check2-square"></i>
                        </span>
                        <div class="tf-subtask-modal__title-box">
                            <div class="tf-subtask-modal__parent-badge">
                                <i class="bi bi-diagram-3"></i>
                                <span>Parent Task: <strong>{{ $task->task_code }}</strong> — {{ $task->title }}</span>
                            </div>
                            <h2 class="modal-title" id="subtaskDetailsModalLabel">Subtask Details</h2>
                        </div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body tf-subtask-modal__body">
                    {{-- Alert container for errors/warnings --}}
                    <div id="subtaskModalAlert" class="alert alert-danger d-none" role="alert"></div>

                    {{-- Title row --}}
                    <div class="tf-subtask-modal__field mb-3">
                        <label for="subtaskModalTitle" class="form-label">
                            Subtask Title <span class="text-danger">*</span>
                        </label>
                        <input
                            type="text"
                            class="form-control"
                            id="subtaskModalTitle"
                            name="title"
                            maxlength="500"
                            required
                            placeholder="e.g. Backend API Development"
                        >
                    </div>

                    {{-- Creator & Assignee Info Card (Separated as required) --}}
                    <div class="tf-subtask-modal__people-card mb-3">
                        <div class="tf-subtask-modal__person-col">
                            <span class="tf-subtask-modal__person-label">
                                <i class="bi bi-person-plus text-primary"></i> Created By
                            </span>
                            <div class="tf-subtask-modal__person-val">
                                <strong id="subtaskModalCreatorName">—</strong>
                                <small id="subtaskModalCreatedAt" class="text-muted d-block">—</small>
                            </div>
                        </div>

                        <div class="tf-subtask-modal__divider" aria-hidden="true"></div>

                        <div class="tf-subtask-modal__person-col">
                            <label for="subtaskModalAssignee" class="tf-subtask-modal__person-label mb-1">
                                <i class="bi bi-person-check text-indigo"></i> Assigned To
                            </label>
                            <select id="subtaskModalAssignee" name="assigned_to" class="form-select form-select-sm">
                                <option value="">Unassigned</option>
                                @foreach ($employees->where('company_id', $task->company_id)->where('department_id', $task->department_id) as $emp)
                                    <option value="{{ $emp->id }}">
                                        {{ $emp->name }} {{ $emp->designation ? "({$emp->designation})" : '' }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    {{-- Metadata Grid: Priority, Status, Start Date, Deadline --}}
                    <div class="row g-3 mb-3">
                        <div class="col-sm-6 col-md-3">
                            <label for="subtaskModalStatus" class="form-label form-label-sm">Status</label>
                            <select id="subtaskModalStatus" name="status" class="form-select form-select-sm">
                                <option value="todo">To Do</option>
                                <option value="in_progress">In Progress</option>
                                <option value="completed">Completed</option>
                            </select>
                        </div>

                        <div class="col-sm-6 col-md-3">
                            <label for="subtaskModalPriority" class="form-label form-label-sm">Priority</label>
                            <select id="subtaskModalPriority" name="priority" class="form-select form-select-sm">
                                <option value="low">Low</option>
                                <option value="medium">Medium</option>
                                <option value="high">High</option>
                                <option value="critical">Critical</option>
                            </select>
                        </div>

                        <div class="col-sm-6 col-md-3">
                            <label for="subtaskModalStartDate" class="form-label form-label-sm">Start Date</label>
                            <input
                                type="date"
                                id="subtaskModalStartDate"
                                name="start_date"
                                class="form-control form-control-sm"
                            >
                        </div>

                        <div class="col-sm-6 col-md-3">
                            <label for="subtaskModalDueDate" class="form-label form-label-sm">
                                Deadline
                                @if ($task->due_date)
                                    <small class="text-muted">(Max: {{ $task->due_date->format('M d') }})</small>
                                @endif
                            </label>
                            <input
                                type="date"
                                id="subtaskModalDueDate"
                                name="due_date"
                                class="form-control form-control-sm"
                                @if ($task->due_date) max="{{ $task->due_date->format('Y-m-d') }}" @endif
                            >
                        </div>
                    </div>

                    {{-- Description / Details --}}
                    <div class="tf-subtask-modal__field mb-3">
                        <label for="subtaskModalDescription" class="form-label">Description / Details</label>
                        <textarea
                            id="subtaskModalDescription"
                            name="description"
                            class="form-control"
                            rows="4"
                            maxlength="5000"
                            placeholder="Add details, acceptance criteria, or technical notes for this subtask…"
                        ></textarea>
                    </div>

                    {{-- Completed By Stamp (shows when completed) --}}
                    <div id="subtaskModalCompletedStamp" class="tf-subtask-modal__completed-stamp d-none">
                        <i class="bi bi-check-circle-fill text-success"></i>
                        <span>Completed by <strong id="subtaskModalCompletedBy">—</strong> on <span id="subtaskModalCompletedAt">—</span></span>
                    </div>
                </div>

                <div class="modal-footer tf-subtask-modal__footer">
                    <button type="button" class="btn btn-outline-danger btn-sm me-auto" id="subtaskModalDeleteBtn">
                        <i class="bi bi-trash3"></i> Delete Subtask
                    </button>
                    <button type="button" class="btn btn-ghost btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm" id="subtaskModalSaveBtn">
                        <i class="bi bi-check-lg"></i> Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
