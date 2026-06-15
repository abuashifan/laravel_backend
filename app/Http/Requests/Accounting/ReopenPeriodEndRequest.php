<?php

namespace App\Http\Requests\Accounting;

class ReopenPeriodEndRequest extends PeriodEndPeriodRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'reason' => ['required', 'string', 'max:1000'],
        ]);
    }
}
