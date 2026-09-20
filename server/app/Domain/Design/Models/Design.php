<?php

namespace App\Domain\Design\Models;

use App\Domain\Catalog\Models\Product;
use App\Domain\Listing\Models\Listing;
use Illuminate\Database\Eloquent\Model;

class Design extends Model
{
    protected $guarded = [];

    protected $casts = [
        'adjust_json' => 'array',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function listings()
    {
        return $this->hasMany(Listing::class);
    }
}
