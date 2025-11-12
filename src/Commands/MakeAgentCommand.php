<?php

namespace LarAgent\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use LarAgent\Commands\Traits\ClickableOutput;

class MakeAgentCommand extends Command
{
    use ClickableOutput;

    protected $signature = 'make:agent {name : The name of the agent}';

    protected $description = 'Create a new LarAgent agent class';

    protected $directory = 'AiAgents';

    public function handle()
    {
        $name = $this->argument('name');

        $path = app_path($this->directory . '/'.$name.'.php');

        if (File::exists($path)) {
            $this->error('Agent already exists: '.$name);
            return 1;
        }

        $stub = File::get(__DIR__.'/stubs/agent.stub');

        File::ensureDirectoryExists(app_path($this->directory));

        File::put($path, $stub);

        File::replaceInFile('{{ class }}', $name, $path);

        $this->info('Agent created successfully: '.$name);
        $this->line('Location: '. $this->makeTextClickable($path));
        $this->line('Check LarAgent docs for agents: ' . $this->makeTextClickable('https://docs.laragent.ai/core-concepts/agents', 'Agent Docs'));

        return 0;
    }
}
