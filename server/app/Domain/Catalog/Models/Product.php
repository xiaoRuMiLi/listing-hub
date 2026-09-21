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
        // ★ R2–R5：新增的 JSON 桶必须 cast（同 Listing 模型）
        'profile_json' => 'array',
        'is_custom' => 'boolean',
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

    /** 商品级物流（各国运费/渠道） */
    public function shipping()
    {
        return $this->hasMany(ProductShipping::class, 'product_id');
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
