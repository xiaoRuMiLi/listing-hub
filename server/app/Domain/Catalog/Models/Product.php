<?php

namespace App\Domain\Catalog\Models;

use App\Domain\Design\Models\Design;
use App\Domain\Listing\Models\Listing;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected $casts = [
        'detail_json' => 'array',
    ];

    /** 供应商来源（多对多） */
    public function sources()
    {
        return $this->hasMany(ProductSupplier::class);
    }

    /** 分类（多对多·多平台） */
    public function categories()
    {
        return $this->belongsToMany(Category::class, 'product_categories')
            ->withPivot(['platform', 'is_primary']);
    }

    public function variants()
    {
        return $this->hasMany(ProductVariant::class);
    }

    public function designs()
    {
        return $this->hasMany(Design::class);
    }

    public function listings()
    {
        return $this->hasMany(Listing::class);
    }
}
