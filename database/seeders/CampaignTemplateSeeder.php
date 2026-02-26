<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use LaundryOS\LeadWatcher\Models\CampaignTemplate;

class CampaignTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $templates = [
            [
                'name' => 'LinkedIn Connection + Message',
                'description' => 'Send a connection request to leads, wait for acceptance, then send a personalized message. The classic LinkedIn outreach sequence.',
                'category' => 'outreach',
                'icon' => 'UserPlus',
                'color' => 'blue',
                'is_system' => true,
                'is_public' => true,
                'default_settings' => [
                    'daily_connections_limit' => 25,
                    'dailyLimit' => 25,
                    'minDelay' => 60,
                    'maxDelay' => 180,
                    'sendWindowStart' => '09:00',
                    'sendWindowEnd' => '18:00',
                    'timezone' => 'America/New_York',
                    'activeDays' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'],
                    'skipHolidays' => true,
                    'trackOpens' => true,
                    'trackClicks' => true,
                ],
                'steps' => [
                    [
                        'type' => 'invitation',
                        'action_type' => 'send_invitation',
                        'config' => [
                            'include_note' => true,
                            'note_template' => "Hi {{first_name}}, I came across your profile and would love to connect. I think there could be some great synergies between what we're both working on.",
                        ],
                        'delay_days' => 0,
                        'delay_hours' => 0,
                    ],
                    [
                        'type' => 'wait',
                        'config' => ['wait_type' => 'fixed'],
                        'delay_days' => 1,
                        'delay_hours' => 0,
                    ],
                    [
                        'type' => 'condition',
                        'condition_type' => 'accepted',
                        'condition_config' => [
                            'timeout_days' => 14,
                            'check_interval_hours' => 24,
                        ],
                        'delay_days' => 0,
                        'delay_hours' => 0,
                    ],
                    [
                        'type' => 'message',
                        'action_type' => 'send_message',
                        'message_template' => "Hey {{first_name}}, thanks for connecting! I noticed you're working at {{company}} — that's really interesting.\n\nI'd love to learn more about what you're focused on. Would you be open to a quick chat sometime this week?",
                        'use_ai_personalization' => false,
                        'delay_days' => 0,
                        'delay_hours' => 0,
                    ],
                ],
            ],
            [
                'name' => 'Profile View + Connect',
                'description' => 'View a lead\'s profile first to create a notification, wait a day, then send a connection request. More natural and gets higher acceptance rates.',
                'category' => 'outreach',
                'icon' => 'Eye',
                'color' => 'green',
                'is_system' => true,
                'is_public' => true,
                'default_settings' => [
                    'daily_connections_limit' => 25,
                    'dailyLimit' => 25,
                    'minDelay' => 60,
                    'maxDelay' => 300,
                    'sendWindowStart' => '09:00',
                    'sendWindowEnd' => '17:00',
                    'timezone' => 'America/New_York',
                    'activeDays' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'],
                    'skipHolidays' => true,
                ],
                'steps' => [
                    [
                        'type' => 'linkedin_view_profile',
                        'action_type' => 'view_profile',
                        'config' => [],
                        'delay_days' => 0,
                        'delay_hours' => 0,
                    ],
                    [
                        'type' => 'wait',
                        'config' => ['wait_type' => 'fixed'],
                        'delay_days' => 1,
                        'delay_hours' => 0,
                    ],
                    [
                        'type' => 'invitation',
                        'action_type' => 'send_invitation',
                        'config' => [
                            'include_note' => true,
                            'note_template' => "Hi {{first_name}}, I was looking at your profile and I'm impressed by your work at {{company}}. Would love to connect!",
                        ],
                        'delay_days' => 0,
                        'delay_hours' => 0,
                    ],
                    [
                        'type' => 'wait',
                        'config' => ['wait_type' => 'fixed'],
                        'delay_days' => 2,
                        'delay_hours' => 0,
                    ],
                    [
                        'type' => 'condition',
                        'condition_type' => 'accepted',
                        'condition_config' => [
                            'timeout_days' => 14,
                            'check_interval_hours' => 24,
                        ],
                        'delay_days' => 0,
                        'delay_hours' => 0,
                    ],
                    [
                        'type' => 'message',
                        'action_type' => 'send_message',
                        'message_template' => "Hi {{first_name}}, great to connect! I've been following some of the work coming out of {{company}} and would love to exchange ideas.\n\nWould you be open to a quick 15-minute call?",
                        'use_ai_personalization' => false,
                        'delay_days' => 0,
                        'delay_hours' => 0,
                    ],
                ],
            ],
            [
                'name' => 'Multi-Touch Follow-Up',
                'description' => 'Connect, then send a series of follow-up messages spaced out over time. Great for staying top of mind without being pushy.',
                'category' => 'nurture',
                'icon' => 'Repeat',
                'color' => 'purple',
                'is_system' => true,
                'is_public' => true,
                'default_settings' => [
                    'daily_connections_limit' => 20,
                    'dailyLimit' => 20,
                    'minDelay' => 120,
                    'maxDelay' => 300,
                    'sendWindowStart' => '09:00',
                    'sendWindowEnd' => '17:00',
                    'timezone' => 'America/New_York',
                    'activeDays' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'],
                ],
                'steps' => [
                    [
                        'type' => 'invitation',
                        'action_type' => 'send_invitation',
                        'config' => [
                            'include_note' => true,
                            'note_template' => "Hi {{first_name}}, I'd love to connect and share some insights that might be relevant to your work at {{company}}.",
                        ],
                        'delay_days' => 0,
                        'delay_hours' => 0,
                    ],
                    [
                        'type' => 'wait',
                        'config' => ['wait_type' => 'fixed'],
                        'delay_days' => 1,
                        'delay_hours' => 0,
                    ],
                    [
                        'type' => 'condition',
                        'condition_type' => 'accepted',
                        'condition_config' => [
                            'timeout_days' => 14,
                            'check_interval_hours' => 24,
                        ],
                        'delay_days' => 0,
                        'delay_hours' => 0,
                    ],
                    [
                        'type' => 'message',
                        'action_type' => 'send_message',
                        'message_template' => "Thanks for connecting, {{first_name}}! I wanted to share a quick thought that might be relevant to your role at {{company}}.\n\nWould it be helpful if I sent over some resources?",
                        'delay_days' => 0,
                        'delay_hours' => 0,
                    ],
                    [
                        'type' => 'wait',
                        'config' => ['wait_type' => 'fixed'],
                        'delay_days' => 5,
                        'delay_hours' => 0,
                    ],
                    [
                        'type' => 'message',
                        'action_type' => 'send_message',
                        'message_template' => "Hi {{first_name}}, just following up — I didn't want this to get lost in your inbox. Happy to jump on a quick call if that's easier. What does your schedule look like this week?",
                        'delay_days' => 0,
                        'delay_hours' => 0,
                    ],
                ],
            ],
            [
                'name' => 'LinkedIn Integration Smoke Test (Non-Mutating)',
                'description' => 'Non-mutating integration template for end-to-end validation of Laravel queue + Python worker + BrowserUse. Steps: check session, open own profile and scroll, open feed and scroll 10 posts.',
                'category' => 'custom',
                'icon' => 'TestTube',
                'color' => 'slate',
                'is_system' => true,
                'is_public' => true,
                'default_settings' => [
                    'non_mutating_test_mode' => true,
                    'daily_connections_limit' => 100,
                    'dailyLimit' => 100,
                    'minDelay' => 5,
                    'maxDelay' => 15,
                    'sendWindowStart' => '00:00',
                    'sendWindowEnd' => '23:59',
                    'timezone' => 'UTC',
                    'activeDays' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'],
                    'skipHolidays' => false,
                ],
                'steps' => [
                    [
                        'type' => 'linkedin_check_session',
                        'action_type' => 'check_session',
                        'config' => [
                            'non_mutating' => true,
                        ],
                        'delay_days' => 0,
                        'delay_hours' => 0,
                    ],
                    [
                        'type' => 'linkedin_view_profile',
                        'action_type' => 'view_profile',
                        'config' => [
                            'profile_url' => 'https://www.linkedin.com/in/me/',
                            'non_mutating' => true,
                        ],
                        'delay_days' => 0,
                        'delay_hours' => 0,
                    ],
                    [
                        'type' => 'linkedin_search_leads',
                        'action_type' => 'browse_feed',
                        'config' => [
                            'posts_to_scroll' => 10,
                            'non_mutating' => true,
                        ],
                        'delay_days' => 0,
                        'delay_hours' => 0,
                    ],
                ],
            ],
        ];

        foreach ($templates as $templateData) {
            CampaignTemplate::updateOrCreate(
                ['name' => $templateData['name'], 'is_system' => true],
                $templateData
            );
        }
    }
}
