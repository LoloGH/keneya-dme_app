<?php

declare(strict_types=1);

use App\Console\Commands\RefreshSmsStatuses;
use App\Console\Commands\SyncDutyPeriods;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Tâches planifiées
|--------------------------------------------------------------------------
|
| Le planificateur s'active par une seule entrée cron (Linux) ou une
| tâche planifiée (Windows) :
|
|   * * * * * cd /chemin/vers/keneya-dme_app && php artisan schedule:run >> /dev/null 2>&1
|
| Voir docs/DEPLOIEMENT.md pour la configuration détaillée.
|
*/

// Suivi d'acheminement des SMS : fait évoluer « accepté » vers
// « envoyé » puis « remis » à partir des états rapportés par SMSGate.
Schedule::command(RefreshSmsStatuses::class)
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->runInBackground();

// Purge des jobs en échec de plus de sept jours.
Schedule::command('queue:prune-failed --hours=168')->daily();

// Gardes planifiées à l'avance (§60) : prise et fin automatiques.
Schedule::command(SyncDutyPeriods::class)
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->runInBackground();
