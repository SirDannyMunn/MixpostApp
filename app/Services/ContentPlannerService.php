<?php

namespace App\Services;

use App\Models\AiCanvasConversation;

class ContentPlannerService
{
    private array $questions = [
        [
            'key' => 'plan_type',
            'question' => 'What type of content plan would you like to create?',
            'validation' => ['build_in_public'],
        ],
        [
            'key' => 'duration_days',
            'question' => 'How many days should this plan cover?',
            'validation' => [7, 14],
        ],
        [
            'key' => 'platform',
            'question' => 'Which platform is this plan for?',
            'validation' => ['twitter', 'linkedin', 'facebook', 'instagram'],
        ],
        [
            'key' => 'goal',
            'question' => 'What is your main goal for this content?',
            'validation' => null, // Free text
        ],
        [
            'key' => 'audience',
            'question' => 'Who is your target audience?',
            'validation' => null, // Free text
        ],
        [
            'key' => 'voice_mode',
            'question' => 'How should we handle your writing voice?',
            'validation' => ['inferred', 'designed'],
        ],
    ];

    public function initializePlanner(AiCanvasConversation $conversation): array
    {
        $state = [
            'question_index' => 0,
            'answers' => [],
            'status' => 'collecting',
        ];

        $conversation->update([
            'planner_mode' => 'content_planner',
            'planner_state' => $state,
        ]);

        return $this->getNextQuestion($state);
    }

    public function processAnswer(AiCanvasConversation $conversation, string $answer): array
    {
        $state = $conversation->planner_state ?? [];
        $currentStatus = $state['status'] ?? 'collecting';

        // Handle review state actions
        if ($currentStatus === 'review') {
            return $this->handleReviewAction($conversation, $answer, $state);
        }

        $questionIndex = (int) ($state['question_index'] ?? 0);

        if ($questionIndex >= count($this->questions)) {
            return [
                'status' => 'error',
                'message' => 'No more questions to answer',
            ];
        }

        $question = $this->questions[$questionIndex];
        
        // Validate answer
        $validatedAnswer = $this->validateAnswer($question, $answer);
        if ($validatedAnswer === null) {
            return [
                'status' => 'invalid',
                'message' => $this->getValidationMessage($question),
                'question' => $question['question'],
            ];
        }

        // Store answer and advance
        $state['answers'][$question['key']] = $validatedAnswer;
        $state['question_index'] = $questionIndex + 1;

        $conversation->update(['planner_state' => $state]);

        // Check if done
        if ($state['question_index'] >= count($this->questions)) {
            $state['status'] = 'review';
            $conversation->update(['planner_state' => $state]);
            return [
                'status' => 'review',
                'message' => $this->formatReviewSummary($state['answers']),
                'answers' => $state['answers'],
            ];
        }

        return $this->getNextQuestion($state);
    }

    private function getNextQuestion(array $state): array
    {
        $questionIndex = (int) ($state['question_index'] ?? 0);
        
        if ($questionIndex >= count($this->questions)) {
            return [
                'status' => 'complete',
                'message' => 'All questions answered',
            ];
        }

        $question = $this->questions[$questionIndex];
        
        return [
            'status' => 'asking',
            'question' => $question['question'],
            'question_key' => $question['key'],
            'question_index' => $questionIndex,
            'options' => $question['validation'],
        ];
    }

    private function validateAnswer(array $question, string $answer): ?string
    {
        $answer = trim($answer);
        
        if ($answer === '') {
            return null;
        }

        // If validation is null, accept any non-empty string
        if ($question['validation'] === null) {
            return $answer;
        }

        // Check if answer matches one of the allowed values
        $normalized = strtolower($answer);
        foreach ($question['validation'] as $valid) {
            if (strtolower((string) $valid) === $normalized) {
                return (string) $valid;
            }
        }

        return null;
    }

    private function getValidationMessage(array $question): string
    {
        if ($question['validation'] === null) {
            return 'Please provide a valid answer.';
        }

        $options = implode(', ', array_map('strval', $question['validation']));
        return "Please choose one of: {$options}";
    }

    private function formatReviewSummary(array $answers): string
    {
        $summary = "Here's your content plan:\n\n";
        $summary .= "• Plan Type: " . ($answers['plan_type'] ?? '') . "\n";
        $summary .= "• Duration: " . ($answers['duration_days'] ?? '') . " days\n";
        $summary .= "• Platform: " . ($answers['platform'] ?? '') . "\n";
        $summary .= "• Goal: " . ($answers['goal'] ?? '') . "\n";
        $summary .= "• Audience: " . ($answers['audience'] ?? '') . "\n";
        $summary .= "• Voice Mode: " . ($answers['voice_mode'] ?? '') . "\n";
        $summary .= "\nReply 'confirm' to create this plan, or 'edit' to make changes.";

        return $summary;
    }

    public function resetToQuestion(AiCanvasConversation $conversation, int $questionIndex): void
    {
        $state = $conversation->planner_state ?? [];
        $state['question_index'] = max(0, min($questionIndex, count($this->questions) - 1));
        $state['status'] = 'collecting';
        $conversation->update(['planner_state' => $state]);
    }

    private function handleReviewAction(AiCanvasConversation $conversation, string $answer, array $state): array
    {
        $answer = strtolower(trim($answer));

        // Handle confirm action
        if ($answer === 'confirm' || $answer === 'yes' || $answer === 'looks good') {
            return [
                'status' => 'confirmed',
                'message' => 'Great! Your content plan has been confirmed. I\'ll start generating your content.',
                'answers' => $state['answers'],
                'action' => 'confirm',
            ];
        }

        // Handle edit action
        if ($answer === 'edit' || $answer === 'change' || $answer === 'no') {
            // Reset to first question
            $state['question_index'] = 0;
            $state['status'] = 'collecting';
            $conversation->update(['planner_state' => $state]);
            
            return [
                'status' => 'editing',
                'message' => 'No problem! Let\'s start over. ' . $this->questions[0]['question'],
                'question' => $this->questions[0]['question'],
                'question_key' => $this->questions[0]['key'],
                'question_index' => 0,
                'options' => $this->questions[0]['validation'],
            ];
        }

        // Unrecognized response
        return [
            'status' => 'review',
            'message' => $this->formatReviewSummary($state['answers']),
            'answers' => $state['answers'],
        ];
    }
}
