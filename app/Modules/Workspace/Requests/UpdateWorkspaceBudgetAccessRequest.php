<?php

namespace App\Modules\Workspace\Requests;

use App\Modules\Workspace\Support\BudgetPermission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateWorkspaceBudgetAccessRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'permissions' => ['present', 'array', 'list', 'max:5'],
            'permissions.*' => ['required', 'distinct', Rule::in(BudgetPermission::GRANTABLE)],
        ];
    }
}
