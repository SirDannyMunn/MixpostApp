<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    // OpenRouter configuration (images + chat)
    'openrouter' => [
        'api_key' => env('OPENROUTER_API_KEY'),
        // Prefer api.openrouter.ai host to avoid site 404/Clerk interference
        'api_url' => env('OPENROUTER_API_URL', 'https://openrouter.ai/api/v1'),
        // Image generation defaults
        'default_model' => env('OPENROUTER_DEFAULT_MODEL', 'openai/dall-e-3'),
        'fallback_placeholder' => env('OPENROUTER_FALLBACK_PLACEHOLDER', true),
        // Text chat / classification models
        'chat_model' => env('OPENROUTER_MODEL', 'anthropic/claude-3.5-sonnet'),
        'classifier_model' => env('OPENROUTER_CLASSIFIER_MODEL', 'anthropic/claude-3.5-haiku'),

        // Optional: pricing per model for token cost estimation in auditing.
        // Fill these with your actual $/1K token rates (input as 'in', output as 'out').
        // You can remove entries you don't use. Keys are model identifiers.
        'pricing' => [
            // Active chat model
            env('OPENROUTER_MODEL', 'anthropic/claude-3.5-sonnet') => [
                'in' => 3.0,   // $ per 1K input tokens
                'out' => 15.0,  // $ per 1K output tokens
            ],
            // Active classifier model
            env('OPENROUTER_CLASSIFIER_MODEL', 'anthropic/claude-3.5-haiku') => [
                'in' => 3.0,
                'out' => 15.0,
            ],
            // Embeddings (input-only typically). Used by EmbeddingsService via OpenRouter
            env('OPENROUTER_EMBED_MODEL', 'openai/text-embedding-3-small') => [
                'in' => 0.02,
                'out' => 0.0, // usually 0 or null for embeddings
            ],
            // Image generation model (non-token). Keep as reference; not used by token estimator
            env('OPENROUTER_DEFAULT_MODEL', 'openai/dall-e-3') => [
                'image' => 0.4, // $ per image (placeholder)
            ],
        ],
    ],

    // Google Gemini (AI Studio) configuration
    'google' => [
        'api_key' => env('GOOGLE_API_KEY'),
        'api_url' => env('GOOGLE_API_URL', 'https://generativelanguage.googleapis.com/v1beta'),
        // Default model used for generateContent (image capable)
        'image_model' => env('GOOGLE_IMAGE_MODEL', 'gemini-2.0-flash-exp'),
    ],

    'deepgram' => [
        'api_key' => env('DEEPGRAM_KEY'),
        'api_url' => env('DEEPGRAM_API_URL', 'https://api.deepgram.com'),
        'model' => env('DEEPGRAM_MODEL', 'nova-2'),
        'language' => env('DEEPGRAM_LANGUAGE'),
    ],

    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'org_id' => env('OPENAI_ORG_ID'),
    ],

    'replicate' => [
        'api_key' => env('REPLICATE_API_KEY'),
    ],

    'elevenlabs' => [
        'api_key' => env('ELEVEN_LABS_API_KEY'),
        'api_url' => env('ELEVEN_LABS_API_URL', 'https://api.elevenlabs.io/v1'),
    ],

];
