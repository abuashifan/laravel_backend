<?php

namespace App\Shared\Workspace;

use App\Shared\Models\Company;

interface ActivityAccountingGateway
{
    /** Return false while the Accounting product is unavailable for this company. */
    public function receive(Company $company, array $approvedActivity): bool;

    public function status(Company $company, int $document): ?array;
}
