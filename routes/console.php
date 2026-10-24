<?php

use Illuminate\Support\Facades\Schedule;

// Reenvía trabajos con el mensaje perdido o cuyo worker murió
Schedule::command('mediaforge:jobs:recover')
    ->everyMinute()
    ->withoutOverlapping(10)
    ->onOneServer();

// Retención de archivos, temporales huérfanos y claves de idempotencia
Schedule::command('mediaforge:cleanup')
    ->everyFifteenMinutes()
    ->withoutOverlapping(60)
    ->onOneServer();

// Mantenimiento de la cola de Laravel
Schedule::command('queue:prune-failed --hours=720')->daily();
