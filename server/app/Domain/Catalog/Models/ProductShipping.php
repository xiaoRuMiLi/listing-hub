<?php

namespace App\Domain\Catalog\Models;

use Illuminate\Database\Eloquent\Model;

class ProductShipping extends Model
{
    protected $table = 'product_shipping';

    public $timestamps = false;

    protected $guarded = [];

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
