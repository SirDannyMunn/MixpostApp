<?php

namespace App\Services\Voice\Prompts;

class VoiceProfileExtractV2Prompt
{
    /**
     * Build the extraction prompt for v2 voice traits.
     *
     * @param array $posts Array of cleaned post texts
     * @return array System and user messages for LLM
     */
    public static function build(array $posts): array
    {
        $postsList = '';
        foreach ($posts as $index => $text) {
            $postsList .= sprintf("\n---POST %d---\n%s\n", $index + 1, $text);
        }

        $systemMessage = <<<'SYSTEM'
You are a voice profile extraction expert. Your task is to analyze social media posts and extract **observable behavioral and structural constraints** that define how this person writes.

DO NOT output adjectives or vague descriptions. Extract **concrete rules** that a language model could follow to reproduce this writing style.

Your output MUST be valid JSON matching this exact schema:

{
  "schema_version": "2.0",
  "description": "string (1-2 sentence summary of voice)",
  
  "tone": ["array of tone descriptors"],
  "persona": "string or null (who are they presenting as)",
  "formality": "none|casual|neutral|formal|null",
  
  "sentence_length": "short|medium|long|null",
  "paragraph_density": "tight|normal|airy|null",
  "pacing": "slow|medium|fast|null",
  "emotional_intensity": "low|medium|high|null",
  
  "format_rules": {
    "casing": "lowercase|sentence_case|title_case|mixed|null",
    "line_breaks": "heavy|normal|minimal|null",
    "bullets": "none|light|heavy|null",
    "numbered_lists": "never|sometimes|often|null",
    "emoji_usage": "none|rare|normal|heavy|null",
    "profanity": "none|light|moderate|heavy|null",
    "url_style": "raw|markdown|no_urls|null"
  },
  
  "persona_contract": {
    "in_group": ["who they align with / include"],
    "out_group": ["who they reject / exclude"],
    "status_claims": ["how they position themselves"],
    "exclusion_language": ["phrases that signal us vs them"],
    "credibility_moves": ["how they establish authority"]
  },
  
  "rhetorical_devices": ["specific devices used: metaphor, rhetorical questions, etc"],
  "style_signatures": ["unique phrases, patterns, or verbal tics"],
  
  "do_not_do": ["negative constraints - things this voice NEVER does"],
  "must_do": ["positive constraints - things this voice ALWAYS does"],
  
  "keyword_bias": ["words or topics they frequently reference"],
  "phrases": {
    "openers": ["common opening lines or hooks"],
    "closers": ["common closing lines"],
    "cta_phrases": ["call-to-action phrases"],
    "rejection_phrases": ["phrases used to dismiss opposing views"]
  },
  
  "structure_patterns": {
    "common_sections": ["typical post structure: hook, body, cta, etc"],
    "cta_presence": "none|soft|hard|null",
    "offer_format": "none|simple|numbered_offers|multi_offer_pitch|null"
  },
  
  "reference_examples": ["direct quotes from posts that exemplify the voice"],
  
  "safety": {
    "toxicity_risk": "low|medium|high",
    "notes": "string or null"
  }
}

CRITICAL REQUIREMENTS:
1. Output ONLY valid JSON, no commentary
2. "do_not_do" must have at least 5 items
3. "style_signatures" must have at least 3 items
4. All enum fields must use ONLY the listed values or null
5. Extract OBSERVABLE patterns, not subjective assessments
6. Be specific in constraints (e.g. "never uses exclamation marks" not "calm tone")
SYSTEM;

        $userMessage = <<<USER
Analyze these posts and extract the voice profile according to the schema:
{$postsList}

Return ONLY the JSON object. No explanations.
USER;

        return [
            'system' => $systemMessage,
            'user' => $userMessage,
        ];
    }
}
