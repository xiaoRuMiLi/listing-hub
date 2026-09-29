<?php

namespace App\Domain\Design\Models;

use App\Domain\Catalog\Models\Product;
use App\Domain\Listing\Models\Listing;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Design extends Model
{
    use SoftDeletes;

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
