<?php

namespace App\Patterns\ChainOfResponsibility\Handlers;

use App\Models\Transaction;
use App\Models\User;
use App\Enums\ApprovalLevel;
use App\Exceptions\ApprovalRequiredException;
use App\Patterns\ChainOfResponsibility\Interfaces\TransactionHandler;
use Illuminate\Support\Facades\Log;

class ManagerApprovalHandler implements TransactionHandler
{
    /**
     * Amount thresholds requiring different approval levels.
     */
    const APPROVAL_THRESHOLDS = [
        'teller' => 10000.00,    // $10K - Teller can approve
        'manager' => 50000.00,   // $50K - Manager approval required
        'admin' => 100000.00,    // $100K - Admin approval required
        'executive' => 500000.00 // $500K - Executive approval required
    ];

    private ?TransactionHandler $next = null;

    public function setNext(TransactionHandler $handler): TransactionHandler
    {
        $this->next = $handler;
        return $handler;
    }

    public function handle(Transaction $transaction): bool
    {
        Log::debug('ManagerApprovalHandler: Checking if manager approval is required', [
            'transaction_id' => $transaction->id,
            'amount' => $transaction->amount,
            'type' => $transaction->type->value
        ]);

        try {
            $approvalRequired = $this->requiresManagerApproval($transaction);

            if ($approvalRequired) {
                $requiredLevel = $this->getRequiredApprovalLevel($transaction);

                Log::info('ManagerApprovalHandler: Manager approval required', [
                    'transaction_id' => $transaction->id,
                    'amount' => $transaction->amount,
                    'required_level' => $requiredLevel->value,
                    'required_level_label' => $requiredLevel->getLabel()
                ]);

                throw new ApprovalRequiredException(
                    "Transaction requires {$requiredLevel->getLabel()} approval",
                    $requiredLevel
                );
            }

            Log::debug('ManagerApprovalHandler: No manager approval required', [
                'transaction_id' => $transaction->id
            ]);

            return $this->next ? $this->next->handle($transaction) : true;

        } catch (ApprovalRequiredException $e) {
            // Re-throw the exception as it's expected behavior
            throw $e;
        } catch (\Exception $e) {
            Log::error('ManagerApprovalHandler: Unexpected error', [
                'transaction_id' => $transaction->id,
                'error' => $e->getMessage(),
                'exception' => get_class($e)
            ]);
            throw $e;
        }
    }

    private function requiresManagerApproval(Transaction $transaction): bool
    {
        // Always require approval for certain transaction types
        $highRiskTypes = [
            TransactionType::INTERNATIONAL_TRANSFER,
            TransactionType::WIRE_TRANSFER,
            TransactionType::LARGE_CASH_WITHDRAWAL
        ];

        if (in_array($transaction->type, $highRiskTypes)) {
            return true;
        }

        // Check amount thresholds
        return $this->exceedsApprovalThreshold($transaction);
    }

    private function exceedsApprovalThreshold(Transaction $transaction): bool
    {
        return $transaction->amount > self::APPROVAL_THRESHOLDS['teller'];
    }

    private function getRequiredApprovalLevel(Transaction $transaction): ApprovalLevel
    {
        $amount = $transaction->amount;

        // Check for special transaction types that require higher approval
        if (in_array($transaction->type, [
            TransactionType::INTERNATIONAL_TRANSFER,
            TransactionType::WIRE_TRANSFER
        ])) {
            // These types always require at least manager approval
            if ($amount > self::APPROVAL_THRESHOLDS['admin']) {
                return ApprovalLevel::ADMIN;
            }
            return ApprovalLevel::MANAGER;
        }

        // Determine level based on amount
        if ($amount > self::APPROVAL_THRESHOLDS['executive']) {
            return ApprovalLevel::EXECUTIVE;
        }

        if ($amount > self::APPROVAL_THRESHOLDS['admin']) {
            return ApprovalLevel::ADMIN;
        }

        if ($amount > self::APPROVAL_THRESHOLDS['manager']) {
            return ApprovalLevel::MANAGER;
        }

        return ApprovalLevel::TELLER;
    }

    public function getName(): string
    {
        return 'ManagerApprovalHandler';
    }

    public function getPriority(): int
    {
        return 50; // Medium priority - after fraud detection
    }
}
