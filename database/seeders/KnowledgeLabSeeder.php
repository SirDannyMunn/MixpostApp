<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class KnowledgeLabSeeder extends Seeder
{
    public function run(): void
    {
        $user = \App\Models\User::query()->first();
        if (!$user) {
            $user = \App\Models\User::create([
                'name' => 'Lab User',
                'email' => 'lab+' . Str::lower(Str::random(6)) . '@example.com',
                'password' => bcrypt('password'),
            ]);
        }

        $org = \App\Models\Organization::query()->first();
        if (!$org) {
            $org = \App\Models\Organization::create([
                'name' => 'Semantic Lab Org',
                'slug' => 'lab-' . Str::lower(Str::random(6)),
            ]);
        }

        // Canonical domain: SaaS content + trust
        $items = [
            // 1-5 Canonical
            ['Semantic Retrieval Test Note #1: Content as reputation',
             "Content is not a growth hack; it's a reputation system. Publish consistently and let trust compound over months, not days."],
            ['Semantic Retrieval Test Note #2: Virality vs trust',
             "Chasing virality erodes trust. If your audience learns you only show up for spikes, they stop believing you when it matters."],
            ['Semantic Retrieval Test Note #3: Opinionated writing',
             "Opinionated writing filters for the right customers. Neutrality feels safe but attracts nobody."],
            ['Semantic Retrieval Test Note #4: Copying competitors',
             "Founders copy competitors because it feels less risky. The cost is invisible: you become interchangeable."],
            ['Semantic Retrieval Test Note #5: Compounding',
             "Trust compounds like capital. One strong post per week beats a dozen shallow posts that nobody remembers."],

            // 6-8 Confounders (keyword overlap, different domain)
            ['Confounder: Trust in cybersecurity',
             "Trust models in cybersecurity focus on identity, device posture, and continuous verification. Content has little to do with it."],
            ['Confounder: Content delivery networks',
             "A content delivery network (CDN) improves latency by distributing assets. That performance has nothing to do with audience trust."],
            ['Confounder: Startup culture failures',
             "Startup culture often fails due to hiring debt and unclear ownership, not because of a lack of content."],

            // 9-12 Intent stress (persuasive/story/contrarian)
            ['Intent: Persuasive CTA',
             "If you're serious about compounding trust, start a weekly letter now. One email. One strong idea. Hit send every Tuesday."],
            ['Intent: Story example',
             "In 2019 I started posting weekly. The first 10 got almost no traction. By month 6, customers were quoting my posts back to me."],
            ['Intent: Contrarian',
             "Most startups don't have a content problem. They have a courage problem: no one will say what they actually believe."],
            ['Intent: Educational how-to',
             "A simple cadence: pick one belief, write 200 words, add one example, end with a question. Repeat every week for a year."],

            // 13-15 Adversarial / low-quality
            ['Low-Quality: Platitudes',
             "Consistency is important. If you are consistent, you build trust. Trust leads to growth and success."],
            ['Low-Quality: Buzzword soup',
             "Leverage your content strategy to unlock synergy and alignment, driving engagement via optimization and execution."],
            ['Low-Quality: Repetition',
             "Content matters a lot. Good content matters. Content that matters is what makes the difference."],
        ];

        foreach ($items as [$title, $raw]) {
            $hash = hash('sha256', $raw);
            $existing = \App\Models\KnowledgeItem::query()
                ->where('organization_id', $org->id)
                ->where('user_id', $user->id)
                ->where('raw_text_sha256', $hash)
                ->first();
            if ($existing) {
                continue;
            }
            \App\Models\KnowledgeItem::create([
                'organization_id' => $org->id,
                'user_id' => $user->id,
                'type' => 'note',
                'source' => 'manual',
                'title' => $title,
                'raw_text' => $raw,
                'raw_text_sha256' => $hash,
                'metadata' => ['domain' => 'saas_trust'],
                'ingested_at' => now(),
            ]);
        }

        // Create knowledge chunks immediately so DB shows populated chunks post-seed
        $seeded = \App\Models\KnowledgeItem::query()
            ->where('organization_id', $org->id)
            ->where('user_id', $user->id)
            ->where('metadata->domain', 'saas_trust')
            ->get();

        $chunked = 0;
        foreach ($seeded as $it) {
            // Run chunking synchronously (embedding left to separate job/test)
            (new \App\Jobs\ChunkKnowledgeItemJob($it->id))->handle();
            $chunked++;
        }

        $countChunks = \App\Models\KnowledgeChunk::query()
            ->where('organization_id', $org->id)
            ->where('user_id', $user->id)
            ->count();

        // Optionally embed immediately if OpenRouter key is available
        $apiKey = (string) config('services.openrouter.api_key');
        if ($apiKey !== '') {
            $embeddedItems = 0;
            foreach ($seeded as $it) {
                (new \App\Jobs\EmbedKnowledgeChunksJob($it->id))->handle(app(\App\Services\Ai\EmbeddingsService::class));
                $embeddedItems++;
            }
            $countEmbedded = \App\Models\KnowledgeChunk::query()
                ->where('organization_id', $org->id)
                ->where('user_id', $user->id)
                ->whereNotNull('embedding_vec')
                ->count();
            $this->command?->info("KnowledgeLabSeeder: Embedded vectors for {$embeddedItems} items. Embedded chunks: {$countEmbedded}/{$countChunks}");
        } else {
            $this->command?->warn('KnowledgeLabSeeder: OPENROUTER_API_KEY not set; skipping embeddings.');
            $this->command?->info("KnowledgeLabSeeder: Seeded items and created chunks for {$chunked} items. Total chunks: {$countChunks}");
        }
    }
}
