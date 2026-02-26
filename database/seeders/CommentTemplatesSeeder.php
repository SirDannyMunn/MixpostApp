<?php

namespace Database\Seeders;

use App\Models\Template;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Seeds default comment templates for the social engagement feature.
 * 
 * These templates provide structure guidance for different comment intents.
 * Unlike post templates, comment templates are lighter and more conversational.
 */
class CommentTemplatesSeeder extends Seeder
{
    public function run(): void
    {
        $templates = [
            [
                'id' => '00000000-0000-0000-0001-000000000001',
                'name' => 'Agree & Amplify',
                'description' => 'Express agreement with the post and add complementary perspective',
                'category' => 'agree',
                'template_data' => [
                    'intent' => 'agree',
                    'platform' => 'generic',
                    'supported_funnels' => ['any'],
                    'structure' => [
                        [
                            'name' => 'agreement',
                            'description' => 'Express genuine agreement with a specific point from the post',
                            'required' => true,
                        ],
                        [
                            'name' => 'amplification',
                            'description' => 'Add your own perspective, experience, or complementary insight',
                            'required' => true,
                        ],
                    ],
                    'constraints' => [
                        'max_chars' => 500,
                        'emoji' => 'minimal',
                        'tone' => 'conversational',
                    ],
                    'examples' => [
                        'This is so true! I experienced exactly this when building my first team. The part about trust being earned through consistency really resonates.',
                        'Spot on. What I\'d add is that this principle applies even more in remote settings where you can\'t rely on face-to-face rapport.',
                    ],
                ],
            ],
            [
                'id' => '00000000-0000-0000-0001-000000000002',
                'name' => 'Thoughtful Question',
                'description' => 'Ask a genuine follow-up question that deepens the conversation',
                'category' => 'question',
                'template_data' => [
                    'intent' => 'question',
                    'platform' => 'generic',
                    'supported_funnels' => ['any'],
                    'structure' => [
                        [
                            'name' => 'acknowledgment',
                            'description' => 'Brief acknowledgment of the post\'s value',
                            'required' => false,
                        ],
                        [
                            'name' => 'question',
                            'description' => 'A specific, thoughtful question that shows genuine curiosity',
                            'required' => true,
                        ],
                        [
                            'name' => 'context',
                            'description' => 'Brief context for why you\'re asking (optional)',
                            'required' => false,
                        ],
                    ],
                    'constraints' => [
                        'max_chars' => 400,
                        'emoji' => 'minimal',
                        'tone' => 'curious',
                    ],
                    'examples' => [
                        'Really interesting perspective! How do you handle this when dealing with stakeholders who have conflicting priorities?',
                        'This resonates. Curious - did you find this approach worked differently in larger vs smaller organizations?',
                    ],
                ],
            ],
            [
                'id' => '00000000-0000-0000-0001-000000000003',
                'name' => 'Personal Story',
                'description' => 'Share a brief, relevant personal experience that relates to the post',
                'category' => 'story',
                'template_data' => [
                    'intent' => 'story',
                    'platform' => 'generic',
                    'supported_funnels' => ['any'],
                    'structure' => [
                        [
                            'name' => 'connection',
                            'description' => 'Connect the post to your experience',
                            'required' => true,
                        ],
                        [
                            'name' => 'story',
                            'description' => 'Brief personal story or anecdote (2-3 sentences max)',
                            'required' => true,
                        ],
                        [
                            'name' => 'lesson',
                            'description' => 'What you learned or takeaway (optional)',
                            'required' => false,
                        ],
                    ],
                    'constraints' => [
                        'max_chars' => 600,
                        'emoji' => 'minimal',
                        'tone' => 'authentic',
                    ],
                    'examples' => [
                        'This reminds me of when I was leading product at my last startup. We tried the opposite approach first and it nearly cost us our biggest client. The moment we shifted to what you\'re describing, everything changed.',
                        'I learned this the hard way early in my career. Made exactly the mistake you\'re warning against and spent 6 months cleaning up the mess. Now it\'s the first thing I tell anyone on my team.',
                    ],
                ],
            ],
            [
                'id' => '00000000-0000-0000-0001-000000000004',
                'name' => 'Value-Add Insight',
                'description' => 'Contribute additional valuable insight that builds on the post',
                'category' => 'value',
                'template_data' => [
                    'intent' => 'value',
                    'platform' => 'generic',
                    'supported_funnels' => ['any'],
                    'structure' => [
                        [
                            'name' => 'bridge',
                            'description' => 'Brief connection to the original post',
                            'required' => true,
                        ],
                        [
                            'name' => 'insight',
                            'description' => 'Your additional insight, framework, or perspective',
                            'required' => true,
                        ],
                        [
                            'name' => 'application',
                            'description' => 'How to apply this insight (optional)',
                            'required' => false,
                        ],
                    ],
                    'constraints' => [
                        'max_chars' => 500,
                        'emoji' => 'minimal',
                        'tone' => 'insightful',
                    ],
                    'examples' => [
                        'Building on this - there\'s a third dimension worth considering: timing. The best message delivered at the wrong moment often fails. I\'ve started mapping not just what and how, but when.',
                        'Great framework. One thing I\'d add: this works especially well when you pair it with regular feedback loops. Without those, you\'re flying blind on whether it\'s actually working.',
                    ],
                ],
            ],
            [
                'id' => '00000000-0000-0000-0001-000000000005',
                'name' => 'Genuine Appreciation',
                'description' => 'Express authentic appreciation for the post and its value',
                'category' => 'appreciate',
                'template_data' => [
                    'intent' => 'appreciate',
                    'platform' => 'generic',
                    'supported_funnels' => ['any'],
                    'structure' => [
                        [
                            'name' => 'appreciation',
                            'description' => 'Express genuine thanks or appreciation',
                            'required' => true,
                        ],
                        [
                            'name' => 'specific_value',
                            'description' => 'What specifically made this valuable to you',
                            'required' => true,
                        ],
                        [
                            'name' => 'application',
                            'description' => 'How you\'ll apply or share this (optional)',
                            'required' => false,
                        ],
                    ],
                    'constraints' => [
                        'max_chars' => 400,
                        'emoji' => 'moderate',
                        'tone' => 'warm',
                    ],
                    'examples' => [
                        'This is exactly what I needed to read today. Been struggling with this exact issue for weeks. Saving this and sharing with my team. 🙏',
                        'Thank you for breaking this down so clearly. The way you explained the second point finally made it click for me. Already planning to implement this Monday.',
                    ],
                ],
            ],
        ];

        foreach ($templates as $data) {
            Template::updateOrCreate(
                ['id' => $data['id']],
                [
                    'organization_id' => null, // Community template
                    'folder_id' => null,
                    'created_by' => null,
                    'name' => $data['name'],
                    'description' => $data['description'],
                    'template_type' => 'comment',
                    'template_data' => $data['template_data'],
                    'category' => $data['category'],
                    'is_public' => true,
                    'usage_count' => 0,
                ]
            );
        }

        $this->command->info('Seeded ' . count($templates) . ' comment templates.');
    }
}
