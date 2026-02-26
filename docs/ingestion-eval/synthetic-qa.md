# Synthetic QA Specification (Phase 1 – Minimal)

## Purpose
Create objective retrieval tests.

## Generation Rules
* Generate 2–3 questions
* Each question:
  * answerable from one chunk
  * factual (who / what / when)
  * no synthesis or opinion

## Output Schema
{
  "question": "string",
  "expected_answer_summary": "string",
  "target_chunk_ids": ["uuid"]
}

## Evaluation Metric
* Retrieval only (no answer generation)
* k = 3
* Record:
  * retrieved rank of first target chunk
  * -1 if missing

## Phase 1 Success
* ≥1 question retrieved @ k=3

