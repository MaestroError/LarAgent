<?php

require_once __DIR__.'/vendor/autoload.php';

use LarAgent\Attributes\Tool;

function config(string $key): mixed
{
    $yourApiKey = include 'openai-api-key.php';

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
    ][$key];
}

class PropertyTestAgent extends LarAgent\Agent
{
    protected $provider = 'default';

    protected $model = 'gpt-4o-mini';

    protected $history = 'in_memory';

    public function instructions()
    {
        return "You are an AI assistant demonstrating agent property capabilities. 
                Keep responses short and focused on the specific question asked.";
    }
}

// Test with n parameter (multiple completions)
echo "Testing n parameter (multiple completions):\n";
$response = PropertyTestAgent::for('test_properties_n')
    ->n(2) // Request 2 alternative completions
    ->respond('Provide a short greeting in under 10 words.');
print_r($response);
echo "\n---\n";

// // Test with topP parameter (nucleus sampling)
// echo "Testing topP parameter (nucleus sampling):\n";
// echo PropertyTestAgent::for('test_properties_topp')
//     ->topP(0.5) // Lower value = more focused/deterministic output
//     ->respond('Give me a quick fact about AI.');
// echo "\n---\n";

// // Test with frequencyPenalty parameter
// echo "Testing frequencyPenalty parameter (control repetition):\n";
// echo PropertyTestAgent::for('test_properties_freq')
//     ->frequencyPenalty(1.5) // Higher value = strongly discourage repetition
//     ->respond('List 3 types of machine learning.');
// echo "\n---\n";

// // Test with presencePenalty parameter
// echo "Testing presencePenalty parameter (topic diversity):\n";
// echo PropertyTestAgent::for('test_properties_presence')
//     ->presencePenalty(1.5) // Higher value = strongly encourages new topics
//     ->respond('Name a few programming languages.');
// echo "\n---\n";

// // Test multiple properties together
// echo "Testing multiple properties together:\n";
// echo PropertyTestAgent::for('test_properties_combined')
//     ->topP(0.7)
//     ->frequencyPenalty(0.8)
//     ->presencePenalty(0.8)
//     ->respond('Tell me about computer vision.');
// echo "\n---\n";

// Test with extreme values to see the differences
echo "Testing with extreme values:\n";
$response = PropertyTestAgent::for('test_properties_extreme')
    ->n(3) // Request 3 alternative completions
    ->topP(0.1) // Very focused sampling
    ->frequencyPenalty(2.0) // Strongly avoid repetition
    ->respond('Suggest some names for a tech startup.');
print_r($response);
echo "\n---\n";

// Test different completion values
echo "Testing different n values:\n";
$response = PropertyTestAgent::for('test_properties_n_values')
    ->n(3) // Request 3 alternative completions
    ->respond('Give me a short motivational quote.');
print_r($response);
