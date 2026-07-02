<?php

namespace Zerp\Quotation\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Zerp\Quotation\Models\SalesQuotation;

class RejectSalesQuotation
{
    use Dispatchable;

    public function __construct(
        public SalesQuotation $quotation
    ) {}
}