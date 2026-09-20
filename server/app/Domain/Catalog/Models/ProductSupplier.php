<?php

namespace App\Domain\Catalog\Models;

use Illuminate\Database\Eloquent\Model;

class ProductSupplier extends Model
{
    protected $guarded = [];

    protected $casts = [
        'cost_json' => 'array',
        'is_primary' => 'boolean',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }
}
