<?php

namespace LarAgent\Commands;

use Illuminate\Console\Command;
use LarAgent\Facades\Context;

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

        // Use Context facade to clear all chat histories for this agent
        Context::of($agentClass)->clearAllChats();

        $this->info("Successfully cleared chat history for agent: {$agentName}");

        return 0;
    }
}
