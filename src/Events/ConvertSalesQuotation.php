<?php

namespace Zerp\Quotation\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Zerp\Quotation\Models\SalesQuotation;
use App\Models\SalesInvoice;

class ConvertSalesQuotation
{
    use Dispatchable;

    public function __construct(
        public SalesQuotation $quotation,
        public SalesInvoice $invoice
    ) {}
}