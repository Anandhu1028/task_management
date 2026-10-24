<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

class UpdateDepartmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('departments.update');
    }

    protected function prepareForValidation(): void
    {
        $designations = $this->input('designations');
        if (is_array($designations) && $designations !== []) {
            $normalized = array_map(static function (mixed $designation): array {
                if (is_string($designation)) {
                    return ['name' => trim($designation), 'roles' => []];
                }

                if (! is_array($designation)) {
                    return ['name' => '', 'roles' => []];
                }

                $roles = is_array($designation['roles'] ?? null) ? $designation['roles'] : [];
                $roleIds = array_map(static function (mixed $role): mixed {
                    if (is_int($role) || (is_string($role) && ctype_digit($role))) {
                        return (int) $role;
                    }

                    return is_string($role)
                        ? Role::query()->where('guard_name', 'web')->where('name', trim($role))->value('id') ?? $role
                        : $role;
                }, $roles);

                return [
                    'name' => trim((string) ($designation['name'] ?? '')),
                    'roles' => array_values(array_unique($roleIds)),
                ];
            }, $designations);

            $this->merge([
                'designations' => $normalized,
                'designation' => $this->input('designation') ?: ($normalized[0]['name'] ?? 'Team Member'),
            ]);
        } elseif ($this->filled('designation')) {
            $this->merge([
                'designations' => [['name' => trim($this->input('designation')), 'roles' => []]],
            ]);
        }
    }

    public function rules(): array
    {
        $department = $this->route('department');

        return [
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'designation' => ['nullable', 'string', 'max:255'],
            'designations' => ['required', 'array', 'min:1'],
            'designations.*' => ['required', 'array:name,roles'],
            'designations.*.name' => ['required', 'string', 'max:255', 'distinct:ignore_case'],
            'designations.*.roles' => ['sometimes', 'array'],
            'designations.*.roles.*' => ['required', 'integer', Rule::exists('roles', 'id')->where('guard_name', 'web')],
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('departments', 'name')
                    ->where('company_id', $this->input('company_id'))
                    ->ignore($department),
            ],
            'description' => ['nullable', 'string', 'max:2000'],
            'status' => ['required', 'in:active,inactive'],
        ];
    }
}
