<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Masa Pemulihan Perusahaan Terhapus
    |--------------------------------------------------------------------------
    |
    | Berapa hari perusahaan yang dihapus owner masih bisa dipulihkan super
    | admin. Lewat dari ini, `companies:sweep-deleted` menghapusnya permanen
    | beserta file database tenant-nya — tidak bisa dipulihkan lagi.
    |
    */

    'deletion_retention_days' => (int) env('COMPANY_DELETION_RETENTION_DAYS', 30),

];
