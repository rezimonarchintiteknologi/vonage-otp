<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Otp\Exceptions\OtpException;
use App\Domain\Otp\Services\OtpService;
use App\Http\Controllers\Controller;
use App\Http\Requests\CheckVerificationRequest;
use App\Http\Requests\CreateVerificationRequest;
use App\Http\Resources\VerificationResource;
use App\Models\Verification;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;

final class VerificationController extends Controller
{
    public function __construct(
        private readonly OtpService $otp,
        private readonly TenantContext $context,
    ) {}

    public function store(CreateVerificationRequest $request): JsonResponse
    {
        $verification = $this->otp->request(
            $this->context->application(),
            $request->string('phone')->toString(),
            [
                'purpose' => $request->string('purpose', 'login')->toString(),
                'channels' => $request->input('channels'),
                'mode' => $this->context->mode(),
                'ip' => $request->ip(),
                'metadata' => (array) $request->input('metadata', []),
            ],
        );

        // 202: kode sudah dibuat dan pengirimannya sedang berjalan. Klien tidak
        // perlu menunggu provider untuk bisa menampilkan layar input kode.
        return VerificationResource::make($verification)
            ->response()
            ->setStatusCode(202);
    }

    public function check(CheckVerificationRequest $request, string $id): JsonResponse
    {
        $verification = $this->otp->check(
            $this->findOrFail($id),
            $request->string('code')->toString(),
        );

        return VerificationResource::make($verification)->response();
    }

    public function show(string $id): JsonResponse
    {
        return VerificationResource::make($this->findOrFail($id))->response();
    }

    public function destroy(string $id): JsonResponse
    {
        $verification = $this->findOrFail($id);

        if (! $this->otp->cancel($verification)) {
            throw OtpException::notFound();
        }

        return response()->json(['data' => ['id' => $verification->id, 'status' => 'cancelled']]);
    }

    /**
     * 404, bukan 403, untuk verifikasi milik tenant lain: membalas 403 memberi
     * tahu penyerang bahwa ID tersebut ada dan milik orang lain.
     */
    private function findOrFail(string $id): Verification
    {
        $verification = Verification::query()
            ->with('application')
            ->where('tenant_id', $this->context->tenantId())
            ->find($id);

        return $verification ?? throw OtpException::notFound();
    }
}
