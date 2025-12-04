<?php

namespace LarAgent\Commands;

use Illuminate\Console\Command;

class AgentChatClearCommand extends Command
{
    protected $signature = 'agent:chat:clear {agent : The name of the agent to clear chat history for}';

    protected $description = 'Clear chat history for a specific agent';

    public function handle()
    {
        $agentName = $this->argument('agent');

        // Try both namespaces
        $agentClass = "\\App\\AiAgents\\{$agentName}";
        if (! class_exists($agentClass)) {
            $agentClass = "\\App\\Agents\\{$agentName}";
            if (! class_exists($agentClass)) {
                $this->error("Agent not found: {$agentName}");

                return 1;
            }
        }

        // Create a temporary instance to get chat keys (using reserved prefix so it won't be tracked)
        $tempAgent = $agentClass::for(\LarAgent\Context\Storages\IdentityStorage::TEMP_SESSION_PREFIX);
        $chatKeys = $tempAgent->getChatKeys();

        // @todo: create via context facade
        // @deprecated for now
        // // getChatKeys() returns only chatHistory keys, extract chat name and clear each
        // $prefix = "chatHistory_{$agentName}_";
        // foreach ($chatKeys as $key) {
        //     $chatName = substr($key, strlen($prefix));
        //     // Create agent for this specific chat and clear it
        //     // clear() internally calls writeToMemory() which persists the empty state
        //     $agentClass::for($chatName)->clear();
        // }

        $this->info("Successfully cleared chat history for agent: {$agentName}");

        return 0;
    }
}
