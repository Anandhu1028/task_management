<?php

namespace App\Http\Requests;

use App\Models\Department;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('users.create');
    }

    public function rules(): array
    {
        $department = Department::query()
            ->with('designations.roles:id')
            ->where('company_id', $this->input('company_id'))
            ->find($this->input('department_id'));
        $designationNames = $department?->designations->pluck('name')->all() ?? [];
        $designation = $department?->designations->firstWhere('name', $this->input('designation'));
        $designationRoleIds = $designation?->roles->pluck('id')->all() ?? [];

        if ($department && $designationNames === [] && trim($department->designation) !== '') {
            $designationNames[] = trim($department->designation);
        }

        return [
            'name' => ['required', 'string', 'max:255'],
            'employee_id' => ['required', 'string', 'max:50', 'unique:users,employee_id'],
            'phone' => ['required', 'string', 'max:20', 'regex:/^\+?[0-9\s\-()]{7,20}$/'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'department_id' => [
                'required',
                'integer',
                Rule::exists('departments', 'id')
                    ->where('company_id', $this->input('company_id'))
                    ->whereNull('deleted_at'),
            ],
            'designation' => ['required', 'string', 'max:255', Rule::in($designationNames)],
            'role_id' => ['required', 'integer', Rule::in($designationRoleIds)],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'status' => ['required', 'in:active,inactive'],
        ];
    }
}
