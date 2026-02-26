<?php

namespace Scratch;

class TinkerDebugClassSmoke
{
    public function run(): void
    {
        echo "[tinker-debug class smoke] starting\n";
        echo "config.app.name=" . (string) config('app.name') . "\n";
        echo "[tinker-debug class smoke] done\n";
    }
}
