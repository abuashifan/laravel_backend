# Modul Budget

Satu mesin anggaran, banyak dimensi, banyak view. "Budget by Account", "Sales
Budget", "Project Budget" adalah **view** dari data yang sama — bukan tabel atau
engine terpisah.

Rencana lengkapnya: `Finlite_knowladge/plans/budget-module/New_Fitures/`.

## Bentuk data

```
budget_periods            wadah fiscal
   └── budget_submissions dokumen anggaran BERVERSI — unit persetujuan
          └── budget_lines FAKTA — semua dimensi ada di sini
```

Grain satu baris anggaran: **satu nominal untuk satu kombinasi
`(submission, akun, cost center, proyek, bulan)`**. Dijaga unique index berbasis
ekspresi `budget_lines_grain_unique`, yang memakai `COALESCE` supaya tetap
menggigit saat dimensinya NULL (NULL != NULL di SQL).

### `department_id` muncul dua kali, dan itu disengaja

| Kolom | Peran |
|---|---|
| `budget_submissions.department_id` | **Pemilik dokumen** — unit persetujuan (`budgets.approve_head` = kepala departemen). NULL berarti anggaran tingkat perusahaan yang diajukan Finance tanpa tahap kepala departemen. |
| `budget_lines.department_id` | **Dimensi** baris. Default mengikuti header, tapi boleh berbeda atau NULL (belum dialokasikan). |

Baris yang dikirim tanpa key `department_id` mewarisi departemen pemilik dokumen.
Kirim `null` eksplisit untuk baris yang memang lintas departemen.

### `revision_number` vs `version_no` — beda peran, jangan disatukan

| Kolom | Menghitung | Naik saat |
|---|---|---|
| `revision_number` | berapa kali satu pengajuan **ditolak** lalu dikembalikan ke draft | `reject()` |
| `version_no` | versi **anggaran** yang berbeda | revisi membuat submission baru (fase 5) |

Revisi tidak menimpa: ia membuat `budget_submissions` baru dengan `version_no + 1`
dan `parent_submission_id` menunjuk pendahulunya, sehingga baris anggaran lama
tetap utuh. Versi lama berstatus `superseded`.

`is_active` menandai versi yang berlaku — tepat satu `true` per
(periode, departemen) di antara versi berstatus `approved`. Inilah yang dibaca
laporan dan peringatan over-budget; `version=active` di mesin analisis bersandar
padanya.

### `direction`

Diturunkan dari `chart_of_accounts.account_type` saat baris ditulis, **tidak
pernah diinput user**. Hanya akun laba-rugi yang bisa dianggarkan; akun neraca
ditolak `BUDGET_ACCOUNT_DIRECTION_MISMATCH`. Disimpan (bukan di-join tiap kali)
supaya filter `direction=revenue` bisa memakai index.

## Mesin

| Service | Tanggung jawab |
|---|---|
| `BudgetAnalysisService` | Inti. Agregasi budget vs actual per kombinasi dimensi; semua view lahir dari sini. |
| `BudgetActualService` | Actual dari ledger. WAJIB lewat `ReportQueryService::reportableJournalLinesQuery()`; tanda dibalik per jenis akun. |
| `BudgetMatchResolver` | Tangga spesifisitas: baris jurnal mengonsumsi anggaran paling spesifik yang cocok. |
| `BudgetAllocationResolver` | Aturan alokasi periode — baris bulanan vs baris tahunan (kumulatif). |
| `BudgetWarningService` | Peringatan over-budget saat posting jurnal. **Tanpa logika sendiri** — memakai ketiga service di atas. |
| `BudgetComparisonService` | Pembungkus tipis: `group_by=[account]`, `mode=variance`. |
| `BudgetConsolidationService` | Pembungkus: `group_by=[department\|project, account]`, dinormalkan ke bentuk bersarang lama. |

**Aturan yang tidak boleh dilanggar:** peringatan over-budget dan laporan
perbandingan wajib memakai resolver dan agregasi yang sama. Perbedaan di antara
keduanya adalah akar empat cacat perilaku lama (G4–G8). Jangan menulis query
`journal_entry_lines` baru di dalam modul ini.

## Definisi angka

| Konteks | Variance | Favorable bila |
|---|---|---|
| Expense | `Budget − Actual` | `> 0` (hemat) |
| Revenue | `Actual − Budget` | `> 0` (target terlampaui) |

Utilization selalu `Actual / Budget × 100`. **Budget = 0 → `null`**, bukan 0
(terbaca "belum terpakai") dan bukan `INF`.

State baris ada di `Support/BudgetState.php`: `on_budget` (toleransi 0.0001),
`under_budget`, `over_budget`, `no_budget`, `no_actual`.

Satu baris agregasi yang mencampur pendapatan dan beban (mis.
`group_by=[department]` tanpa filter arah) ditandai `direction: mixed` —
"favorable" tidak punya makna tunggal di baris seperti itu.

## Yang TIDAK ada di modul ini

- ❌ Tabel ledger / cash ledger sendiri — actual selalu dari `journal_entry_lines`
- ❌ Entitas `cost_centers` — `departments` **adalah** cost center
- ❌ `budget_version_lines` — versi menyimpan barisnya sendiri
- ❌ Input actual manual
