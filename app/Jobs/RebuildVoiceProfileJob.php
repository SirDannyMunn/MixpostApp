<?php

namespace App\Jobs;

use App\Models\VoiceProfile;
use App\Services\Voice\VoiceProfileBuilderService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RebuildVoiceProfileJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 600; // 10 minutes
    public $tries = 2;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public string $voiceProfileId,
        public array $filters = []
    ) {
    }

    /**
     * Execute the job.
     */
    public function handle(VoiceProfileBuilderService $builder): void
    {
        $profile = VoiceProfile::query()
            ->where('id', $this->voiceProfileId)
            ->whereNull('deleted_at')
            ->first();

        if (!$profile) {
            Log::warning('Voice profile not found for rebuild', [
                'profile_id' => $this->voiceProfileId,
            ]);
            return;
        }

        // Set status to processing
        $profile->status = 'processing';
        $profile->updated_at = now();
        $profile->save();

        try {
            $builder->rebuild($profile, $this->filters);

            // Set status to ready after successful rebuild
            $profile->status = 'ready';
            $profile->updated_at = now();
            $profile->save();

            Log::info('Voice profile rebuilt successfully', [
                'profile_id' => $profile->id,
                'sample_size' => $profile->sample_size,
                'confidence' => $profile->confidence,
            ]);
        } catch (\RuntimeException $e) {
            $profile->status = 'error';
            $profile->updated_at = now();
            $profile->save();

            Log::error('Voice profile rebuild failed', [
                'profile_id' => $profile->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        $profile = VoiceProfile::query()
            ->where('id', $this->voiceProfileId)
            ->whereNull('deleted_at')
            ->first();

        if ($profile) {
            $profile->status = 'error';
            $profile->updated_at = now();
            $profile->save();
        }

        Log::error('Voice profile rebuild job failed permanently', [
            'profile_id' => $this->voiceProfileId,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);
    }
}
