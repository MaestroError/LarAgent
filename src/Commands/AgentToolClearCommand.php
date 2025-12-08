<?php

namespace LarAgent\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class AgentToolClearCommand extends Command
{
    protected $signature = 'agent:tool-clear';

    protected $description = 'Clear the MCP tool cache';

    public function handle()
    {
        $store = config('laragent.mcp_tool_caching.store');

        if ($store) {
            $this->info("Clearing MCP tool cache from store '{$store}'...");

            // Clear only MCP tool keys, not the entire store
            $cleared = $this->clearMcpToolKeys(Cache::store($store));

            $this->info("Cleared {$cleared} MCP tool cache entries.");
        } else {
            $this->info('Clearing MCP tool cache from default store...');

            $cleared = $this->clearMcpToolKeys(Cache::getFacadeRoot());

            if ($cleared > 0) {
                $this->info("Cleared {$cleared} MCP tool cache entries.");
            } else {
                $this->warn('Cannot clear MCP tool cache selectively for this cache driver.');
                $this->warn('Use `php artisan cache:clear` to clear entire cache.');
            }
        }

        return 0;
    }

    /**
     * Clear MCP tool cache keys from the given cache instance
     */
    protected function clearMcpToolKeys($cache): int
    {
        $cleared = 0;

        // Try Redis with SCAN (safe for production)
        if (method_exists($cache->getStore(), 'getRedis')) {
            $redis = $cache->getStore()->getRedis();
            $prefix = method_exists($cache->getStore(), 'getPrefix') ? $cache->getStore()->getPrefix() : '';
            $cursor = '0';

            do {
                $result = $redis->scan($cursor, 'MATCH', $prefix.'laragent:tools:*', 'COUNT', 100);
                $cursor = $result[0];
                $keys = $result[1] ?? [];

                if (! empty($keys)) {
                    $redis->del($keys);
                    $cleared += count($keys);
                }
            } while ($cursor !== '0');

            return $cleared;
        }

        $mcpServers = config('laragent.mcp_servers', []);
        foreach (array_keys($mcpServers) as $serverName) {
            $baseKey = "laragent:tools:{$serverName}";
            if ($cache->forget($baseKey)) {
                $cleared++;
            }

            foreach (['tools', 'resources'] as $method) {
                $key = "{$baseKey}:{$method}";
                if ($cache->forget($key)) {
                    $cleared++;
                }
            }
        }

        return $cleared;
    }
}
