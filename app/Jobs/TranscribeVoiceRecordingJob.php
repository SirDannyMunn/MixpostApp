<?php

namespace App\Jobs;

use App\Models\IngestionSource;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use LaundryOS\SocialWatcher\Services\DeepgramTranscriptionService;
use App\Services\Ai\Generation\ContentGenBatchLogger;

class TranscribeVoiceRecordingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 300;

    public function __construct(
        public string $ingestionSourceId,
        public string $audioStoragePath,
        public string $contentType,
        public ?array $folderIds = null,
        public ?string $userId = null,
    ) {}

    public function handle(): void
    {
        $logger = new ContentGenBatchLogger(storage_path('logs/ingestionSourceLogs'), true);
        $logger->startRun('TranscribeVoiceRecordingJob:' . $this->ingestionSourceId, [
            'ingestion_source_id' => $this->ingestionSourceId,
            'audio_path' => $this->audioStoragePath,
            'content_type' => $this->contentType,
        ]);

        $src = IngestionSource::find($this->ingestionSourceId);
        if (!$src) {
            $logger->flush('not_found');
            $this->cleanupAudioFile();
            return;
        }

        try {
            // Read the audio file from storage
            if (!Storage::disk('local')->exists($this->audioStoragePath)) {
                throw new \RuntimeException('Audio file not found in storage');
            }

            $audioContent = Storage::disk('local')->get($this->audioStoragePath);
            if (!$audioContent || strlen($audioContent) === 0) {
                throw new \RuntimeException('Audio file is empty');
            }

            $logger->capture('audio_loaded', ['size' => strlen($audioContent)]);

            // Transcribe using Deepgram
            /** @var DeepgramTranscriptionService $deepgram */
            $deepgram = app(DeepgramTranscriptionService::class);
            $transcription = $deepgram->transcribeAudio($audioContent, $this->contentType);
            $logger->capture('deepgram_response', ['response' => $transcription]);

            // Extract the transcript text
            $transcript = $transcription['results']['channels'][0]['alternatives'][0]['transcript'] ?? '';
            
            if (trim($transcript) === '') {
                $src->status = 'failed';
                $src->error = 'Could not transcribe audio - no speech detected';
                $src->save();
                $logger->flush('no_speech');
                $this->cleanupAudioFile();
                return;
            }

            // Update the ingestion source with the transcript
            $src->raw_text = $transcript;
            $src->dedup_hash = IngestionSource::dedupHashFromText($transcript);
            $src->status = 'pending'; // Ready for ProcessIngestionSourceJob
            $src->error = null;
            $src->save();

            $logger->capture('transcription_complete', [
                'transcript_length' => strlen($transcript),
                'transcript_preview' => substr($transcript, 0, 200),
            ]);

            // Now dispatch the regular processing job
            ProcessIngestionSourceJob::dispatch(
                $src->id,
                false,
                $this->folderIds,
                $this->userId
            );

            $logger->flush('queued_processing');

        } catch (\Throwable $e) {
            Log::error('TranscribeVoiceRecordingJob failed', [
                'ingestion_source_id' => $this->ingestionSourceId,
                'error' => $e->getMessage(),
            ]);
            $logger->capture('error', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);

            $src->status = 'failed';
            $src->error = 'Transcription failed: ' . substr($e->getMessage(), 0, 500);
            $src->save();

            $logger->flush('failed');
        }

        $this->cleanupAudioFile();
    }

    private function cleanupAudioFile(): void
    {
        try {
            if (Storage::disk('local')->exists($this->audioStoragePath)) {
                Storage::disk('local')->delete($this->audioStoragePath);
            }
        } catch (\Throwable) {
            // Ignore cleanup errors
        }
    }
}
