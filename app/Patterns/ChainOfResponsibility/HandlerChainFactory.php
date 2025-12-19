<?php

namespace App\Patterns\ChainOfResponsibility;

use App\Patterns\ChainOfResponsibility\Interfaces\TransactionHandler;
use App\Patterns\ChainOfResponsibility\Handlers\AmountValidationHandler;
use App\Patterns\ChainOfResponsibility\Handlers\AccountStateHandler;
use App\Patterns\ChainOfResponsibility\Handlers\DailyLimitHandler;
use App\Patterns\ChainOfResponsibility\Handlers\FraudDetectionHandler;
use App\Patterns\ChainOfResponsibility\Handlers\ManagerApprovalHandler;
use Illuminate\Support\Facades\Log;

class HandlerChainFactory
{
    /**
     * Create and configure the handler chain.
     */
    public function createChain(): TransactionHandler
    {
        Log::debug('HandlerChainFactory: Creating transaction handler chain');

        // Create all handlers
        $handlers = [
            new AmountValidationHandler(),
            new AccountStateHandler(),
            new DailyLimitHandler(),
            new FraudDetectionHandler(),
            new ManagerApprovalHandler()
        ];

        // Sort handlers by priority (lower number = higher priority)
        usort($handlers, fn($a, $b) => $a->getPriority() <=> $b->getPriority());

        // Log the handler order
        $handlerNames = array_map(fn($handler) => $handler->getName(), $handlers);
        Log::debug('HandlerChainFactory: Handler chain order', [
            'order' => $handlerNames,
            'count' => count($handlers)
        ]);

        // Chain the handlers together
        $chain = null;
        $currentHandler = null;

        foreach ($handlers as $handler) {
            if ($chain === null) {
                $chain = $handler;
                $currentHandler = $handler;
            } else {
                $currentHandler = $currentHandler->setNext($handler);
            }
        }

        if ($chain === null) {
            throw new \RuntimeException('No handlers were created for the chain');
        }

        return $chain;
    }

    /**
     * Create a custom handler chain for specific use cases.
     */
    public function createCustomChain(array $handlerClasses): TransactionHandler
    {
        Log::debug('HandlerChainFactory: Creating custom handler chain', [
            'handler_classes' => $handlerClasses
        ]);

        $handlers = [];

        foreach ($handlerClasses as $class) {
            if (!class_exists($class)) {
                throw new \RuntimeException("Handler class not found: {$class}");
            }

            $handler = app($class);

            if (!$handler instanceof TransactionHandler) {
                throw new \RuntimeException("Class {$class} does not implement TransactionHandler interface");
            }

            $handlers[] = $handler;
        }

        // Sort by priority
        usort($handlers, fn($a, $b) => $a->getPriority() <=> $b->getPriority());

        // Chain them together
        $chain = null;
        $currentHandler = null;

        foreach ($handlers as $handler) {
            if ($chain === null) {
                $chain = $handler;
                $currentHandler = $handler;
            } else {
                $currentHandler = $currentHandler->setNext($handler);
            }
        }

        return $chain ?? throw new \RuntimeException('No handlers were created for the custom chain');
    }

    /**
     * Get handler statistics for monitoring.
     */
    public function getHandlerStats(): array
    {
        return [
            'total_handlers' => 5,
            'handler_names' => [
                'AmountValidationHandler',
                'AccountStateHandler',
                'DailyLimitHandler',
                'FraudDetectionHandler',
                'ManagerApprovalHandler'
            ],
            'priorities' => [
                'AmountValidationHandler' => 10,
                'AccountStateHandler' => 20,
                'DailyLimitHandler' => 30,
                'FraudDetectionHandler' => 40,
                'ManagerApprovalHandler' => 50
            ]
        ];
    }

    /**
     * Validate the handler chain configuration.
     */
    public function validateChain(): bool
    {
        try {
            $chain = $this->createChain();

            // Test the chain with a simple transaction
            $testTransaction = $this->createTestTransaction();
            $result = $chain->handle($testTransaction);

            Log::debug('HandlerChainFactory: Chain validation successful', [
                'test_result' => $result
            ]);

            return true;

        } catch (\Exception $e) {
            Log::error('HandlerChainFactory: Chain validation failed', [
                'error' => $e->getMessage(),
                'exception' => get_class($e)
            ]);
            return false;
        }
    }

    private function createTestTransaction(): \App\Models\Transaction
    {
        // Create a mock transaction for testing
        $transaction = new \App\Models\Transaction();
        $transaction->id = 999999; // Test ID
        $transaction->amount = 100.00;
        $transaction->currency = 'USD';
        $transaction->type = \App\Enums\TransactionType::TRANSFER;
        $transaction->status = \App\Enums\TransactionStatus::PENDING;
        $transaction->fee = 1.00;
        $transaction->initiated_by = 1; // Test user ID
        $transaction->from_account_id = 1; // Test account ID
        $transaction->to_account_id = 2; // Test account ID
        $transaction->ip_address = '127.0.0.1';

        return $transaction;
    }
}
