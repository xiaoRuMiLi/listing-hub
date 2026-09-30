<?php

namespace App\Http\Controllers\Api;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductShipping;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Design\Models\Design;
use App\Domain\Listing\Models\Listing;
use App\Domain\Listing\Models\ListingVariant;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * 维护接口（R16 · 2026-09-30）
 *
 * POST/DELETE /api/v1/maintenance/trashed —— **物理清理软删行**（仅管理员）
 *
 * 用途：改前 push 语义下产生的「幽灵行」= 同一业务键**既有活行、又有软删行**
 *      （例：软删 design 后再推 → 新建活行，旧软删行残留）。
 *      R15 已从源头杜绝新的幽灵行；本接口用于**清理历史残留**。
 *
 * Query：
 *   mode=ghosts|all   （默认 ghosts）
 *     · ghosts：只清「同一业务键另有活行」的软删行（安全，推荐）
 *     · all   ：清掉该表**所有**软删行（含无活行对应的"纯软删"，如历史 GB 运费）
 *   tables=<csv>      默认 products,designs,listings,listing_variants,product_variants,product_shipping
 *
 * 返回：{ ok:true, data:{ mode, purged:{ <table>: <n> } } }
 */
class MaintenanceController extends Controller
{
    private const TABLES = ['products', 'designs', 'listings', 'listing_variants', 'product_variants', 'product_shipping'];

    public function purgeTrashed(Request $r)
    {
        $u = $r->user();
        if (!($u && method_exists($u, 'isAdmin') && $u->isAdmin())) {
            return response()->json(['ok' => false, 'error' => ['code' => 'forbidden', 'message' => '仅管理员可清理']], 403);
        }

        $mode = strtolower((string) $r->query('mode', 'ghosts'));
        if (! in_array($mode, ['ghosts', 'all'], true)) {
            $mode = 'ghosts';
        }
        $tables = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) $r->query('tables', implode(',', self::TABLES)))
        )));

        $purged = [];
        foreach ($tables as $tbl) {
            $purged[$tbl] = $this->purgeTable($tbl, $mode);
        }

        return ['ok' => true, 'data' => ['mode' => $mode, 'purged' => $purged]];
    }

    private function purgeTable(string $tbl, string $mode): int
    {
        return match ($tbl) {
            'products' => $this->purgeModel(
                Product::class,
                fn ($x) => ['code' => $x->code],
                $mode
            ),
            'designs' => $this->purgeModel(
                Design::class,
                fn ($x) => ['design_code' => $x->design_code],
                $mode
            ),
            'listings' => $this->purgeModel(
                Listing::class,
                fn ($x) => ['account_id' => $x->account_id, 'marketplace' => $x->marketplace, 'sku' => $x->sku],
                $mode
            ),
            'listing_variants' => $this->purgeModel(
                ListingVariant::class,
                fn ($x) => ['account_id' => $x->account_id, 'marketplace' => $x->marketplace, 'sku' => $x->sku],
                $mode
            ),
            'product_variants' => $this->purgeModel(
                ProductVariant::class,
                fn ($x) => ['product_id' => $x->product_id, 'external_variant_id' => $x->external_variant_id],
                $mode
            ),
            'product_shipping' => $this->purgeModel(
                ProductShipping::class,
                fn ($x) => $x->product_variant_id
                    ? ['product_variant_id' => $x->product_variant_id, 'country' => $x->country]
                    : ['product_id' => $x->product_id, 'product_variant_id' => null, 'country' => $x->country],
                $mode
            ),
            default => 0,
        };
    }

    private function purgeModel(string $model, callable $keyOf, string $mode): int
    {
        $n = 0;
        foreach ($model::onlyTrashed()->orderBy('id')->get() as $row) {
            if ($mode === 'ghosts') {
                // 仅当同业务键另有**活行**时才清（真"幽灵"）
                $hasLive = $model::where($keyOf($row))->exists();
                if (! $hasLive) {
                    continue;
                }
            }
            $row->forceDelete();
            $n++;
        }

        return $n;
    }
}
