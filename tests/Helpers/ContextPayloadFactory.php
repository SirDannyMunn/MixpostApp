<?php

namespace Tests\Helpers;

class ContextPayloadFactory
{
    /**
     * Build a VIP knowledge reference payload in the shape expected by the
     * ContentGenerator override resolver.
     */
    public static function makeVipKnowledgeReference(?string $id, string $content, int $maxLength = 20000): array
    {
        $trimmed = mb_substr($content, 0, $maxLength);
        return [
            'id' => ($id !== null && $id !== '') ? $id : null,
            'type' => 'reference',
            'content' => $trimmed,
        ];
    }
}

