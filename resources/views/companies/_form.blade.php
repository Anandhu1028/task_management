<form method="POST" action="{{ $formAction }}" class="tf-management__form">
    @csrf
    @if ($company)
        @method('PUT')
    @endif
    <div class="modal-header tf-user-offcanvas__header">
        <div>
            <span class="tf-user-offcanvas__eyebrow">COMPANY MANAGEMENT</span>
            <h2 class="modal-title" id="companyFormModalTitle">{{ $company ? 'Edit company' : 'Add company' }}</h2>
            <p>{{ $company ? 'Update company details and status.' : 'Add a company to your organization.' }}</p>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
    </div>
    <div class="modal-body tf-user-offcanvas__body">
        <section class="tf-user-offcanvas__section">
            <h3><i class="bi bi-building"></i> Company information</h3>
            <div class="tf-user-offcanvas__grid">
                <div class="tf-user-offcanvas__field tf-user-offcanvas__field--full">
                    <label for="companyFormName">Company name <span>*</span></label>
                    <input id="companyFormName" name="name" value="{{ old('name', $company?->name) }}" class="form-control" required maxlength="255" autofocus>
                    @error('name') <div class="tf-management__field-error">{{ $message }}</div> @enderror
                </div>
                <div class="tf-user-offcanvas__field tf-user-offcanvas__field--full">
                    <label for="companyFormDescription">Description</label>
                    <textarea id="companyFormDescription" name="description" class="form-control" rows="4" maxlength="2000" placeholder="A short description of this company">{{ old('description', $company?->description) }}</textarea>
                    @error('description') <div class="tf-management__field-error">{{ $message }}</div> @enderror
                </div>
                <div class="tf-user-offcanvas__field tf-user-offcanvas__field--full">
                    <label for="companyFormStatus">Status <span>*</span></label>
                    <select id="companyFormStatus" name="status" class="form-select" required>
                        <option value="active" @selected(old('status', $company?->status ?? 'active') === 'active')>Active</option>
                        <option value="inactive" @selected(old('status', $company?->status) === 'inactive')>Inactive</option>
                    </select>
                    @error('status') <div class="tf-management__field-error">{{ $message }}</div> @enderror
                </div>
            </div>
        </section>
    </div>
    <div class="modal-footer tf-user-offcanvas__footer">
        <button type="button" class="tf-btn tf-btn--ghost" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="tf-btn tf-btn--primary">
            <i class="bi bi-check-lg"></i>
            {{ $submitLabel }}
        </button>
    </div>
</form>
