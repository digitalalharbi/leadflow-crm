<?php

namespace Tests\Unit;

use App\Services\Leads\PhoneNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PhoneNormalizerTest extends TestCase
{
    private PhoneNormalizer $normalizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->normalizer = new PhoneNormalizer;
    }

    #[DataProvider('saudiFormats')]
    public function test_it_collapses_every_saudi_format_to_one_key(string $input): void
    {
        $this->assertSame('+966512345678', $this->normalizer->normalize($input));
    }

    public static function saudiFormats(): array
    {
        return [
            'national trunk' => ['0512345678'],
            'bare mobile' => ['512345678'],
            'country code' => ['966512345678'],
            'e164' => ['+966512345678'],
            'spaced' => ['+966 51 234 5678'],
            'dashed' => ['05-1234-5678'],
            'parens' => ['(966) 512-345-678'],
            'double zero' => ['00966512345678'],
            'arabic indic digits' => ['٠٥١٢٣٤٥٦٧٨'],
            'persian digits' => ['۰۵۱۲۳۴۵۶۷۸'],
        ];
    }

    public function test_it_keeps_a_foreign_number_intact(): void
    {
        $this->assertSame('+971501234567', $this->normalizer->normalize('+971501234567'));
    }

    public function test_it_normalises_a_saudi_landline(): void
    {
        $this->assertSame('+966112345678', $this->normalizer->normalize('0112345678'));
    }

    #[DataProvider('unusableValues')]
    public function test_it_rejects_unusable_input(?string $input): void
    {
        $this->assertNull($this->normalizer->normalize($input));
    }

    public static function unusableValues(): array
    {
        return [[null], [''], ['abc'], ['1'], ['---'], ['12345']];
    }

    public function test_it_honours_a_non_saudi_default_country_code(): void
    {
        $this->assertSame('+201001234567', $this->normalizer->normalize('01001234567', '20'));
    }

    public function test_it_strips_the_plus_for_whatsapp_links(): void
    {
        $this->assertSame('966512345678', $this->normalizer->toWhatsApp('+966512345678'));
    }
}
