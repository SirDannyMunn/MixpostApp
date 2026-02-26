You’re right.
Let’s stop engineering around the failure and **fix the real problem: the API must always return valid JSON**. Everything else is noise until THAT is reliable.

Here is the **simple, focused correction plan** for your dev — no bloat, no over-complication.

---

# ✅ Problem

Claude is returning **malformed JSON**, which breaks the pipeline.
This is the real root cause.

We shouldn’t be patching downstream logic to guess, recover, or rescue content.
We should make Claude *always* return valid structured JSON.

---

# 🎯 Goal

Make `runJsonContentCall()` **boring and deterministic**:

* LLM → ALWAYS valid JSON
* JSON → decode cleanly
* Schema → pass reliably

No heroics.
No “smart recovery”.
No complicated fallback machinery.

---

# 🔧 Fix Strategy

Two changes solve 95% of these failures.

---

## **1️⃣ Fix Prompting — Make JSON Return Non-Negotiable**

Claude fails when:

* prompt is long
* system prompt is fuzzy
* too many context blocks
* output scaffolding not enforced

### Update the instruction to Claude to something like this:

```
You must respond ONLY with valid JSON.
Do not include explanations, markdown, or prose.

Return exactly:
{
  "content": "<final post text>"
}

If you cannot produce content for any reason,
return:
{
  "content": ""
}
```

Then explicitly forbid text outside JSON:

```
Do NOT include commentary.
Do NOT return text before or after JSON.
Do NOT stream partial JSON.
```

Claude responds cleanly when the contract is blunt and narrow.

---

## **2️⃣ Reduce Cognitive Load on LLM**

Claude breaks JSON most often because it is overloaded.

Right now you are giving it:

* template structure
* swipe structures
* giant duplicated business summary
* long user prompt
* constraints
* tone stuff
* references dump

It’s too much.

Claude is brilliant at reasoning.
Claude is terrible at structured JSON **under stress**.

### Fix:

Remove duplicated junk.
Stop dumping walls of text.
Trim `user_context`.
Shorten instructions.

---

# 🔒 Hard API Settings to Apply

These improve stability massively:

```
response_format: json_object
temperature: 0.4 – 0.6
max_tokens: SAFE HEADROOM (don’t sit at edge)
streaming: OFF
```

Streaming + JSON = chaos.
Claude truncates JSON when token cap hits.
That’s what you saw.

---

# 🧪 Testing

After changes, test:

1️⃣ Very short prompt
2️⃣ Medium prompt
3️⃣ Worst-case long prompt

Confirm:

* JSON always closes
* no leaked assistant text
* no markdown
* no emoji outside JSON
* no truncation

---

# 🎯 Acceptance Criteria

This issue is done when:

* Claude never returns invalid JSON in normal use
* You can run 20 generations in a row with zero failures
* You delete 90% of the “recovery logic” we discussed earlier because you don’t need it anymore

---

# 🧠 Philosophy

Right now your system was getting complicated because:

* it was cleaning up after the LLM
* instead of making the LLM behave

That’s backwards.

We want:
**Strong contract → simple pipeline → predictable behavior**

---

If you want, next I can:

* rewrite your prompt block
* reduce your context payload design
* lock in a stable schema contract
* give you a working Claude JSON template that never breaks

But yes — you’re thinking correctly.

Solve the response discipline problem.
Everything else becomes easier.
