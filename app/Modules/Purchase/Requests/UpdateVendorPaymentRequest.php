<?php

namespace App\Modules\Purchase\Requests;

/**
 * Edit pembayaran memakai aturan isian yang sama dengan buat baru (formulir
 * mengirim ulang isian lengkap). Boleh-tidaknya diedit ditentukan mode
 * lock/open di service.
 */
class UpdateVendorPaymentRequest extends StoreVendorPaymentRequest {}
