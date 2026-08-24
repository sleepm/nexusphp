<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class CleanupRun extends Command
{
    protected $signature = 'cleanup:run {--forceall : Force a complete cleaning instead of interval-based}';

    protected $description = 'Run full site cleanup (docleanup). Options: --forceall';

    public function handle(): int
    {
        $forceAll = $this->option('forceall') ? 1 : 0;

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
        // docleanup() can `global` the derived variables. The legacy bootstrap
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
        require_once ROOT_PATH . 'include/cleanup.php';

        $tstart = microtime(true);
        $result = docleanup($forceAll, true);
        $totaltime = microtime(true) - $tstart;

        $this->info(sprintf('Time consumed: %.6f sec', $totaltime));
        $this->info('Done');
        do_log(sprintf('[CLEANUP_RUN] forceall: %d, result: %s, cost: %.6f sec', $forceAll, $result, $totaltime));
        return Command::SUCCESS;
    }
}