<?php

use LarAgent\Context\Truncation\SimpleTruncationStrategy;
use LarAgent\Message;
use LarAgent\Messages\DataModels\MessageArray;

describe('SimpleTruncationStrategy', function () {
    it('keeps all messages when count is below keep_messages limit', function () {
        $strategy = new SimpleTruncationStrategy(['keep_messages' => 10]);
        
        $messages = new MessageArray();
        $messages->add(Message::user('Message 1'));
        $messages->add(Message::assistant('Response 1'));
        $messages->add(Message::user('Message 2'));
        
        $result = $strategy->truncate($messages, 100000, 50000);
        
        expect($result->count())->toBe(3);
    });

    it('removes old messages when exceeding keep_messages limit', function () {
        $strategy = new SimpleTruncationStrategy(['keep_messages' => 2]);
        
        $messages = new MessageArray();
        $messages->add(Message::user('Message 1'));
        $messages->add(Message::assistant('Response 1'));
        $messages->add(Message::user('Message 2'));
        $messages->add(Message::assistant('Response 2'));
        $messages->add(Message::user('Message 3'));
        
        $result = $strategy->truncate($messages, 100000, 50000);
        
        expect($result->count())->toBe(2);
        $lastMessages = $result->toArray();
        expect($lastMessages[0]->getContentAsString())->toBe('Response 2');
        expect($lastMessages[1]->getContentAsString())->toBe('Message 3');
    });

    it('preserves system messages regardless of keep_messages limit', function () {
        $strategy = new SimpleTruncationStrategy(['keep_messages' => 2, 'preserve_system' => true]);
        
        $messages = new MessageArray();
        $messages->add(Message::system('System instructions'));
        $messages->add(Message::user('Message 1'));
        $messages->add(Message::assistant('Response 1'));
        $messages->add(Message::user('Message 2'));
        $messages->add(Message::assistant('Response 2'));
        
        $result = $strategy->truncate($messages, 100000, 50000);
        
        $resultArray = $result->toArray();
        expect($result->count())->toBe(3);
        expect($resultArray[0]->getRole())->toBe('system');
        expect($resultArray[1]->getContentAsString())->toBe('Response 2');
        expect($resultArray[2]->getContentAsString())->toBe('Message 2');
    });

    it('preserves developer messages when configured', function () {
        $strategy = new SimpleTruncationStrategy(['keep_messages' => 2, 'preserve_system' => true]);
        
        $messages = new MessageArray();
        $messages->add(Message::system('System instructions'));
        $messages->add(Message::developer('Developer notes'));
        $messages->add(Message::user('Message 1'));
        $messages->add(Message::assistant('Response 1'));
        $messages->add(Message::user('Message 2'));
        
        $result = $strategy->truncate($messages, 100000, 50000);
        
        $resultArray = $result->toArray();
        expect($result->count())->toBe(4);
        expect($resultArray[0]->getRole())->toBe('system');
        expect($resultArray[1]->getRole())->toBe('developer');
        expect($resultArray[2]->getContentAsString())->toBe('Response 1');
        expect($resultArray[3]->getContentAsString())->toBe('Message 2');
    });

    it('does not preserve system messages when preserve_system is false', function () {
        $strategy = new SimpleTruncationStrategy(['keep_messages' => 2, 'preserve_system' => false]);
        
        $messages = new MessageArray();
        $messages->add(Message::system('System instructions'));
        $messages->add(Message::user('Message 1'));
        $messages->add(Message::assistant('Response 1'));
        $messages->add(Message::user('Message 2'));
        
        $result = $strategy->truncate($messages, 100000, 50000);
        
        expect($result->count())->toBe(2);
        $resultArray = $result->toArray();
        expect($resultArray[0]->getContentAsString())->toBe('Response 1');
        expect($resultArray[1]->getContentAsString())->toBe('Message 2');
    });
});
