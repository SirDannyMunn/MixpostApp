This is a great specific question.

**Short Answer:**
**Do not change your API.** Modify the requirements document.

Your current structure (`document_context.references`) is perfectly semantic. It says: *"Here is a list of things the user explicitly referenced."*

Changing the frontend to support a new `overrides` object is unnecessary engineering work. You can achieve the exact same "VIP/Override" behavior by simply telling your backend developer: **"Treat everything inside `document_context.references` as a mandatory override."**

Here is the updated requirements document tailored to match your **actual** current payload.

---

# Technical Requirements: Explicit Context Overrides

*(Revised for existing API Structure)*

## 1. Objective

Enable users to explicitly control generation context via the frontend `@mention` system. Any item passed in the `document_context.references` array must be treated as a **Mandatory Override (VIP)**, taking precedence over automatic retrieval.

## 2. API Payload Specification

**No changes to the endpoint.** We will utilize the existing `document_context.references` array.

**Incoming JSON Payload (Reference):**

```json
{
  "message": "@MyTemplate write a post...",
  "document_context": {
    "references": [
      {
        "id": "019b31e7-...",
        "type": "bookmark",  <-- This field drives the logic
        "title": "...",
        "content": "...",
        "url": "..."
      },
      {
        "id": "tpl_123",
        "type": "template",  <-- We need to support this new type
        "title": "Listicle Structure"
      }
    ]
  }
}

```

## 3. Backend Logic Updates

### A. Controller Layer (`AiController.php`)

The controller currently passes `document_context` to the service. No major changes needed here, assuming it passes the array as-is.

### B. Service Layer (`ContentGeneratorService.php`)

**Method:** `generate(...)`

You must separate the "Explicit References" from the "Background Context."

**Logic Flow:**

1. **Extract References:** Pull the `references` array from the input.
2. **Categorize Overrides:** Loop through the references and sort them into buckets based on `type`.

```php
$overrides = [
    'template_id' => null,
    'knowledge'   => [],
    'swipes'      => [],
    'facts'       => []
];

foreach ($references as $ref) {
    switch ($ref['type']) {
        case 'template':
            $overrides['template_id'] = $ref['id'];
            break;
        case 'swipe':
            $overrides['swipes'][] = $ref['id'];
            break;
        case 'fact':
            $overrides['facts'][] = $ref['id'];
            break;
        case 'bookmark': // Treat bookmarks as Knowledge Items
        case 'file':
            $overrides['knowledge'][] = $ref; // Keep full object if content is provided
            break;
    }
}

```

3. **Template Override:**
* **IF** `$overrides['template_id']` is set -> `Template::find($id)`.
* **ELSE** -> Run standard `TemplateSelector::select()`.


4. **Pass to Context Assembler:**
* Pass the sorted `$overrides` arrays into `ContextAssembler::build()`.



### C. Context Assembler (`ContextAssembler.php`)

**Method:** `build(...)`

The Assembler must implement a **"VIP First"** strategy for the Token Budget.

**Step 1: VIP Injection (The Override Loop)**
Iterate through the `$overrides` lists. These items skip the vector search scoring.

* **Templates/Swipes:** Load structure from DB using the ID.
* **Bookmarks/Knowledge:**
* *Optimization:* Since your API sends the `content` string in the payload, **use it directly**.
* Do not waste DB queries looking up the bookmark content if the frontend just sent it to you.
* Add to context context string immediately.
* **Deduct from Token Budget.**



**Step 2: Automatic Backfill**

* Check remaining Token Budget.
* **IF** budget > 0: Run the standard `Retriever` logic (vector search) to find *additional* relevant facts/chunks to fill the gaps.
* **IF** budget <= 0: Skip automatic retrieval. (The user's manual selections were so large they filled the context).

---

## 4. Frontend & Data Requirements

*To make this work without API structure changes, you just need to ensure the **data inside** the structure is correct.*

1. **Supported Types:** The frontend must send the correct string in the `type` field for different objects:
* `"bookmark"` (Existing)
* `"template"` (New)
* `"swipe"` (New)
* `"fact"` (New)


2. **Template Handling:** If the user selects a Template, the frontend must ensure it sends the `id` in the reference object so the backend can look up the structure.

## 5. Summary of Behavior

* **User Action:** User types `@` and selects "Viral Story".
* **API Request:** Sends `type: "bookmark"` (or `"story"`) in `references`.
* **Backend:** Sees the reference. Instead of searching for "relevant stories," it **forces** this specific story into the context.
* **Result:** The AI writes using exactly what the user requested.