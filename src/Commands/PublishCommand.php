<?php

namespace LarAgent\Commands;

use Illuminate\Console\Command;

class PublishCommand extends Command
{
    protected $signature = 'la:publish {type : The type of resource to publish (simple-eloquent-storage, eloquent-storage, eloquent-storage-messages, eloquent-storage-sessions)}';

    protected $description = 'Publish LarAgent resources (migrations, etc.)';

    public function handle()
    {
        $type = $this->argument('type');

        return match ($type) {
            'simple-eloquent-storage' => $this->publishMigration(
                'create_laragent_storage_table.php',
                'SimpleEloquentStorage'
            ),
            'eloquent-storage' => $this->publishBothEloquentMigrations(),
            'eloquent-storage-messages' => $this->publishMigration(
                'create_laragent_messages_table.php',
                'EloquentStorage (Messages)'
            ),
            'eloquent-storage-sessions' => $this->publishMigration(
                'create_laragent_session_identities_table.php',
                'EloquentStorage (SessionIdentities)'
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
        $result1 = $this->publishMigration(
            'create_laragent_messages_table.php',
            'EloquentStorage (Messages)'
        );

        if ($result1 !== 0) {
            return $result1;
        }

        // Add a small delay to ensure different timestamps
        sleep(1);

        return $this->publishMigration(
            'create_laragent_session_identities_table.php',
            'EloquentStorage (SessionIdentities)'
        );
    }

    /**
     * Publish a migration file.
     *
     * @param  string  $migrationFile  The migration filename
     * @param  string  $name  Human-readable name for output
     * @return int Exit code
     */
    protected function publishMigration(string $migrationFile, string $name): int
    {
        $sourcePath = __DIR__.'/../Context/Database/migrations/'.$migrationFile;
        $timestamp = date('Y_m_d_His');
        $destinationPath = database_path("migrations/{$timestamp}_{$migrationFile}");

        if (! file_exists($sourcePath)) {
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
        copy($sourcePath, $destinationPath);

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

        return 1;
    }
}
