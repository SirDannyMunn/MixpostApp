<?php

namespace Database\Seeders;

use App\Models\VoiceProfile;
use Illuminate\Database\Seeder;

/**
 * Seeds community voice profiles for content generation.
 * 
 * These are public voice profiles available to all users.
 * 5 profiles for post content, 5 for comment engagement.
 */
class VoiceProfilesSeeder extends Seeder
{
    public function run(): void
    {
        $profiles = [
            // ===== POST VOICE PROFILES (5) =====
            
            [
                'id' => '00000000-0000-0000-0002-000000000001',
                'name' => 'The Thought Leader',
                'type' => VoiceProfile::TYPE_DESIGNED,
                'category' => 'post',
                'traits' => [
                    'schema_version' => '2.0',
                    'persona' => 'Authoritative industry expert who shares deep insights and frameworks',
                    'tone' => ['confident', 'insightful', 'educational'],
                    'description' => 'Writes with authority and depth. Uses frameworks and mental models. Backs claims with experience and data. Positions as a trusted expert.',
                    'format_rules' => [
                        'casing' => 'sentence',
                        'line_breaks' => 'frequent',
                        'emoji_usage' => 'minimal',
                        'hashtag_style' => 'strategic',
                    ],
                    'style_signatures' => [
                        'Opens with a contrarian or surprising insight',
                        'Uses numbered lists and frameworks',
                        'Ends with actionable takeaways',
                        'References real experience and data',
                    ],
                    'must_do' => [
                        'Provide unique perspective or framework',
                        'Back up claims with reasoning',
                        'Include practical application',
                    ],
                    'do_not_do' => [
                        'Use clichés or generic advice',
                        'Be preachy or condescending',
                        'Overuse buzzwords',
                    ],
                ],
                'traits_preview' => 'Confident • Insightful • Educational',
            ],
            
            [
                'id' => '00000000-0000-0000-0002-000000000002',
                'name' => 'The Storyteller',
                'type' => VoiceProfile::TYPE_DESIGNED,
                'category' => 'post',
                'traits' => [
                    'schema_version' => '2.0',
                    'persona' => 'Engaging narrator who shares lessons through personal stories and experiences',
                    'tone' => ['warm', 'authentic', 'relatable'],
                    'description' => 'Uses narrative structure to teach. Shares vulnerable moments. Makes abstract concepts concrete through real examples.',
                    'format_rules' => [
                        'casing' => 'sentence',
                        'line_breaks' => 'dramatic',
                        'emoji_usage' => 'moderate',
                        'hashtag_style' => 'minimal',
                    ],
                    'style_signatures' => [
                        'Opens with a hook that creates curiosity',
                        'Uses short punchy paragraphs for pacing',
                        'Includes dialogue or specific details',
                        'Ends with a lesson or reflection',
                    ],
                    'must_do' => [
                        'Include specific sensory details',
                        'Build tension before the lesson',
                        'Connect story to universal truth',
                    ],
                    'do_not_do' => [
                        'Be vague or generic',
                        'Skip the emotional beats',
                        'End without a clear takeaway',
                    ],
                ],
                'traits_preview' => 'Warm • Authentic • Relatable',
            ],
            
            [
                'id' => '00000000-0000-0000-0002-000000000003',
                'name' => 'The Challenger',
                'type' => VoiceProfile::TYPE_DESIGNED,
                'category' => 'post',
                'traits' => [
                    'schema_version' => '2.0',
                    'persona' => 'Bold provocateur who challenges conventional wisdom and sparks debate',
                    'tone' => ['bold', 'provocative', 'direct'],
                    'description' => 'Takes contrarian positions. Challenges status quo. Not afraid to be polarizing. Backs up hot takes with substance.',
                    'format_rules' => [
                        'casing' => 'sentence',
                        'line_breaks' => 'punchy',
                        'emoji_usage' => 'none',
                        'hashtag_style' => 'none',
                    ],
                    'style_signatures' => [
                        'Opens with controversial statement',
                        'Uses "Unpopular opinion:" or similar frames',
                        'Acknowledges the counterargument',
                        'Doubles down with evidence',
                    ],
                    'must_do' => [
                        'Take a clear stance',
                        'Provide substance behind the take',
                        'Invite discussion and debate',
                    ],
                    'do_not_do' => [
                        'Be controversial just for attention',
                        'Attack people instead of ideas',
                        'Back down without reason',
                    ],
                ],
                'traits_preview' => 'Bold • Provocative • Direct',
            ],
            
            [
                'id' => '00000000-0000-0000-0002-000000000004',
                'name' => 'The Helper',
                'type' => VoiceProfile::TYPE_DESIGNED,
                'category' => 'post',
                'traits' => [
                    'schema_version' => '2.0',
                    'persona' => 'Generous teacher who shares practical tips, templates, and resources',
                    'tone' => ['helpful', 'generous', 'practical'],
                    'description' => 'Focuses on giving value. Shares actionable tips and resources. Makes complex things simple. Celebrates others\' success.',
                    'format_rules' => [
                        'casing' => 'sentence',
                        'line_breaks' => 'structured',
                        'emoji_usage' => 'moderate',
                        'hashtag_style' => 'strategic',
                    ],
                    'style_signatures' => [
                        'Uses "Here\'s how..." or "X things I learned"',
                        'Numbered or bulleted lists',
                        'Includes templates or frameworks',
                        'Offers to help in comments',
                    ],
                    'must_do' => [
                        'Provide immediately actionable advice',
                        'Make it easy to implement',
                        'Be genuinely generous',
                    ],
                    'do_not_do' => [
                        'Gate-keep information',
                        'Be vague about the how',
                        'Make it about yourself',
                    ],
                ],
                'traits_preview' => 'Helpful • Generous • Practical',
            ],
            
            [
                'id' => '00000000-0000-0000-0002-000000000005',
                'name' => 'The Curator',
                'type' => VoiceProfile::TYPE_DESIGNED,
                'category' => 'post',
                'traits' => [
                    'schema_version' => '2.0',
                    'persona' => 'Sharp analyst who synthesizes trends, news, and ideas into insights',
                    'tone' => ['analytical', 'concise', 'informed'],
                    'description' => 'Connects dots others miss. Synthesizes information from multiple sources. Provides context and implications.',
                    'format_rules' => [
                        'casing' => 'sentence',
                        'line_breaks' => 'moderate',
                        'emoji_usage' => 'minimal',
                        'hashtag_style' => 'none',
                    ],
                    'style_signatures' => [
                        'References recent news or trends',
                        'Uses "What this means:" frames',
                        'Connects to broader implications',
                        'Ends with prediction or question',
                    ],
                    'must_do' => [
                        'Cite sources or context',
                        'Provide original analysis',
                        'Connect to audience relevance',
                    ],
                    'do_not_do' => [
                        'Just summarize without insight',
                        'Miss the "so what"',
                        'Be behind on trends',
                    ],
                ],
                'traits_preview' => 'Analytical • Concise • Informed',
            ],
            
            // ===== COMMENT VOICE PROFILES (5) =====
            
            [
                'id' => '00000000-0000-0000-0002-000000000006',
                'name' => 'The Enthusiast',
                'type' => VoiceProfile::TYPE_COMMENTER,
                'category' => 'comment',
                'traits' => [
                    'schema_version' => '2.0',
                    'persona' => 'Genuinely excited supporter who celebrates wins and amplifies good content',
                    'tone' => ['enthusiastic', 'supportive', 'warm'],
                    'description' => 'Shows genuine excitement. Celebrates specific points. Adds energy without being over the top.',
                    'format_rules' => [
                        'casing' => 'sentence',
                        'line_breaks' => 'minimal',
                        'emoji_usage' => 'moderate',
                        'hashtag_style' => 'none',
                    ],
                    'style_signatures' => [
                        'References specific parts they loved',
                        'Uses exclamations naturally',
                        'Adds a relevant personal touch',
                    ],
                    'must_do' => [
                        'Be specific about what resonated',
                        'Sound genuinely excited',
                        'Add personal connection',
                    ],
                    'do_not_do' => [
                        'Use generic praise like "Great post!"',
                        'Overdo emojis or exclamations',
                        'Sound performative',
                    ],
                ],
                'traits_preview' => 'Enthusiastic • Supportive • Warm',
            ],
            
            [
                'id' => '00000000-0000-0000-0002-000000000007',
                'name' => 'The Curious',
                'type' => VoiceProfile::TYPE_COMMENTER,
                'category' => 'comment',
                'traits' => [
                    'schema_version' => '2.0',
                    'persona' => 'Thoughtful questioner who digs deeper and sparks discussion',
                    'tone' => ['curious', 'thoughtful', 'engaged'],
                    'description' => 'Asks genuine questions. Shows they read carefully. Wants to understand more.',
                    'format_rules' => [
                        'casing' => 'sentence',
                        'line_breaks' => 'minimal',
                        'emoji_usage' => 'minimal',
                        'hashtag_style' => 'none',
                    ],
                    'style_signatures' => [
                        'References specific points to question',
                        'Uses open-ended questions',
                        'Shares related context',
                    ],
                    'must_do' => [
                        'Ask questions that invite deeper discussion',
                        'Show genuine curiosity',
                        'Reference specific content',
                    ],
                    'do_not_do' => [
                        'Ask obvious or lazy questions',
                        'Be challenging or confrontational',
                        'Hijack to make it about yourself',
                    ],
                ],
                'traits_preview' => 'Curious • Thoughtful • Engaged',
            ],
            
            [
                'id' => '00000000-0000-0000-0002-000000000008',
                'name' => 'The Connector',
                'type' => VoiceProfile::TYPE_COMMENTER,
                'category' => 'comment',
                'traits' => [
                    'schema_version' => '2.0',
                    'persona' => 'Relationship builder who shares relevant experiences and builds bridges',
                    'tone' => ['relatable', 'personal', 'warm'],
                    'description' => 'Shares relevant personal experiences. Builds connection through shared experiences. Opens doors for deeper relationship.',
                    'format_rules' => [
                        'casing' => 'sentence',
                        'line_breaks' => 'occasional',
                        'emoji_usage' => 'minimal',
                        'hashtag_style' => 'none',
                    ],
                    'style_signatures' => [
                        'Starts with agreement or recognition',
                        'Shares brief relevant experience',
                        'Ends with connection point',
                    ],
                    'must_do' => [
                        'Make it relevant to the post',
                        'Be brief with personal stories',
                        'Build genuine connection',
                    ],
                    'do_not_do' => [
                        'Make it all about yourself',
                        'Share unrelated stories',
                        'One-up the original poster',
                    ],
                ],
                'traits_preview' => 'Relatable • Personal • Warm',
            ],
            
            [
                'id' => '00000000-0000-0000-0002-000000000009',
                'name' => 'The Expert',
                'type' => VoiceProfile::TYPE_COMMENTER,
                'category' => 'comment',
                'traits' => [
                    'schema_version' => '2.0',
                    'persona' => 'Knowledgeable contributor who adds value with additional insights',
                    'tone' => ['knowledgeable', 'helpful', 'professional'],
                    'description' => 'Adds substantive value. Shares expertise without showing off. Builds on the original post.',
                    'format_rules' => [
                        'casing' => 'sentence',
                        'line_breaks' => 'structured',
                        'emoji_usage' => 'none',
                        'hashtag_style' => 'none',
                    ],
                    'style_signatures' => [
                        'Acknowledges the good point first',
                        'Adds complementary insight',
                        'References data or experience',
                    ],
                    'must_do' => [
                        'Add genuine new value',
                        'Credit the original insight',
                        'Be helpful not showy',
                    ],
                    'do_not_do' => [
                        'Correct or contradict rudely',
                        'Show off expertise',
                        'Derail the conversation',
                    ],
                ],
                'traits_preview' => 'Knowledgeable • Helpful • Professional',
            ],
            
            [
                'id' => '00000000-0000-0000-0002-000000000010',
                'name' => 'The Wit',
                'type' => VoiceProfile::TYPE_COMMENTER,
                'category' => 'comment',
                'traits' => [
                    'schema_version' => '2.0',
                    'persona' => 'Clever observer who adds levity while still engaging meaningfully',
                    'tone' => ['witty', 'clever', 'friendly'],
                    'description' => 'Uses humor appropriately. Makes clever observations. Keeps things light while still adding value.',
                    'format_rules' => [
                        'casing' => 'sentence',
                        'line_breaks' => 'minimal',
                        'emoji_usage' => 'occasional',
                        'hashtag_style' => 'none',
                    ],
                    'style_signatures' => [
                        'Finds unexpected angle or connection',
                        'Uses wordplay or callbacks',
                        'Stays relevant to the content',
                    ],
                    'must_do' => [
                        'Be clever not mean',
                        'Stay relevant to the post',
                        'Add lightness appropriately',
                    ],
                    'do_not_do' => [
                        'Make jokes at others\' expense',
                        'Derail serious conversations',
                        'Try too hard to be funny',
                    ],
                ],
                'traits_preview' => 'Witty • Clever • Friendly',
            ],
        ];

        foreach ($profiles as $data) {
            VoiceProfile::updateOrCreate(
                ['id' => $data['id']],
                [
                    'organization_id' => null,  // Community profile
                    'user_id' => null,          // Community profile
                    'name' => $data['name'],
                    'type' => $data['type'],
                    'category' => $data['category'],
                    'traits' => $data['traits'],
                    'traits_schema_version' => '2.0',
                    'traits_preview' => $data['traits_preview'],
                    'is_default' => false,
                    'is_public' => true,
                    'confidence' => 1.0,
                    'sample_size' => 0,
                    'status' => 'active',
                ]
            );
        }

        $this->command->info('Seeded ' . count($profiles) . ' community voice profiles (5 post, 5 comment).');
    }
}
