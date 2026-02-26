<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class TinkerDebug extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'tinker:debug {file : Filename in tinker/debug (with or without .php)}';

    /**
     * The console command description.
     */
    protected $description = 'Require and execute a PHP script from tinker/debug within the Laravel app context';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $input = (string) $this->argument('file');

        // Normalize filename
        $filename = str_ends_with($input, '.php') ? $input : $input . '.php';

        // Resolve absolute path inside project root
        $path = base_path('tinker/debug/' . $filename);

        if (! file_exists($path)) {
            $this->error("Script not found: {$path}");

            // Suggest available files
            $dir = base_path('tinker/debug');
            if (is_dir($dir)) {
                $files = collect(scandir($dir))
                    ->filter(fn ($f) => is_file($dir . DIRECTORY_SEPARATOR . $f))
                    ->filter(fn ($f) => str_ends_with($f, '.php'))
                    ->values();

                if ($files->isNotEmpty()) {
                    $this->info('Available scripts:');
                    foreach ($files as $f) {
                        $this->line(' - ' . pathinfo($f, PATHINFO_FILENAME));
                    }
                }
            }

            return self::FAILURE;
        }

        $this->comment('Running: ' . $path);

        // Ensure relative includes in scripts resolve from project root
        $cwd = getcwd();
        chdir(base_path());

        try {
            /** @noinspection PhpIncludeInspection */
            require $path; // Run the script within the Laravel app context
        } finally {
            // Restore CWD
            if ($cwd !== false) {
                @chdir($cwd);
            }
        }

        return self::SUCCESS;
    }
}

