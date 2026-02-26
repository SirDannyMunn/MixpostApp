<?php

return [
    // Cosine distance threshold (lower is more similar). Results above this are discarded.
    'similarity' => [
        'threshold' => env('VECTOR_SIMILARITY_THRESHOLD', 0.35),
    ],

    // Maximum chunks by intent (used to cap retrieval size)
    'retrieval' => [
        'max_per_intent' => [
            'educational' => env('VECTOR_MAX_EDU', 5),
            'persuasive' => env('VECTOR_MAX_PERSUASIVE', 4),
            'story' => env('VECTOR_MAX_STORY', 6),
            'contrarian' => env('VECTOR_MAX_CONTRARIAN', 5),
            'emotional' => env('VECTOR_MAX_EMOTIONAL', 5),
            '*' => env('VECTOR_MAX_DEFAULT', 5),
        ],
    ],
];

