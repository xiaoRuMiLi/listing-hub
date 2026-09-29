<?php

namespace App\Http\Controllers\Api;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductShipping;
use App\Domain\Catalog\Models\ProductSupplier;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Design\Models\Design;
use App\Domain\Listing\Models\Listing;
use App\Domain\Listing\Models\ListingVariant;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * 删除 API（软删 · 2026-09-29）
 *
 * 设计（用户口径）：
 *  - Q1 粒度：整体删 + 分部删都有
 *  - Q2 软删（deleted_at，可恢复）
 *  - Q3 已上架 listing：需 ?force=true 才连带删；否则拒删并提示数量
 *  - Q4 共享 design：解绑 + 顺带软删孤儿 design（无任何活 listing 引用即孤儿）
 *  - Q5 权限：非管理员只删自己推的（pushed_by 含自己 email）；管理员删任意
 *  - blobs / assets（内容寻址·共享）：**永不删**
 *
 * 幂等：删不存在的 → 返回 ok:true。
 */
class DeleteController extends Controller
{
    /** 可被商品级级联软删的从属表计数键 */
    private function emptyStats(): array
    {
        return [
            'products' => 0,
            'product_suppliers' => 0,
            'product_variants' => 0,
            'product_shipping' => 0,
            'designs' => 0,
            'orphan_designs' => 0,
            'listings' => 0,
            'listing_variants' => 0,
        ];
    }

    /** 是否为管理员 */
    private function isAdmin(Request $r): bool
    {
        $u = $r->user();

        return $u && method_exists($u, 'isAdmin') && $u->isAdmin();
    }

    /** 该行是否由当前用户推送（pushed_by 含自己 email） */
    private function ownedBy(Request $r, ?string $pushedBy): bool
    {
        if ($this->isAdmin($r)) {
            return true;
        }
        $email = optional($r->user())->email;
        if (! $email) {
            return false;
        }

        return $pushedBy !== null && str_contains($pushedBy, $email);
    }

