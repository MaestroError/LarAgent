<?php

/**
 * Manual Test: Context7 MCP server over HTTP
 *
 * Run with: php testsManual/Context7McpTest.php
 * Or: CONTEXT7_API_KEY=... php testsManual/Context7McpTest.php
 */

require_once __DIR__.'/McpManualTestSupport.php';

use Redberry\MCPClient\Enums\Transporters;

$context7ApiKey = mcpManualLoadContext7ApiKey();

mcpManualPrintHeader('Manual MCP Test: Context7 (HTTP)');

try {
    mcpManualAssert(
        $context7ApiKey !== '',
        'Missing Context7 API key. Set CONTEXT7_API_KEY or update testsManual/context7-api-key.php.'
    );

    mcpManualSetConfig(mcpManualBaseConfig([
        'context7' => [
            'type' => Transporters::HTTP,
            'base_url' => 'https://mcp.context7.com/mcp',
            'timeout' => 60,
            'headers' => [
                'CONTEXT7_API_KEY' => $context7ApiKey,
            ],
        ],
    ]));

    $agent = mcpManualAgent('manual_mcp_context7', ['context7']);

    mcpManualPrintInfo('Discovering MCP tools through LarAgent::getTools().');
    $tools = $agent->getTools();

    mcpManualAssert($tools !== [], 'No tools were discovered from the Context7 MCP server.');
    mcpManualPrintInfo('Discovered '.count($tools).' tools:');
    mcpManualPrintToolList($tools);

    $resolverTool = mcpManualFindToolContainingAll($tools, ['resolve', 'library'])
        ?? mcpManualFindTool($tools, ['resolve-library-id', 'resolve_library_id']);
    $fallbackTool = $tools[0] ?? null;

    mcpManualAssert($resolverTool !== null || $fallbackTool !== null, 'No callable Context7 tool was found.');

    $toolToExecute = $resolverTool ?? $fallbackTool;
    $toolInput = mcpManualBuildInput($toolToExecute, [
        'library' => 'laravel',
        'libraryName' => 'laravel',
        'library_name' => 'laravel',
        'query' => 'laravel',
        'search' => 'laravel',
        'topic' => 'routing',
    ]);

    $toolResult = mcpManualExecuteTool($toolToExecute, $toolInput);
    mcpManualPrintResult('Verification result', $toolResult['decoded']);

    mcpManualAssert($toolResult['raw'] !== null, 'The Context7 verification tool returned a null result.');
    mcpManualPrintSuccess('Context7 MCP HTTP integration is working.');
    exit(0);
} catch (Throwable $e) {
    mcpManualPrintError($e->getMessage());
    exit(1);
}
