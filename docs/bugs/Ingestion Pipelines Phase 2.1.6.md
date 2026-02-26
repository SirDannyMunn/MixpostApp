
# ingestion_source i added

$UnknownTable = array(
	array(
		"id" => "019b7f12-667d-70ce-a35c-1f521c194f2e",
		"organization_id" => "019b31e7-8ff9-73e4-ac74-f9b72214bc31",
		"user_id" => "019b31e7-8f8c-71fd-9539-3e647ad91d6a",
		"source_type" => "text",
		"source_id" => NULL,
		"origin" => "manual",
		"platform" => NULL,
		"raw_url" => NULL,
		"raw_text" => "AI significantly impacts corporate strategy by shifting the focus from simple cost-cutting to **reinforcing business models**, creating **proprietary data moats**, and moving from **productivity to connectivity**.\n\nAccording to the sources, here is how AI is reshaping corporate strategy:\n\n### 1. Reinforcing Business Models Over Cost Reduction\nA critical strategic shift involves moving away from the narrative that AI is purely for automating work to reduce costs. Instead, leading companies are using AI to **reinforce their core business models** to drive revenue. \n*   **Revenue Growth:** In sectors like plaintiff law, where attorneys work on a contingency basis rather than by the hour, AI allows them to take on more clients and increase earnings rather than eroding their billable hours. \n*   **Improved Outcomes:** In loan servicing, AI voice agents are not just driving efficiency; they are achieving **better collection rates**, which strengthens the lender’s business model by delivering superior financial outcomes.\n\n### 2. Creating Compounding Competitive Advantages\nCorporate strategy is increasingly focused on building **compounding advantages** that separate leaders from followers. \n*   **Non-Public Data Assets:** Companies can create defensibility by capturing **\"outcomes data\"** from end-to-end workflows. Because this data is not available on the public internet, it cannot be used by general AI labs to train models. \n*   **Informed Decision-Making:** This proprietary data allows companies to \"triage\" their resources. For example, AI can help a firm decide which cases or projects are worth more investment based on historical outcome characteristics. \n\n### 3. Shifting from Productivity to Connectivity\nIn consumer-facing strategies, there is a predicted shift from AI as a \"productivity tool\" (helping you work) to a **\"connectivity tool\"** (helping you stay connected). \n*   **Deepening Engagement:** Strategy now involves addressing the core human emotion of wanting to be \"seen\". AI applications are being designed to ingest a user’s **digital footprint**—such as photos and online interactions—to understand them deeply without the user having to narrate their life story.\n*   **Startup Agility:** Startups can compete with large incumbents by creating **net new user interaction models** that do not natively fit into existing platforms, allowing them to capture new creative outlets.\n\n### 4. Transitioning to Autonomous Research and Development (R&D)\nFor industries like life sciences, chemicals, and material science, the strategic \"destination\" is **autonomous science**. \n*   **Self-Driving Labs:** Strategy is moving toward a \"closed loop\" where AI iterates on theory, plans experiments, and physical robots carry them out without human intervention.\n*   **Market-Driven Adoption:** The speed of this adoption is driven by market demand; industries with \"ready and willing\" buyers for research outputs, such as pharma, are prioritising these autonomous capabilities to gain **cost and speed advantages**.\n\n### Summary Analogy\nTo understand this strategic shift, think of a **compounding flywheel**. While initial AI use might just be a \"grease\" that makes the gears of a company turn more cheaply (cost reduction), a true AI-driven corporate strategy acts as the **motor** itself. By feeding the engine with proprietary outcome data, the company doesn't just run faster; it learns exactly where to steer to find the most value, eventually becoming a self-driving operation that is difficult for competitors to catch.",
		"mime_type" => NULL,
		"dedup_hash" => "cf915344cb93f134945ffde6d7c086fdf8cb0859",
		"status" => "completed",
		"error" => NULL,
		"created_at" => "2026-01-02 14:17:50",
		"updated_at" => "2026-01-02 14:17:54",
		"title" => NULL,
		"metadata" => NULL,
		"confidence_score" => NULL,
		"quality_score" => NULL,
		"deleted_at" => NULL,
		"quality" => NULL,
		"dedup_reason" => NULL,
	),
);

# expected result

