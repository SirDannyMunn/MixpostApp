<?php

namespace App\Enums;

enum LlmRequestType: string
{
    case INFER_CONTEXT = 'infer_context';
    case NORMALIZE = 'normalize';
    case CHUNK_EXTRACT = 'chunk_extract';
    case EMBED = 'embed';
    case GENERATE = 'generate';
    case REPAIR = 'repair';
    case CLASSIFY = 'classify';
    case REPLAY = 'replay';
    case SCORE_FOLDER_CANDIDATES = 'score_folder_candidates';
    case TEMPLATE_PARSE = 'template_parse';
    case FAITHFULNESS_AUDIT = 'faithfulness_audit';
    case SYNTHETIC_QA = 'synthetic_qa_min';
    case GENERATION_GRADER = 'generation_grader';
    case REFLEXION_CRITIQUE = 'reflexion_critique';
    case REFLEXION_REFINE = 'reflexion_refine';
}
