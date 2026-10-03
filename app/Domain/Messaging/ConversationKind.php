<?php

namespace App\Domain\Messaging;

use App\Domain\Shared\LabelledEnum;

enum ConversationKind: string
{
    use LabelledEnum;

    case Direct = 'direct';
    case Group = 'group';
    case Client = 'client';

    /** The permission needed to use threads of this kind. */
    public function permission(): string
    {
        return $this === self::Client ? 'messages.client' : 'messages.send';
    }
}
