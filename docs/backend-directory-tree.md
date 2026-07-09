# Backend Directory Tree

Generated: 2026-07-08 (regenerated setelah modularisasi DDD Fase 0–8)

Purpose: entry-point directory map for backend agents. Read this before broad filesystem searches.

Command (jika `tree` tersedia):

```bash
tree -a -d -I '.git|vendor|node_modules|storage|bootstrap/cache|.phpunit.cache|coverage|dist|build|.idea|.vscode' -L 6 .
```

Jika `tree` tidak tersedia, gunakan `find` + renderer (lihat "Cara regenerasi" di bawah).

Excluded intentionally: `.git`, `vendor`, `node_modules`, `storage`, `bootstrap/cache`, test/build/cache/editor folders.

> **Struktur modular (DDD):** kode domain ada di `app/Modules/{Modul}/{Controllers,Requests,Services,Models,Routes,Providers}`; kode cross-cutting di `app/Shared/`. Folder lama pra-modularisasi (`app/Http/Controllers/Api`, `app/Services`, `app/Models`, `app/Support`, `app/Traits`, `app/Enums`, `app/Data`, `app/Contracts`) sudah **dihapus** (Fase 8). `config/` & `database/migrations/` sengaja tidak dipindah.

```text
.
├── app
│   ├── Console
│   │   └── Commands
│   ├── Http
│   │   └── Controllers
│   ├── Modules
│   │   ├── Access
│   │   │   ├── Controllers
│   │   │   ├── Providers
│   │   │   ├── Requests
│   │   │   └── Routes
│   │   ├── Accounting
│   │   │   ├── Controllers
│   │   │   ├── Models
│   │   │   ├── Providers
│   │   │   ├── Requests
│   │   │   ├── Routes
│   │   │   └── Services
│   │   ├── Auth
│   │   │   ├── Controllers
│   │   │   ├── Providers
│   │   │   ├── Requests
│   │   │   └── Routes
│   │   ├── Budget
│   │   │   ├── Controllers
│   │   │   ├── Models
│   │   │   ├── Providers
│   │   │   ├── Requests
│   │   │   ├── Routes
│   │   │   └── Services
│   │   ├── CashBank
│   │   │   ├── Controllers
│   │   │   ├── Models
│   │   │   ├── Providers
│   │   │   ├── Requests
│   │   │   ├── Routes
│   │   │   └── Services
│   │   ├── Companies
│   │   │   ├── Controllers
│   │   │   ├── Providers
│   │   │   ├── Routes
│   │   │   └── Services
│   │   ├── Dashboard
│   │   │   ├── Controllers
│   │   │   ├── Routes
│   │   │   └── Services
│   │   ├── FixedAssets
│   │   │   ├── Controllers
│   │   │   ├── Models
│   │   │   ├── Requests
│   │   │   ├── Routes
│   │   │   └── Services
│   │   ├── Inventory
│   │   │   ├── Controllers
│   │   │   ├── Models
│   │   │   ├── Providers
│   │   │   ├── Requests
│   │   │   ├── Routes
│   │   │   ├── Services
│   │   │   │   └── Reports
│   │   │   └── Support
│   │   ├── Journal
│   │   │   ├── Controllers
│   │   │   ├── Models
│   │   │   ├── Providers
│   │   │   ├── Requests
│   │   │   ├── Routes
│   │   │   └── Services
│   │   ├── MasterData
│   │   │   ├── Controllers
│   │   │   ├── Models
│   │   │   ├── Providers
│   │   │   ├── Requests
│   │   │   ├── Routes
│   │   │   └── Services
│   │   ├── OpeningBalance
│   │   │   ├── Controllers
│   │   │   ├── Models
│   │   │   ├── Requests
│   │   │   ├── Routes
│   │   │   ├── Services
│   │   │   └── Support
│   │   ├── Purchase
│   │   │   ├── Controllers
│   │   │   ├── Models
│   │   │   ├── Providers
│   │   │   ├── Requests
│   │   │   ├── Routes
│   │   │   └── Services
│   │   │       └── Concerns
│   │   ├── Reports
│   │   │   ├── Controllers
│   │   │   │   ├── Purchase
│   │   │   │   └── Sales
│   │   │   ├── Providers
│   │   │   ├── Requests
│   │   │   │   ├── Concerns
│   │   │   │   ├── Purchase
│   │   │   │   └── Sales
│   │   │   ├── Routes
│   │   │   └── Services
│   │   │       ├── Purchase
│   │   │       └── Sales
│   │   ├── Sales
│   │   │   ├── Controllers
│   │   │   ├── Models
│   │   │   ├── Providers
│   │   │   ├── Requests
│   │   │   ├── Routes
│   │   │   └── Services
│   │   │       └── Concerns
│   │   ├── Settings
│   │   │   ├── Controllers
│   │   │   ├── Providers
│   │   │   ├── Requests
│   │   │   ├── Routes
│   │   │   └── Services
│   │   ├── Setup
│   │   │   ├── Controllers
│   │   │   ├── Requests
│   │   │   ├── Routes
│   │   │   └── Services
│   │   └── Tenant
│   │       ├── Controllers
│   │       ├── Providers
│   │       └── Routes
│   ├── Providers
│   └── Shared
│       ├── AccountMapping
│       ├── Api
│       ├── Audit
│       ├── DataRetention
│       ├── DocumentNumbering
│       ├── Enums
│       ├── Exceptions
│       ├── Http
│       │   ├── Controllers
│       │   └── Middleware
│       ├── Models
│       ├── Permission
│       ├── Providers
│       ├── Reports
│       │   └── Data
│       ├── SourceDocument
│       ├── Tenant
│       ├── TransactionLifecycle
│       │   ├── Checkers
│       │   └── Contracts
│       └── Validation
├── bootstrap
├── config
├── database
│   ├── factories
│   │   └── Tenant
│   ├── migrations
│   │   ├── central
│   │   └── tenant
│   ├── seeders
│   │   └── tenant
│   └── tenants
├── docs
│   └── implementation_plans
├── public
├── resources
│   ├── css
│   ├── js
│   └── views
├── routes
└── tests
    ├── Feature
    │   ├── Access
    │   ├── Accounting
    │   ├── Architecture
    │   ├── Budget
    │   ├── CashBank
    │   ├── Dashboard
    │   ├── Demo
    │   ├── DocumentNumbering
    │   ├── FixedAssets
    │   ├── Inventory
    │   ├── Journal
    │   ├── MasterData
    │   ├── OpeningBalance
    │   ├── Permissions
    │   ├── Purchase
    │   ├── Reports
    │   ├── Sales
    │   ├── Settings
    │   ├── Setup
    │   └── Tenant
    └── Unit
        ├── Enums
        ├── Inventory
        ├── Permissions
        ├── Purchase
        ├── Reports
        └── Sales

191 directories
```

## WAJIB: jaga file ini tetap terbaru

**Kapan update:** setiap kali menambah/menghapus/memindah **direktori** di backend (mis. modul baru, subfolder `Services/`, `Requests/Concerns`, dll). Perubahan file di dalam folder existing tidak wajib meng-update tree ini (tree hanya direktori), tapi perubahan struktur folder **wajib**.

**Bagian dari Definition of Done** untuk setiap task yang mengubah struktur folder backend — jangan tandai task selesai sebelum tree ini diselaraskan.

### Cara regenerasi

Bila `tree` tersedia, jalankan command di atas. Bila tidak, pakai `find`:

```bash
cd laravel_backend
find . -type d \
  -not -path './.git*' -not -path './vendor*' -not -path './node_modules*' \
  -not -path './storage*' -not -path './bootstrap/cache*' -not -path './.phpunit.cache*' \
  -not -path '*/coverage*' -not -path './dist*' -not -path './build*' \
  -not -path './.idea*' -not -path './.vscode*' \
  | sort | awk -F/ 'NF-1<=6'
```

Lalu render ke format tree (box-drawing) dan perbarui blok di atas + tanggal `Generated:` + jumlah `NNN directories`.
