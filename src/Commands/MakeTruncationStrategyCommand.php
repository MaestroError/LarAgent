<?php

namespace LarAgent\Commands;

use Illuminate\Console\Command;

class MakeTruncationStrategyCommand extends Command
{
    protected $signature = 'make:truncation-strategy {name : The name of the truncation strategy}';

    protected $description = 'Create a new truncation strategy class';

    public function handle()
    {
        $name = $this->argument('name');

        // Ensure name ends with "Strategy" if not already
        if (! str_ends_with($name, 'Strategy')) {
            $name .= 'Strategy';
        }

        $path = app_path('TruncationStrategies/'.$name.'.php');

        if (file_exists($path)) {
            $this->error('Truncation strategy already exists: '.$name);

            return 1;
        }

        $stub = file_get_contents(__DIR__.'/stubs/truncation-strategy.stub');

        $stub = str_replace('{{ class }}', $name, $stub);

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        file_put_contents($path, $stub);

        $this->info('Truncation strategy created successfully: '.$name);
        $this->line('Location: '.$path);

        return 0;
    }
}
