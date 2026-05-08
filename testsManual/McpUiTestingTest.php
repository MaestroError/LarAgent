<?php

/**
 * Manual Test: MCP ui-testing server over STDIO
 *
 * Run with: php testsManual/McpUiTestingTest.php
 */

require_once __DIR__.'/McpManualTestSupport.php';

use Redberry\MCPClient\Enums\Transporters;

mcpManualSetConfig(mcpManualBaseConfig([
    'ui-testing' => [
        'type' => Transporters::STDIO,
        'command' => ['npx', '-y', '@playwright/mcp@latest'],
        'cwd' => dirname(__DIR__),
        'request_timeout' => 90,
        'startup_delay' => 1500,
    ],
]));

mcpManualPrintHeader('Manual MCP Test: ui-testing (STDIO)');

try {
    $agent = mcpManualAgent('manual_mcp_ui_testing', ['ui-testing']);

    mcpManualPrintInfo('Discovering MCP tools through LarAgent::getTools().');
    $tools = $agent->getTools();

    mcpManualAssert($tools !== [], 'No tools were discovered from the ui-testing MCP server.');
    mcpManualPrintInfo('Discovered '.count($tools).' tools:');
    mcpManualPrintToolList($tools);

    $navigateTool = mcpManualFindToolContainingAll($tools, ['navigate'])
        ?? mcpManualFindTool($tools, ['browser_navigate']);
    $snapshotTool = mcpManualFindToolContainingAll($tools, ['snapshot'])
        ?? mcpManualFindTool($tools, ['browser_snapshot']);
    $fallbackTool = mcpManualFirstToolWithoutRequiredArgs($tools) ?? $tools[0];

    if ($navigateTool !== null) {
        $navigateInput = mcpManualBuildInput($navigateTool, [
            'url' => 'https://example.com',
        ]);
        $navigateResult = mcpManualExecuteTool($navigateTool, $navigateInput);
        mcpManualPrintResult('Navigate result', $navigateResult['decoded']);
        mcpManualPrintSuccess('Navigation tool executed successfully.');
    } else {
        mcpManualPrintInfo('No navigate tool found; skipping page navigation step.');
    }

    $toolToExecute = $snapshotTool ?? $fallbackTool;
    $toolInput = $toolToExecute === $snapshotTool
        ? []
        : mcpManualBuildInput($toolToExecute);

    $toolResult = mcpManualExecuteTool($toolToExecute, $toolInput);
    mcpManualPrintResult('Verification result', $toolResult['decoded']);

    mcpManualAssert($toolResult['raw'] !== null, 'The ui-testing verification tool returned a null result.');
    mcpManualPrintSuccess('ui-testing MCP STDIO integration is working.');
    exit(0);
} catch (Throwable $e) {
    mcpManualPrintError($e->getMessage());
    exit(1);
}
