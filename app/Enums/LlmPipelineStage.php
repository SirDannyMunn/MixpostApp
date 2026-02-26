<?php

namespace App\Enums;

enum LlmPipelineStage: string
{
    case INGESTION = 'ingestion';
    case RETRIEVAL = 'retrieval';
    case GENERATION = 'generation';
    case REPAIR = 'repair';
    case EMBEDDING = 'embedding';
}
