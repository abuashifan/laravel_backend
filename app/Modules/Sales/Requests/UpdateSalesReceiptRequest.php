<?php

namespace App\Modules\Sales\Requests;

/**
 * Edit penerimaan memakai aturan isian yang sama dengan buat baru (formulir
 * mengirim ulang isian lengkap). Boleh-tidaknya diedit ditentukan mode
 * lock/open di service.
 */
class UpdateSalesReceiptRequest extends StoreSalesReceiptRequest {}
