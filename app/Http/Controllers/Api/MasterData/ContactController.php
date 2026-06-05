<?php

namespace App\Http\Controllers\Api\MasterData;

use App\Http\Controllers\Controller;
use App\Http\Requests\MasterData\StoreContactRequest;
use App\Http\Requests\MasterData\UpdateContactRequest;
use App\Models\Tenant\Contact;
use App\Services\MasterData\ContactService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContactController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ContactService $service)
    {
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
        $contact = Contact::query()->findOrFail($id);
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

