# Backend Directory Tree

Generated: 2026-06-16

Purpose: entry-point directory map for backend agents. Read this before broad filesystem searches.

Command:

```bash
tree -a -d -I '.git|vendor|node_modules|storage|bootstrap/cache|.phpunit.cache|coverage|dist|build|.idea|.vscode' -L 6 .
```

Excluded intentionally: `.git`, `vendor`, `node_modules`, `storage`, `bootstrap/cache`, test/build/cache/editor folders.

```text
.
├── app
│   ├── Console
│   │   └── Commands
│   ├── Contracts
│   │   └── Transactions
│   ├── Data
│   │   └── Reports
│   ├── Enums
│   ├── Exceptions
│   ├── Http
│   │   ├── Controllers
│   │   │   └── Api
│   │   │       ├── Access
│   │   │       ├── Accounting
│   │   │       ├── Auth
│   │   │       ├── CashBank
│   │   │       ├── Companies
│   │   │       ├── FixedAssets
│   │   │       ├── Inventory
│   │   │       ├── Journal
│   │   │       ├── MasterData
│   │   │       ├── OpeningBalance
│   │   │       ├── Purchase
│   │   │       ├── Reports
│   │   │       ├── Sales
│   │   │       ├── Settings
│   │   │       ├── Setup
│   │   │       ├── Tenant
│   │   │       └── Transactions
│   │   ├── Middleware
│   │   └── Requests
│   │       ├── Access
│   │       ├── Accounting
│   │       ├── Auth
│   │       ├── CashBank
│   │       ├── Concerns
│   │       ├── FixedAssets
│   │       ├── Inventory
│   │       ├── Journal
│   │       ├── MasterData
│   │       ├── OpeningBalance
│   │       ├── Purchase
│   │       ├── Reports
│   │       ├── Sales
│   │       ├── Settings
│   │       └── Setup
│   ├── Models
│   │   └── Tenant
│   ├── Modules
│   │   ├── Access
│   │   │   ├── Providers
│   │   │   └── Routes
│   │   ├── Accounting
│   │   │   ├── Providers
│   │   │   └── Routes
│   │   ├── Auth
│   │   │   ├── Providers
│   │   │   └── Routes
│   │   ├── CashBank
│   │   │   ├── Providers
│   │   │   └── Routes
│   │   ├── Companies
│   │   │   ├── Providers
│   │   │   └── Routes
│   │   ├── FixedAssets
│   │   │   └── Routes
│   │   ├── Inventory
│   │   │   ├── Providers
│   │   │   └── Routes
│   │   ├── Journal
│   │   │   ├── Providers
│   │   │   └── Routes
│   │   ├── MasterData
│   │   │   ├── Providers
│   │   │   └── Routes
│   │   ├── OpeningBalance
│   │   │   └── Routes
│   │   ├── Purchase
│   │   │   ├── Providers
│   │   │   └── Routes
│   │   ├── Reports
│   │   │   ├── Providers
│   │   │   └── Routes
│   │   ├── Sales
│   │   │   ├── Providers
│   │   │   └── Routes
│   │   ├── Settings
│   │   │   ├── Providers
│   │   │   └── Routes
│   │   ├── Setup
│   │   │   └── Routes
│   │   └── Tenant
│   │       ├── Providers
│   │       └── Routes
│   ├── Providers
│   ├── Services
│   │   ├── AccountMapping
│   │   ├── Accounting
│   │   ├── Audit
│   │   ├── CashBank
│   │   ├── Companies
│   │   ├── DataRetention
│   │   ├── DocumentNumbering
│   │   ├── FixedAssets
│   │   ├── Inventory
│   │   │   └── Reports
│   │   ├── Journal
│   │   ├── MasterData
│   │   ├── OpeningBalance
│   │   ├── Permissions
│   │   ├── Purchase
│   │   │   └── Concerns
│   │   ├── Reports
│   │   ├── Sales
│   │   │   └── Concerns
│   │   ├── Settings
│   │   ├── Setup
│   │   ├── Tenant
│   │   ├── Transactions
│   │   │   └── Checkers
│   │   └── Validation
│   ├── Shared
│   │   ├── Api
│   │   ├── Audit
│   │   ├── DocumentNumbering
│   │   ├── SourceDocument
│   │   ├── Support
│   │   └── TransactionLifecycle
│   ├── Support
│   │   ├── AccountMapping
│   │   ├── Api
│   │   ├── Audit
│   │   ├── DataRetention
│   │   ├── DocumentNumbering
│   │   ├── Inventory
│   │   ├── OpeningBalance
│   │   ├── Reports
│   │   ├── Revision
│   │   ├── SourceLink
│   │   └── Transaction
│   └── Traits
├── bootstrap
│   └── cache
├── config
├── database
│   ├── factories
│   │   └── Tenant
│   ├── migrations
│   │   ├── central
│   │   └── tenant
│   ├── seeders
│   │   └── tenant
│   └── tenants
├── docs
│   └── implementation_plans
├── public
├── resources
│   ├── css
│   ├── js
│   └── views
├── routes
└── tests
    ├── Feature
    │   ├── Access
    │   ├── Accounting
    │   ├── Architecture
    │   ├── CashBank
    │   ├── Demo
    │   ├── DocumentNumbering
    │   ├── Inventory
    │   ├── Journal
    │   ├── MasterData
    │   ├── OpeningBalance
    │   ├── Permissions
    │   ├── Purchase
    │   ├── Reports
    │   ├── Sales
    │   ├── Settings
    │   ├── Setup
    │   └── Tenant
    └── Unit
        ├── Enums
        ├── Inventory
        ├── Permissions
        ├── Purchase
        ├── Reports
        └── Sales

188 directories
```
