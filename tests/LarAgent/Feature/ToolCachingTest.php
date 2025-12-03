<?php

namespace LarAgent\Tests\LarAgent\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use LarAgent\Agent;
use LarAgent\Tests\TestCase;
use LarAgent\Tool;

class ToolCachingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Config::set('laragent.tool_caching.enabled', true);
        Config::set('laragent.tool_caching.store', 'array'); // Use array driver for testing
    }

    public function test_it_caches_mcp_tools()
    {
        // Mock the MCP client response or behavior
        // Since we can't easily mock the internal MCP client without dependency injection or facade,
        // we might need to rely on a subclass of Agent that mocks the MCP client creation.

        // However, Agent.php has `createMcpClient` which is protected.
        // We can create a TestAgent that overrides it.

        $agent = new class('test_key') extends Agent
        {
            public $mockMcpClient;

            protected function createMcpClient(): \Redberry\MCPClient\MCPClient
            {
                return $this->mockMcpClient;
            }

            protected function initMcpClient()
            {
                // Do nothing
            }

            public function toDTO(): \LarAgent\Core\DTO\AgentDTO
            {
                // Return dummy DTO to avoid triggering getTools() during construction
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

            public function manualInitMcpClient()
            {
                // Do nothing
            }

            public function registerMcpServers()
            {
                return ['test_server'];
            }

            // Expose protected method for testing
            public function test_build_tools_from_mcp_servers()
            {
                return $this->buildToolsFromMcpServers();
            }
        };

        // Mock MCP Client
        $mockClient = \Mockery::mock(\Redberry\MCPClient\MCPClient::class);
        $mockClient->shouldReceive('connect')->andReturnSelf();

        // Mock tools response
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

        $mockCollection = \Mockery::mock(\Redberry\MCPClient\Collection::class);
        $mockCollection->shouldReceive('getIterator')->andReturn(new \ArrayIterator($mockToolsData));

        $mockClient->shouldReceive('tools')->andReturn($mockCollection);

        $agent->mockMcpClient = $mockClient;

        // Configure agent to use a dummy MCP server
        Config::set('laragent.mcp_servers', [
            'test_server' => [
                'type' => 'stdio', // Dummy type
                'command' => ['echo', 'hello'],
            ],
        ]);

        // Trigger tool building (which should cache)
        $tools = $agent->test_build_tools_from_mcp_servers();

        $this->assertCount(1, $tools);
        $this->assertEquals('test_tool', $tools[0]->getName());

        // Verify it is in cache
        // Key format: laragent:tools:{serverName}
        $key = 'laragent:tools:test_server';
        $this->assertTrue(Cache::store('array')->has($key));

        // Now, let's create a new agent and verify it uses the cache
        // We will mock the client to throw an exception if tools() is called

        $agent2 = new class('test_key_2') extends Agent
        {
            public $mockMcpClient;

            protected function createMcpClient(): \Redberry\MCPClient\MCPClient
            {
                return $this->mockMcpClient;
            }

            protected function initMcpClient()
            {
                // Do nothing
            }

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

        $mockClient2 = \Mockery::mock(\Redberry\MCPClient\MCPClient::class);
        // connect might still be called for lazy connection setup
        $mockClient2->shouldReceive('connect')->andReturnSelf();
        // tools() should NOT be called
        $mockClient2->shouldReceive('tools')->never();

        $agent2->mockMcpClient = $mockClient2;

        $tools2 = $agent2->test_build_tools_from_mcp_servers();

        $this->assertCount(1, $tools2);
        $this->assertEquals('test_tool', $tools2[0]->getName());
    }
}
