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
}
