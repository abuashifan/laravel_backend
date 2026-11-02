<?php

namespace App\Modules\Settings\Controllers;

use App\Http\Controllers\Controller;
use App\Shared\Api\ApiResponse;
use App\Shared\Audit\AuditAction;
use App\Shared\Audit\AuditEvent;
use App\Shared\Audit\AuditLogService;
use App\Shared\DocumentNumbering\DocumentNumberFormat;
use App\Shared\DocumentNumbering\DocumentNumberService;
use App\Shared\DocumentNumbering\DocumentType;
use App\Shared\Models\DocumentNumberingSetting;
use App\Shared\Tenant\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Throwable;

class DocumentNumberingSettingController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly TenantContext $tenantContext,
        private readonly AuditLogService $auditLogService,
    ) {}

    public function index(): JsonResponse
    {
        $company = $this->tenantContext->company();
        if (! $company) {
            return $this->errorResponse('Active company context not found.', 422);
        }

        $this->numbers->ensureDefaultSettings($company);

        $rows = DocumentNumberingSetting::query()
            ->where('company_id', $company->id)
            ->orderBy('name')
            ->get()
            ->map(fn (DocumentNumberingSetting $setting): array => $this->present($setting))
            ->values();

        return $this->successResponse($rows, 'Document numbering settings retrieved successfully');
    }

    public function update(Request $request, string $documentType): JsonResponse
    {
        $company = $this->tenantContext->company();
        if (! $company) {
            return $this->errorResponse('Active company context not found.', 422);
        }
        if (! DocumentType::exists($documentType)) {
            return $this->errorResponse('Jenis dokumen tidak dikenal.', 404);
        }

        $data = $request->validate([
            'prefix' => ['sometimes', 'string', 'min:1', 'max:10', 'regex:/^[A-Za-z0-9._\-\/]+$/'],
            // Format wajib memuat {NUMBER}; tanpa itu semua dokumen bernomor sama.
            'format' => ['sometimes', 'string', 'max:60', 'regex:/\{NUMBER\}/'],
            'padding' => ['sometimes', 'integer', 'min:1', 'max:12'],
            'reset_period' => ['sometimes', Rule::in(['never', 'monthly', 'fiscal_year'])],
        ], [
            'format.regex' => 'Format harus memuat {NUMBER}.',
            'prefix.regex' => 'Prefiks hanya boleh huruf, angka, titik, strip, atau garis miring.',
        ]);

        $setting = $this->numbers->getOrCreateSetting($company, $documentType);
        $old = $setting->only(['prefix', 'format', 'padding', 'reset_period']);
        $setting->fill($data)->save();

        try {
            $this->auditLogService->logCentral([
                'event' => AuditEvent::SETTINGS_COMPANY_UPDATED,
                'action' => AuditAction::UPDATE,
                'module' => 'settings',
                'record_type' => 'document_numbering_setting',
                'record_id' => (string) $setting->id,
                'message' => "Document numbering {$documentType} updated.",
                'old_values' => $old,
                'new_values' => $setting->only(['prefix', 'format', 'padding', 'reset_period']),
            ]);
        } catch (Throwable $e) {
            // fail-safe
        }

        return $this->successResponse($this->present($setting->refresh()), 'Document numbering setting updated successfully');
    }

    /** @return array<string,mixed> */
    private function present(DocumentNumberingSetting $setting): array
    {
        $today = now()->toDateString();

        return [
            'document_type' => $setting->document_type,
            'name' => $setting->name,
            'prefix' => $setting->prefix,
            'format' => $setting->format,
            'padding' => $setting->padding,
            'reset_period' => $setting->reset_period,
            // Contoh dari format saat ini, tanpa menggeser sekuens.
            'example' => DocumentNumberFormat::format((string) $setting->format, [
                'prefix' => $setting->prefix,
                'year' => substr($today, 0, 4),
                'month' => substr($today, 5, 2),
                'number' => DocumentNumberFormat::padNumber(1, (int) $setting->padding),
                'document_type' => $setting->document_type,
            ]),
        ];
    }
}
