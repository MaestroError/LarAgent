<?php

namespace LarAgent\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class MakeAgentToolCommand extends Command
{
    protected $signature = 'make:agent:tool {name : The name of the agent tool}';

    protected $description = 'Create a new agent tool class';

    public function handle()
    {
        $name = $this->argument('name');
        $toolsDir = app_path('AgentTools');
        $filePath = $toolsDir.'/'.$name.'.php';

        // Check if directory exists, if not create it
        if (! File::isDirectory($toolsDir)) {
            File::makeDirectory($toolsDir, 0755, true);
        }

        // Check if file already exists
        if (File::exists($filePath)) {
            $this->error("Agent tool already exists: {$name}");

            return Command::FAILURE;
        }

        // Get the stub content
        $stub = File::get(__DIR__.'/stubs/agent-tool.stub');

        // Replace placeholders
        $toolNameKebab = Str::snake($name);
        $content = str_replace(
            ['{{ class }}', '{{ name }}'],
            [$name, $toolNameKebab],
            $stub
        );

        // Create the file
        File::put($filePath, $content);

        $this->info("Agent tool created successfully: {$name}");
        $this->info("Location: {$filePath}");

        return Command::SUCCESS;
    }
}
