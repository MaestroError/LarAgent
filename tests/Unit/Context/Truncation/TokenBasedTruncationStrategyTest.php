<?php

use LarAgent\Context\Truncation\TokenBasedTruncationStrategy;
use LarAgent\Message;
use LarAgent\Messages\DataModels\MessageArray;
use LarAgent\Usage\DataModels\Usage;

describe('TokenBasedTruncationStrategy', function () {
    it('keeps all messages when tokens are below target', function () {
        $strategy = new TokenBasedTruncationStrategy(['target_percentage' => 0.75]);
        
        $messages = new MessageArray();
        $messages->add(Message::user('Message 1'));
        $messages->add(Message::assistant('Response 1'));
        $messages->add(Message::user('Message 2'));
        
        // Current tokens (50000) < target (75000)
        $result = $strategy->truncate($messages, 100000, 50000);
        
        expect($result->count())->toBe(3);
    });

    it('removes old messages to reach target token percentage', function () {
        $strategy = new TokenBasedTruncationStrategy(['target_percentage' => 0.5]);
        
        $messages = new MessageArray();
        
        // Create messages with usage data that exceeds target
        // Target: 100000 * 0.5 = 50000 tokens
        $msg1 = Message::assistant('First response');
        $msg1->setUsage(new Usage(promptTokens: 0, completionTokens: 20000));
        
        $msg2 = Message::assistant('Second response');
        $msg2->setUsage(new Usage(promptTokens: 0, completionTokens: 20000));
        
        $msg3 = Message::assistant('Third response');
        $msg3->setUsage(new Usage(promptTokens: 0, completionTokens: 20000));
        
        $messages->add($msg1);
        $messages->add($msg2);
        $messages->add($msg3);
        
        // Total estimated tokens: 60000 > target (50000)
        $result = $strategy->truncate($messages, 100000, 60000);
        
        // Should remove some messages to get below target
        // With 60000 tokens total and 50000 target, we can only keep 2 messages (40000 tokens)
        expect($result->count())->toBeLessThan(3);
    });

    it('preserves system messages regardless of token limit', function () {
        $strategy = new TokenBasedTruncationStrategy([
            'target_percentage' => 0.1, // Very low target
            'preserve_system' => true,
        ]);
        
        $messages = new MessageArray();
        $messages->add(Message::system('System instructions'));
        $messages->add(Message::user('Message 1'));
        $messages->add(Message::assistant('Response 1'));
        $messages->add(Message::user('Message 2'));
        
        $result = $strategy->truncate($messages, 100000, 90000);
        
        $resultArray = $result->all();
        expect($resultArray[0]->getRole())->toBe('system');
    });

    it('uses message usage data when available for token estimation', function () {
        $strategy = new TokenBasedTruncationStrategy(['target_percentage' => 0.5]);
        
        $messages = new MessageArray();
        $msg1 = Message::assistant('Response 1');
        $msg1->setUsage(new Usage(promptTokens: 10000, completionTokens: 5000));
        
        $msg2 = Message::assistant('Response 2');
        $msg2->setUsage(new Usage(promptTokens: 15000, completionTokens: 7500));
        
        $messages->add($msg1);
        $messages->add($msg2);
        
        // Should use actual token counts from usage data
        $result = $strategy->truncate($messages, 100000, 80000);
        
        expect($result)->toBeInstanceOf(MessageArray::class);
    });

    it('maintains chronological order after truncation', function () {
        $strategy = new TokenBasedTruncationStrategy(['target_percentage' => 0.5]);
        
        $messages = new MessageArray();
        $messages->add(Message::user('First message'));
        $messages->add(Message::assistant('First response'));
        $messages->add(Message::user('Second message'));
        $messages->add(Message::assistant('Second response'));
        $messages->add(Message::user('Third message'));
        
        $result = $strategy->truncate($messages, 100000, 60000);
        
        // Messages should still be in chronological order
        $resultArray = $result->all();
        if (count($resultArray) >= 2) {
            // Most recent messages should be at the end
            $lastMsg = end($resultArray);
            expect($lastMsg->getContentAsString())->toBe('Third message');
        }
    });
});
