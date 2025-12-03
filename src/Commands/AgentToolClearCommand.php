<?php

namespace LarAgent\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class AgentToolClearCommand extends Command
{
    protected $signature = 'agent:tool-clear';

    protected $description = 'Clear the tool cache';

    public function handle()
    {
        $store = config('laragent.tool_caching.store');
        $this->info('Clearing tool cache (store: '.($store ?? 'default').')...');

        // Since we use specific keys, we can't easily "clear only tool cache" unless we use tags.
        // Cache tags are not supported by all drivers (e.g. file, database).
        // So we might have to rely on cache:clear or just warn the user.
        // OR, we can iterate if we knew the keys.
        // But we don't know the keys easily.

        // However, the requirement said: "Cache removing is preferable to happen with Laravel's standard cache:clear command, if not, we should add separate command to empty the tool's cache"

        // If we use a separate store for tools, we can clear that store.
        // If we use the default store, we might clear everything.

        // Let's try to use tags if available.
        // In Agent.php, I didn't implement tags.

        // If I can't use tags, I can't selectively clear.
        // But wait, if the user configured a specific store for tools (e.g. a separate redis db), we can flush it.

        if ($store) {
            try {
                Cache::store($store)->flush();
                $this->info('Tool cache cleared.');
            } catch (\Exception $e) {
                $this->error("Failed to clear cache store '{$store}': ".$e->getMessage());
            }
        } else {
            $this->warn("No specific tool cache store configured. Please run 'php artisan cache:clear' to clear the default cache, or configure a dedicated store for tools.");
        }

        return 0;
    }
}
