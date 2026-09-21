<?php

namespace App\Domain\Listing\Models;

use App\Domain\Catalog\Models\Product;
use App\Domain\Design\Models\Design;
use App\Domain\Identity\Models\Account;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Listing extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected $casts = [
        'is_parent' => 'boolean',
        'is_custom' => 'boolean',
        'is_complete' => 'boolean',
        'attrs_json' => 'array',
        'customization_json' => 'array',
        'missing_fields' => 'array',
        // ★ R2–R5：新增的 JSON 桶必须 cast，否则 Eloquent 把数组当标量写库
        //   → 落库成字符数组（2026-09-21 实测 copy_json 变 2967 个单字符键）
        'copy_json' => 'array',
        'variant_json' => 'array',
        'parent_row_id' => 'integer',
        'first_published_at' => 'datetime',
        'published_at' => 'datetime',
    ];

    public function account()
    {
        return $this->belongsTo(Account::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function design()
    {
        return $this->belongsTo(Design::class);
    }

    public function revisions()
    {
        return $this->hasMany(ListingRevision::class);
    }

    /** 变体子体（同账号/平台/站点） */
    public function children()
    {
        return $this->hasMany(Listing::class, 'parent_sku', 'sku')
            ->where('account_id', $this->account_id)
            ->where('marketplace', $this->marketplace);
    }

    public function assets()
    {
        return $this->hasMany(\App\Domain\Asset\Models\Asset::class, 'owner_id')
            ->where('owner_type', 'listing');
    }
}
