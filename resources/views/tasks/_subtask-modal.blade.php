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
                            Subtask name <span class="text-danger">*</span>
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

                    {{-- Description --}}
                    <div class="tf-subtask-modal__field mb-3">
                        <label for="subtaskModalDescription" class="form-label">Description (optional)</label>
                        <textarea
                            id="subtaskModalDescription"
                            name="description"
                            class="form-control"
                            rows="4"
                            maxlength="5000"
                            placeholder="Add details, acceptance criteria, or technical notes for this subtask…"
                        ></textarea>
                    </div>

                    <div class="tf-subtask-modal__field mb-3">
                        <label for="subtaskModalAssignee" class="form-label">Assignee</label>
                        <select id="subtaskModalAssignee" name="assigned_to" class="form-select">
                            <option value="">Unassigned</option>
                            @foreach ($employees->where('company_id', $task->company_id)->where('department_id', $task->department_id) as $emp)
                                <option value="{{ $emp->id }}">
                                    {{ $emp->name }} {{ $emp->designation ? "({$emp->designation})" : '' }}
                                </option>
                            @endforeach
                        </select>
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
