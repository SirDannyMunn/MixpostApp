<?php

namespace App\Jobs;

use App\Models\Template;
use App\Services\Ai\LLMClient;
use App\Services\Ai\SchemaValidator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ParseTemplateFromTextJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $templateId, public string $rawText, public string $platform = 'generic') {}

    public function handle(LLMClient $llm, SchemaValidator $validator): void
    {
        $template = Template::find($this->templateId);
        if (!$template) return;

        $system = 'From the given post, extract a reusable template schema. Output JSON: {structure:{sections:[{key,goal,rules?}]}, constraints:{max_chars?,emoji?,tone?}}';
        $user = "PLATFORM: {$this->platform}\nPOST:\n{$this->rawText}";
        $decoded = $llm->call('template_parse', $system, $user, 'template_parse', ['max_tokens' => 800]);
        if (!is_array($decoded) || !$validator->validate('template_parse', $decoded)) return;

        $template->template_data = [
            'structure' => $decoded['structure'] ?? ['sections' => []],
            'constraints' => $decoded['constraints'] ?? [],
        ];
        $template->save();
    }
}

