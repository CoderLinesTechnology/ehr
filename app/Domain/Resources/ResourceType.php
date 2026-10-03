<?php

namespace App\Domain\Resources;

/** The five kinds of resource; each has its own tab, icon and tile colour (comp 09). */
enum ResourceType: string
{
    case Guide = 'guide';
    case Form = 'form';
    case Document = 'document';
    case Video = 'video';
    case Faq = 'faq';

    public function label(): string
    {
        return match ($this) {
            self::Guide => 'Guide',
            self::Form => 'Form',
            self::Document => 'Document',
            self::Video => 'Video',
            self::Faq => 'FAQ',
        };
    }

    /** The tab label ("All, Guides, Forms, Documents, Videos, FAQs"). */
    public function plural(): string
    {
        return $this->label().'s';
    }

    /** Lucide icon of the tile. */
    public function icon(): string
    {
        return match ($this) {
            self::Guide, self::Document => 'file-text',
            self::Form => 'clipboard-list',
            self::Video => 'square-play',
            self::Faq => 'circle-help',
        };
    }

    /** Tile tone: guides blue, forms green, videos purple, documents orange, FAQs teal. */
    public function tone(): string
    {
        return match ($this) {
            self::Guide => 'blue',
            self::Form => 'green',
            self::Video => 'purple',
            self::Document => 'amber',
            self::Faq => 'teal',
        };
    }

    /** "5 min read" for text, "10 min" for things you fill in or watch. */
    public function minutesLabel(int $minutes): string
    {
        return in_array($this, [self::Form, self::Video], true) ? "{$minutes} min" : "{$minutes} min read";
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $t) => $t->value, self::cases());
    }
}
