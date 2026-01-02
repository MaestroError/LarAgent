<?php

namespace LarAgent\Commands;

use Illuminate\Console\Command;
use LarAgent\Facades\Context;

class AgentChatRemoveCommand extends Command
{
    protected $signature = 'agent:chat:remove {agent : The name of the agent to remove chat history for}';

    protected $description = 'Remove chat history for a specific agent';

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

        // Use Context facade to get chat identities count
        $manager = Context::of($agentClass);
        $chatIdentities = $manager->getChatIdentities();
        $count = $chatIdentities->count();

        if ($count > 0) {
            $this->info("Found {$count} chat histories to remove...");

            // List the keys being removed
            foreach ($chatIdentities as $identity) {
                $this->line("Removing chat history: {$identity->getKey()}");
            }

            // Remove all chat histories
            $manager->removeAllChats();

            $this->info("Successfully removed all chat histories for agent: {$agentName}");
        } else {
            $this->info("No chat histories found for agent: {$agentName}");
        }

        return 0;
    }
}
