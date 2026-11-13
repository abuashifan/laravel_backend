<?php

namespace App\Modules\Pos\Controllers;

use App\Mail\CompanyInvitationMail;
use App\Modules\Pos\Models\PosMemberLimit;
use App\Modules\Pos\Services\PosAuditService;
use App\Modules\Pos\Services\PosSecurityService;
use App\Shared\Audit\AuditLogService;
use App\Shared\Exceptions\ApiException;
use App\Shared\Models\Company;
use App\Shared\Models\CompanyInvitation;
use App\Shared\Models\CompanyUser;
use App\Shared\Models\TenantDatabase;
use App\Shared\Permission\CompanyAccessService;
use App\Shared\Subscription\UserQuotaService;
use App\Shared\Tenant\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Pengguna toko dari aplikasi kasir (Fase 2): undang kasir dengan templat
 * izin (`config/pos.php`), ubah peran, nonaktifkan. Data keanggotaan dan
 * undangan sama dengan layar Akses di Finlite Accounting -- hanya tampilannya
 * disederhanakan menjadi peran Kasir / Kepala toko.
 */
class PosTeamController extends PosController
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly CompanyAccessService $access,
        private readonly UserQuotaService $quota,
        private readonly AuditLogService $audit,
        private readonly PosSecurityService $security,
        private readonly PosAuditService $posAudit,
    ) {}

    public function index(): JsonResponse
    {
        $company = $this->company();
        $limits = PosMemberLimit::query()->pluck('max_discount_percent', 'user_id');
        $members = CompanyUser::query()
            ->with(['user:id,name,email', 'assignedPermissions:id,key'])
            ->where('company_id', $company->id)
            ->whereIn('status', ['active', 'inactive'])
            ->orderByDesc('is_owner')
            ->orderBy('id')
            ->get()
            ->map(fn (CompanyUser $member) => [
                'id' => $member->id,
                'user_id' => $member->user_id,
                'name' => $member->user?->name,
                'email' => $member->user?->email,
                'status' => $member->status,
                'is_owner' => (bool) $member->is_owner,
                // null = ikut batas bawaan toko (pengaturan).
                'max_discount_percent' => $limits->has($member->user_id) && $limits[$member->user_id] !== null ? (float) $limits[$member->user_id] : null,
                'role' => $this->roleOf((bool) $member->is_owner, (bool) $member->full_access, $member->assignedPermissions->pluck('key')->all()),
            ]);

        $invitations = CompanyInvitation::query()
            ->where('company_id', $company->id)
            ->where('status', 'pending')
            ->where('expires_at', '>', now())
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (CompanyInvitation $invitation) => [
                'id' => $invitation->id,
                'email' => $invitation->email,
                'role' => $this->roleOf(false, (bool) $invitation->full_access, (array) $invitation->permissions),
                'expires_at' => $invitation->expires_at?->toISOString(),
                'accept_url' => CompanyInvitationMail::acceptUrl($invitation),
            ]);

        return $this->successResponse([
            'members' => $members,
            'invitations' => $invitations,
            'quota' => $this->quota->summaryFor($company),
            'roles' => collect(config('pos.role_templates'))
                ->map(fn (array $role, string $key) => ['key' => $key, 'label' => $role['label'], 'description' => $role['description']])
                ->values(),
        ]);
    }

    public function invite(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'role' => ['required', Rule::in(array_keys((array) config('pos.role_templates')))],
        ]);
        $email = mb_strtolower(trim($data['email']));
        $company = $this->company();

        $invitation = DB::transaction(function () use ($company, $email, $data, $request) {
            Company::query()->whereKey($company->id)->lockForUpdate()->firstOrFail();

            // Undangan menunggu ikut dihitung supaya pemilik tidak mengundang melebihi jatah paket.
            $pending = CompanyInvitation::query()
                ->where('company_id', $company->id)
                ->where('status', 'pending')
                ->where('expires_at', '>', now())
                ->where('email', '!=', $email)
                ->count();
            if ($this->quota->usedCount($company) + $pending >= $this->quota->limitFor($company)) {
                throw ApiException::make('USER_QUOTA_EXCEEDED', 'Jatah pengguna paket Kasir sudah penuh. Nonaktifkan pengguna lain atau perbesar paket.', 422);
            }

            $alreadyMember = CompanyUser::query()
                ->where('company_id', $company->id)
                ->where('status', 'active')
                ->whereHas('user', fn ($query) => $query->whereRaw('LOWER(email) = ?', [$email]))
                ->exists();
            if ($alreadyMember) {
                throw ApiException::make('ALREADY_COMPANY_MEMBER', 'Email ini sudah menjadi pengguna toko.', 422);
            }

            $attributes = [
                'product' => TenantDatabase::PRODUCT_POS,
                'full_access' => false,
                'permissions' => $this->templateKeys($data['role']),
                'token' => Str::random(48),
                'status' => 'pending',
                'invited_by' => $request->user()?->id,
                'expires_at' => now()->addDays(7),
                'accepted_at' => null,
            ];

            $invitation = CompanyInvitation::query()->where('company_id', $company->id)->where('email', $email)->first();
            if ($invitation) {
                $invitation->forceFill($attributes)->save();
            } else {
                $invitation = CompanyInvitation::query()->create(array_merge(['company_id' => $company->id, 'email' => $email], $attributes));
            }
            $this->logInvitation('access.invitation.created', $invitation);

            return $invitation;
        });

        $mailSent = true;
        try {
            Mail::to($invitation->email)->send(new CompanyInvitationMail($invitation, $company->name, $request->user()?->name));
        } catch (Throwable $e) {
            report($e);
            $mailSent = false;
        }

        return $this->successResponse([
            'id' => $invitation->id,
            'email' => $invitation->email,
            'role' => $data['role'],
            'accept_url' => CompanyInvitationMail::acceptUrl($invitation),
            'mail_sent' => $mailSent,
        ], $mailSent ? 'Undangan dikirim ke email.' : 'Undangan dibuat, tapi email gagal dikirim. Bagikan tautannya lewat WhatsApp.', 201);
    }

    public function revoke(int $id): JsonResponse
    {
        $invitation = CompanyInvitation::query()->where('company_id', $this->company()->id)->findOrFail($id);
        if ($invitation->status !== 'pending') {
            throw ApiException::make('INVITATION_INVALID', 'Undangan ini sudah tidak menunggu.', 422);
        }
        $invitation->forceFill(['status' => 'revoked'])->save();
        $this->logInvitation('access.invitation.revoked', $invitation);

        return $this->successResponse(null, 'Undangan dibatalkan.');
    }

    /** Ubah peran (`role`) dan/atau status (`active`/`inactive`) satu pengguna toko. */
    public function updateMember(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'role' => ['sometimes', Rule::in(array_keys((array) config('pos.role_templates')))],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
            'max_discount_percent' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
        ]);
        $member = CompanyUser::query()->where('company_id', $this->company()->id)->with('user:id,name')->findOrFail($id);
        $actor = (int) $request->user()?->id;

        if ($member->is_owner) {
            throw ApiException::make('OWNER_PROTECTED', 'Pemilik toko selalu punya akses penuh.', 422);
        }

        if (isset($data['role'])) {
            // Izin akuntansi (bila toko juga memakai Finlite Accounting) dipertahankan.
            $keep = $member->assignedPermissions()->pluck('permissions.key')
                ->reject(fn (string $key) => str_starts_with($key, 'pos.'))
                ->values()->all();
            $this->access->update($member, false, array_values(array_unique(array_merge($keep, $this->templateKeys($data['role'])))), $actor, 'access.user.permissions_updated', ['source' => 'pos', 'role' => $data['role']]);
        }
        if (isset($data['status']) && $data['status'] !== $member->status) {
            $this->access->changeStatus($member, $data['status'], $actor);
        }
        if (array_key_exists('max_discount_percent', $data)) {
            $stored = PosMemberLimit::query()->where('user_id', $member->user_id)->value('max_discount_percent');
            $before = $stored === null ? null : round((float) $stored, 2);
            $percent = $data['max_discount_percent'] === null ? null : round((float) $data['max_discount_percent'], 2);
            $this->security->setMemberLimit((int) $member->user_id, $percent);
            if ($before !== $percent) {
                $this->posAudit->record(
                    'settings.update',
                    sprintf('Batas diskon %s diubah menjadi %s.', $member->user?->name ?? 'pengguna', $percent === null ? 'ikut bawaan toko' : $percent.'%'),
                    $request->user(),
                    ['type' => 'member', 'id' => (int) $member->user_id, 'label' => $member->user?->name],
                    ['max_discount_percent' => [$before, $percent]],
                );
            }
        }

        return $this->successResponse(null, 'Pengguna toko diperbarui.');
    }

    private function company(): Company
    {
        $company = $this->context->company();
        abort_if(! $company, 404);

        return $company;
    }

    /** @return list<string> */
    private function templateKeys(string $role): array
    {
        $matrix = (array) config('permissions.matrix_keys', []);

        return array_values(array_intersect((array) config("pos.role_templates.{$role}.permissions", []), $matrix));
    }

    /** @param  array<int, string>  $keys */
    private function roleOf(bool $isOwner, bool $fullAccess, array $keys): string
    {
        if ($isOwner) {
            return 'owner';
        }
        if ($fullAccess) {
            return 'full_access';
        }
        $pos = array_values(array_filter($keys, fn (string $key) => str_starts_with($key, 'pos.')));
        sort($pos);
        foreach (array_keys((array) config('pos.role_templates')) as $role) {
            $template = $this->templateKeys($role);
            sort($template);
            if ($pos === $template) {
                return $role;
            }
        }

        return 'custom';
    }

    private function logInvitation(string $event, CompanyInvitation $invitation): void
    {
        // Log central: toko POS-only tidak punya tenant akuntansi untuk audit tenant.
        $this->audit->logSuccess([
            'company_id' => $invitation->company_id,
            'user_id' => $invitation->invited_by,
            'event' => $event,
            'module' => 'access',
            'action' => $event,
            'message' => 'Company invitation changed from POS.',
            'record_type' => CompanyInvitation::class,
            'record_id' => $invitation->id,
            'record_number' => $invitation->email,
            'new_values' => ['status' => $invitation->status, 'permissions' => $invitation->permissions],
            'metadata' => ['source' => 'pos'],
        ], tenant: false);
    }
}
