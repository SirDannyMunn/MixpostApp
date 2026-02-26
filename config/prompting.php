<?php

return [
    // Target token budget for the model's context (excludes model response tokens)
    'context_token_budget' => (int) env('PROMPT_CONTEXT_TOKEN_BUDGET', 1800),
];

