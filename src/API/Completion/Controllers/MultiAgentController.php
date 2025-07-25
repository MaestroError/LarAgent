<?php

namespace LarAgent\API\Completion\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use LarAgent\API\Completion\Traits\HasSessionId;
use LarAgent\API\Completions;

abstract class MultiAgentController
{
    use HasSessionId;

    protected ?array $agents = null;

    protected ?array $models = null;

    public function completion(Request $request)
    {
        $request->validate([
            'model' => ['required', 'string'],
        ]);

        $sessionId = $this->setSessionId();

        $model = $request->model;

        try {
            // Check the agent and model
            if (! str_contains($model, '/')) {
                $agentClass = $this->getAgent($model);
                $model = '';
            } else {
                // Separate agent from model
                $result = explode('/', $model, 2);
                $agent = $result[0];

                // Get agent class
                $agentClass = $this->getAgent($agent);
                $model = $result[1];
            }

            $response = Completions::make($request, $agentClass, $model, $sessionId);

            if ($response instanceof \Generator) {
                // Return SSE
                return response()->stream(function () use ($response) {
                    foreach ($response as $chunk) {
                        echo "event: chunk\n";
                        echo 'data: '.json_encode($chunk)."\n\n";
                        ob_flush();
                        flush();
                    }
                }, 200, [
                    'Content-Type' => 'text/event-stream',
                    'Cache-Control' => 'no-cache',
                    'X-Accel-Buffering' => 'no',
                ]);
            } else {
                return response()->json($response);
            }
        } catch (\Throwable $th) {
            Log::error($th);

            return response()->json([
                'error' => $th->getMessage(),
            ], 500);
        }

    }

    public function models()
    {
        $appName = config('laragent.app_name');
        foreach ($this->models as $model) {
            $models[] = [
                'id' => $model,
                'object' => 'model',
                'created' => 1753357877,
                'owned_by' => $appName,
            ];
        }

        return response()->json([
            'object' => 'list',
            'data' => $models,
        ]);
    }

    private function getAgent(string $agent)
    {
        foreach ($this->agents as $agentClass) {
            if (basename($agentClass) === $agent) {
                return $agentClass;
            }
        }
        throw new InvalidArgumentException('Invalid model name, expected format: agentName/model or AgentName');
    }
}
