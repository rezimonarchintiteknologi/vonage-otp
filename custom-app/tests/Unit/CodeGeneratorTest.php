<?php

declare(strict_types=1);

use App\Domain\Otp\Services\CodeGenerator;

beforeEach(function (): void {
    $this->generator = new CodeGenerator('pepper-untuk-test');
});

it('menghasilkan kode dengan panjang yang diminta', function (int $length): void {
    expect($this->generator->generate($length))
        ->toHaveLength($length)
        ->toMatch('/^\d+$/');
})->with([4, 6, 8]);

it('mempertahankan angka nol di depan', function (): void {
    // 200 kali cukup untuk menabrak kasus kode berawalan nol pada panjang 4.
    $codes = array_map(fn () => $this->generator->generate(4), range(1, 200));

    expect($codes)->each->toHaveLength(4);
});

it('tidak mengulang kode secara mencolok', function (): void {
    $codes = array_map(fn () => $this->generator->generate(6), range(1, 100));

    expect(count(array_unique($codes)))->toBeGreaterThan(90);
});

it('mengikat hash ke nomor tujuan', function (): void {
    $a = $this->generator->hash('123456', '628123456789');
    $b = $this->generator->hash('123456', '628987654321');

    expect($a)->not->toBe($b);
});

it('memverifikasi kode yang benar dan menolak yang salah', function (): void {
    $hash = $this->generator->hash('123456', '628123456789');

    expect($this->generator->verify('123456', '628123456789', $hash))->toBeTrue()
        ->and($this->generator->verify('654321', '628123456789', $hash))->toBeFalse()
        ->and($this->generator->verify('123456', '628999999999', $hash))->toBeFalse();
});

it('menolak pepper kosong', function (): void {
    new CodeGenerator('');
})->throws(RuntimeException::class);

it('menghasilkan hash berbeda untuk pepper berbeda', function (): void {
    $other = new CodeGenerator('pepper-lain');

    expect($this->generator->hash('123456', '628123456789'))
        ->not->toBe($other->hash('123456', '628123456789'));
});
