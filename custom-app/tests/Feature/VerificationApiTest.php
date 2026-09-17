<?php

declare(strict_types=1);

use App\Models\Verification;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::preventStrayRequests();

    Http::fake([
        'graph.facebook.com/*' => Http::response([
            'messages' => [['id' => 'wamid.TEST123']],
        ]),
    ]);

    $this->ctx = createTenantWithKey();
});

/** Kirim permintaan verifikasi dan kembalikan kode yang benar-benar dikirim. */
function requestOtp(array $ctx, string $phone = '081234567890'): array
{
    $response = test()->withToken($ctx['token'])
        ->postJson('/v1/verifications', ['phone' => $phone]);

    // Pengiriman berjalan di antrean, persis seperti di produksi.
    workQueue();

    $code = null;
    Http::recorded(function (Request $request) use (&$code): void {
        $code = codeFromMetaRequest($request) ?? $code;
    });

    return [$response, $code];
}

it('membuat verifikasi dan mengembalikan 202 dengan nomor termasking', function (): void {
    [$response] = requestOtp($this->ctx);

    $response->assertStatus(202)
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.masked_phone', '6281*****7890')
        ->assertJsonPath('data.channels', ['whatsapp', 'sms'])
        ->assertJsonPath('data.attempts_remaining', 5);
});

it('mengirim WhatsApp lewat Meta dengan template authentication', function (): void {
    requestOtp($this->ctx);

    Http::assertSent(function (Request $request): bool {
        return str_contains($request->url(), 'graph.facebook.com')
            && data_get($request->data(), 'messaging_product') === 'whatsapp'
            && data_get($request->data(), 'to') === '6281234567890'
            && data_get($request->data(), 'template.name') === 'otp_authentication';
    });
});

it('menyetujui kode yang benar', function (): void {
    [$response, $code] = requestOtp($this->ctx);
    $id = $response->json('data.id');

    $this->withToken($this->ctx['token'])
        ->postJson("/v1/verifications/{$id}/check", ['code' => $code])
        ->assertOk()
        ->assertJsonPath('data.status', 'approved');

    expect(Verification::find($id)->consumed_at)->not->toBeNull();
});

it('menaikkan attempts dan menurunkan sisa percobaan saat kode salah', function (): void {
    [$response] = requestOtp($this->ctx);
    $id = $response->json('data.id');

    $this->withToken($this->ctx['token'])
        ->postJson("/v1/verifications/{$id}/check", ['code' => '000000'])
        ->assertStatus(400)
        ->assertJsonPath('error.code', 'invalid_code')
        ->assertJsonPath('error.details.attempts_remaining', 4);

    expect(Verification::find($id)->attempts)->toBe(1);
});

it('menolak percobaan keenam dengan too_many_attempts', function (): void {
    [$response, $code] = requestOtp($this->ctx);
    $id = $response->json('data.id');

    foreach (range(1, 5) as $attempt) {
        $this->withToken($this->ctx['token'])
            ->postJson("/v1/verifications/{$id}/check", ['code' => '000000'])
            ->assertStatus($attempt === 5 ? 429 : 400);
    }

    // Percobaan keenam ditolak bahkan dengan kode yang benar.
    $this->withToken($this->ctx['token'])
        ->postJson("/v1/verifications/{$id}/check", ['code' => $code])
        ->assertStatus(429)
        ->assertJsonPath('error.code', 'too_many_attempts');
});

it('hanya mengizinkan satu kali pemakaian kode', function (): void {
    [$response, $code] = requestOtp($this->ctx);
    $id = $response->json('data.id');

    $this->withToken($this->ctx['token'])
        ->postJson("/v1/verifications/{$id}/check", ['code' => $code])
        ->assertOk();

    $this->withToken($this->ctx['token'])
        ->postJson("/v1/verifications/{$id}/check", ['code' => $code])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'already_consumed');
});

it('menolak permintaan kedua untuk nomor yang masih punya verifikasi aktif', function (): void {
    requestOtp($this->ctx);
    [$second] = requestOtp($this->ctx);

    $second->assertStatus(409)->assertJsonPath('error.code', 'verification_exists');
});

it('menolak nomor di luar prefix yang diizinkan', function (): void {
    $this->withToken($this->ctx['token'])
        ->postJson('/v1/verifications', ['phone' => '+14155552671'])
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'country_not_allowed');
});

it('menolak format nomor yang tidak valid', function (): void {
    $this->withToken($this->ctx['token'])
        ->postJson('/v1/verifications', ['phone' => '0812'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'invalid_phone');
});

it('menolak verifikasi yang sudah kedaluwarsa', function (): void {
    [$response, $code] = requestOtp($this->ctx);
    $id = $response->json('data.id');

    Verification::where('id', $id)->update(['expires_at' => now()->subMinute()]);

    $this->withToken($this->ctx['token'])
        ->postJson("/v1/verifications/{$id}/check", ['code' => $code])
        ->assertStatus(410)
        ->assertJsonPath('error.code', 'expired');
});

it('membatalkan verifikasi yang masih aktif', function (): void {
    [$response] = requestOtp($this->ctx);
    $id = $response->json('data.id');

    $this->withToken($this->ctx['token'])
        ->deleteJson("/v1/verifications/{$id}")
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled');

    expect(Verification::find($id)->status->value)->toBe('cancelled');
});

it('menampilkan status verifikasi tanpa membocorkan kode atau hash', function (): void {
    [$response] = requestOtp($this->ctx);
    $id = $response->json('data.id');

    $show = $this->withToken($this->ctx['token'])->getJson("/v1/verifications/{$id}");

    $show->assertOk()->assertJsonPath('data.id', $id);

    expect($show->content())
        ->not->toContain('code_hash')
        ->not->toContain('msisdn_hash')
        ->not->toContain('6281234567890');
});

it('menolak permintaan tanpa API key', function (): void {
    $this->postJson('/v1/verifications', ['phone' => '081234567890'])
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'unauthorized');
});

it('menolak API key yang sudah dicabut', function (): void {
    $this->ctx['key']->forceFill(['revoked_at' => now()])->save();

    $this->withToken($this->ctx['token'])
        ->postJson('/v1/verifications', ['phone' => '081234567890'])
        ->assertStatus(401);
});

it('menolak kunci milik tenant yang ditangguhkan', function (): void {
    $this->ctx['tenant']->forceFill(['status' => 'suspended'])->save();

    $this->withToken($this->ctx['token'])
        ->postJson('/v1/verifications', ['phone' => '081234567890'])
        ->assertStatus(403);
});
