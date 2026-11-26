<?php

namespace App\Modules\Workspace\Support;

final class BudgetPermission
{
    public const VIEW = 'workspace.budget.view';

    public const SUBMIT = 'workspace.budget.submit';

    public const APPROVE_ANNUAL = 'workspace.budget.approve_annual';

    public const APPROVE_ACTIVITY = 'workspace.budget.approve_activity';

    public const MANAGE_PERIODS = 'workspace.budget.manage_periods';

    public const MANAGE_ACCESS = 'workspace.budget.manage_access';

    public const GRANTABLE = [self::VIEW, self::SUBMIT, self::APPROVE_ANNUAL, self::APPROVE_ACTIVITY, self::MANAGE_PERIODS];

    public const DOCUMENT_ACCESS = self::VIEW.'|'.self::SUBMIT.'|'.self::APPROVE_ANNUAL.'|'.self::APPROVE_ACTIVITY;

    public const APPROVAL = self::APPROVE_ANNUAL.'|'.self::APPROVE_ACTIVITY;
}
