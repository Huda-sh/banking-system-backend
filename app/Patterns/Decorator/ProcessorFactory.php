<?php

namespace App\Patterns\Decorator;

use App\Patterns\Decorator\Interfaces\TransactionProcessor;
use App\Patterns\Decorator\Processors\BaseTransactionProcessor;
use App\Patterns\Decorator\Processors\FraudDetectionDecorator;
use App\Patterns\Decorator\Processors\AuditLoggingDecorator;
use App\Patterns\Decorator\Processors\NotificationDecorator;
use App\Exceptions\ProcessorException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ProcessorFactory
{
    /**
     * Default processor chain configuration.
     */
    const DEFAULT_PROCESSOR_CHAIN = [
        'fraud_detection' => FraudDetectionDecorator::class,
        'audit_logging' => AuditLoggingDecorator::class,
        'notifications' => NotificationDecorator::class
    ];

    /**
     * Create and configure the transaction processor chain.
     */
    public function createProcessorChain(): TransactionProcessor
    {
        try {
            Log::debug('ProcessorFactory: Creating transaction processor chain');

            // Start with the base processor
            $processor = app(BaseTransactionProcessor::class);

            Log::debug('ProcessorFactory: Base processor created', [
                'processor' => get_class($processor)
            ]);

            // Wrap with decorators based on configuration
            $processorChain = $this->getProcessorChainConfiguration();

            foreach ($processorChain as $decoratorClass) {
                if (class_exists($decoratorClass)) {
                    $processor = app($decoratorClass, ['processor' => $processor]);
                    Log::debug('ProcessorFactory: Decorator added to chain', [
                        'decorator' => $decoratorClass,
                        'current_processor' => get_class($processor)
                    ]);
                } else {
                    Log::warning('ProcessorFactory: Decorator class not found', [
                        'class' => $decoratorClass
                    ]);
                }
            }

            Log::info('ProcessorFactory: Processor chain created successfully', [
                'chain_size' => count($processorChain) + 1,
                'base_processor' => BaseTransactionProcessor::class,
                'decorators' => array_values($processorChain)
            ]);

            return $processor;

        } catch (\Exception $e) {
            Log::error('ProcessorFactory: Failed to create processor chain', [
                'error' => $e->getMessage(),
                'exception' => get_class($e),
                'trace' => $e->getTraceAsString()
            ]);
            throw new ProcessorException("Failed to create processor chain: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Get processor chain configuration from config or use default.
     */
    private function getProcessorChainConfiguration(): array
    {
        $configChain = config('banking.processor_chain', []);

        if (!empty($configChain)) {
            Log::debug('ProcessorFactory: Using configured processor chain', [
                'chain' => $configChain
            ]);
            return $configChain;
        }

        Log::debug('ProcessorFactory: Using default processor chain', [
            'chain' => self::DEFAULT_PROCESSOR_CHAIN
        ]);

        return self::DEFAULT_PROCESSOR_CHAIN;
    }

    /**
     * Create a custom processor chain for specific use cases.
     */
    public function createCustomProcessorChain(array $decoratorClasses): TransactionProcessor
    {
        try {
            Log::debug('ProcessorFactory: Creating custom processor chain', [
                'decorators' => $decoratorClasses
            ]);

            $processor = app(BaseTransactionProcessor::class);

            foreach ($decoratorClasses as $decoratorClass) {
                if (!class_exists($decoratorClass)) {
                    throw new ProcessorException("Decorator class not found: {$decoratorClass}");
                }

                $processor = app($decoratorClass, ['processor' => $processor]);
            }

            Log::info('ProcessorFactory: Custom processor chain created successfully', [
                'chain_size' => count($decoratorClasses) + 1,
                'decorators' => $decoratorClasses
            ]);

            return $processor;

        } catch (\Exception $e) {
            Log::error('ProcessorFactory: Failed to create custom processor chain', [
                'error' => $e->getMessage(),
                'exception' => get_class($e)
            ]);
            throw new ProcessorException("Failed to create custom processor chain: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Get processor chain statistics and metadata.
     */
    public function getProcessorChainStats(): array
    {
        $chain = $this->getProcessorChainConfiguration();
        $processor = $this->createProcessorChain();

        $stats = [
            'total_processors' => count($chain) + 1,
            'base_processor' => BaseTransactionProcessor::class,
            'decorators' => array_values($chain),
            'enabled_decorators' => [],
            'processor_metadata' => []
        ];

        // Get metadata from each processor in the chain
        $currentProcessor = $processor;
        $processorIndex = 0;

        while ($currentProcessor) {
            $metadata = $currentProcessor->getMetadata();
            $stats['processor_metadata'][] = [
                'index' => $processorIndex,
                'name' => $currentProcessor->getName(),
                'enabled' => $currentProcessor->isEnabled(),
                'metadata' => $metadata
            ];

            if ($currentProcessor->isEnabled()) {
                $stats['enabled_decorators'][] = $currentProcessor->getName();
            }

            $processorIndex++;
            $currentProcessor = $this->getNextProcessor($currentProcessor);
        }

        return $stats;
    }

    /**
     * Get the next processor in the chain (reflection-based).
     */
    private function getNextProcessor(TransactionProcessor $processor): ?TransactionProcessor
    {
        try {
            $reflection = new \ReflectionClass($processor);
            $property = $reflection->getProperty('processor');
            $property->setAccessible(true);
            return $property->getValue($processor);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Validate processor chain configuration.
     */
    public function validateProcessorChain(): bool
    {
        try {
            Log::debug('ProcessorFactory: Validating processor chain configuration');

            $chain = $this->getProcessorChainConfiguration();

            foreach ($chain as $decoratorClass) {
                if (!class_exists($decoratorClass)) {
                    Log::error('ProcessorFactory: Validation failed - class not found', [
                        'class' => $decoratorClass
                    ]);
                    return false;
                }

                $decorator = app($decoratorClass, ['processor' => app(BaseTransactionProcessor::class)]);
                if (!$decorator instanceof TransactionProcessor) {
                    Log::error('ProcessorFactory: Validation failed - invalid interface', [
                        'class' => $decoratorClass
                    ]);
                    return false;
                }
            }

            Log::info('ProcessorFactory: Processor chain validation successful');
            return true;

        } catch (\Exception $e) {
            Log::error('ProcessorFactory: Processor chain validation failed', [
                'error' => $e->getMessage(),
                'exception' => get_class($e)
            ]);
            return false;
        }
    }

    /**
     * Get available processor types.
     */
    public function getAvailableProcessorTypes(): array
    {
        return [
            'base' => BaseTransactionProcessor::class,
            'decorators' => [
                'fraud_detection' => FraudDetectionDecorator::class,
                'audit_logging' => AuditLoggingDecorator::class,
                'notifications' => NotificationDecorator::class
            ]
        ];
    }

    /**
     * Create a minimal processor chain for testing.
     */
    public function createTestProcessorChain(): TransactionProcessor
    {
        Log::debug('ProcessorFactory: Creating test processor chain');

        $baseProcessor = app(BaseTransactionProcessor::class);
        $baseProcessor->enabled = false; // Disable real processing for tests

        return $baseProcessor;
    }
}
