<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SendOtpRequest;
use App\Http\Requests\VerifyOtpRequest;
use App\Services\Otp\Exceptions\OtpException;
use App\Services\Otp\OtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class OtpController extends Controller
{
    public function __construct(private readonly OtpService $otp) {}

    public function send(SendOtpRequest $request): JsonResponse
    {
        try {
            $result = $this->otp->request(
                $request->string('phone')->toString(),
                $request->ip(),
                $request->input('locale'),
            );

            return response()->json(['data' => $result], 202);
        } catch (OtpException $e) {
            return $this->error($e);
        }
    }

    public function verify(VerifyOtpRequest $request): JsonResponse
    {
        try {
            $result = $this->otp->verify(
                $request->string('reference')->toString(),
                $request->string('code')->toString(),
            );

            // Di sini biasanya kamu login-kan user atau tandai nomor terverifikasi.
            return response()->json(['data' => $result]);
        } catch (OtpException $e) {
            return $this->error($e);
        }
    }

    private function error(OtpException $e): JsonResponse
    {
        // JANGAN pernah menulis kode OTP ke log.
        Log::warning('[otp] ' . $e->errorCode, [
            'message' => $e->getMessage(),
            'meta'    => $e->meta,
        ]);

        return response()->json(['error' => $e->toArray()], $e->httpStatus);
    }
}
