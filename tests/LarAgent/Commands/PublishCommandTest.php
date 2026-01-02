<?php

use Illuminate\Support\Facades\File;

beforeEach(function () {
    // Clean up any existing test migrations
    $patterns = [
        '*_create_laragent_storage_table.php',
        '*_create_laragent_messages_table.php',
        '*_create_laragent_session_identities_table.php',
    ];
    foreach ($patterns as $pattern) {
        $migrations = glob(database_path('migrations/'.$pattern));
        foreach ($migrations as $migration) {
            unlink($migration);
        }
    }
});

afterEach(function () {
    // Clean up after tests
    $patterns = [
        '*_create_laragent_storage_table.php',
        '*_create_laragent_messages_table.php',
        '*_create_laragent_session_identities_table.php',
    ];
    foreach ($patterns as $pattern) {
        $migrations = glob(database_path('migrations/'.$pattern));
        foreach ($migrations as $migration) {
            unlink($migration);
        }
    }
});

describe('PublishCommand', function () {

    it('publishes simple-eloquent-storage migration', function () {
        $this->artisan('la:publish', ['type' => 'simple-eloquent-storage'])
            ->expectsOutput('SimpleEloquentStorage migration published successfully!')
            ->assertExitCode(0);

        $migrations = glob(database_path('migrations/*_create_laragent_storage_table.php'));
        expect($migrations)->toHaveCount(1);
        expect(file_get_contents($migrations[0]))->toContain('laragent_storage');
    });

    it('overwrites migration when it already exists', function () {
        // First publish
        $this->artisan('la:publish', ['type' => 'simple-eloquent-storage'])
            ->assertExitCode(0);

        $firstMigrations = glob(database_path('migrations/*_create_laragent_storage_table.php'));
        $firstMigrationPath = $firstMigrations[0];

        // Wait a moment to ensure different timestamp
        sleep(1);

        // Second publish - should overwrite without confirmation
        $this->artisan('la:publish', ['type' => 'simple-eloquent-storage'])
            ->expectsOutput('SimpleEloquentStorage migration published successfully!')
            ->assertExitCode(0);

        // Should have new migration with different timestamp
        $newMigrations = glob(database_path('migrations/*_create_laragent_storage_table.php'));
        expect($newMigrations)->toHaveCount(1);
        expect($newMigrations[0])->not->toBe($firstMigrationPath);
    });

    it('fails with invalid type', function () {
        $this->artisan('la:publish', ['type' => 'invalid-type'])
            ->expectsOutput('Invalid type: invalid-type')
            ->expectsOutputToContain('Available types:')
            ->assertExitCode(1);
    });

    it('shows available types on invalid input', function () {
        $this->artisan('la:publish', ['type' => 'unknown'])
            ->expectsOutputToContain('simple-eloquent-storage')
            ->assertExitCode(1);
    });

    it('creates migrations directory if not exists', function () {
        $migrationsPath = database_path('migrations');

        // Ensure directory exists for this test (it should in Laravel)
        expect(is_dir($migrationsPath) || File::isDirectory($migrationsPath))->toBeTrue();

        $this->artisan('la:publish', ['type' => 'simple-eloquent-storage'])
            ->assertExitCode(0);

        $migrations = glob(database_path('migrations/*_create_laragent_storage_table.php'));
        expect($migrations)->toHaveCount(1);
    });

    it('migration file contains correct schema', function () {
        $this->artisan('la:publish', ['type' => 'simple-eloquent-storage'])
            ->assertExitCode(0);

        $migrations = glob(database_path('migrations/*_create_laragent_storage_table.php'));
        $content = file_get_contents($migrations[0]);

        expect($content)->toContain('Schema::create')
            ->and($content)->toContain('laragent_storage')
            ->and($content)->toContain('$table->id()')
            ->and($content)->toContain('$table->string(\'key\')')
            ->and($content)->toContain('$table->json(\'data\')')
            ->and($content)->toContain('$table->timestamps()');
    });

    it('publishes eloquent-storage-messages migration', function () {
        $this->artisan('la:publish', ['type' => 'eloquent-storage-messages'])
            ->expectsOutput('EloquentStorage (Messages) migration published successfully!')
            ->assertExitCode(0);

        $migrations = glob(database_path('migrations/*_create_laragent_messages_table.php'));
        expect($migrations)->toHaveCount(1);
        expect(file_get_contents($migrations[0]))->toContain('laragent_messages');
    });

    it('eloquent-storage-messages migration contains correct schema', function () {
        $this->artisan('la:publish', ['type' => 'eloquent-storage-messages'])
            ->assertExitCode(0);

        $migrations = glob(database_path('migrations/*_create_laragent_messages_table.php'));
        $content = file_get_contents($migrations[0]);

        expect($content)->toContain('Schema::create')
            ->and($content)->toContain('laragent_messages')
            ->and($content)->toContain('$table->id()')
            ->and($content)->toContain('$table->string(\'session_key\')')
            ->and($content)->toContain('$table->unsignedInteger(\'position\')')
            ->and($content)->toContain('$table->string(\'role\'')
            ->and($content)->toContain('$table->json(\'content\')')
            ->and($content)->toContain('$table->json(\'tool_calls\')')
            ->and($content)->toContain('$table->json(\'usage\')')
            ->and($content)->toContain('$table->json(\'metadata\')')
            ->and($content)->toContain('$table->timestamps()');
    });

    it('publishes eloquent-storage-sessions migration', function () {
        $this->artisan('la:publish', ['type' => 'eloquent-storage-sessions'])
            ->expectsOutput('EloquentStorage (SessionIdentities) migration published successfully!')
            ->assertExitCode(0);

        $migrations = glob(database_path('migrations/*_create_laragent_session_identities_table.php'));
        expect($migrations)->toHaveCount(1);
        expect(file_get_contents($migrations[0]))->toContain('laragent_session_identities');
    });

    it('eloquent-storage-sessions migration contains correct schema', function () {
        $this->artisan('la:publish', ['type' => 'eloquent-storage-sessions'])
            ->assertExitCode(0);

        $migrations = glob(database_path('migrations/*_create_laragent_session_identities_table.php'));
        $content = file_get_contents($migrations[0]);

        expect($content)->toContain('Schema::create')
            ->and($content)->toContain('laragent_session_identities')
            ->and($content)->toContain('$table->id()')
            ->and($content)->toContain('$table->string(\'session_key\')')
            ->and($content)->toContain('$table->unsignedInteger(\'position\')')
            ->and($content)->toContain('$table->string(\'key\')')
            ->and($content)->toContain('$table->string(\'agent_name\')')
            ->and($content)->toContain('$table->string(\'chat_name\')')
            ->and($content)->toContain('$table->string(\'user_id\')')
            ->and($content)->toContain('$table->string(\'group\')')
            ->and($content)->toContain('$table->string(\'scope\')')
            ->and($content)->toContain('$table->timestamps()');
    });

    it('publishes both migrations with eloquent-storage option', function () {
        $this->artisan('la:publish', ['type' => 'eloquent-storage'])
            ->expectsOutput('EloquentStorage (Messages) migration published successfully!')
            ->expectsOutput('EloquentStorage (SessionIdentities) migration published successfully!')
            ->assertExitCode(0);

        $messagesMigrations = glob(database_path('migrations/*_create_laragent_messages_table.php'));
        $sessionsMigrations = glob(database_path('migrations/*_create_laragent_session_identities_table.php'));

        expect($messagesMigrations)->toHaveCount(1);
        expect($sessionsMigrations)->toHaveCount(1);
    });

    it('eloquent-storage overwrites existing migrations', function () {
        // First publish both migrations
        $this->artisan('la:publish', ['type' => 'eloquent-storage'])
            ->assertExitCode(0);

        // Verify both were created
        $messagesMigrationsBefore = glob(database_path('migrations/*_create_laragent_messages_table.php'));
        $sessionsMigrationsBefore = glob(database_path('migrations/*_create_laragent_session_identities_table.php'));
        expect($messagesMigrationsBefore)->toHaveCount(1);
        expect($sessionsMigrationsBefore)->toHaveCount(1);

        // Store original paths
        $firstMessagesPath = $messagesMigrationsBefore[0];
        $firstSessionsPath = $sessionsMigrationsBefore[0];

        // Wait a moment to ensure different timestamp
        sleep(1);

        // Publish again - should overwrite without confirmation
        $this->artisan('la:publish', ['type' => 'eloquent-storage'])
            ->expectsOutput('EloquentStorage (Messages) migration published successfully!')
            ->expectsOutput('EloquentStorage (SessionIdentities) migration published successfully!')
            ->assertExitCode(0);

        // Both migrations should still exist (but with new timestamps - different paths)
        $messagesMigrationsAfter = glob(database_path('migrations/*_create_laragent_messages_table.php'));
        $sessionsMigrationsAfter = glob(database_path('migrations/*_create_laragent_session_identities_table.php'));

        expect($messagesMigrationsAfter)->toHaveCount(1);
        expect($sessionsMigrationsAfter)->toHaveCount(1);
        expect($messagesMigrationsAfter[0])->not->toBe($firstMessagesPath);
        expect($sessionsMigrationsAfter[0])->not->toBe($firstSessionsPath);
    });

});
