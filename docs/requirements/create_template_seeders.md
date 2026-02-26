Create a `Seeder` class for the templates table template model, which uses the following data. Note that we already have a template `Seeder`, but the data is junk, so we need to replace it with this. 

[
[
 "name" => "LinkedIn Authority Post",
 "description" => "Thought-leadership style educational post for LinkedIn to build authority.",
 "thumbnail_url" => null,
 "template_type" => "linkedin",
 "category" => "educational",
 "is_public" => true,
 "usage_count" => 0,
 "template_data" => json_encode([
    "structure" => [
        ["section" => "Hook", "description" => "Bold statement or contrarian insight", "required" => true],
        ["section" => "Context", "description" => "Explain the situation or problem", "required" => true],
        ["section" => "Lesson", "description" => "What you learned / discovered", "required" => true],
        ["section" => "Value Points", "description" => "3–5 short punchy bullets of value", "required" => true],
        ["section" => "CTA", "description" => "Invite discussion or encourage engagement", "required" => false],
    ],
    "constraints" => [
        "max_chars" => 2000,
        "emoji" => "disallow",
        "tone" => "authority",
    ]
 ])
],
[
 "name" => "LinkedIn Story Post",
 "description" => "Narrative style storytelling format for emotional engagement.",
 "template_type" => "linkedin",
 "category" => "story",
 "is_public" => true,
 "usage_count" => 0,
 "template_data" => json_encode([
    "structure" => [
        ["section" => "Opening Emotion", "required" => true],
        ["section" => "Situation", "required" => true],
        ["section" => "Struggle", "required" => true],
        ["section" => "Breakthrough", "required" => true],
        ["section" => "Lesson", "required" => true],
        ["section" => "CTA", "required" => false],
    ],
    "constraints" => [
        "max_chars" => 2200,
        "emoji" => "allow",
        "tone" => "emotional",
    ]
 ])
],
[
 "name" => "LinkedIn Lead Gen Post",
 "description" => "Direct response LinkedIn post aimed at conversions.",
 "template_type" => "linkedin",
 "category" => "sales",
 "is_public" => true,
 "usage_count" => 0,
 "template_data" => json_encode([
    "structure" => [
        ["section" => "Pain Hook", "required" => true],
        ["section" => "Problem Amplification", "required" => true],
        ["section" => "Solution Preview", "required" => true],
        ["section" => "Proof", "required" => true],
        ["section" => "Offer", "required" => true],
        ["section" => "CTA", "required" => true],
    ],
    "constraints" => [
        "max_chars" => 2200,
        "emoji" => "disallow",
        "tone" => "persuasive",
    ]
 ])
],
[
 "name" => "Twitter Thread — Educational",
 "description" => "Structured educational Twitter thread.",
 "template_type" => "twitter",
 "category" => "educational",
 "is_public" => true,
 "usage_count" => 0,
 "template_data" => json_encode([
    "structure" => [
        ["section" => "Hook Tweet", "required" => true],
        ["section" => "Thesis Tweet", "required" => true],
        ["section" => "Value Tweet 1", "required" => true],
        ["section" => "Value Tweet 2", "required" => true],
        ["section" => "Value Tweet 3", "required" => false],
        ["section" => "Summary Tweet", "required" => true],
        ["section" => "CTA Tweet", "required" => true]
    ],
    "constraints" => [
        "max_chars" => 4000,
        "emoji" => "allow",
        "tone" => "educational"
    ]
 ])
],
[
 "name" => "Twitter Single Post",
 "description" => "Punchy single-tweet value post.",
 "template_type" => "twitter",
 "category" => "short",
 "is_public" => true,
 "usage_count" => 0,
 "template_data" => json_encode([
   "structure" => [
      ["section" => "Hook", "required" => true],
      ["section" => "Core Insight", "required" => true],
      ["section" => "CTA", "required" => false]
   ],
   "constraints" => [
      "max_chars" => 280,
      "emoji" => "allow",
      "tone" => "direct"
   ]
 ])
],
[
 "name" => "Instagram Reel Script",
 "description" => "Fast-pacing spoken reel script.",
 "template_type" => "reel",
 "category" => "script",
 "is_public" => true,
 "usage_count" => 0,
 "template_data" => json_encode([
  "structure" => [
      ["section" => "Hook (1s)", "required" => true],
      ["section" => "Context (2s)", "required" => true],
      ["section" => "Main Value (6–10s)", "required" => true],
      ["section" => "CTA (2s)", "required" => false]
  ],
  "constraints" => [
      "max_chars" => 800,
      "emoji" => "allow",
      "tone" => "energetic"
  ]
 ])
],
[
 "name" => "YouTube Short Script",
 "description" => "Short punchy YouTube short script.",
 "template_type" => "short",
 "category" => "script",
 "is_public" => true,
 "usage_count" => 0,
 "template_data" => json_encode([
  "structure" => [
      ["section" => "Pattern Interrupt", "required" => true],
      ["section" => "Problem Statement", "required" => true],
      ["section" => "Value Delivery", "required" => true],
      ["section" => "CTA", "required" => false]
  ],
  "constraints" => [
      "max_chars" => 900,
      "emoji" => "allow",
      "tone" => "bold"
  ]
 ])
],
[
 "name" => "Instagram Carousel — Educational",
 "description" => "Carousel designed for saving & sharing.",
 "template_type" => "carousel",
 "category" => "educational",
 "is_public" => true,
 "usage_count" => 0,
 "template_data" => json_encode([
 "structure" => [
   ["section" => "Slide 1 — Hook", "required" => true],
   ["section" => "Slide 2 — Why This Matters", "required" => true],
   ["section" => "Slide 3–5 — Value Points", "required" => true],
   ["section" => "Final Slide — CTA", "required" => true]
 ],
 "constraints" => [
    "max_chars" => 2500,
    "emoji" => "allow",
    "tone" => "educational"
 ]
 ])
],
[
 "name" => "Problem–Agitate–Solve Post",
 "description" => "Classic PAS persuasion structure.",
 "template_type" => "generic",
 "category" => "sales",
 "is_public" => true,
 "usage_count" => 0,
 "template_data" => json_encode([
 "structure" => [
    ["section" => 'Problem', "required" => true],
    ["section" => 'Agitate', "required" => true],
    ["section" => 'Solution', "required" => true],
    ["section" => "CTA", "required" => true]
 ],
 "constraints" => [
   "max_chars" => 1500,
   "emoji" => "disallow",
   "tone" => "persuasive"
 ]
 ])
],
[
 "name" => "Before–After–Bridge Post",
 "description" => "BAB persuasion template.",
 "template_type" => "generic",
 "category" => "sales",
 "is_public" => true,
 "usage_count" => 0,
 "template_data" => json_encode([
 "structure" => [
    ["section" => "Before", "required" => true],
    ["section" => "After", "required" => true],
    ["section" => "Bridge", "required" => true],
    ["section" => "CTA", "required" => false]
 ],
 "constraints" => [
   "max_chars" => 1500,
   "emoji" => "disallow",
   "tone" => "persuasive"
 ]
 ])
],
[
 "name" => "Case Study Micro Post",
 "description" => "Short performance / proof post.",
 "template_type" => "generic",
 "category" => "proof",
 "is_public" => true,
 "usage_count" => 0,
 "template_data" => json_encode([
 "structure" => [
    ["section" => "Who + Situation", "required" => true],
    ["section" => "What We Did", "required" => true],
    ["section" => "Result", "required" => true],
    ["section" => "CTA", "required" => true]
 ],
 "constraints" => [
   "max_chars" => 1600,
   "emoji" => "disallow",
   "tone" => "authority"
 ]
 ])
],
[
 "name" => "Educational Listicle",
 "description" => "List style educational post.",
 "template_type" => "generic",
 "category" => "educational",
 "is_public" => true,
 "usage_count" => 0,
 "template_data" => json_encode([
 "structure" => [
    ["section" => "Hook", "required" => true],
    ["section" => "Intro Context", "required" => true],
    ["section" => "List Items", "required" => true],
    ["section" => "Summary", "required" => false],
    ["section" => "CTA", "required" => false]
 ],
 "constraints" => [
   "max_chars" => 2000,
   "emoji" => "allow",
   "tone" => "educational"
 ]
 ])
],
[
 "name" => "Hot Take",
 "description" => "Contrarian opinion post.",
 "template_type" => "generic",
 "category" => "opinion",
 "is_public" => true,
 "template_data" => json_encode([
 "structure" => [
    ["section" => "Bold Take", "required" => true],
    ["section" => "Reasoning", "required" => true],
    ["section" => "Clarification / Risk", "required" => false],
    ["section" => "CTA Debate", "required" => false]
 ],
 "constraints" => [
   "max_chars" => 1500,
   "emoji" => "disallow",
   "tone" => "bold"
 ]
 ])
],
[
 "name" => "Myth Busting Post",
 "description" => "Debunk common misconceptions.",
 "template_type" => "generic",
 "category" => "educational",
 "is_public" => true,
 "template_data" => json_encode([
 "structure" => [
    ["section" => "Myth Statement", "required" => true],
    ["section" => "Truth", "required" => true],
    ["section" => "Explanation", "required" => true],
    ["section" => "CTA", "required" => false]
 ],
 "constraints" => [
   "max_chars" => 1800,
   "emoji" => "allow",
   "tone" => "educational"
 ]
 ])
],
[
 "name" => "Step-By-Step Guide",
 "description" => "Actionable guide post.",
 "template_type" => "generic",
 "category" => "guide",
 "is_public" => true,
 "template_data" => json_encode([
 "structure" => [
    ["section" => "Hook", "required" => true],
    ["section" => "Why It Matters", "required" => true],
    ["section" => "Steps", "required" => true],
    ["section" => "Outcome Expectation", "required" => true],
    ["section" => "CTA", "required" => false]
 ],
 "constraints" => [
   "max_chars" => 2200,
   "emoji" => "allow",
   "tone" => "educational"
 ]
 ])
],
[
 "name" => "Mistakes Post",
 "description" => "Common mistakes list.",
 "template_type" => "generic",
 "category" => "educational",
 "is_public" => true,
 "template_data" => json_encode([
 "structure" => [
    ["section" => "Hook", "required" => true],
    ["section" => "Mistakes List", "required" => true],
    ["section" => "Better Way", "required" => false],
    ["section" => "CTA", "required" => false]
 ],
 "constraints" => [
   "max_chars" => 2000,
   "emoji" => "allow",
   "tone" => "educational"
 ]
 ])
],
[
 "name" => "Quote Commentary",
 "description" => "Use a quote + breakdown.",
 "template_type" => "generic",
 "category" => "quote",
 "is_public" => true,
 "template_data" => json_encode([
 "structure" => [
    ["section" => "Quote", "required" => true],
    ["section" => "Meaning", "required" => true],
    ["section" => "Application", "required" => true]
 ],
 "constraints" => [
   "max_chars" => 1600,
   "emoji" => "allow",
   "tone" => "reflective"
 ]
 ])
],
[
 "name" => "FAQ Post",
 "description" => "Answer common audience question.",
 "template_type" => "generic",
 "category" => "educational",
 "is_public" => true,
 "template_data" => json_encode([
 "structure" => [
    ["section" => "Question", "required" => true],
    ["section" => "Short Answer", "required" => true],
    ["section" => "Explanation", "required" => true],
    ["section" => "CTA", "required" => false]
 ],
 "constraints" => [
   "max_chars" => 1800,
   "emoji" => "allow",
   "tone" => "helpful"
 ]
 ])
],
[
 "name" => "Announcement Post",
 "description" => "Product/company update format.",
 "template_type" => "generic",
 "category" => "announcement",
 "is_public" => true,
 "template_data" => json_encode([
 "structure" => [
    ["section" => "Headline", "required" => true],
    ["section" => "What’s New", "required" => true],
    ["section" => "Why It Matters", "required" => true],
    ["section" => "CTA", "required" => true]
 ],
 "constraints" => [
   "max_chars" => 2000,
   "emoji" => "allow",
   "tone" => "professional"
 ]
 ])
],
[
 "name" => "Authority Framework Post",
 "description" => "Teach a framework or model.",
 "template_type" => "generic",
 "category" => "authority",
 "is_public" => true,
 "template_data" => json_encode([
 "structure" => [
    ["section" => "Hook", "required" => true],
    ["section" => "Problem Context", "required" => true],
    ["section" => "Framework", "required" => true],
    ["section" => "Application", "required" => true],
    ["section" => "CTA", "required" => false]
 ],
 "constraints" => [
   "max_chars" => 2200,
   "emoji" => "disallow",
   "tone" => "authority"
 ]
 ])
],
];
