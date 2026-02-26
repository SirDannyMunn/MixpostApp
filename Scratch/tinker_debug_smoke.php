<?php

echo "[tinker-debug smoke] starting\n";

echo "app.env=" . app()->environment() . "\n";
echo "app.name=" . (string) config('app.name') . "\n";
echo "laravel.version=" . app()->version() . "\n";

echo "[tinker-debug smoke] done\n";
