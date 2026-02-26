Short answer: **yes, this is almost certainly `max_tokens` biting you**, plus your prompts are now fighting each other. That’s why you’re getting hard cut-offs mid-sentence.

Let’s break it down clearly.

---

# What’s happening

### 1️⃣ `max_tokens` *is* a hard output ceiling

OpenRouter’s `/chat/completions` behaves like OpenAI’s API, not Anthropic’s native API:

* `max_tokens` = **max output tokens**
* When the model hits that, it **hard stops**, even mid-sentence
* That’s exactly what you’re seeing

Your payload:

```
max_tokens = 1200
```

Your decoded output keeps stopping right around there.
So yes — the behavior is *expected*.

### Bonus: **tokens ≠ characters**

1200 tokens ≈ 800–1200 English words ≈ 4–7k characters.

But JSON mode, schema scaffolding, system instructions, and structure reinforcement **eat into that output budget** because the model still has to “budget” structure generation.

So although your *content text* looks short, the model tokenizes far more internally.

---

### 2️⃣ Your prompts are now actively conflicting

You now have **two system prompts**, and they contradict each other.

System Prompt #1:

> JSON API. Return ONE valid JSON. If you fail, return `{}`.

System Prompt #2:

> You are an expert social writer. Constraints: max_chars=1200; emoji allow; tone professional.

Then OpenRouter JSON mode adds its own constraints.

So the model is now juggling:

* strict JSON format enforcement
* template enforcement
* swipe structure awareness
* tone rules
* 1200 char constraint (prompt)
* 1200 token constraint (API)

Result = confused model, earlier stopping, conservative completion.

---

### 3️⃣ Your **user message is truncated**

Your payload literally cuts off mid-JSON:

```
"structure":[{"purpose":"P"
```

That means the model has to infer the rest.
Claude hates malformed “structured-thinking instruction” and responds conservatively when unsure.

Previously your system was loose and forgiving.
Now it’s strict and brittle.

---

# Why truncation didn’t happen before

Before we tightened JSON mode + stricter prompts, Claude:

* was allowed to free-generate
* was allowed to exceed constraints loosely
* wasn’t boxed in with two authoritarian system prompts

Now we boxed it into a smaller output envelope and told it:

> obey structure exactly
> return JSON only
> never break format
> keep content under X
> BUT ALSO write big persuasive content

So it stops early rather than risk breaking format.

This is expected Anthropic behavior.

---

# What you *should* do (practical, not hand-wavy)

### ✅ 1️⃣ Stop using tight `max_tokens`

If you’re generating long posts:

```
max_tokens: 4096
```

Or just leave it unset unless you’re streaming.

You want to enforce content limits via:

* schema
* system prompt
* validator
* repair loop

Not the API scissors.

---

### ✅ 2️⃣ Kill one of the system prompts

Pick one authority.

Right now the hierarchy is:

SYSTEM
SYSTEM
USER

Claude treats system as law.
You gave it two governments.

Do this instead:

* **One single system prompt**
* Containing:

  * JSON rule
  * tone rule
  * structure rule
  * constraints

Clean + simple + one authority.

---

### ✅ 3️⃣ Stop mixing “guidance constraints” with “mechanical API constraints”

Business reality:

* API limits = *technical guard rails*
* Prompt constraints = *creative instruction*
* Validation layer = *truth police*
* Repair loop = *fixer*

Right now you’re trying to solve business rules with API scissors.
Wrong layer.

---

### ✅ 4️⃣ Fix the literal broken user prompt

Your prompt is being passed malformed:

```
"structure":[{"purpose":"P"
```

That matters.
Fix the upstream truncation.

---

# Blunt truth opinion

You’re right:

> “I feel like all of this stuff is now just making my system extremely complicated”

Yes.
The system has become **over-engineered** for where you are right now.

You stacked:

* JSON strict mode
* schema validator
* structure enforcement
* template constraints
* swipe structure rhythm logic
* fallback composer
* retrieval gating
* dual system prompts

Before the system was resilient, forgiving, and output-first.
Now it’s perfect-or-die.

And it’s dying.

---

# My forward-thinking recommendation

For now:

1️⃣ Remove second system prompt
2️⃣ Raise or remove `max_tokens`
3️⃣ Fix broken user content truncation
4️⃣ Keep JSON enforcement
5️⃣ Let the validator complain instead of API chopping content

Once stable, then tighten rules again.
