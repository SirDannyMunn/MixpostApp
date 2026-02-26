<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use LaundryOS\VideoGen\Models\Avatar;
use LaundryOS\VideoGen\Services\TextToSpeechService;
use LaundryOS\VideoGen\Services\VideoGenerationService;

class GenerateSadTalkerNews extends Command
{
    protected $signature = 'video:sadtalker-news';
    protected $description = 'Generate a breaking news video with animate-diff and audio merging';

    public function handle()
    {
        $this->info('🎬 Generating Breaking News Video with SadTalker');
        $this->newLine();

        // Find a good Hispanic female avatar
        $avatar = Avatar::query()
            ->where('ethnicity', 'hispanic')
            ->where('gender', 'female')
            ->inRandomOrder()
            ->first();

        if (!$avatar) {
            $this->warn('No Hispanic female avatar found, trying any female...');
            $avatar = Avatar::query()
                ->where('gender', 'female')
                ->inRandomOrder()
                ->first();
        }

        if (!$avatar) {
            $this->error('No female avatar found at all!');
            return 1;
        }

        $this->info("Selected avatar: {$avatar->name} (ID: {$avatar->id})");
        $this->line("Ethnicity: {$avatar->ethnicity}, Gender: {$avatar->gender}, Age: {$avatar->age_range}");
        $this->newLine();

        // The breaking news script
        $script = "We interrupt this program to bring you some breaking news... Wan 2.5 is now live on Replicate! Back to you Jill.";

        $this->info('Script:');
        $this->line($script);
        $this->newLine();

        // Generate TTS audio first
        $this->info('🎤 Generating audio with OpenAI TTS...');
        $ttsService = app(TextToSpeechService::class);
        $audioPath = $ttsService->generate($script, 'en', [
            'voice' => 'nova', // Female voice
        ]);

        $this->info("✓ Audio generated: {$audioPath}");
        $this->newLine();

        // Create video job with animate-diff model (we have permission for this one)
        $this->info('📹 Creating video job with animate-diff model...');
        $videoService = app(VideoGenerationService::class);

        $job = $videoService->createJob(
            script: $script,
            avatar: $avatar,
            language: 'en',
            options: [
                'model' => 'lucataco/animate-diff',
                'audio_path' => $audioPath,
            ]
        );

        $this->info("✓ Job created: #{$job->id}");
        $this->line("  Status: {$job->status}");
        $this->line("  Model: {$job->replicate_model}");
        $this->newLine();

        // Process the video
        $this->info('⏳ Processing video with animate-diff and merging audio (this may take a few minutes)...');
        $this->newLine();
        
        $completedJob = $videoService->generateVideo($job);

        $this->newLine();
        $this->info('✅ Video generation complete!');
        $this->newLine();
        
        $this->line("Job ID: {$completedJob->id}");
        $this->line("Status: {$completedJob->status}");
        $this->line("Video URL: {$completedJob->video_url}");
        $this->line("Audio URL: {$completedJob->audio_url}");
        $this->line("Model: {$completedJob->replicate_model}");

        // Show public URL if on S3
        if (str_starts_with($completedJob->video_url, 'video-gen/')) {
            $publicUrl = Storage::disk('s3')->url($completedJob->video_url);
            $this->newLine();
            $this->info('🌐 Public S3 URL:');
            $this->line($publicUrl);
        }

        return 0;
    }
}
