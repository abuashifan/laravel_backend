<?php

namespace App\Modules\Access\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Access\Services\InvitationAcceptanceService;
use App\Shared\Api\ApiResponse;
use Illuminate\Http\Request;

class AcceptInvitationController extends Controller
{
    use ApiResponse;

    public function __invoke(Request $request, string $token, InvitationAcceptanceService $acceptance)
    {
        $member = $acceptance->accept($token, $request->user());

        return $this->successResponse(['company_id' => $member->company_id, 'company_user_id' => $member->id], 'Invitation accepted.');
    }
}
