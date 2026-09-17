<?php

declare(strict_types=1);

use App\Domain\Otp\Exceptions\OtpException;
use App\Domain\Otp\Services\PhoneNormalizer;

beforeEach(function (): void {
    $this->normalizer = new PhoneNormalizer('62');
});

it('menormalkan berbagai bentuk nomor Indonesia ke E.164 tanpa plus', function (string $input): void {
    expect($this->normalizer->normalize($input, ['62']))->toBe('6281234567890');
})->with([
    '081234567890',
    '+6281234567890',
    '6281234567890',
    '0812-3456-7890',
    '0812 3456 7890',
    '(0812) 3456-7890',
]);

it('menolak nomor tanpa digit', function (): void {
    $this->normalizer->normalize('bukan-nomor', ['62']);
})->throws(OtpException::class);

it('menolak nomor yang terlalu pendek', function (): void {
    $this->normalizer->normalize('0812', ['62']);
})->throws(OtpException::class);

it('menolak prefix negara di luar daftar', function (): void {
    expect(fn () => $this->normalizer->normalize('+14155552671', ['62']))
        ->toThrow(OtpException::class);
});

it('menerima prefix lain kalau application mengizinkannya', function (): void {
    expect($this->normalizer->normalize('+60123456789', ['62', '60']))->toBe('60123456789');
});

it('memasking nomor menyisakan empat depan dan empat belakang', function (): void {
    expect(PhoneNormalizer::mask('6281234567890'))->toBe('6281*****7890');
});

it('memetakan prefix ke operator', function (string $msisdn, string $operator): void {
    expect(PhoneNormalizer::operator($msisdn))->toBe($operator);
})->with([
    ['6281234567890', 'telkomsel'],
    ['6285512345678', 'indosat'],
    ['6281712345678', 'xl'],
    ['6283112345678', 'axis'],
    ['6289512345678', 'tri'],
    ['6288112345678', 'smartfren'],
    ['6280012345678', 'unknown'],
    ['14155552671', 'unknown'],
]);

it('menghasilkan hash yang stabil dan berbeda antar nomor', function (): void {
    expect(PhoneNormalizer::hash('6281234567890'))
        ->toBe(PhoneNormalizer::hash('6281234567890'))
        ->not->toBe(PhoneNormalizer::hash('6281234567891'));
});
