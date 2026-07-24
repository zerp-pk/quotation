<?php

namespace Zerp\Quotation\Http\Requests\Api;

/**
 * Body for PUT /api/quotation/quotations/{id}. Same shape as the store request;
 * the controller only allows it while the quotation is still a draft. See
 * zerp-pk/zerp#25.
 */
class UpdateQuotationApiRequest extends StoreQuotationApiRequest
{
}
