<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class CronRun extends Command
{
    protected $signature = 'cron:run';

    protected $description = 'Run throttled site cleanup (autoclean). Mirrors public/cron.php';

    public function handle(): int
    {
        if (!defined('TIMENOW')) {
            define('TIMENOW', time());
        }
        $GLOBALS['rootpath'] = ROOT_PATH;
        if (empty($GLOBALS['Cache'])) {
            require_once ROOT_PATH . 'classes/class_cache_redis.php';
            $GLOBALS['Cache'] = new \class_cache_redis();
        }
        // include/config.php derives many legacy globals from the DB-backed
        // settings; load the settings into globals first, then run it so
        // autoclean() can `global` the derived variables. The legacy bootstrap
        // tolerates missing setting keys (warnings only), so silence them here.
        foreach (get_setting() as $name => $value) {
            $GLOBALS[strtoupper($name)] = $value;
        }
        set_error_handler(function ($severity, $message, $file, $line) {
            if (str_contains($message, 'Undefined array key') || str_contains($message, 'Undefined variable')) {
                return true;
            }
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });
        try {
            extract($GLOBALS);
            require_once ROOT_PATH . 'include/config.php';
            foreach (get_defined_vars() as $name => $value) {
                $GLOBALS[$name] = $value;
            }
        } finally {
            restore_error_handler();
        }

        if (!($GLOBALS['useCronTriggerCleanUp'] ?? true)) {
            $this->warn('Forbidden. Clean-up is set to be browser-triggered.');
            return Command::SUCCESS;
        }

        $result = autoclean();
        if ($result) {
            $this->info($result . "\n");
        } else {
            $this->info("Clean-up not triggered.\n");
        }
        do_log(sprintf('[CRON_RUN] result: %s', var_export($result, true)));
        return Command::SUCCESS;
    }
}
