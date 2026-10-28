<?php

// Satu kunci lama dapat mewakili beberapa izin matriks. Array kosong berarti
// izin lama tidak memiliki padanan pada rancangan baru.
$map = [
    'dashboard.view' => ['dashboard.overview.view'],
    'settings.users.view' => ['access.users.view'],
    'settings.users.manage' => ['access.users.edit'],
    'settings.permissions.view' => ['access.users.view'],
    'settings.permissions.manage' => ['access.users.edit'],
    'setup.view' => ['setup.wizard.view'],
    'setup.edit' => ['setup.wizard.edit'],
    'setup.validate' => ['setup.wizard.edit'],
    'setup.finalize' => ['setup.wizard.approve'],
    'setup.reopen' => ['setup.wizard.reopen'],
    'journal.view' => ['accounting.journals.view'],
    'journal.create' => ['accounting.journals.create'],
    'journal.edit' => ['accounting.journals.edit'],
    'journal.void' => ['accounting.journals.delete'],
    'journal.approve' => ['accounting.journals.approve'],
    'journal.post' => ['accounting.journals.approve'],
    'cash_bank.transfer' => ['cash_bank.transfers.create'],
    'inventory.view' => ['inventory.stock.view', 'inventory.movements.view', 'inventory.adjustments.view', 'inventory.opname.view', 'inventory.valuation.view'],
    'inventory.manage' => ['inventory.movements.create', 'inventory.adjustments.create', 'inventory.adjustments.edit', 'inventory.opname.create', 'inventory.opname.edit'],
    'inventory.transfer' => ['inventory.movements.create'],
    'inventory.stock_opname' => ['inventory.opname.create', 'inventory.opname.edit', 'inventory.opname.approve'],
    'inventory.reports.view' => ['reports.inventory.view'],
    'inventory.integration.run' => [],
    'fixed_assets.view' => ['fixed_assets.assets.view'],
    'fixed_assets.create' => ['fixed_assets.assets.create'],
    'fixed_assets.edit' => ['fixed_assets.assets.edit'],
    'fixed_assets.deactivate' => ['fixed_assets.assets.delete'],
    'fixed_assets.capitalize' => ['fixed_assets.assets.approve'],
    'fixed_assets.depreciate' => ['fixed_assets.depreciation.approve'],
    'fixed_assets.dispose' => ['fixed_assets.disposal.approve'],
    'fixed_assets.opening_import' => ['fixed_assets.opening.import'],
    'fixed_assets.reports.view' => ['reports.fixed_assets.view'],
    'fixed_assets.settings.manage' => ['fixed_assets.settings.create', 'fixed_assets.settings.edit'],
    'reports.save' => ['reports.saved.manage'],
    'reports.multi_period' => ['reports.multi_period.use'],
    'fiscal_year.view' => ['accounting.fiscal_year.view'],
    'fiscal_year.close' => ['accounting.fiscal_year.approve'],
    'fiscal_year.reopen' => ['accounting.fiscal_year.reopen'],
    'fiscal_year.lock_override' => ['accounting.period_locks.override'],
    'fiscal_year.lock_manage' => ['accounting.period_locks.create', 'accounting.period_locks.edit'],
    'fiscal_year.closing_wizard' => ['accounting.fiscal_year.approve'],
    'period_end.view' => ['accounting.period_end.view'],
    'period_end.run' => ['accounting.period_end.approve'],
    'period_end.reopen' => ['accounting.period_end.reopen'],
    'opening_balance.view' => ['accounting.opening_balance.view'],
    'opening_balance.manage' => ['accounting.opening_balance.create', 'accounting.opening_balance.edit', 'accounting.opening_balance.delete'],
    'opening_balance.validate' => ['accounting.opening_balance.edit'],
    'opening_balance.post' => ['accounting.opening_balance.approve'],
    'opening_balance.lock' => ['accounting.opening_balance.approve'],
    'opening_balance.reopen' => ['accounting.opening_balance.reopen'],
    'imports.revert' => ['master_data.import.revert'],
    'masterdata.import' => ['master_data.import.execute'],
    'transactions.import' => ['accounting.journals.import', 'sales.invoices.import', 'purchase.bills.import'],
    'budgets.view' => ['budget.submissions.view'],
    'budgets.submit' => ['budget.submissions.create'],
    'budgets.approve_head' => ['budget.submissions.approve_head'],
    'budgets.approve_finance' => ['budget.submissions.approve_finance'],
    'budgets.manage' => ['budget.periods.manage'],
    'budgets.revise' => ['budget.submissions.edit'],
    'budgets.export' => ['budget.submissions.export'],
    'audit.view' => ['access.audit.view'],
    'access.users.invite' => ['access.invitations.create'],
    'access.users.deactivate' => ['access.users.delete'],
    'access.users.remove' => ['access.users.delete'],
    'access.users.manage' => ['access.users.edit'],
    'access.roles.view' => ['access.users.view'],
    'access.roles.create' => ['access.users.edit'],
    'access.roles.edit' => ['access.users.edit'],
    'access.roles.clone' => ['access.users.edit'],
    'access.roles.deactivate' => ['access.users.edit'],
    'access.roles.manage' => ['access.users.edit'],
    'access.permissions.view' => ['access.users.view'],
    'access.permissions.assign' => ['access.users.edit'],
    'access.permissions.revoke' => ['access.users.edit'],
    'access.permissions.manage' => ['access.users.edit'],
    'access.invitations.resend' => ['access.invitations.create'],
    'access.invitations.revoke' => ['access.invitations.delete'],
    'access.invitations.manage' => ['access.invitations.create', 'access.invitations.delete'],
];