    /** 403 响应 */
    private function forbidden(): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'ok' => false,
            'error' => ['code' => 'forbidden', 'message' => '非管理员只能删除自己推送的数据（pushed_by 不含当前账号）'],
        ], 403);
    }

    // ===================== 整体删除（商品级） =====================

    /** DELETE /products/{code}[?force=true] */
    public function destroyProduct(Request $r, string $code)
    {
        $force = filter_var($r->query('force', false), FILTER_VALIDATE_BOOLEAN);
        $product = Product::where('code', $code)->first();
        if (! $product) {
            return ['ok' => true, 'data' => ['code' => $code, 'soft_deleted' => $this->emptyStats(), 'note' => 'not_found_idempotent']];
        }

        if (! $this->ownedBy($r, $product->pushed_by)) {
            return $this->forbidden();
        }

        // Q3：已上架 listing 检查（当前活行数）
        $listings = Listing::where('product_id', $product->id)->get();
        $published = $listings->whereNotNull('published_at')->count();
        if ($published > 0 && ! $force) {
            return response()->json([
                'ok' => false,
                'error' => [
                    'code' => 'has_published_listings',
                    'message' => "该商品有 {$published} 个已上架 listing，加 ?force=true 强删",
                    'published_count' => $published,
                ],
            ], 409);
        }

        $stats = DB::transaction(function () use ($product) {
            return $this->softDeleteProductGraph($product);
        });

        return ['ok' => true, 'data' => [
            'code' => $code,
            'soft_deleted' => $stats,
            'untouched' => ['blobs' => 'shared', 'assets' => 'shared'],
        ]];
    }

    /**
     * 商品级级联软删（在事务内调用）。返回各表删除计数。
     * 顺序：listing_variants → listings → shipping → variants → suppliers → designs → product → 孤儿 design。
     * blobs / assets 不动。
     */
    private function softDeleteProductGraph(Product $product): array
    {
        $stats = $this->emptyStats();

        // 1) listing_variants（按 product_code）
        $stats['listing_variants'] = ListingVariant::where('product_code', $product->code)->delete();

        // 2) listings
        $stats['listings'] = Listing::where('product_id', $product->id)->delete();

        // 3) product_shipping（商品级 + 变体级）
        $variantIds = ProductVariant::where('product_id', $product->id)->pluck('id')->all();
        $stats['product_shipping'] = ProductShipping::where('product_id', $product->id)
            ->orWhereIn('product_variant_id', $variantIds ?: [0])
            ->delete();

        // 4) product_variants
        $stats['product_variants'] = ProductVariant::where('product_id', $product->id)->delete();

        // 5) product_suppliers
        $stats['product_suppliers'] = ProductSupplier::where('product_id', $product->id)->delete();

        // 6) designs
        $designCodes = Design::where('product_id', $product->id)->pluck('design_code')->all();
        $stats['designs'] = Design::where('product_id', $product->id)->delete();

        // 7) 商品本体
        $product->delete();

        // 8) 孤儿 design（Q4-B：无任何活 listing 引用的、且刚删的 design）
        $stats['orphan_designs'] = $this->countOrphanDesigns($designCodes);

        return $stats;
    }

    /** 统计（并确认）刚删 design 中有多少成孤儿（无活 listing 引用） */
    private function countOrphanDesigns(array $designCodes): int
    {
        $n = 0;
        foreach ($designCodes as $dc) {
            $stillUsed = Listing::where('design_code', $dc)->exists()
                || ListingVariant::where('design_code', $dc)->exists();
            $design = Design::withTrashed()->where('design_code', $dc)->first();
            if ($design && ! $stillUsed && $design->trashed()) {
                $n++;
            }
        }

        return $n;
    }

    // ===================== 分部删除（子资源级） =====================

    /** DELETE /products/{code}/variants/{external_variant_id} */
    public function destroyVariant(Request $r, string $code, string $ext)
    {
        $product = Product::where('code', $code)->first();
        if (! $product) {
            return ['ok' => true, 'data' => ['note' => 'not_found_idempotent']];
        }
        if (! $this->ownedBy($r, $product->pushed_by)) {
            return $this->forbidden();
        }
        $variant = ProductVariant::where('product_id', $product->id)->where('external_variant_id', $ext)->first();
        if (! $variant) {
            return ['ok' => true, 'data' => ['note' => 'not_found_idempotent']];
        }
        $shippingDeleted = ProductShipping::where('product_variant_id', $variant->id)->delete();
        $variant->delete();

        return ['ok' => true, 'data' => ['code' => $code, 'external_variant_id' => $ext, 'soft_deleted' => ['product_variants' => 1, 'product_shipping' => $shippingDeleted]]];
    }

    /** DELETE /products/{code}/shipping?country=US[&scope=product|variant] */
    public function destroyShipping(Request $r, string $code)
    {
        $product = Product::where('code', $code)->first();
        if (! $product) {
            return ['ok' => true, 'data' => ['note' => 'not_found_idempotent']];
        }
        if (! $this->ownedBy($r, $product->pushed_by)) {
            return $this->forbidden();
        }
        $country = strtoupper((string) $r->query('country', ''));
        $scope = (string) $r->query('scope', 'product'); // product|variant|all

        $q = ProductShipping::query();
        if ($scope === 'variant') {
            $variantIds = ProductVariant::where('product_id', $product->id)->pluck('id')->all();
            $q->whereIn('product_variant_id', $variantIds ?: [0]);
            if ($country !== '') {
                $q->where('country', $country);
            }
        } elseif ($scope === 'all') {
            $variantIds = ProductVariant::where('product_id', $product->id)->pluck('id')->all();
            $q->where(function ($w) use ($product, $variantIds) {
                $w->where('product_id', $product->id)->orWhereIn('product_variant_id', $variantIds ?: [0]);
            });
            if ($country !== '') {
                $q->where('country', $country);
            }
        } else { // product 级
            $q->where('product_id', $product->id);
            if ($country !== '') {
                $q->where('country', $country);
            }
        }
        $n = $q->delete();

        return ['ok' => true, 'data' => ['code' => $code, 'country' => $country ?: null, 'scope' => $scope, 'soft_deleted' => ['product_shipping' => $n]]];
    }

    /** DELETE /designs/{design_code} */
    public function destroyDesign(Request $r, string $designCode)
    {
        $design = Design::where('design_code', $designCode)->first();
        if (! $design) {
            return ['ok' => true, 'data' => ['note' => 'not_found_idempotent']];
        }
        if (! $this->ownedBy($r, $design->pushed_by)) {
            return $this->forbidden();
        }
        // Q4：仍被别的活 listing 引用 → 只解绑（不软删 design）
        $stillUsed = Listing::where('design_code', $designCode)->exists()
            || ListingVariant::where('design_code', $designCode)->exists();
        if ($stillUsed) {
            // 解绑：把 listing 的 design_code 置空（保留 design）
            Listing::where('design_code', $designCode)->update(['design_code' => null]);
            ListingVariant::where('design_code', $designCode)->update(['design_code' => null]);

            return ['ok' => true, 'data' => ['design_code' => $designCode, 'action' => 'unbound', 'note' => '仍被引用，仅解绑']];
        }
        $design->delete();

        return ['ok' => true, 'data' => ['design_code' => $designCode, 'action' => 'soft_deleted', 'soft_deleted' => ['designs' => 1]]];
    }

    /** DELETE /listings/{sku} */
    public function destroyListing(Request $r, string $sku)
    {
        $listing = Listing::where('sku', $sku)->first();
        if (! $listing) {
            return ['ok' => true, 'data' => ['note' => 'not_found_idempotent']];
        }
        if (! $this->ownedBy($r, $listing->pushed_by)) {
            return $this->forbidden();
        }
        $v = DB::transaction(function () use ($listing, $sku) {
            $n = ListingVariant::where('sku', $sku)
                ->orWhere('parent_sku', $sku)
                ->delete();
            $listing->delete();

            return $n;
        });

        return ['ok' => true, 'data' => ['sku' => $sku, 'soft_deleted' => ['listings' => 1, 'listing_variants' => $v]]];
    }
}
