# Changelog

All notable changes to `LarAgent` will be documented in this file.

## [Unreleased]

### ⚠️ Breaking Changes (v0.7 → v0.8)

#### Tool Execution Events Now Include ToolCall Object

**What Changed:**
- `BeforeToolExecution` and `AfterToolExecution` events now include the `ToolCall` object
- Hook callbacks for `beforeToolExecution()` and `afterToolExecution()` receive additional parameters

**Migration Required:**

**Event Listeners:**
```php
// Before (v0.7)
Event::listen(BeforeToolExecution::class, function ($event) {
    // $event->tool available
    // $event->toolCall NOT available
});

// After (v0.8)
Event::listen(BeforeToolExecution::class, function ($event) {
    // $event->tool available
    // $event->toolCall NOW available - contains ID, name, arguments
    Log::info('Tool call', [
        'id' => $event->toolCall->getId(),
        'tool' => $event->toolCall->getToolName(),
        'args' => json_decode($event->toolCall->getArguments(), true),
    ]);
});
```

**Hook Callbacks:**
```php
// Before (v0.7)
$agent->beforeToolExecution(function($agent, $tool) {
    // 2 parameters
});

$agent->afterToolExecution(function($agent, $tool, &$result) {
    // 3 parameters
});

// After (v0.8)
$agent->beforeToolExecution(function($agent, $tool, $toolCall) {
    // 3 parameters - $toolCall added
    logger()->info("Executing: {$toolCall->getToolName()}", [
        'call_id' => $toolCall->getId(),
    ]);
});

$agent->afterToolExecution(function($agent, $tool, $toolCall, &$result) {
    // 4 parameters - $toolCall added
    logger()->info("Completed: {$toolCall->getToolName()}", [
        'call_id' => $toolCall->getId(),
        'result' => $result,
    ]);
});
```

**Benefits:**
- Full tool call tracing with unique IDs
- Access to exact arguments passed to each tool
- Better correlation between tool calls and results
- Enhanced debugging and audit capabilities

### Added
- ToolCall object now passed to `BeforeToolExecution` event
- ToolCall object now passed to `AfterToolExecution` event
- ToolCall parameter added to tool execution hook callbacks
- New tests for ToolCall presence in events

### Changed
- `BeforeToolExecution` event constructor signature updated
- `AfterToolExecution` event constructor signature updated
- `processBeforeToolExecution()` method signature in Hooks trait updated
- `processAfterToolExecution()` method signature in Hooks trait updated
- Hook callbacks now receive ToolCall parameter for better logging capabilities