$master = ['coa', 'contacts', 'products', 'units', 'warehouses', 'departments', 'projects', 'payment_terms'];
foreach ($master as $feature) {
    foreach (['view' => 'view', 'create' => 'create', 'edit' => 'edit', 'deactivate' => 'delete'] as $old => $new) {
        $map[$feature.'.'.$old] = ['master_data.'.$feature.'.'.$new];
    }
}
$map['master_data.view'] = array_map(fn ($feature) => 'master_data.'.$feature.'.view', $master);
$map['master_data.manage'] = array_merge(...array_map(fn ($feature) => [
    'master_data.'.$feature.'.create', 'master_data.'.$feature.'.edit', 'master_data.'.$feature.'.delete',
], $master));

foreach (['sales' => ['quotations', 'orders', 'delivery_orders', 'proformas', 'invoices', 'deposits', 'receipts', 'returns'],
    'purchase' => ['requests', 'orders', 'goods_receipts', 'bills', 'deposits', 'payments', 'returns']] as $module => $features) {
    foreach (['view' => 'view', 'create' => 'create', 'edit' => 'edit', 'void' => 'delete', 'approve' => 'approve', 'post' => 'approve'] as $old => $new) {
        $map[$module.'.'.$old] = array_map(fn ($feature) => $module.'.'.$feature.'.'.$new, $features);
    }
    foreach ($features as $feature) {
        foreach (['cancel' => 'delete', 'void' => 'delete', 'post' => 'approve', 'confirm' => 'approve',
            'ship' => 'approve', 'deliver' => 'approve', 'issue' => 'approve', 'receive' => 'approve'] as $old => $new) {
            $map[$module.'.'.$feature.'.'.$old] = [$module.'.'.$feature.'.'.$new];
        }
        $map[$module.'.'.$feature.'.convert'] = [$module.'.documents.convert'];
    }
}

foreach (['view' => 'view', 'create' => 'create', 'edit' => 'edit', 'void' => 'delete', 'approve' => 'approve', 'post' => 'approve'] as $old => $new) {
    $features = $old === 'void' ? ['receipts', 'payments', 'transfers'] : ['receipts', 'payments', 'transfers', 'reconciliations'];
    $map['cash_bank.'.$old] = array_map(fn ($feature) => 'cash_bank.'.$feature.'.'.$new, $features);
}
foreach (['create' => 'create', 'edit' => 'edit', 'void' => 'delete'] as $old => $new) {
    $map['inventory.adjustment.'.$old] = ['inventory.adjustments.'.$new];
}
foreach (['movements', 'adjustments', 'opname'] as $feature) {
    $map['inventory.'.$feature.'.post'] = ['inventory.'.$feature.'.approve'];
    $map['inventory.'.$feature.'.finalize'] = ['inventory.'.$feature.'.approve'];
    $map['inventory.'.$feature.'.void'] = ['inventory.'.$feature.'.delete'];
}

$reports = ['financial', 'general_ledger', 'sales', 'purchase', 'ar', 'ap', 'reconciliation', 'inventory', 'fixed_assets', 'cash_bank', 'tax', 'budget', 'project'];
foreach (['view', 'export'] as $action) {
    $map['reports.'.$action] = array_map(fn ($feature) => 'reports.'.$feature.'.'.$action, $reports);
}
foreach (['view', 'upload', 'map', 'commit', 'cancel', 'templates.view'] as $action) {
    $map['imports.'.$action] = ['master_data.import.execute', 'accounting.journals.import', 'sales.invoices.import', 'purchase.bills.import'];
}

return $map;