This was a large piece of pasted text, so I was expecting it to be broken down into various chunk types. 

# failed job log from failed_jobs table

PDOException: SQLSTATE[22001]: String data, right truncated: 7 ERROR:  value too long for type character varying(20) in C:\laragon\www\MixpostApp\vendor\laravel\framework\src\Illuminate\Database\Connection.php:570
Stack trace:
#0 C:\laragon\www\MixpostApp\vendor\laravel\framework\src\Illuminate\Database\Connection.php(570): PDOStatement->execute()
#1 C:\laragon\www\MixpostApp\vendor\laravel\framework\src\Illuminate\Database\Connection.php(813): Illuminate\Database\Connection->Illuminate\Database\{closure}('insert into "kn...', Array)
#2 C:\laragon\www\MixpostApp\vendor\laravel\framework\src\Illuminate\Database\Connection.php(780): Illuminate\Database\Connection->runQueryCallback('insert into "kn...', Array, Object(Closure))
#3 C:\laragon\www\MixpostApp\vendor\laravel\framework\src\Illuminate\Database\Connection.php(559): Illuminate\Database\Connection->run('insert into "kn...', Array, Object(Closure))
#4 C:\laragon\www\MixpostApp\vendor\laravel\framework\src\Illuminate\Database\Connection.php(523): Illuminate\Database\Connection->statement('insert into "kn...', Array)
#5 C:\laragon\www\MixpostApp\vendor\laravel\framework\src\Illuminate\Database\Query\Builder.php(3804): Illuminate\Database\Connection->insert('insert into "kn...', Array)
#6 C:\laragon\www\MixpostApp\vendor\laravel\framework\src\Illuminate\Database\Eloquent\Builder.php(2235): Illuminate\Database\Query\Builder->insert(Array)
#7 C:\laragon\www\MixpostApp\vendor\laravel\framework\src\Illuminate\Database\Eloquent\Model.php(1412): Illuminate\Database\Eloquent\Builder->__call('insert', Array)
#8 C:\laragon\www\MixpostApp\vendor\laravel\framework\src\Illuminate\Database\Eloquent\Model.php(1240): Illuminate\Database\Eloquent\Model->performInsert(Object(Illuminate\Database\Eloquent\Builder))
#9 C:\laragon\www\MixpostApp\vendor\laravel\framework\src\Illuminate\Database\Eloquent\Builder.php(1219): Illuminate\Database\Eloquent\Model->save()
#10 C:\laragon\www\MixpostApp\vendor\laravel\framework\src\Illuminate\Support\helpers.php(390): Illuminate\Database\Eloquent\Builder->Illuminate\Database\Eloquent\{closure}(Object(App\Models\KnowledgeChunk))
#11 C:\laragon\www\MixpostApp\vendor\laravel\framework\src\Illuminate\Database\Eloquent\Builder.php(1218): tap(Object(App\Models\KnowledgeChunk), Object(Closure))
#12 C:\laragon\www\MixpostApp\vendor\laravel\framework\src\Illuminate\Support\Traits\ForwardsCalls.php(23): Illuminate\Database\Eloquent\Builder->create(Array)
#13 C:\laragon\www\MixpostApp\vendor\laravel\framework\src\Illuminate\Database\Eloquent\Model.php(2540): Illuminate\Database\Eloquent\Model->forwardCallTo(Object(Illuminate\Database\Eloquent\Builder), 'create', Array)
#14 C:\laragon\www\MixpostApp\vendor\laravel\framework\src\Illuminate\Database\Eloquent\Model.php(2556): Illuminate\Database\Eloquent\Model->__call('create', Array)
#15 C:\laragon\www\MixpostApp\app\Jobs\ChunkKnowledgeItemJob.php(55): Illuminate\Database\Eloquent\Model::__callStatic('create', Array)
#16 C:\laragon\www\MixpostApp\vendor\laravel\framework\src\Illuminate\Container\BoundMethod.php(36): App\Jobs\ChunkKnowledgeItemJob->handle()
#17 C:\laragon\www\MixpostApp\vendor\laravel\framework\src\Illuminate\Container\Util.php(43): Illuminate\Container\BoundMethod::Illuminate\Container\{closure}()
#18 C:\laragon\www\MixpostApp\vendor\laravel\framework\src\Illuminate\Container\BoundMethod.php(96): Illuminate\Container\Util::unwrapIfClosure(Object(Closure))
#19 C:\laragon\www\MixpostApp\vendor\laravel\framework\src\Illuminate\Container\BoundMethod.php(35): Illuminate\Container\BoundMethod::callBoundMethod(Object(Illuminate\Foundation\Application), Array, Object(Closure))
#20 C:\laragon\www\MixpostApp\vendor\laravel\framework\src\Illuminate\Container\Container.php(799): Illuminate\Container\BoundMethod::call(Object(Illuminate\Foundation\Application), Array, Array, NULL)
#21 C:\laragon\www\MixpostApp\vendor\laravel\framework\src\Illuminate\Bus\Dispatcher.php(129): Illuminate\Container\Container->call(Array)
#22 C:\laragon\www\MixpostApp\vendor\laravel\framework\src\Illuminate\Pipeline\Pipeline.php(180): Illuminate\Bus\Dispatcher->Illuminate\Bus\{closure}(Object(App\Jobs\ChunkKnowledgeItemJob))
#23 C:\laragon\www\MixpostApp\vendor\laravel\framework\src\Illuminate\Pipeline\Pipeline.php(137): Illuminate\Pipeline\Pipeline->Illuminate\Pipeline\{closure}(Object(App\Jobs\ChunkKnowledgeItemJob))
#24 C:\laragon\www\MixpostApp\vendor\laravel\framework\src\Illuminate\Bus\Dispatcher.php(133): Illuminate\Pipeline\Pipeline->then(Object(Closure))
#25 C:\laragon\www\MixpostApp\vendor\laravel\framework\src\Illuminate\Queue\CallQueuedHandler.php(134): Illuminate\Bus\Dispatcher->dispatchNow(Object(App\Jobs\ChunkKnowledgeItemJob), false)
#26 C:\laragon\www\MixpostApp\vendor\laravel\framework\src\Illuminate\Pipeline\Pipeline.php(180): Illuminate\Queue\CallQueuedHandler->Illuminate\Queue\{closure}(Object(App\Jobs\ChunkKnowledgeItemJob))
#27 C:\laragon\www\MixpostApp\vendor\laravel\framework\src\Illuminate\Pipeline\Pipeline.php(137): Illuminate\Pipeline\Pipeline->Illuminate\Pipeline\{closure}(Object(App\Jobs\ChunkKnowledgeItemJob))
#28 C:\laragon\www\MixpostApp\vendor\laravel\framework\src\Illuminate\Queue\CallQueuedHandler.php(127): Illuminate\Pipeline\Pipeline->then(Object(Closure))
#29 C:\laragon\www\MixpostApp\vendor\laravel\framework\src\Illuminate\Queue\CallQueuedHandler.php(68): Illuminate\Queue\CallQueuedHandler->dispatchThroughMiddleware(Object(Illuminate\Queue\Jobs\RedisJob), Object(App\Jobs\ChunkKnowledgeItemJob))
#30 C:\laragon\www\MixpostApp\vendor\laravel\framework\src\Illuminate\Queue\Jobs\Job.php(102): Illuminate\Queue\CallQueuedHandler->call(Object(Illuminate\Queue\Jobs\RedisJob), Array)
#31 C:\laragon\www\MixpostApp\vendor\laravel\framework\src\Illuminate\Queue\Worker.php(487): Illuminate\Queue\Jobs\Job->fire()
#32 C:\laragon\www\MixpostApp\vendor\laravel\framework\src\Illuminate\Queue\Worker.php(437): Illuminate\Queue\Worker->process('redis', Object(Illuminate\Queue\Jobs\RedisJob), Object(Illuminate\Queue\WorkerOptions))
#33 C:\laragon\www\MixpostApp\vendor\laravel\framework\src\Illuminate\Queue\Worker.php(201): Illuminate\Queue\Worker->runJob(Object(Illuminate\Queue\Jobs\RedisJob), 'redis', Object(Illuminate\Queue\WorkerOptions))
#34 C:\laragon\www\MixpostApp\vendor\laravel\framework\src\Illuminate\Queue\Console\WorkCommand.php(148): Illuminate\Queue\Worker->daemon('redis', 'default', Object(Illuminate\Queue\WorkerOptions))
#35 C:\laragon\www\MixpostApp\vendor\laravel\framework\src\Illuminate\Queue\Console\WorkCommand.php(131): Illuminate\Queue\Console\WorkCommand->runWorker('redis', 'default')
#36 C:\laragon\www\MixpostApp\vendor\laravel\framework\src\Illuminate\Container\BoundMethod.php(36): Illuminate\Queue\Console\WorkCommand->handle()
#37 C:\laragon\www\MixpostApp\vendor\laravel\framework\src\Illuminate\Container\Util.php(43): Illuminate\Container\BoundMethod::Illuminate\Container\{closure}()
#38 C:\laragon\www\MixpostApp\vendor\laravel\framework\src\Illuminate\Container\BoundMethod.php(96): Illuminate\Container\Util::unwrapIfClosure(Object(Closure))
#39 C:\laragon\www\MixpostApp\vendor\laravel\framework\src\Illuminate\Container\BoundMethod.php(35): Illuminate\Container\BoundMethod::callBoundMethod(Object(Illuminate\Foundation\Application), Array, Object(Closure))
#40 C:\laragon\www\MixpostApp\vendor\laravel\framework\src\Illuminate\Container\Container.php(799): Illuminate\Container\BoundMethod::call(Object(Illuminate\Foundation\Application), Array, Array, NULL)
#41 C:\laragon\www\MixpostApp\vendor\laravel\framework\src\Illuminate\Console\Command.php(211): Illuminate\Container\Container->call(Array)
#42 C:\laragon\www\MixpostApp\vendor\symfony\console\Command\Command.php(341): Illuminate\Console\Command->execute(Object(Symfony\Component\Console\Input\ArgvInput), Object(Illuminate\Console\OutputStyle))
#43 C:\laragon\www\MixpostApp\vendor\laravel\framework\src\Illuminate\Console\Command.php(180): Symfony\Component\Console\Command\Command->run(Object(Symfony\Component\Console\Input\ArgvInput), Object(Illuminate\Console\OutputStyle))
#44 C:\laragon\www\MixpostApp\vendor\symfony\console\Application.php(1102): Illuminate\Console\Command->run(Object(Symfony\Component\Console\Input\ArgvInput), Object(Symfony\Component\Console\Output\ConsoleOutput))
#45 C:\laragon\www\MixpostApp\vendor\symfony\console\Application.php(356): Symfony\Component\Console\Application->doRunCommand(Object(Illuminate\Queue\Console\WorkCommand), Object(Symfony\Component\Console\Input\ArgvInput), Object(Symfony\Component\Console\Output\ConsoleOutput))
#46 C:\laragon\www\MixpostApp\vendor\symfony\console\Application.php(195): Symfony\Component\Console\Application->doRun(Object(Symfony\Component\Console\Input\ArgvInput), Object(Symfony\Component\Console\Output\ConsoleOutput))
#47 C:\laragon\www\MixpostApp\vendor\laravel\framework\src\Illuminate\Foundation\Console\Kernel.php(198): Symfony\Component\Console\Application->run(Object(Symfony\Component\Console\Input\ArgvInput), Object(Symfony\Component\Console\Output\ConsoleOutput))
#48 C:\laragon\www\MixpostApp\artisan(35): Illuminate\Foundation\Console\Kernel->handle(Object(Symfony\Component\Console\Input\ArgvInput), Object(Symfony\Component\Console\Output\ConsoleOutput))
#49 {main}

