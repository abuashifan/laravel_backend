<?php

namespace App\Modules\MasterData\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\MasterData\Models\Contact;
use App\Modules\MasterData\Requests\StoreContactRequest;
use App\Modules\MasterData\Requests\UpdateContactRequest;
use App\Modules\MasterData\Services\ContactService;
use App\Shared\Api\ApiResponse;
use App\Shared\Api\ResolvesAdjacentRecords;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContactController extends Controller
{
    use ApiResponse;
    use ResolvesAdjacentRecords;

    public function __construct(private readonly ContactService $service) {}

    public function adjacent(Request $request): JsonResponse
    {
        return $this->adjacentResponse(Contact::query(), $request, 'contact_code');
    }

    public function index(Request $request): JsonResponse
    {
        $items = $this->service->list($request->query());

        return $this->listResponse($items, $request, 'Contacts retrieved successfully');
    }

    public function store(StoreContactRequest $request): JsonResponse
    {
        $contact = $this->service->create($request->validated());

        return $this->successResponse($contact, 'Contact created successfully', 201);
    }

    public function show(int $id): JsonResponse
    {
        $contact = Contact::query()->with('paymentTerm')->findOrFail($id);

        return $this->successResponse($contact, 'Contact retrieved successfully');
    }

    public function update(UpdateContactRequest $request, int $id): JsonResponse
    {
        $contact = Contact::query()->findOrFail($id);
        $contact = $this->service->update($contact, $request->validated());

        return $this->successResponse($contact, 'Contact updated successfully');
    }

    public function deactivate(int $id): JsonResponse
    {
        $contact = Contact::query()->findOrFail($id);
        $contact = $this->service->deactivate($contact);

        return $this->successResponse($contact, 'Contact deactivated successfully');
    }

    public function activate(int $id): JsonResponse
    {
        $contact = Contact::query()->findOrFail($id);
        $contact = $this->service->activate($contact);

        return $this->successResponse($contact, 'Contact activated successfully');
    }
}
