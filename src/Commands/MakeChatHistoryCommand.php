<?php

declare(strict_types=1);

namespace LarAgent\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use LarAgent\Commands\Traits\ClickableOutput;

final class MakeChatHistoryCommand extends Command
{
    use ClickableOutput;

    protected $signature = 'make:agent:chat-history {name : The name of the chat history class}';

    protected $description = 'Create a new LarAgent chat history class';

    protected $directory = 'AgentChatHistories';

    public function handle(): int
    {
        $name = $this->argument('name');

        $path = app_path($this->directory.'/'.$name.'.php');

        if (File::exists($path)) {
            $this->error('Chat history already exists: '.$name);
            return 1;
        }

        $stub = File::get(__DIR__.'/stubs/chat-history.stub');

        File::ensureDirectoryExists(app_path($this->directory));

        File::put($path, $stub);

        File::replaceInFile('{{ class }}', $name, $path);

        $this->info('Chat history created successfully: '.$name);
        $this->line('Location: '.$this->makeTextClickable($path));
        $this->line('Check LarAgent docs for chat history: ' . $this->makeTextClickable('https://docs.laragent.ai/core-concepts/chat-history#creating-custom-chat-histories', 'Chat History Docs'));

        return 0;
    }
}
