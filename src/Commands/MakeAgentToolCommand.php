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
        // Validate that the name is a valid PHP class name (PascalCase)
        if (!preg_match('/^[A-Z][a-zA-Z0-9]*$/', $name)) {
            $this->error('Invalid tool name. Tool name must be a valid PHP class name (PascalCase).');
            return Command::FAILURE;
        }
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
        $toolNameSnake = Str::snake($name);
        $content = str_replace(
            ['{{ class }}', '{{ name }}'],
            [$name, $toolNameSnake],
            $stub
        );

        // Create the file
        File::put($filePath, $content);

        $this->info("Agent tool created successfully: {$name}");
        $this->line("Location: {$filePath}");

        return Command::SUCCESS;
    }
}
