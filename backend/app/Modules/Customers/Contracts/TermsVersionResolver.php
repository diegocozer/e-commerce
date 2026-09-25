<?php

declare(strict_types=1);

namespace App\Modules\Customers\Contracts;

/**
 * Current Terms/Privacy version (setting `legal.terms_version`). Customers may
 * not depend on Settings (config/modules.php), so the value is resolved through
 * this contract; the default implementation reads the `settings` row.
 */
interface TermsVersionResolver
{
    public function current(): string;
}
