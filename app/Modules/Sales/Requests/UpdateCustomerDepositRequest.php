<?php

namespace App\Modules\Sales\Requests;

/**
 * Edit (mode lock/open) memakai aturan isian yang sama dengan buat baru:
 * formulir mengirim ulang isian lengkap. Boleh-tidaknya diedit ditentukan
 * di service.
 */
class UpdateCustomerDepositRequest extends StoreCustomerDepositRequest {}
