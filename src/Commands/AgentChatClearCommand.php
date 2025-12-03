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

        // Create a temporary instance to get chat keys
        $agent = $agentClass::for('temp');
        $chatKeys = $agent->getChatKeys();

        if (! empty($chatKeys)) {
            // Clear each chat history
            foreach ($chatKeys as $key) {
                // Clear messages
                $agent->clear();
            }
        }

        $this->info("Successfully cleared chat history for agent: {$agentName}");

        return 0;
    }
}
