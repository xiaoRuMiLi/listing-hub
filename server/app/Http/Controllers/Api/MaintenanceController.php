<?php

namespace App\Http\Controllers\Api;

use App\Domain\Asset\Models\Blob;
use App\Domain\Asset\Services\MediaService;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductShipping;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Design\Models\Design;
use App\Domain\Listing\Models\Listing;
use App\Domain\Listing\Models\ListingVariant;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

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
 *   tables=<csv>      默认 **子表在前**（R18）：product_shipping,listing_variants,listings,product_variants,designs,products
 *                      ★ 必须「先删子、后删父」——否则 mode=all 硬删父表(products)时子行还在 → 撞外键 → 500。
 *
 * 返回：{ ok:true, data:{ mode, purged:{ <table>: <n> } } }
 */
class MaintenanceController extends Controller
{
    // ★ R18（2026-10-08）：必须【子表在前、父表在后】，`mode=all` 才能一次清完。
    //   原顺序 products 在第 1 → 硬删商品时子行(designs/listings/variants/shipping)还在 → 外键约束 → 500。
    private const TABLES = ['product_shipping', 'listing_variants', 'listings', 'product_variants', 'designs', 'products'];

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

    /**
     * ★ 2026-10-16：修复历史「图片类型丢失」的 blob（mime = application/octet-stream / key 以 .bin 结尾）
     *
     * POST /api/v1/maintenance/fix-image-mimes?limit=50&dry_run=0
     *
     * 做法：按【文件头】嗅探真实类型 → 同 sha、换正确后缀重建 OSS 对象 → 更新 blob 行 (storage_key/mime/public_url)
     *      → 并把 designs / listings / listing_variants 里引用旧 URL 的列就地改写为新 URL。
     * 幂等：修好后再跑，扫到的都是“已知类型” → 直接跳过。
     */
    public function fixImageMimes(Request $r)
    {
        $u = $r->user();
        if (! ($u && method_exists($u, 'isAdmin') && $u->isAdmin())) {
            return response()->json(['ok' => false, 'error' => ['code' => 'forbidden', 'message' => '仅管理员可执行']], 403);
        }

        $dry = (bool) (int) $r->input('dry_run', 0);
        $limit = max(1, min(500, (int) $r->input('limit', 50)));
        $media = app(MediaService::class);
        $disk = Storage::disk($media->disk());

        $known = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp', 'video/mp4', 'video/quicktime', 'video/webm'];
        $blobs = Blob::where(function ($q) use ($known) {
            $q->whereNotIn('mime', $known)->orWhere('storage_key', 'like', '%.bin');
        })->orderBy('id')->limit($limit)->get();

        $fixed = [];
        $skipped = [];
        $refs = ['designs' => 0, 'listings' => 0, 'listing_variants' => 0];

        foreach ($blobs as $b) {
            $bytes = null;
            try {
                $bytes = (string) $disk->get($b->storage_key);
            } catch (\Throwable $e) {
                $bytes = null;
            }
            $mime = ($bytes !== null && $bytes !== '') ? $media->sniffMime($bytes) : null;
            if (! $mime) {
                $skipped[] = ['blob' => $b->id, 'why' => $bytes === null ? 'read_failed' : 'not_an_image'];
                continue;
            }
            $newKey = $media->keyFor($b->sha256, $media->extFor($mime));
            if ($newKey === $b->storage_key && $media->isMediaMime($b->mime)) {
                $skipped[] = ['blob' => $b->id, 'why' => 'already_ok'];
                continue;
            }

            $old = (string) $b->public_url;
            $new = $media->publicUrl($newKey);

            if (! $dry) {
                $disk->put($newKey, $bytes);
                $b->update(['storage_key' => $newKey, 'mime' => $mime, 'public_url' => $new]);
                if ($old !== '' && $old !== $new) {
                    $refs['designs'] += $this->rewriteRef(Design::class, ['main_image', 'other_images'], $old, $new);
                    $refs['listings'] += $this->rewriteRef(Listing::class, ['main_image', 'other_images', 'attrs_json', 'copy_json', 'variant_json'], $old, $new);
                    $refs['listing_variants'] += $this->rewriteRef(ListingVariant::class, ['main_image', 'other_images'], $old, $new);
                }
            }
            $fixed[] = ['blob' => $b->id, 'mime' => $mime, 'from' => $old, 'to' => $new];
        }

        return ['ok' => true, 'data' => [
            'dry_run' => $dry,
            'scanned' => $blobs->count(),
            'fixed' => count($fixed),
            'refs' => $refs,
            'skipped' => array_slice($skipped, 0, 20),
            'skipped_count' => count($skipped),
            'items' => array_slice($fixed, 0, 20),
        ]];
    }

    /** 把某模型某几列里的 old URL 就地替换为 new（返回受影响行数；支持 cast 成数组的 JSON 列） */
    private function rewriteRef(string $model, array $cols, string $old, string $new): int
    {
        $n = 0;
        foreach ($cols as $col) {
            foreach ($model::where($col, 'like', '%' . $old . '%')->get() as $row) {
                $v = $row->{$col};
                if (is_array($v)) {   // JSON 桶（被 cast 成数组的列）
                    $json = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    if ($json === false || strpos($json, $old) === false) {
                        continue;
                    }
                    $row->{$col} = json_decode(str_replace($old, $new, $json), true);
                    $row->save();
                    $n++;
                    continue;
                }
                $v = (string) $v;
                if ($v === '' || strpos($v, $old) === false) {
                    continue;
                }
                $row->{$col} = str_replace($old, $new, $v);
                $row->save();
                $n++;
            }
        }

        return $n;
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
