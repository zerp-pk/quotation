<?php

namespace Zerp\Quotation\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Zerp\Quotation\Models\SalesQuotation;

class SentSalesQuotation
{
    use Dispatchable;

    public function __construct(
        public SalesQuotation $quotation
    ) {}
}