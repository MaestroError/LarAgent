<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use LarAgent\Agent;

beforeEach(function () {
    Config::set('laragent.mcp_tool_caching.enabled', true);
});

it('caches MCP tools after first fetch', function () {
    Config::set('laragent.mcp_tool_caching.store', 'array');
    $agent = createTestAgent();

    $tools = $agent->test_build_tools_from_mcp_servers();

    expect($tools)->toHaveCount(1);
    expect($tools[0]->getName())->toBe('test_tool');
});

it('stores tools in cache with correct key', function () {
    Config::set('laragent.mcp_tool_caching.store', 'array');
    $agent = createTestAgent();

    $agent->test_build_tools_from_mcp_servers();

    $key = 'laragent:tools:test_server';
    expect(Cache::store('array')->has($key))->toBeTrue();
});

it('uses cached tools on subsequent requests', function () {
    Config::set('laragent.mcp_tool_caching.store', 'array');

    $agent1 = createTestAgent();
    $agent1->test_build_tools_from_mcp_servers();

    // Second agent should NOT call tools() - uses cache instead
    $agent2 = createTestAgentWithNeverCalledTools();
    $tools = $agent2->test_build_tools_from_mcp_servers();

    expect($tools)->toHaveCount(1);
    expect($tools[0]->getName())->toBe('test_tool');
});

it('preserves tool properties when caching', function () {
    Config::set('laragent.mcp_tool_caching.store', 'array');
    $agent = createTestAgent();
    $tools = $agent->test_build_tools_from_mcp_servers();

    $tool = $tools[0];
    expect($tool->getName())->toBe('test_tool');
    expect($tool->getDescription())->toBe('A test tool');
    expect($tool->getProperties())->toHaveKey('arg1');
    expect($tool->getRequired())->toContain('arg1');
});

it('clears mcp tool cache with command for default store', function () {
    Config::set('laragent.mcp_tool_caching.store', null);

    $agent = createTestAgent();
    $agent->test_build_tools_from_mcp_servers();

    expect(Cache::has('laragent:tools:test_server'))->toBeTrue();

    $this->artisan('agent:tool-clear')
        ->expectsOutput('Clearing MCP tool cache from default store...')
        ->assertExitCode(0);
});

it('clears mcp tool cache from dedicated store', function () {
    Config::set('laragent.mcp_tool_caching.store', 'array');

    $agent = createTestAgent();
    $agent->test_build_tools_from_mcp_servers();

    expect(Cache::store('array')->has('laragent:tools:test_server'))->toBeTrue();

    $this->artisan('agent:tool-clear')
        ->expectsOutput("Clearing MCP tool cache from store 'array'...")
        ->expectsOutput('Cleared 1 MCP tool cache entries.')
        ->assertExitCode(0);

    expect(Cache::store('array')->has('laragent:tools:test_server'))->toBeFalse();
});

function createTestAgent()
{
    $agent = new class('test_key') extends Agent
    {
        public $mockMcpClient;

        protected function createMcpClient(): \Redberry\MCPClient\MCPClient
        {
            return $this->mockMcpClient;
        }

        protected function initMcpClient() {}

        public function toDTO(): \LarAgent\Core\DTO\AgentDTO
        {
            return new \LarAgent\Core\DTO\AgentDTO(
                provider: 'test',
                providerName: 'test',
                message: null,
                tools: [],
                instructions: '',
                responseSchema: [],
                configuration: []
            );
        }

        public function registerMcpServers()
        {
            return ['test_server'];
        }

        public function test_build_tools_from_mcp_servers()
        {
            return $this->buildToolsFromMcpServers();
        }
    };

    $mockClient = Mockery::mock(\Redberry\MCPClient\MCPClient::class);
    $mockClient->shouldReceive('connect')->andReturnSelf();

    $mockToolsData = [
        [
            'name' => 'test_tool',
            'description' => 'A test tool',
            'inputSchema' => [
                'properties' => ['arg1' => ['type' => 'string']],
                'required' => ['arg1'],
            ],
        ],
    ];

    $mockCollection = Mockery::mock(\Redberry\MCPClient\Collection::class);
    $mockCollection->shouldReceive('getIterator')->andReturn(new \ArrayIterator($mockToolsData));
    $mockClient->shouldReceive('tools')->andReturn($mockCollection);

    $agent->mockMcpClient = $mockClient;

    Config::set('laragent.mcp_servers', [
        'test_server' => [
            'type' => 'stdio',
            'command' => ['echo', 'hello'],
        ],
    ]);

    return $agent;
}

function createTestAgentWithNeverCalledTools()
{
    $agent = new class('test_key_2') extends Agent
    {
        public $mockMcpClient;

        protected function createMcpClient(): \Redberry\MCPClient\MCPClient
        {
            return $this->mockMcpClient;
        }

        protected function initMcpClient() {}

        public function toDTO(): \LarAgent\Core\DTO\AgentDTO
        {
            return new \LarAgent\Core\DTO\AgentDTO(
                provider: 'test',
                providerName: 'test',
                message: null,
                tools: [],
                instructions: '',
                responseSchema: [],
                configuration: []
            );
        }

        public function registerMcpServers()
        {
            return ['test_server'];
        }

        public function test_build_tools_from_mcp_servers()
        {
            return $this->buildToolsFromMcpServers();
        }
    };

    $mockClient = Mockery::mock(\Redberry\MCPClient\MCPClient::class);
    $mockClient->shouldReceive('connect')->andReturnSelf();
    // tools() should NOT be called - cache must be used
    $mockClient->shouldReceive('tools')->never();

    $agent->mockMcpClient = $mockClient;

    Config::set('laragent.mcp_servers', [
        'test_server' => [
            'type' => 'stdio',
            'command' => ['echo', 'hello'],
        ],
    ]);

    return $agent;
}
