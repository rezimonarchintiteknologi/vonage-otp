<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class OtpFlowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('otp:rl:phone:' . sha1('6281234567890'));
    }

    public function test_request_then_verify_succeeds(): void
    {
        Http::fake([
            'api.nexmo.com/v2/verify' => Http::response([
                'request_id' => 'req-123',
                'check_url'  => null,
            ], 202),
            'api.nexmo.com/v2/verify/req-123' => Http::response([
                'request_id' => 'req-123',
                'status'     => 'completed',
            ], 200),
        ]);

        $send = $this->postJson('/api/otp/request', ['phone' => '081234567890']);

        $send->assertStatus(202)
            ->assertJsonPath('data.masked_phone', '6281****7890')
            ->assertJsonPath('data.channels', ['whatsapp', 'sms'])
            ->assertJsonPath('data.channel_timeout', 30);

        // request_id provider tidak boleh bocor ke client
        $this->assertStringNotContainsString('req-123', $send->getContent());

        $reference = $send->json('data.reference');
        $this->assertSame(40, strlen($reference));

        // workflow yang dikirim ke Vonage: whatsapp lalu sms, nomor E.164 tanpa plus
        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.nexmo.com/v2/verify'
                && $request['workflow'] === [
                    ['channel' => 'whatsapp', 'to' => '6281234567890'],
                    ['channel' => 'sms', 'to' => '6281234567890'],
                ];
        });

        $verify = $this->postJson('/api/otp/verify', [
            'reference' => $reference,
            'code'      => '123456',
        ]);

        $verify->assertOk()
            ->assertJsonPath('data.verified', true)
            ->assertJsonPath('data.attempts', 1);

        // reference sekali pakai
        $this->postJson('/api/otp/verify', ['reference' => $reference, 'code' => '123456'])
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'REFERENCE_NOT_FOUND');
    }

    public function test_wrong_code_returns_invalid_code(): void
    {
        Http::fake([
            'api.nexmo.com/v2/verify' => Http::response(['request_id' => 'req-9'], 202),
            'api.nexmo.com/v2/verify/req-9' => Http::response([
                'title'  => 'Invalid Code',
                'type'   => 'https://developer.vonage.com/api-errors/verify#invalid-code',
                'detail' => 'The code you provided does not match the expected value.',
            ], 400),
        ]);

        $reference = $this->postJson('/api/otp/request', ['phone' => '081234567890'])
            ->json('data.reference');

        $this->postJson('/api/otp/verify', ['reference' => $reference, 'code' => '000000'])
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'INVALID_CODE');
    }

    public function test_rate_limit_per_phone(): void
    {
        Http::fake(['api.nexmo.com/*' => Http::response(['request_id' => 'req-x'], 202)]);

        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/otp/request', ['phone' => '081234567890'])->assertStatus(202);
        }

        $this->postJson('/api/otp/request', ['phone' => '081234567890'])
            ->assertStatus(429)
            ->assertJsonPath('error.code', 'RATE_LIMITED');
    }

    public function test_country_prefix_blocked(): void
    {
        Http::fake();

        $this->postJson('/api/otp/request', ['phone' => '+14155552671'])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'COUNTRY_NOT_ALLOWED');

        Http::assertNothingSent();
    }
}
