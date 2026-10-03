<?php

namespace Tests\Feature\Clients;

use App\Support\PhoneNumbers;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class PhoneNumbersTest extends TestCase
{
    /** @return array<string, array{0: ?string, 1: ?string, 2: ?string}> */
    public static function normalizations(): array
    {
        return [
            'ghana national with trunk zero' => ['0244100001', 'GH', '+233244100001'],
            'ghana national with spaces' => ['024 410 0001', 'GH', '+233244100001'],
            'ghana national with dashes and brackets' => ['(024) 410-0001', 'GH', '+233244100001'],
            'ghana without the zero' => ['244100001', 'GH', '+233244100001'],
            'ghana with country code, no plus' => ['233244100001', 'GH', '+233244100001'],
            'ghana international' => ['+233 24 410 0001', 'GH', '+233244100001'],
            'international 00 prefix' => ['00233244100001', 'GH', '+233244100001'],
            'international is accepted as typed in any country' => ['+44 7911 123456', 'GH', '+447911123456'],
            'international with no country known' => ['+447911123456', null, '+447911123456'],
            'lower-case country code' => ['0244100001', 'gh', '+233244100001'],
            'non-breaking space' => ["024\u{00A0}410\u{00A0}0001", 'GH', '+233244100001'],
            'unicode dash' => ["024\u{2013}410\u{2013}0001", 'GH', '+233244100001'],
            'nigeria mobile' => ['0803 123 4567', 'NG', '+2348031234567'],
            'kenya mobile' => ['0712 345 678', 'KE', '+254712345678'],
            'uk mobile' => ['07911 123456', 'GB', '+447911123456'],
            'us ten digits' => ['(202) 555-0123', 'US', '+12025550123'],
            'us eleven digits' => ['1 202 555 0123', 'US', '+12025550123'],
            'blank' => ['   ', 'GH', null],
            'null' => [null, 'GH', null],
            'letters' => ['024 CALL ME', 'GH', null],
            'letters inside' => ['0244a00001', 'GH', null],
            'too short for ghana' => ['02441', 'GH', null],
            'too long for ghana' => ['02441000012', 'GH', null],
            'international too short' => ['+23324', 'GH', null],
            'international too long' => ['+2332441000011234567', 'GH', null],
            'international starting with zero' => ['+0233244100001', 'GH', null],
            'local number, country not supported' => ['011 91234 5678', 'BR', null],
            'local number, no country' => ['0244100001', null, null],
            'plus alone' => ['+', 'GH', null],
        ];
    }

    #[Test]
    #[DataProvider('normalizations')]
    public function it_normalises_to_e164_or_refuses(?string $input, ?string $country, ?string $expected): void
    {
        $this->assertSame($expected, PhoneNumbers::normalize($input, $country));
    }

    #[Test]
    public function normalising_is_idempotent(): void
    {
        $once = PhoneNumbers::normalize('0244 100 001', 'GH');

        $this->assertSame($once, PhoneNumbers::normalize($once, 'GH'));
        $this->assertSame($once, PhoneNumbers::normalize($once, null));
    }

    #[Test]
    public function it_knows_which_countries_can_be_completed_from_local_format(): void
    {
        $this->assertTrue(PhoneNumbers::supportsLocalFormat('GH'));
        $this->assertTrue(PhoneNumbers::supportsLocalFormat('gh'));
        $this->assertFalse(PhoneNumbers::supportsLocalFormat('BR'));
        $this->assertFalse(PhoneNumbers::supportsLocalFormat(null));
    }

    /** @return array<string, array{0: string, 1: ?string}> */
    public static function searchTerms(): array
    {
        return [
            'local with trunk zero' => ['0244100001', '244100001'],
            'local with spaces' => ['024 410 0001', '244100001'],
            'local with dashes' => ['024-410-0001', '244100001'],
            'partial local' => ['0244', '244'],
            'international' => ['+233 24 410 0001', '+233244100001'],
            'international partial' => ['+233 24', '+23324'],
            '00 prefix' => ['00233 244', '+233244'],
            'digits only, no zero' => ['244100001', '244100001'],
            'a letter leaves it alone' => ['024 410 000a', null],
            'a name' => ['Ama Owusu', null],
            'an email' => ['ama@example.org', null],
            'a client number' => ['C-00012', null],
            'two digits is too little' => ['02', null],
            'blank' => ['   ', null],
        ];
    }

    #[Test]
    #[DataProvider('searchTerms')]
    public function it_rewrites_local_numbers_for_searching_and_leaves_everything_else(string $term, ?string $needle): void
    {
        $this->assertSame($needle, PhoneNumbers::searchNeedle($term));
    }

    #[Test]
    public function a_stored_number_contains_the_needle_of_any_way_staff_type_it(): void
    {
        $stored = PhoneNumbers::normalize('0244 100 001', 'GH');

        foreach (['0244100001', '024 410 0001', '024-410', '0244', '244100001', '+233 24 410 0001', '00233 244 100 001'] as $typed) {
            $needle = PhoneNumbers::searchNeedle($typed);

            $this->assertNotNull($needle, $typed);
            $this->assertStringContainsString($needle, $stored, "searching \"{$typed}\" must find {$stored}");
        }
    }

    #[Test]
    public function it_shows_a_number_in_the_viewers_own_format_or_internationally(): void
    {
        $this->assertSame('024 410 0001', PhoneNumbers::display('+233244100001', 'GH'));
        $this->assertSame('+233 24 410 0001', PhoneNumbers::display('+233244100001', 'US'));
        $this->assertSame('+233 24 410 0001', PhoneNumbers::display('+233244100001', null));
        $this->assertSame('07911 123456', PhoneNumbers::display('+447911123456', 'GB'));
        $this->assertSame('+44 7911 123456', PhoneNumbers::display('+447911123456', 'GH'));
        $this->assertSame('202 555 0123', PhoneNumbers::display('+12025550123', 'US'), 'US numbers have no trunk zero');
    }

    #[Test]
    public function it_returns_what_it_cannot_understand_exactly_as_stored(): void
    {
        $this->assertSame('', PhoneNumbers::display(null, 'GH'));
        $this->assertSame('', PhoneNumbers::display('', 'GH'));
        $this->assertSame('ext. 12', PhoneNumbers::display('ext. 12', 'GH'));
        $this->assertSame('+9999999999', PhoneNumbers::display('+9999999999', 'GH'));
    }
}
