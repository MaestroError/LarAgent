<?php

declare(strict_types=1);

namespace LarAgent\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use LarAgent\Commands\Traits\ClickableOutput;

final class MakeToolCommand extends Command
{
    use ClickableOutput;

    protected $signature = 'make:agent:tool {name : The name of the tool class}';

    protected $description = 'Create a new LarAgent tool class';

    protected $directory = 'AgentTools';

    public function handle(): int
    {
        $name = $this->argument('name');

        $path = app_path($this->directory.'/'.$name.'.php');

        if (File::exists($path)) {
            $this->error('Tool already exists: '.$name);

            return 1;
        }

        $stub = File::get(__DIR__.'/stubs/tool.stub');

        File::ensureDirectoryExists(app_path($this->directory));

        File::put($path, $stub);

        File::replaceInFile('{{ class }}', $name, $path);

        $this->info('Tool created successfully: '.$name);
        $this->line('Location: '.$this->makeTextClickable($path));
        $this->line('Check LarAgent docs for tools: ' . $this->makeTextClickable('https://docs.laragent.ai/core-concepts/tools#3-using-tool-classes', 'Tool Docs'));

        return 0;
    }
}
