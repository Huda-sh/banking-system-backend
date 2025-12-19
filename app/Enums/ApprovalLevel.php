<?php

namespace App\Enums;

enum ApprovalLevel: string
{
    case TELLER = 'teller';
    case MANAGER = 'manager';
    case ADMIN = 'admin';
    case RISK_MANAGER = 'risk_manager';
    case COMPLIANCE_OFFICER = 'compliance_officer';
    case SENIOR_MANAGER = 'senior_manager';
    case EXECUTIVE = 'executive';

    /**
     * Get the displayable label for the approval level.
     */
    public function getLabel(): string
    {
        return match($this) {
            self::TELLER => 'Teller',
            self::MANAGER => 'Manager',
            self::ADMIN => 'Administrator',
            self::RISK_MANAGER => 'Risk Manager',
            self::COMPLIANCE_OFFICER => 'Compliance Officer',
            self::SENIOR_MANAGER => 'Senior Manager',
            self::EXECUTIVE => 'Executive',
        };
    }

    /**
     * Get the description for the approval level.
     */
    public function getDescription(): string
    {
        return match($this) {
            self::TELLER => 'Front-line staff with basic transaction approval authority',
            self::MANAGER => 'Branch manager with medium-level approval authority',
            self::ADMIN => 'System administrator with high-level approval authority',
            self::RISK_MANAGER => 'Risk management specialist for high-risk transactions',
            self::COMPLIANCE_OFFICER => 'Compliance specialist for regulatory approval',
            self::SENIOR_MANAGER => 'Senior management with executive-level approval authority',
            self::EXECUTIVE => 'C-level executive with highest approval authority',
        };
    }

    /**
     * Get the hierarchy level (1 = lowest, 7 = highest).
     */
    public function getHierarchyLevel(): int
    {
        return match($this) {
            self::TELLER => 1,
            self::MANAGER => 2,
            self::ADMIN => 3,
            self::COMPLIANCE_OFFICER => 4,
            self::RISK_MANAGER => 5,
            self::SENIOR_MANAGER => 6,
            self::EXECUTIVE => 7,
        };
    }

    /**
     * Get the maximum transaction amount this level can approve.
     */
    public function getMaxApprovalAmount(string $currency = 'USD'): float
    {
        return match($this) {
            self::TELLER => 5000.00,
            self::MANAGER => 25000.00,
            self::ADMIN => 100000.00,
            self::COMPLIANCE_OFFICER => 250000.00,
            self::RISK_MANAGER => 500000.00,
            self::SENIOR_MANAGER => 1000000.00,
            self::EXECUTIVE => 5000000.00,
        };
    }

    /**
     * Get the minimum transaction amount that requires this approval level.
     */
    public function getMinAmountForLevel(string $currency = 'USD'): float
    {
        return match($this) {
            self::TELLER => 0.01,
            self::MANAGER => 5000.01,
            self::ADMIN => 25000.01,
            self::COMPLIANCE_OFFICER => 100000.01,
            self::RISK_MANAGER => 250000.01,
            self::SENIOR_MANAGER => 500000.01,
            self::EXECUTIVE => 1000000.01,
        };
    }

    /**
     * Check if this level can approve a transaction of given amount.
     */
    public function canApproveAmount(float $amount, string $currency = 'USD'): bool
    {
        return $amount <= $this->getMaxApprovalAmount($currency);
    }

    /**
     * Get the required roles for this approval level.
     */
    public function getRequiredRoles(): array
    {
        return match($this) {
            self::TELLER => ['teller', 'cashier'],
            self::MANAGER => ['manager', 'branch_manager'],
            self::ADMIN => ['admin', 'system_administrator'],
            self::COMPLIANCE_OFFICER => ['compliance', 'compliance_officer'],
            self::RISK_MANAGER => ['risk', 'risk_manager'],
            self::SENIOR_MANAGER => ['senior_manager', 'director'],
            self::EXECUTIVE => ['executive', 'ceo', 'cfo'],
        };
    }

    /**
     * Check if a user has the required role for this approval level.
     */
    public function userHasRequiredRole($user): bool
    {
        $requiredRoles = $this->getRequiredRoles();
        return $user->hasAnyRole($requiredRoles);
    }

    /**
     * Get the escalation path for this approval level.
     */
    public function getEscalationPath(): array
    {
        return match($this) {
            self::TELLER => [self::MANAGER, self::ADMIN],
            self::MANAGER => [self::ADMIN, self::SENIOR_MANAGER],
            self::ADMIN => [self::SENIOR_MANAGER, self::EXECUTIVE],
            self::COMPLIANCE_OFFICER => [self::RISK_MANAGER, self::SENIOR_MANAGER],
            self::RISK_MANAGER => [self::SENIOR_MANAGER, self::EXECUTIVE],
            self::SENIOR_MANAGER => [self::EXECUTIVE],
            self::EXECUTIVE => [],
        };
    }

    /**
     * Get approval levels that can override this level.
     */
    public function getOverridingLevels(): array
    {
        $currentLevel = $this->getHierarchyLevel();
        return array_filter(self::cases(), fn($level) => $level->getHierarchyLevel() > $currentLevel);
    }

    /**
     * Determine the required approval level for a transaction amount.
     */
    public static function determineRequiredLevel(float $amount, string $currency = 'USD'): self
    {
        foreach (self::cases() as $level) {
            if ($amount >= $level->getMinAmountForLevel($currency) &&
                $amount <= $level->getMaxApprovalAmount($currency)) {
                return $level;
            }
        }

        // If amount exceeds all levels, return the highest level
        return self::EXECUTIVE;
    }

    /**
     * Get all approval levels as an array.
     */
    public static function toArray(): array
    {
        return array_map(fn($case) => [
            'value' => $case->value,
            'label' => $case->getLabel(),
            'description' => $case->getDescription(),
            'hierarchy_level' => $case->getHierarchyLevel(),
            'max_amount' => $case->getMaxApprovalAmount(),
            'required_roles' => $case->getRequiredRoles()
        ], self::cases());
    }

    /**
     * Get approval level by value.
     */
    public static function fromValue(string $value): ?self
    {
        foreach (self::cases() as $case) {
            if ($case->value === $value) {
                return $case;
            }
        }
        return null;
    }

    /**
     * Get approval levels sorted by hierarchy.
     */
    public static function sortByHierarchy(): array
    {
        $levels = self::cases();
        usort($levels, fn($a, $b) => $a->getHierarchyLevel() <=> $b->getHierarchyLevel());
        return $levels;
    }

    /**
     * Get the minimum approval level required for high-risk transactions.
     */
    public static function getHighRiskMinimumLevel(): self
    {
        return self::RISK_MANAGER;
    }

    /**
     * Get approval levels that require dual authorization.
     */
    public static function dualAuthorizationLevels(): array
    {
        return [self::RISK_MANAGER->value, self::SENIOR_MANAGER->value, self::EXECUTIVE->value];
    }
}
