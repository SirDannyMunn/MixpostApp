<?php

namespace App\Services\Ai;

use App\Services\Ai\Generation\DTO\PromptSignals;

class FolderScopeResolver
{
    public function __construct(
        protected EmbeddingsService $embeddings,
        protected FolderEmbeddingRepository $repo,
    ) {}

    /**
     * @param array{mode?:string,maxFolders?:int,minScore?:float,allowUnscopedFallback?:bool,candidateK?:int} $policy
     * @return array{
     *   folder_ids:array<int,string>,
     *   candidates:array<int,array{folder_id:string,score:float,distance:float}>,
     *   min_score:float,
     *   max_folders:int,
     *   mode:string,
     *   method:string,
     *   used:bool,
     *   block_retrieval:bool,
     *   reason?:string
     * }
     */
    public function resolve(
        string $orgId,
        string $userId,
        string $prompt,
        array $classification,
        ?PromptSignals $signals,
        array $policy
    ): array {
        $mode = (string) ($policy['mode'] ?? 'off');
        $maxFolders = (int) ($policy['maxFolders'] ?? 2);
        $minScore = (float) ($policy['minScore'] ?? 0.8);
        $allowUnscoped = array_key_exists('allowUnscopedFallback', $policy)
            ? (bool) $policy['allowUnscopedFallback']
            : true;
        $candidateK = (int) ($policy['candidateK'] ?? 10);

        $result = [
            'folder_ids' => [],
            'candidates' => [],
            'min_score' => $minScore,
            'max_folders' => $maxFolders,
            'mode' => $mode,
            'method' => 'embedding',
            'used' => false,
            'block_retrieval' => false,
        ];

        if ($mode === 'off') {
            return $result;
        }

        $embedding = $this->embeddings->embedOne($prompt);
        $candidates = $this->repo->search($orgId, $embedding, $candidateK);

        $result['candidates'] = array_slice($candidates, 0, 5);
        $result['used'] = true;

        if (empty($candidates)) {
            $result['reason'] = 'no_candidates';
            $result['block_retrieval'] = ($mode === 'strict' || !$allowUnscoped);
            return $result;
        }

        $selected = array_values(array_filter($candidates, fn($c) => ($c['score'] ?? 0.0) >= $minScore));
        if (!empty($selected)) {
            $selected = array_slice($selected, 0, max(1, $maxFolders));
            $result['folder_ids'] = array_values(array_filter(array_map(fn($c) => (string) ($c['folder_id'] ?? ''), $selected)));
            return $result;
        }

        $result['reason'] = 'low_score';
        $result['block_retrieval'] = ($mode === 'strict' || !$allowUnscoped);
        return $result;
    }
}
