<?php

namespace App\Domain\Clients;

use App\Support\PhoneNumbers;

/**
 * What a staff member typed in a client search box, understood.
 *
 *   CL-0012 / cl-12 / C-00012 / 0012 → client number 12 (and nothing else)
 *   12                      → client number 12 (a bare number: too short to be text)
 *   244 / 5551234           → client number 244 AND the digits inside a phone number
 *   024 410 0001            → the national significant number inside +233244100001
 *   +233 24 410 0001        → the same, international
 *   ama owusu / ama@x.org   → every word must occur in name, e-mail or phone
 *   ab                      → fewer than 3 characters: a name starting with "ab"
 *
 * Words of three characters or more are served by the trigram index on
 * clients.search_text; shorter text cannot use a trigram index, so it is a
 * name-prefix match instead.
 */
final readonly class ClientSearchTerm
{
    public const MAX_LENGTH = 100;

    public const MAX_WORDS = 5;

    /**
     * @param  list<string>  $words  every one must occur in search_text (matched as typed, LIKE wildcards not special)
     */
    public function __construct(
        public string $text,
        public ?int $number = null,
        public array $words = [],
        public ?string $namePrefix = null,
    ) {}

    public static function parse(?string $raw): self
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', (string) $raw));
        $text = trim(mb_substr($text, 0, self::MAX_LENGTH));

        if ($text === '') {
            return new self('');
        }

        // C-00012 — an explicit client number.
        if (preg_match('/^cl?[\s\-]*0*(\d{1,9})$/i', $text, $m) === 1) {
            return new self($text, self::positive($m[1]));
        }

        // 0012 / 00012 — a zero-padded number: short, and two leading zeros mean no local phone number
        // (an international 00-prefixed number is far longer than six digits).
        if (strlen($text) <= 6 && preg_match('/^0{2,}([1-9]\d*)$/', $text, $m) === 1) {
            return new self($text, self::positive($m[1]));
        }

        // Digits and separators only: a phone number (local or international), maybe also a client number.
        if (preg_match('/^\+?[\d\s\p{Z}().\-\x{2010}-\x{2015}\x{2212}]+$/u', $text) === 1) {
            $number = preg_match('/^[1-9]\d{0,8}$/', $text) === 1 ? (int) $text : null;
            $needle = PhoneNumbers::searchNeedle($text);

            // A rewritten needle under three characters ("024" → "24") is too little to search a phone number by.
            return new self($text, $number, ($needle !== null && strlen($needle) >= 3) ? [$needle] : []);
        }

        if (mb_strlen($text) < 3) {
            return new self($text, null, [], preg_match('/\p{L}/u', $text) === 1 ? $text : null);
        }

        return new self($text, null, array_slice(explode(' ', $text), 0, self::MAX_WORDS));
    }

    /** Nothing was typed. */
    public function isEmpty(): bool
    {
        return $this->text === '';
    }

    /** Something was typed but it can match nothing (for example just "+"). */
    public function matchesNothing(): bool
    {
        return ! $this->isEmpty() && $this->number === null && $this->words === [] && $this->namePrefix === null;
    }

    private static function positive(string $digits): ?int
    {
        $number = (int) $digits;

        return $number >= 1 ? $number : null;
    }
}
