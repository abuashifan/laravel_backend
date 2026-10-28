<?php

// Permission lama yang tidak dipakai lagi oleh rute, layanan, atau UI saat ini.
// Kunci yang masih dipakai oleh pemeriksaan aplikasi ditangani pada Fase 5–6.
return [
    'access.invitations.manage', 'access.permissions.assign', 'access.permissions.revoke',
    'access.roles.manage', 'access.users.invite', 'audit.view',
    'fiscal_year.lock_override', 'fixed_assets.deactivate',
    'inventory.adjustment.create', 'inventory.adjustment.edit', 'inventory.adjustment.void',
    'inventory.integration.run', 'inventory.stock_opname', 'inventory.transfer',
    'master_data.manage', 'opening_balance.lock', 'opening_balance.validate',
    'purchase.requests.convert', 'reports.export', 'sales.quotations.convert',
    'settings.permissions.manage', 'settings.permissions.view',
    'settings.users.manage', 'settings.users.view',
];
