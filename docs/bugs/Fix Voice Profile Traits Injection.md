Here is the engineering requirements document to standardize how Voice Profiles are parsed and injected into the prompt.

# Engineering Spec: Voice Profile Traits Injection (v1.0)

**Priority:** High
**Component:** `ContextFactory` / `PromptComposer`
**Objective:** Replace generic voice summaries with structured data from the `VoiceProfile->traits` JSON column to improve stylistic fidelity.

---

## 1. Problem Description

Currently, the system uses a high-level summary or the `traits_preview` field when applying a Voice Profile. This ignores the rich, granular data stored in the `traits` JSON column (e.g., specific "Do Not Do" lists, "Style Signatures," and "Reference Examples").

As a result, the AI captures the *vibe* (e.g., "Casual") but misses the specific *tactics* (e.g., "Heavy emoji use," "No passive voice," "Short sentence length") that make the voice authentic.

## 2. Data Source

The source of truth is the **`traits`** column in the `voice_profiles` table.

* **Format:** JSON String
* **Key Fields to Extract:**
* `style_signatures` (Array): Specific writing habits (e.g., "rhetorical questions", "ALL CAPS for emphasis").
* `do_not_do` (Array): Negative constraints (e.g., "no passive voice", "no long paragraphs").
* `tone` (Array): Adjectives describing the voice.
* `reference_examples` (Array): Verbatim post examples for few-shot prompting.



## 3. Technical Requirements

### 3.1. Context Assembly Update (`ContextFactory`)

Modify how the `VoiceProfile` object is processed when building the `Context`.

1. **Decode JSON:** Ensure `$voiceProfile->traits` is decoded from a JSON string into an accessible array or object.
2. **Map Fields:** Extract the specific keys listed above. Do not rely on `description` or `traits_preview`.

### 3.2. Prompt Composition Update (`PromptComposer`)

The system prompt must explicitly list these constraints to force the LLM to adhere to them.

**Current (Hypothetical) Output:**

> "Voice: Tech entrepreneur voice with high-energy, casual tone."

**Required New Output:**
Construct a "Voice Signature" block in the System Prompt that looks like this:

```text
VOICE_SIGNATURE:
- Tone: Casual, Playful, Confident, Boastful
- Style Markers: heavy emoji use, abbreviated writing, rhetorical questions, ALL CAPS for emphasis
- Constraints (DO NOT DO): formal language, long paragraphs, complex sentences, academic tone
- Examples:
  * "Just CRUSHED our Q3 numbers! 📈 Revenue up 127% YoY!"
  * "Hot take: AI isn't killing jobs - it's creating MASSIVE opportunities!"

```

### 3.3. Fallback Logic

If `traits` is invalid JSON or missing key fields, fall back to the `description` field to prevent errors.

---

## 4. Implementation Logic (Pseudo-Code)

**File:** `app/Services/Ai/Generation/Steps/PromptComposer.php` (or where `voice` is formatted).

```php
public function formatVoice(VoiceProfile $voice): string
{
    // 1. Decode the traits JSON
    $traits = is_string($voice->traits) ? json_decode($voice->traits, true) : $voice->traits;

    // 2. Return fallback if decode fails
    if (!$traits) {
        return "Voice Description: " . $voice->description;
    }

    // 3. Construct structured instruction
    $output = "VOICE GUIDELINES:\n";
    
    // Tones
    if (!empty($traits['tone'])) {
        $output .= "- Tone: " . implode(', ', $traits['tone']) . "\n";
    }

    // Style Signatures (The "How")
    if (!empty($traits['style_signatures'])) {
        $output .= "- Style Signatures: " . implode(', ', $traits['style_signatures']) . "\n";
    }

    // Negative Constraints (The "Do Not")
    if (!empty($traits['do_not_do'])) {
        $output .= "- ABSOLUTELY AVOID: " . implode(', ', $traits['do_not_do']) . "\n";
    }

    // Few-Shot Examples (Crucial for mimicry)
    if (!empty($traits['reference_examples'])) {
        $output .= "- Reference Examples:\n";
        foreach (array_slice($traits['reference_examples'], 0, 3) as $ex) {
            $output .= "  * \"$ex\"\n";
        }
    }

    return $output;
}

```

---

## 5. Verification Checklist

* [ ] **Parsing:** System successfully decodes the `traits` JSON string.
* [ ] **Prompt Injection:** The log (`ai:show-prompt`) shows the detailed "Style Signatures" and "ABSOLUTELY AVOID" lists.
* [ ] **Output Quality:** The generated content uses specific traits (e.g., all caps for emphasis, emojis) defined in the profile.
* [ ] **Safety:** The system does not crash if `traits` is empty or null.