Next Illuminate\Database\QueryException: SQLSTATE[22001]: String data, right truncated: 7 ERROR:  value too long for type character varying(20) (Connection: pgsql, SQL: insert into "knowledge_chunks" ("knowledge_item_id", "organization_id", "user_id", "chunk_text", "chunk_type", "chunk_role", "authority", "confidence", "time_horizon", "source_type", "source_variant", "source_ref", "tags", "token_count", "created_at", "id") values (019b8404-9a01-7163-ba25-d0555dc80a38, 019b31e7-8ff9-73e4-ac74-f9b72214bc31, 019b31e7-8f8c-71fd-9539-3e647ad91d6a, Nicolás Maduro and Cilia Flores were captured during U.S. military operation on January 3, 2026, normalized_claim, other, multiple news sources, 0.9, unknown, text, normalized, {"ingestion_source_id":"019b8404-9088-737e-8c80-6dfecb36c118","knowledge_item_id":"019b8404-9a01-7163-ba25-d0555dc80a38"}, ?, 23, 2026-01-03 13:21:01, 019b8404-bdea-73bd-b08e-f1ba4eb053dc)) in C:\laragon\www\MixpostApp\vendor\laravel\framework\src\Illuminate\Database\Connection.php:826
Stack trace:
#0 C:\laragon\www\MixpostApp\vendor\laravel\framework\src\Illuminate\Database\Connection.php(780): Illuminate\Database\Connection->runQueryCallback('insert into "kn...', Array, Object(Closure))
#1 C:\laragon\www\MixpostApp\vendor\laravel\framework\src\Illuminate\Database\Connection.php(559): Illuminate\Database\Connection->run('insert into "kn...', Array, Object(Closure))
#2 C:\laragon\www\MixpostApp\vendor\laravel\framework\src\Illuminate\Database\Connection.php(523): Illuminate\Database\Connection->statement('insert into "kn...', Array)
#3 C:\laragon\www\MixpostApp\vendor\laravel\framework\src\Illuminate\Database\Query\Builder.php(3804): Illuminate\Database\Connection->insert('insert into "kn...', Array)
#4 C:\laragon\www\MixpostApp\vendor\laravel\framework\src\Illuminate\Database\Eloquent\Builder.php(2235): Illuminate\Database\Query\Builder->insert(Array)
#5 C:\laragon\www\MixpostApp\vendor\laravel\framework\src\Illuminate\Database\Eloquent\Model.php(1412): Illuminate\Database\Eloquent\Builder->__call('insert', Array)
#6 C:\laragon\www\MixpostApp\vendor\laravel\framework\src\Illuminate\Database\Eloquent\Model.php(1240): Illuminate\Database\Eloquent\Model->performInsert(Object(Illuminate\Database\Eloquent\Builder))
#7 C:\laragon\www\MixpostApp\vendor\laravel\framework\src\Illuminate\Database\Eloquent\Builder.php(1219): Illuminate\Database\Eloquent\Model->save()
#8 C:\laragon\www\MixpostApp\vendor\laravel\framework\src\Illuminate\Support\helpers.php(390): Illuminate\Database\Eloquent\Builder->Illuminate\Database\Eloquent\{closure}(Object(App\Models\KnowledgeChunk))
#9 C:\laragon\www\MixpostApp\vendor\laravel\framework\src\Illuminate\Database\Eloquent\Builder.php(1218): tap(Object(App\Models\KnowledgeChunk), Object(Closure))
#10 C:\laragon\www\MixpostApp\vendor\laravel\framework\src\Illuminate\Support\Traits\ForwardsCalls.php(23): Illuminate\Database\Eloquent\Builder->create(Array)
#11 C:\laragon\www\MixpostApp\vendor\laravel\framework\src\Illuminate\Database\Eloquent\Model.php(2540): Illuminate\Database\Eloquent\Model->forwardCallTo(Object(Illuminate\Database\Eloquent\Builder), 'create', Array)
#12 C:\laragon\www\MixpostApp\vendor\laravel\framework\src\Illuminate\Database\Eloquent\Model.php(2556): Illuminate\Database\Eloquent\Model->__call('create', Array)
#13 C:\laragon\www\MixpostApp\app\Jobs\ChunkKnowledgeItemJob.php(55): Illuminate\Database\Eloquent\Model::__callStatic('create', Array)
#14 C:\laragon\www\MixpostApp\vendor\laravel\framework\src\Illuminate\Container\BoundMethod.php(36): App\Jobs\ChunkKnowledgeItemJob->handle()
#15 C:\laragon\www\MixpostApp\vendor\laravel\framework\src\Illuminate\Container\Util.php(43): Illuminate\Container\BoundMethod::Illuminate\Container\{closure}()
#16 C:\laragon\www\MixpostApp\vendor\laravel\framework\src\Illuminate\Container\BoundMethod.php(96): Illuminate\Container\Util::unwrapIfClosure(Object(Closure))
#17 C:\laragon\www\MixpostApp\vendor\laravel\framework\src\Illuminate\Container\BoundMethod.php(35): Illuminate\Container\BoundMethod::callBoundMethod(Object(Illuminate\Foundation\Application), Array, Object(Closure))
#18 C:\laragon\www\MixpostApp\vendor\laravel\framework\src\Illuminate\Container\Container.php(799): Illuminate\Container\BoundMethod::call(Object(Illuminate\Foundation\Application), Array, Array, NULL)
#19 C:\laragon\www\MixpostApp\vendor\laravel\framework\src\Illuminate\Bus\Dispatcher.php(129): Illuminate\Container\Container->call(Array)
#20 C:\laragon\www\MixpostApp\vendor\laravel\framework\src\Illuminate\Pipeline\Pipeline.php(180): Illuminate\Bus\Dispatcher->Illuminate\Bus\{closure}(Object(App\Jobs\ChunkKnowledgeItemJob))
#21 C:\laragon\www\MixpostApp\vendor\laravel\framework\src\Illuminate\Pipeline\Pipeline.php(137): Illuminate\Pipeline\Pipeline->Illuminate\Pipeline\{closure}(Object(App\Jobs\ChunkKnowledgeItemJob))
#22 C:\laragon\www\MixpostApp\vendor\laravel\framework\src\Illuminate\Bus\Dispatcher.php(133): Illuminate\Pipeline\Pipeline->then(Object(Closure))
#23 C:\laragon\www\MixpostApp\vendor\laravel\framework\src\Illuminate\Queue\CallQueuedHandler.php(134): Illuminate\Bus\Dispatcher->dispatchNow(Object(App\Jobs\ChunkKnowledgeItemJob), false)
#24 C:\laragon\www\MixpostApp\vendor\laravel\framework\src\Illuminate\Pipeline\Pipeline.php(180): Illuminate\Queue\CallQueuedHandler->Illuminate\Queue\{closure}(Object(App\Jobs\ChunkKnowledgeItemJob))
#25 C:\laragon\www\MixpostApp\vendor\laravel\framework\src\Illuminate\Pipeline\Pipeline.php(137): Illuminate\Pipeline\Pipeline->Illuminate\Pipeline\{closure}(Object(App\Jobs\ChunkKnowledgeItemJob))
#26 C:\laragon\www\MixpostApp\vendor\laravel\framework\src\Illuminate\Queue\CallQueuedHandler.php(127): Illuminate\Pipeline\Pipeline->then(Object(Closure))
#27 C:\laragon\www\MixpostApp\vendor\laravel\framework\src\Illuminate\Queue\CallQueuedHandler.php(68): Illuminate\Queue\CallQueuedHandler->dispatchThroughMiddleware(Object(Illuminate\Queue\Jobs\RedisJob), Object(App\Jobs\ChunkKnowledgeItemJob))
#28 C:\laragon\www\MixpostApp\vendor\laravel\framework\src\Illuminate\Queue\Jobs\Job.php(102): Illuminate\Queue\CallQueuedHandler->call(Object(Illuminate\Queue\Jobs\RedisJob), Array)
#29 C:\laragon\www\MixpostApp\vendor\laravel\framework\src\Illuminate\Queue\Worker.php(487): Illuminate\Queue\Jobs\Job->fire()
#30 C:\laragon\www\MixpostApp\vendor\laravel\framework\src\Illuminate\Queue\Worker.php(437): Illuminate\Queue\Worker->process('redis', Object(Illuminate\Queue\Jobs\RedisJob), Object(Illuminate\Queue\WorkerOptions))
#31 C:\laragon\www\MixpostApp\vendor\laravel\framework\src\Illuminate\Queue\Worker.php(201): Illuminate\Queue\Worker->runJob(Object(Illuminate\Queue\Jobs\RedisJob), 'redis', Object(Illuminate\Queue\WorkerOptions))
#32 C:\laragon\www\MixpostApp\vendor\laravel\framework\src\Illuminate\Queue\Console\WorkCommand.php(148): Illuminate\Queue\Worker->daemon('redis', 'default', Object(Illuminate\Queue\WorkerOptions))
#33 C:\laragon\www\MixpostApp\vendor\laravel\framework\src\Illuminate\Queue\Console\WorkCommand.php(131): Illuminate\Queue\Console\WorkCommand->runWorker('redis', 'default')
#34 C:\laragon\www\MixpostApp\vendor\laravel\framework\src\Illuminate\Container\BoundMethod.php(36): Illuminate\Queue\Console\WorkCommand->handle()
#35 C:\laragon\www\MixpostApp\vendor\laravel\framework\src\Illuminate\Container\Util.php(43): Illuminate\Container\BoundMethod::Illuminate\Container\{closure}()
#36 C:\laragon\www\MixpostApp\vendor\laravel\framework\src\Illuminate\Container\BoundMethod.php(96): Illuminate\Container\Util::unwrapIfClosure(Object(Closure))
#37 C:\laragon\www\MixpostApp\vendor\laravel\framework\src\Illuminate\Container\BoundMethod.php(35): Illuminate\Container\BoundMethod::callBoundMethod(Object(Illuminate\Foundation\Application), Array, Object(Closure))
#38 C:\laragon\www\MixpostApp\vendor\laravel\framework\src\Illuminate\Container\Container.php(799): Illuminate\Container\BoundMethod::call(Object(Illuminate\Foundation\Application), Array, Array, NULL)
#39 C:\laragon\www\MixpostApp\vendor\laravel\framework\src\Illuminate\Console\Command.php(211): Illuminate\Container\Container->call(Array)
#40 C:\laragon\www\MixpostApp\vendor\symfony\console\Command\Command.php(341): Illuminate\Console\Command->execute(Object(Symfony\Component\Console\Input\ArgvInput), Object(Illuminate\Console\OutputStyle))
#41 C:\laragon\www\MixpostApp\vendor\laravel\framework\src\Illuminate\Console\Command.php(180): Symfony\Component\Console\Command\Command->run(Object(Symfony\Component\Console\Input\ArgvInput), Object(Illuminate\Console\OutputStyle))
#42 C:\laragon\www\MixpostApp\vendor\symfony\console\Application.php(1102): Illuminate\Console\Command->run(Object(Symfony\Component\Console\Input\ArgvInput), Object(Symfony\Component\Console\Output\ConsoleOutput))
#43 C:\laragon\www\MixpostApp\vendor\symfony\console\Application.php(356): Symfony\Component\Console\Application->doRunCommand(Object(Illuminate\Queue\Console\WorkCommand), Object(Symfony\Component\Console\Input\ArgvInput), Object(Symfony\Component\Console\Output\ConsoleOutput))
#44 C:\laragon\www\MixpostApp\vendor\symfony\console\Application.php(195): Symfony\Component\Console\Application->doRun(Object(Symfony\Component\Console\Input\ArgvInput), Object(Symfony\Component\Console\Output\ConsoleOutput))
#45 C:\laragon\www\MixpostApp\vendor\laravel\framework\src\Illuminate\Foundation\Console\Kernel.php(198): Symfony\Component\Console\Application->run(Object(Symfony\Component\Console\Input\ArgvInput), Object(Symfony\Component\Console\Output\ConsoleOutput))
#46 C:\laragon\www\MixpostApp\artisan(35): Illuminate\Foundation\Console\Kernel->handle(Object(Symfony\Component\Console\Input\ArgvInput), Object(Symfony\Component\Console\Output\ConsoleOutput))
#47 {main}

# logs

  2026-01-03 13:20:52 App\Jobs\ProcessIngestionSourceJob ............................................................................................................................................................................................................. RUNNING
  2026-01-03 13:20:53 App\Jobs\ProcessIngestionSourceJob ....................................................................................................................................................................................................... 839.32ms DONE
  2026-01-03 13:20:53 App\Jobs\NormalizeKnowledgeItemJob ............................................................................................................................................................................................................. RUNNING
  2026-01-03 13:21:01 App\Jobs\NormalizeKnowledgeItemJob ............................................................................................................................................................................................................. 8s DONE
  2026-01-03 13:21:01 App\Jobs\ChunkKnowledgeItemJob ................................................................................................................................................................................................................. RUNNING
  2026-01-03 13:21:02 App\Jobs\ChunkKnowledgeItemJob ........................................................................................................................................................................................................... 188.75ms FAIL