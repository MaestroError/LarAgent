<?php

declare(strict_types=1);

namespace LarAgent\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

final class MakeChatHistoryCommand extends Command
{
    protected $signature = 'make:chat-history {name : The name of the chat history class}';

    protected $description = 'Create a new LarAgent chat history class';

    protected $directory = 'AgentChatHistories';

    public function handle(): int
    {
        $name = $this->argument('name');

        File::ensureDirectoryExists(app_path($this->directory));

        $path = app_path($this->directory.'/'.$name.'.php');

        if (File::exists($path)) {
            $this->error('Chat history already exists: '.$name);

            return 1;
        }

        $stub = File::get(__DIR__.'/stubs/chat-history.stub');

        $stub = File::replaceInFile('{{ class }}', $name, $stub);

        File::put($path, $stub);

        $this->info('Chat history created successfully: '.$name);
        $this->line('Location: '.$path);

        return 0;
    }
}
