<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\KnowledgeItem;
use App\Models\SwipeItem;
use App\Jobs\ExtractBusinessFactsJob;
use App\Jobs\ExtractSwipeStructureJob;

class HydrateAiContext extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ai:context:hydrate 
                            {--type=all : The type of context to hydrate (facts, swipes, all)} 
                            {--force : Force reprocessing even if data exists}';

    protected $aliases = [
        'ai:hydrate',
    ];

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Dispatches jobs to extract Business Facts and Swipe Structures from existing content.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $type = $this->option('type');
        $force = $this->option('force');

        $this->info("Starting AI Context Hydration... [Mode: $type]");

        if ($type === 'facts' || $type === 'all') {
            $this->processFacts($force);
        }

        if ($type === 'swipes' || $type === 'all') {
            $this->processSwipes($force);
        }

        $this->newLine();
        $this->info('Hydration jobs dispatched to the queue!');
        $this->comment('Make sure your queue worker is running: php artisan queue:work');
    }

    protected function processFacts($force)
    {
        $query = KnowledgeItem::query();

        // If not forcing, only process items that don't have facts yet
        if (!$force) {
            $query->doesntHave('businessFacts');
        }

        $count = $query->count();
        
        if ($count === 0) {
            $this->comment('No Knowledge Items require fact extraction.');
            return;
        }

        $this->newLine();
        $this->info("Extracting Business Facts from $count items...");
        $bar = $this->output->createProgressBar($count);

        $query->chunk(100, function ($items) use ($bar) {
            foreach ($items as $item) {
                // Dispatch the job defined in your docs
                dispatch(new ExtractBusinessFactsJob($item->id));
                $bar->advance();
            }
        });

        $bar->finish();
    }

    protected function processSwipes($force)
    {
        $query = SwipeItem::query();

        // If not forcing, only process items that don't have structures yet
        if (!$force) {
            $query->doesntHave('swipeStructures');
        }

        $count = $query->count();

        if ($count === 0) {
            $this->comment('No Swipe Items require structure extraction.');
            return;
        }

        $this->newLine();
        $this->info("Extracting Structures from $count swipe items...");
        $bar = $this->output->createProgressBar($count);

        $query->chunk(100, function ($items) use ($bar) {
            foreach ($items as $item) {
                // Dispatch the job defined in your docs
                dispatch(new ExtractSwipeStructureJob($item->id));
                $bar->advance();
            }
        });

        $bar->finish();
    }
}

