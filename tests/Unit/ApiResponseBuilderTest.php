<?php

namespace Tests\Unit;

use App\Support\Api\ApiErrorCode;
use App\Support\Api\ApiResponseBuilder;
use App\Support\Transaction\TransactionPolicyResult;
use Tests\TestCase;

class ApiResponseBuilderTest extends TestCase
{
    public function test_success_response_has_success_true(): void
    {
        $res = ApiResponseBuilder::success(['x' => 1], 'OK');
        $data = $res->getData(true);

        $this->assertTrue($data['success']);
        $this->assertSame('OK', $data['message']);
    }

    public function test_error_response_has_success_false_and_code(): void
    {
        $res = ApiResponseBuilder::error(ApiErrorCode::PERMISSION_DENIED);
        $data = $res->getData(true);

        $this->assertFalse($data['success']);
        $this->assertSame(ApiErrorCode::PERMISSION_DENIED, $data['code']);
    }

    public function test_warning_response_has_requires_confirmation_true(): void
    {
        $res = ApiResponseBuilder::warning(ApiErrorCode::FUTURE_TRANSACTION_DATE_WARNING);
        $data = $res->getData(true);

        $this->assertFalse($data['success']);
        $this->assertTrue($data['requires_confirmation']);
    }

    public function test_validation_response_uses_validation_error_and_status_422(): void
    {
        $res = ApiResponseBuilder::validation(['a' => ['required']]);
        $data = $res->getData(true);

        $this->assertFalse($data['success']);
        $this->assertSame(ApiErrorCode::VALIDATION_ERROR, $data['code']);
        $this->assertSame(422, $res->status());
    }

    public function test_unknown_code_still_returns_message(): void
    {
        $res = ApiResponseBuilder::error('SOME_UNKNOWN_CODE', null);
        $data = $res->getData(true);

        $this->assertNotEmpty($data['message']);
    }

    public function test_api_error_code_exists_and_is_warning(): void
    {
        $this->assertTrue(ApiErrorCode::exists(ApiErrorCode::PERMISSION_DENIED));
        $this->assertTrue(ApiErrorCode::isWarning(ApiErrorCode::FUTURE_TRANSACTION_DATE_WARNING));
    }

    public function test_from_policy_result_returns_warning_when_policy_warning(): void
    {
        $policy = TransactionPolicyResult::warning(ApiErrorCode::FUTURE_TRANSACTION_DATE_WARNING, 'Warn');
        $res = ApiResponseBuilder::fromPolicyResult($policy);
        $data = $res->getData(true);

        $this->assertTrue($data['requires_confirmation']);
    }

    public function test_from_policy_result_returns_error_when_policy_denied(): void
    {
        $policy = TransactionPolicyResult::deny(ApiErrorCode::PERMISSION_DENIED, 'Denied');
        $res = ApiResponseBuilder::fromPolicyResult($policy);
        $data = $res->getData(true);

        $this->assertFalse($data['success']);
        $this->assertSame(ApiErrorCode::PERMISSION_DENIED, $data['code']);
    }

    public function test_from_policy_result_returns_success_when_policy_allowed(): void
    {
        $policy = TransactionPolicyResult::allow('Allowed');
        $res = ApiResponseBuilder::fromPolicyResult($policy);
        $data = $res->getData(true);

        $this->assertTrue($data['success']);
        $this->assertSame('Allowed', $data['message']);
    }
}

