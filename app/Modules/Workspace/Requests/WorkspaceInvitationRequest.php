<?php

namespace App\Modules\Workspace\Requests;

use Illuminate\Foundation\Http\FormRequest;

class WorkspaceInvitationRequest extends FormRequest
{
    public function rules(): array
    {
        return ['email' => ['required', 'email', 'max:255']];
    }
}
