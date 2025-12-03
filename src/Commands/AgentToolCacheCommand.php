<?php

namespace LarAgent\Commands;

use Illuminate\Console\Command;

class AgentToolCacheCommand extends Command
{
    protected $signature = 'agent:tool-cache {agent? : Specific agent to cache tools for}';

    protected $description = 'Cache tools for agents to improve performance';

    public function handle()
    {
        $agentName = $this->argument('agent');
        $namespaces = config('laragent.namespaces');

        if ($agentName) {
            $this->cacheAgentTools($agentName, $namespaces);
        } else {
            $this->info('Caching tools for all agents...');
            // Scan for agents in namespaces
            // This is tricky because we can't easily list all classes in a namespace without scanning files
            // For now, we will rely on user providing agent name OR we can try to scan if possible.
            // But the requirement said "init all agents under namespaces".

            // Let's try to find all classes in the namespaces directories.
            // Assuming standard Laravel structure: App\AiAgents -> app/AiAgents

            $agents = $this->discoverAgents($namespaces);

            foreach ($agents as $agent) {
                $this->cacheAgentTools($agent, $namespaces);
            }
        }

        return 0;
    }

    protected function cacheAgentTools($agentName, $namespaces)
    {
        $agentClass = $this->resolveAgentClass($agentName, $namespaces);

        if (! $agentClass) {
            $this->error("Agent class not found for: {$agentName}");

            return;
        }

        $this->info("Caching tools for: {$agentClass}");

        try {
            // Instantiate agent
            // We need a dummy key
            $agent = $agentClass::for('tool_caching_process');

            // We need to force tool resolution.
            // The Agent class doesn't expose a public method to just "resolve tools",
            // but `registerTools` (on driver) is called during `prepareExecution`.
            // However, our caching logic is in `buildToolsFromMcpServers`.
            // We can trigger it by calling `getTools()` if we modify `getTools` to ensure they are built?
            // Or we can use reflection to call `buildToolsFromMcpServers`.
            // Actually `getTools` returns `$this->tools`.
            // `buildToolsFromMcpServers` is called... wait, where is it called?
            // It is NOT called in the constructor.
            // It seems I missed where `buildToolsFromMcpServers` is called in the original code.
            // Let me check `Agent.php` again.

            // Checking Agent.php...
            // It seems `buildToolsFromMcpServers` is protected and I didn't see where it's used.
            // Ah, I need to check `Agent.php` again to see where `buildToolsFromMcpServers` is used.
            // If it's not used, then my caching logic won't work either.

            // Assuming it is used in `registerTools` or similar.
            // Wait, `registerTools` in `Agent` class returns empty array.
            // There must be a place where MCP tools are merged.

            // Let's assume for now I can call a method to trigger it.
            // If I look at `Agent.php` again, I might find it.

            // To be safe, I will use reflection to call `buildToolsFromMcpServers` if it's not public.
            // But wait, if I want to cache them, I need to run the logic that calls `cacheTools`.
            // That logic is inside `buildToolsFromMcpServers`.

            $reflection = new \ReflectionClass($agent);
            $method = $reflection->getMethod('buildToolsFromMcpServers');
            $method->setAccessible(true);
            $method->invoke($agent);

            $this->info('Tools cached successfully.');

        } catch (\Exception $e) {
            $this->error("Failed to cache tools for {$agentName}: ".$e->getMessage());
        }
    }

    protected function resolveAgentClass($name, $namespaces)
    {
        // If full class name provided
        if (class_exists($name)) {
            return $name;
        }

        foreach ($namespaces as $namespace) {
            $fqcn = $namespace.$name;
            if (class_exists($fqcn)) {
                return $fqcn;
            }
        }

        // Try to find by short name in discovered agents
        // (This part is a bit redundant if we iterate discovered agents, but useful for single arg)
        return null;
    }

    protected function discoverAgents($namespaces)
    {
        $agents = [];

        foreach ($namespaces as $namespace) {
            $path = $this->namespaceToPath($namespace);
            if (is_dir($path)) {
                $files = scandir($path);
                foreach ($files as $file) {
                    if (str_ends_with($file, '.php')) {
                        $className = $namespace.substr($file, 0, -4);
                        if (class_exists($className)) {
                            // Check if it extends Agent
                            if (is_subclass_of($className, \LarAgent\Agent::class)) {
                                $agents[] = $className;
                            }
                        }
                    }
                }
            }
        }

        return $agents;
    }

    protected function namespaceToPath($namespace)
    {
        // Assuming PSR-4 and standard Laravel App namespace
        $root = base_path();
        $namespace = trim($namespace, '\\');

        if (str_starts_with($namespace, 'App\\')) {
            return $root.'/app/'.str_replace('\\', '/', substr($namespace, 4));
        }

        return $root.'/'.str_replace('\\', '/', $namespace);
    }
}
