<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class HttpGetReport extends Command
{
    protected $signature = 'http:get-report
                            {--paths= : Comma-separated URL paths to filter by (e.g., "classify-intent,ai/chat")}
                            {--after= : Only include logs after this timestamp (e.g., "2026-01-12 12:00:00")}';

    protected $description = 'Parse http.log and output matching HTTP requests/responses to a new report file';

    public function handle(): int
    {
        $logPath = storage_path('logs/http.log');

        if (!file_exists($logPath)) {
            $this->error('http.log not found at: ' . $logPath);
            return 1;
        }

        $paths = $this->option('paths');
        $after = $this->option('after');

        $pathFilters = $paths ? array_map('trim', explode(',', $paths)) : [];
        $afterTime = null;

        if ($after) {
            try {
                $afterTime = Carbon::parse($after);
            } catch (\Exception $e) {
                $this->error('Invalid --after timestamp: ' . $e->getMessage());
                return 1;
            }
        }

        $this->info('Parsing http.log...');
        $this->info('Path filters: ' . ($paths ?: 'none'));
        $this->info('After: ' . ($after ?: 'none'));

        $entries = $this->parseLogFile($logPath);
        $filtered = $this->filterEntries($entries, $pathFilters, $afterTime);

        if (empty($filtered)) {
            $this->warn('No matching log entries found.');
            return 0;
        }

        $this->info('Found ' . count($filtered) . ' matching log entries.');

        $outputFileName = 'http-report-' . date('Ymd-His') . '.log';
        $outputPath = storage_path('logs/' . $outputFileName);

        $content = implode("\n" . str_repeat('=', 80) . "\n", $filtered);
        file_put_contents($outputPath, $content);

        $this->info('Report written: ' . $outputPath);
        return 0;
    }

    protected function parseLogFile(string $path): array
    {
        $content = file_get_contents($path);
        $entries = [];

        // Split by log entry markers (date pattern at start of line)
        $pattern = '/\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]/';
        $parts = preg_split($pattern, $content, -1, PREG_SPLIT_DELIM_CAPTURE);

        // First part is typically empty or before first log
        array_shift($parts);

        // Group into [timestamp, content] pairs
        for ($i = 0; $i < count($parts); $i += 2) {
            if (!isset($parts[$i + 1])) {
                break;
            }

            $timestamp = $parts[$i];
            $logContent = trim($parts[$i + 1]);

            $entries[] = [
                'timestamp' => $timestamp,
                'content' => '[' . $timestamp . ']' . $logContent,
                'full' => '[' . $timestamp . ']' . $logContent,
            ];
        }

        return $entries;
    }

    protected function filterEntries(array $entries, array $pathFilters, ?Carbon $afterTime): array
    {
        $filtered = [];

        foreach ($entries as $entry) {
            // Check timestamp filter
            if ($afterTime) {
                try {
                    $entryTime = Carbon::parse($entry['timestamp']);
                    if ($entryTime->lt($afterTime)) {
                        continue;
                    }
                } catch (\Exception $e) {
                    // Skip if can't parse timestamp
                    continue;
                }
            }

            // Check path filters
            if (!empty($pathFilters)) {
                $matches = false;
                foreach ($pathFilters as $pathFilter) {
                    if (stripos($entry['content'], $pathFilter) !== false) {
                        $matches = true;
                        break;
                    }
                }
                if (!$matches) {
                    continue;
                }
            }

            $filtered[] = $entry['full'];
        }

        return $filtered;
    }
}
