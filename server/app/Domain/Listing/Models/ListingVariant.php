<?php

namespace App\Domain\Listing\Models;

use App\Domain\Catalog\Models\Product;
use App\Domain\Design\Models\Design;
use App\Domain\Identity\Models\Account;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * 变体子体（独立表 · 对齐本地 listing_variants.csv）
 *
 * 关联：
 *  - 父体：parent_listing_id → listings.id（首选）/ parent_sku（冗余兜底）
 *  - 跨端稳定键：local_row_id = 本地 listing_variants.row_id
 */
class ListingVariant extends Model
{
    use SoftDeletes;

    protected $table = 'listing_variants';

    protected $guarded = [];

    protected $casts = [
        // ★ JSON 桶必须 cast，否则 Eloquent 把数组当标量写库（落库成字符数组）
        'pricing_json' => 'array',
        'shipping_json' => 'array',
        'local_row_id' => 'integer',
        'parent_local_row_id' => 'integer',
        'parent_listing_id' => 'integer',
        'edited_at' => 'datetime',
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

    /** 父体 listing */
    public function parent()
    {
        return $this->belongsTo(Listing::class, 'parent_listing_id');
    }
}
