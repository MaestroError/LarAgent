<?php

namespace LarAgent\Commands;

use Illuminate\Console\Command;

class PublishCommand extends Command
{
    protected $signature = 'la:publish {type : The type of resource to publish (simple-eloquent-storage, eloquent-storage, eloquent-storage-messages, eloquent-storage-sessions, usage-storage)}';

    protected $description = 'Publish LarAgent resources (migrations, etc.)';

    public function handle()
    {
        $type = $this->argument('type');

        return match ($type) {
            'simple-eloquent-storage' => $this->publishMigration(
                'create_laragent_storage_table.php',
                'SimpleEloquentStorage',
                __DIR__.'/../Context/Database/migrations/'
            ),
            'eloquent-storage' => $this->publishBothEloquentMigrations(),
            'eloquent-storage-messages' => $this->publishMigration(
                'create_laragent_messages_table.php',
                'EloquentStorage (Messages)',
                __DIR__.'/../Context/Database/migrations/'
            ),
            'eloquent-storage-sessions' => $this->publishMigration(
                'create_laragent_session_identities_table.php',
                'EloquentStorage (SessionIdentities)',
                __DIR__.'/../Context/Database/migrations/'
            ),
            'usage-storage' => $this->publishMigration(
                'create_laragent_usage_table.php',
                'UsageStorage',
                __DIR__.'/../Usage/Database/migrations/'
            ),
            default => $this->invalidType($type),
        };
    }

    /**
     * Publish both EloquentStorage migrations (messages and session identities).
     *
     * @return int Exit code
     */
    protected function publishBothEloquentMigrations(): int
    {
        $basePath = __DIR__.'/../Context/Database/migrations/';

        $result1 = $this->publishMigration(
            'create_laragent_messages_table.php',
            'EloquentStorage (Messages)',
            $basePath
        );

        if ($result1 !== 0) {
            return $result1;
        }

        // Add a small delay to ensure different timestamps
        sleep(1);

        return $this->publishMigration(
            'create_laragent_session_identities_table.php',
            'EloquentStorage (SessionIdentities)',
            $basePath
        );
    }

    /**
     * Publish a migration file.
     *
     * @param  string  $migrationFile  The migration filename
     * @param  string  $name  Human-readable name for output
     * @param  string  $sourcePath  Path to the source migration directory
     * @return int Exit code
     */
    protected function publishMigration(string $migrationFile, string $name, string $sourcePath): int
    {
        $fullSourcePath = $sourcePath.$migrationFile;
        $timestamp = date('Y_m_d_His');
        $destinationPath = database_path("migrations/{$timestamp}_{$migrationFile}");

        if (! file_exists($fullSourcePath)) {
            $this->error("Source migration file not found: {$migrationFile}");

            return 1;
        }

        // Remove existing migration if exists (by name, ignoring timestamp)
        $existingMigrations = glob(database_path("migrations/*_{$migrationFile}"));
        foreach ($existingMigrations as $existingMigration) {
            unlink($existingMigration);
        }

        // Ensure migrations directory exists
        if (! is_dir(database_path('migrations'))) {
            mkdir(database_path('migrations'), 0755, true);
        }

        // Copy migration file
        copy($fullSourcePath, $destinationPath);

        $this->info("{$name} migration published successfully!");
        $this->line('Location: '.$destinationPath);
        $this->newLine();
        $this->info('Run "php artisan migrate" to create the table.');

        return 0;
    }

    /**
     * Handle invalid type argument.
     */
    protected function invalidType(string $type): int
    {
        $this->error("Invalid type: {$type}");
        $this->newLine();
        $this->info('Available types:');
        $this->line('  - simple-eloquent-storage    : Publishes migration for SimpleEloquentStorage (stores entire array as JSON)');
        $this->line('  - eloquent-storage           : Publishes both EloquentStorage migrations (messages + sessions)');
        $this->line('  - eloquent-storage-messages  : Publishes migration for LaragentMessage model only');
        $this->line('  - eloquent-storage-sessions  : Publishes migration for LaragentSessionIdentity model only');
        $this->line('  - usage-storage              : Publishes migration for UsageStorage (token usage tracking)');

        return 1;
    }
}
