<?php

namespace App\Modules\MasterData\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAccountMappingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'account_id' => ['nullable', 'integer'],
        ];
    }
}

