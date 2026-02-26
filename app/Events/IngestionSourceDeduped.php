<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class IngestionSourceDeduped
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $ingestionSourceId,
        public string $reason,
        public ?string $existingKnowledgeItemId = null,
    ) {}
}

