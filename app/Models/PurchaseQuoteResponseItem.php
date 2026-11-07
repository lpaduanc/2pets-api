<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseQuoteResponseItem extends Model
{
    protected $fillable = ['purchase_quote_response_id', 'purchase_quote_item_id', 'unit_price'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['unit_price' => 'decimal:4'];
    }
}
