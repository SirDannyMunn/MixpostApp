<?php

return [
    // Minimum confidence-weighted similarity score to accept a swipe structure
    'similarity_threshold' => env('SWIPE_SIMILARITY_THRESHOLD', 0.30),
    // Default number of swipes to use when auto-selecting
    'top_n' => env('SWIPE_TOP_N', 2),

    // Canonical-first resolver (structure-fit-first)
    'structure_candidate_limit' => env('STRUCTURE_CANDIDATE_LIMIT', 10),
    // Fit score is 0–100
    'structure_min_fit_score' => env('STRUCTURE_MIN_FIT_SCORE', 55),
];

