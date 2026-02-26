

---

# BACKEND REQUIREMENTS

### Backend-Owned Context + Conversation–Snapshot Linking (Aligned To `storeSnapshot()` Reality)

---

## 1️⃣ Objectives

1. Backend owns context truth.
2. Conversation + Messages must be linkable to:

   * Generation Snapshots
   * Context state at that moment
3. Conversations must be able to:

   * Rehydrate state from last known snapshot
   * Expose that to frontend
4. Voice / template / swipes / facts must live in backend truth

---

## 2️⃣ Key Architecture Direction

> **Snapshots remain the source of truth**
> Conversations and messages need to know **which snapshot represents current active state**.

So instead of frontend storing context →
we store relationships and derive context via snapshots.

---

## 3️⃣ Required Data Enhancements

---

### A. Link Conversations & Messages to Snapshots

Update `generation_snapshots`:

Add:

```
conversation_id (uuid, nullable)
conversation_message_id (uuid, nullable)
```

Whenever `storeSnapshot()` runs, if chat context exists:

* attach to conversation
* attach to message if applicable

Now every message → has proof of its context.

---

### B. Track Active Context Per Conversation (Backend Generated)

Instead of blindly duplicating fields everywhere, conversation can store:

```
last_snapshot_id (uuid, nullable)
```

Backend can derive active context from that snapshot.

However, to avoid repeatedly parsing snapshot JSON every time, **also store denormalized active context**:

Add to `ai_chat_conversations`:

```
active_voice_profile_id
active_template_id
active_swipe_ids (json)
active_fact_ids (json)
active_reference_ids (json)
```

These are:

* written whenever new snapshot stored
* derived from snapshot payload
* NOT driven by frontend

This gives:

* snapshot = historical truth
* active_* fields = fast read state

This is the right combo.

---

## 4️⃣ Behavioral Rules

---

### On Generation

When chat runs:

1️⃣ Build snapshot (existing behavior)
2️⃣ Call `SnapshotService::storeSnapshot()`
3️⃣ Ensure:

* `conversation_id` stored
* `conversation_message_id` stored

4️⃣ Extract from snapshot:

* voice_profile_id
* template_id
* swipe_ids[]
* fact_ids[]
* reference_ids[]

5️⃣ Update conversation:

```
conversation.last_snapshot_id = snapshot.id
conversation.active_voice_profile_id = ...
conversation.active_template_id = ...
conversation.active_swipe_ids = [...]
conversation.active_fact_ids = [...]
conversation.active_reference_ids = [...]
```

No frontend state involved.

---

### On Conversation Load

When frontend requests conversation:

Backend returns:

```
conversation: {
  ...,
  active_context: {
    voice_profile_id,
    template_id,
    swipe_ids[],
    fact_ids[],
    reference_ids[]
  }
}
```

Frontend renders pills.
Done.

---

### On Context Change Without Message (Optional Support)

If you allow context to be changed without triggering generation:

Backend endpoint required:

```
PATCH /ai/conversations/{id}/context
```

Backend updates active_* fields directly.
No snapshot yet (still okay).
Next generation snapshot continues truth timeline.

If you want MVP lean:

* you can skip this
* only update context upon generation events
* users changing pills only matters when sending next AI request

That’s your product choice.

---

## 5️⃣ Snapshot Content Requirement

Ensure snapshot stores:

* template_id
* voice_profile_id
* swipe_ids
* fact_ids
* reference_ids
* platform
* options
* model used
* validation
* etc

But MOST IMPORTANTLY:
(previously missing)

```
voice_profile_id
voice_source ("override_reference" | "inline" | "none")
```

Because voice matters long term.

---

## 6️⃣ Acceptance Criteria

Backend is correct when:

* [ ] `generation_snapshots` linked to:

  * conversation
  * message
* [ ] conversations know:

  * last_snapshot_id
  * active_* context fields populated
* [ ] snapshot stores voice data
* [ ] reopening conversation gives frontend usable context data
* [ ] no backend guesswork
* [ ] system is deterministic + auditable
* [ ] replay still works

---

## 7️⃣ Why This Is Correct Strategy

* Backend remains authority
* Snapshots provide forensic truth
* Conversations provide fast truth
* UI becomes dumb/simple
* Debugging becomes easy
* Replayability remains perfect
* Determinism remains intact

And most importantly:
users experience **consistent AI behavior**, not roulette.
