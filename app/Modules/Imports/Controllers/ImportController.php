<?php

namespace App\Modules\Imports\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Imports\Requests\StoreImportRequest;
use App\Modules\Imports\Requests\UpdateImportMappingRequest;
use App\Modules\Imports\Services\DuplicateImportFileException;
use App\Modules\Imports\Services\ImportBatchService;
use App\Modules\Imports\Services\ImportTemplateService;
use App\Shared\Api\ApiErrorCode;
use App\Shared\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ImportController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly ImportBatchService $batches,
        private readonly ImportTemplateService $templates,
    ) {}

    public function store(StoreImportRequest $request): JsonResponse
    {
        try {
            $result = $this->batches->upload(
                (string) $request->validated('profile'),
                $request->file('file'),
                (bool) ($request->validated('confirm_duplicate_file') ?? false)
            );
        } catch (DuplicateImportFileException $exception) {
            return $this->warningResponse(
                ApiErrorCode::IMPORT_FILE_DUPLICATE,
                'Berkas ini sudah pernah diunggah. Konfirmasi untuk melanjutkan.',
                [],
                ['duplicate' => $exception->duplicate]
            );
        }

        return $this->successResponse($result, 'Import batch created.', 201);
    }

    public function mapping(UpdateImportMappingRequest $request, string $uuid): JsonResponse
    {
        return $this->successResponse(
            $this->batches->applyMapping($uuid, $request->validated('column_map')),
            'Import mapping saved and validated.'
        );
    }

    public function show(string $uuid): JsonResponse
    {
        return $this->successResponse($this->batches->show($uuid), 'Import batch retrieved.');
    }

    public function rows(Request $request, string $uuid): JsonResponse
    {
        return $this->listResponse($this->batches->rows($uuid, $request->query()), $request, 'Import rows retrieved.');
    }

    public function commit(string $uuid): JsonResponse
    {
        $this->batches->commit($uuid);
    }

    public function destroy(string $uuid): JsonResponse
    {
        $this->batches->cancel($uuid);

        return $this->successResponse(null, 'Import batch cancelled.');
    }

    public function template(string $profile): StreamedResponse
    {
        $data = $this->templates->csv($profile);

        return response()->streamDownload(function () use ($data): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, $data['headers']);
            foreach ($data['rows'] as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, $data['filename'], [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
