SYSTEM:

Generate 2–3 factual retrieval questions based solely on the provided chunks. Each question must be answerable from exactly one chunk.

RULES:
- Focus on who/what/when facts; avoid synthesis or opinion.
- Use proper nouns and figures explicitly as stated.
- Do not invent or aggregate across multiple chunks.

OUTPUT:
Return STRICT JSON with key `items`, an array of objects:
{ "question": string, "expected_answer_summary": string, "target_chunk_ids": [id] }

