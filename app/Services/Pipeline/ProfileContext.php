<?php

namespace App\Services\Pipeline;

class ProfileContext
{
    /**
     * @param array<string, mixed> $defaults
     */
    public function __construct(
        public readonly string $name,
        public readonly ?string $platform,
        public readonly ?string $actorId,
        public readonly array $defaults,
        public readonly bool $persist,
        public readonly bool $skipAnnotations,
        public readonly bool $strict,
    ) {
    }

    /**
     * @param array<string, mixed> $profileConfig
     * @param array{skip_annotations?:bool,strict?:bool} $options
     */
    public static function fromConfig(array $profileConfig, array $options = []): self
    {
        return new self(
            name: (string) ($profileConfig['name'] ?? ''),
            platform: isset($profileConfig['platform']) ? (string) $profileConfig['platform'] : null,
            actorId: isset($profileConfig['actor']) ? (string) $profileConfig['actor'] : null,
            defaults: (array) ($profileConfig['defaults'] ?? []),
            persist: !array_key_exists('persist', $profileConfig) || $profileConfig['persist'] !== false,
            skipAnnotations: (bool) ($options['skip_annotations'] ?? false),
            strict: (bool) ($options['strict'] ?? false),
        );
    }

    public function isKeywordDriven(): bool
    {
        $defaults = $this->defaults;

        return !empty($defaults['keywords'])
            || !empty($defaults['query'])
            || !empty($defaults['q'])
            || !empty($defaults['searchQueries']);
    }

    /**
     * @return array<int, string>
     */
    public function keywordSignals(): array
    {
        $defaults = $this->defaults;

        $signals = [];
        foreach (['keywords', 'query', 'q', 'searchQueries'] as $key) {
            if (!empty($defaults[$key])) {
                $signals[] = $key;
            }
        }

        return $signals;
    }

    public function expectsTranscriptText(): bool
    {
        return $this->name === 'youtube_transcript' || str_contains($this->name, 'transcript');
    }
}
