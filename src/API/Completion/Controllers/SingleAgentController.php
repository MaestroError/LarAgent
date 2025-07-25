<?php

namespace LarAgent\API\Completion\Controllers;

use Illuminate\Http\Request;
use LarAgent\API\Completions;
use Illuminate\Support\Facades\Log;
use LarAgent\API\Completion\Traits\HasSessionId;
use InvalidArgumentException;

abstract class SingleAgentController
{
    use HasSessionId;

    protected ?string $agentClass = null;

    protected ?array $models = null;

    public function completion(Request $request)
    {
        $request->validate([
            'model' => ['required', 'string'],
        ]);

        $sessionId = $this->setSessionId();

        try {
            
            // Check the model name
            if(!in_array($request->model, $this->models)) {
                throw new InvalidArgumentException('Invalid model name');
            }

            $response = Completions::make($request, $this->agentClass, null, $sessionId);
    
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
                "id" => $model,
                "object" => "model",
                "created" => 1753357877,
                "owned_by" => $appName
            ];
        }
        return response()->json([
            "object" => "list",
            "data" => $models
        ]);
    }
}
