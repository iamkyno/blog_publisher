<?php

use Illuminate\Support\Facades\Schedule;

// Cron: * * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
Schedule::command('autoposter:dispatch-uploads')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('autoposter:mark-published')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('horizon:snapshot')->everyFiveMinutes();
