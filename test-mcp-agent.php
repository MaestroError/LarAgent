<?php

require_once __DIR__.'/vendor/autoload.php';

use LarAgent\Attributes\Tool;

function config(string $key): mixed
{
    $yourApiKey = include 'openai-api-key.php';
    $githubKey = include 'github-api-key.php';

    return [
        'laragent.default_driver' => LarAgent\Drivers\OpenAi\OpenAiDriver::class,
        'laragent.default_chat_history' => LarAgent\History\InMemoryChatHistory::class,
        'laragent.providers.default' => [
            'label' => 'openai',
            'model' => 'gpt-4o',
            'api_key' => $yourApiKey,
            'default_context_window' => 50000,
            'default_max_completion_tokens' => 1000,
            'default_temperature' => 1,
        ],
        'laragent.fallback_provider' => null,
        'laragent.mcp_servers' => [
            'github' => [
                'type' => \Redberry\MCPClient\Enums\Transporters::HTTP,
                'base_url' => 'https://api.githubcopilot.com/mcp',
                'timeout' => 30,
                'token' => $githubKey,
            ],
            'mcp_server_memory' => [
                'type' => \Redberry\MCPClient\Enums\Transporters::STDIO,
                'command' => [
                    'npx',
                    '-y',
                    '@modelcontextprotocol/server-memory',
                ],
                'timeout' => 30,
                'cwd' => './',
            ],
            'mcp_everything' => [
                'type' => \Redberry\MCPClient\Enums\Transporters::STDIO,
                'command' => [
                    'npx',
                    '-y',
                    '@modelcontextprotocol/server-everything',
                ],
                'timeout' => 30,
                'cwd' => './',
                'env' => [],
            ],
        ],
    ][$key];
}

class McpAgent extends LarAgent\Agent
{
    protected $provider = 'default';

    protected $model = 'gpt-4.1-mini';

    protected $history = 'in_memory';

    protected $mcpServers = [
        'github',
    ];

    public function instructions()
    {
        $user = ['name' => 'John', 'age' => 25];

        // Example of manual resource usage
        $resourceArray = $this->mcpClient->connect('mcp_everything')->readResource('test://static/resource/1');
        // Returns:
        // "contents" => array:1 [▼
        //     0 => array:4 [▼
        //         "uri" => "test://static/resource/1"
        //         "name" => "Resource 1"
        //         "mimeType" => "text/plain"
        //         "text" => "Resource 1: This is a plaintext resource"
        //     ]
        // ]

        return
            "You are weather agent holding info about weather in any city.
            Always use User's name while responding.
            User info: ".json_encode($user);
    }

    public function prompt($message)
    {
        return $message;
    }

    public function registerTools()
    {
        $user = ['location' => 'Tbilisi'];

        return [
            \LarAgent\Tool::create('user_location', "Returns user's current location")
                ->setCallback(function () use ($user) {
                    return $user['location'];
                }),
        ];
    }

    public function registerMcpServers()
    {
        return [
            'mcp_server_memory:tools|except:delete_entities,delete_observations,delete_relations',
            'mcp_everything:resources|only:Resource 1,Resource 2',
        ];
    }

    // Example of a tool defined as a method with optional and required parameters
    #[Tool('Get the current weather in a given location')]
    public function weatherTool(string $location, $unit = 'celsius')
    {
        echo '// Wheather tool called for '.$location." // \n\n";

        return 'The weather in '.$location.' is '.'20'.' degrees '.$unit;
    }
}

// dd(McpAgent::for('test_chat')->getTools());
// echo McpAgent::for('test_chat')->message('Please read the resource 1 and tell me what it says')->respond();
// echo McpAgent::for('test_chat')->message('Create an entity persona, with name John (I), he is a developer')->respond();
echo McpAgent::for('test_chat')->message('Give me list of repositories on maestroerror account')->respond();
