<?php

namespace LarAgent;

use Illuminate\Support\Str;
use LarAgent\Commands\AgentChatClearCommand;
use LarAgent\Commands\AgentChatCommand;
use LarAgent\Commands\AgentChatRemoveCommand;
use LarAgent\Commands\MakeAgentCommand;
use LarAgent\Commands\PublishCommand;
use LarAgent\Context\ContextManager;
use LarAgent\Commands\MakeAgentToolCommand;
use LarAgent\Core\Contracts\ChatHistory;
use LarAgent\Core\Contracts\LlmDriver;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class LarAgentServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        /*
         * This class is a Package Service Provider
         *
         * More info: https://github.com/spatie/laravel-package-tools
         */
        $package
            ->name('laragent')
            ->hasConfigFile()
            ->hasCommands([
                MakeAgentCommand::class,
                MakeAgentToolCommand::class,
                AgentChatCommand::class,
                AgentChatClearCommand::class,
                AgentChatRemoveCommand::class,
                PublishCommand::class,
            ]);

    }

    public function register()
    {
        parent::register();

        $this->app->singleton(LlmDriver::class, function ($app) {
            $config = $app['config']->get('laragent.providers.default');
            $defaultDriver = $app['config']->get('laragent.default_driver');

            return new $defaultDriver($config);
        });

        $this->app->bind(ChatHistory::class, function ($app) {
            $name = Str::random(10);
            $defaultChatHistory = $app['config']->get('laragent.default_chat_history');

            return new $defaultChatHistory($name, []);
        });

        // Register ContextManager for facade
        $this->app->bind(ContextManager::class, function ($app) {
            return new ContextManager();
        });
    }
}
