<?php

namespace Tests\Feature\Clients;

use App\Domain\Clients\ClientSearchTerm;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ClientSearchTermTest extends TestCase
{
    /** @return array<string, array{0: string, 1: ?int, 2: list<string>, 3: ?string}> */
    public static function terms(): array
    {
        return [
            // text
            'a name' => ['Ama', null, ['Ama'], null],
            'a full name' => ['Ama Owusu', null, ['Ama', 'Owusu'], null],
            'whitespace is collapsed' => ["  Ama \t  Owusu  ", null, ['Ama', 'Owusu'], null],
            'an email' => ['ama@example.org', null, ['ama@example.org'], null],
            'more than five words are ignored' => ['a1 b2 c3 d4 e5 f6 g7', null, ['a1', 'b2', 'c3', 'd4', 'e5'], null],
            // fewer than three characters: a name prefix, never a trigram scan
            'two letters' => ['am', null, [], 'am'],
            'one letter' => ['a', null, [], 'a'],
            // client numbers
            'formatted number' => ['C-00012', 12, [], null],
            'lower-case formatted number' => ['c-00012', 12, [], null],
            'number with a space' => ['C 12', 12, [], null],
            'number with no dash' => ['c12', 12, [], null],
            'CL format' => ['CL-0012', 12, [], null],
            'lower-case CL' => ['cl-12', 12, [], null],
            'four digit padded' => ['0012', 12, [], null],
            'padded number' => ['00012', 12, [], null],
            'six digit formatted number' => ['C-100000', 100000, [], null],
            'a bare number is a number, too short to be a phone number' => ['12', 12, [], null],
            'a bare single digit' => ['7', 7, [], null],
            // phone numbers, which may also be a client number
            'three digits: number or phone fragment' => ['244', 244, ['244'], null],
            'a longer bare number: number or phone fragment' => ['5551234', 5551234, ['5551234'], null],
            'local phone number' => ['0244100001', null, ['244100001'], null],
            'local phone number with spaces' => ['024 410 0001', null, ['244100001'], null],
            'international phone number' => ['+233 24 410 0001', null, ['+233244100001'], null],
            'a ten digit bare number is not a client number' => ['2441000012', null, ['2441000012'], null],
        ];
    }

    /** @param list<string> $words */
    #[Test]
    #[DataProvider('terms')]
    public function it_understands_what_staff_type(string $typed, ?int $number, array $words, ?string $prefix): void
    {
        $term = ClientSearchTerm::parse($typed);

        $this->assertSame($number, $term->number, 'client number');
        $this->assertSame($words, $term->words, 'words that must all occur');
        $this->assertSame($prefix, $term->namePrefix, 'name prefix');
    }

    #[Test]
    public function nothing_typed_is_empty_and_nonsense_matches_nothing(): void
    {
        $this->assertTrue(ClientSearchTerm::parse(null)->isEmpty());
        $this->assertTrue(ClientSearchTerm::parse('   ')->isEmpty());
        $this->assertFalse(ClientSearchTerm::parse('Ama')->isEmpty());

        foreach (['+', '-', '()', '0', '000', '--'] as $nonsense) {
            $term = ClientSearchTerm::parse($nonsense);

            $this->assertFalse($term->isEmpty(), $nonsense);
            $this->assertTrue($term->matchesNothing(), "\"{$nonsense}\" can match nothing");
        }

        $this->assertFalse(ClientSearchTerm::parse('Ama')->matchesNothing());
        $this->assertFalse(ClientSearchTerm::parse('12')->matchesNothing());
    }

    #[Test]
    public function a_term_is_capped_in_length(): void
    {
        $term = ClientSearchTerm::parse(str_repeat('a', 500));

        $this->assertSame(ClientSearchTerm::MAX_LENGTH, mb_strlen($term->text));
    }

    #[Test]
    public function text_with_a_letter_is_never_treated_as_a_phone_number(): void
    {
        // "024 410 000a" contains a letter: it stays text and is matched as typed, words and all.
        $term = ClientSearchTerm::parse('024 410 000a');

        $this->assertNull($term->number);
        $this->assertSame(['024', '410', '000a'], $term->words);
    }
}
