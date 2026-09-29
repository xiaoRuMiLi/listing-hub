<?php

namespace App\Http\Controllers\Api;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductShipping;
use App\Domain\Catalog\Models\ProductSupplier;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\Models\Supplier;
use App\Domain\Design\Models\Design;
use App\Domain\Identity\Models\Account;
use App\Domain\Listing\Models\Listing;
use App\Domain\Listing\Models\ListingVariant;
use App\Domain\Sync\Models\SyncJob;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * 同步：本地 push（幂等 upsert） / pull（增量，JSON）。
 * 见 API.md §2。
 */
class SyncController extends Controller
{
    public function push(Request $r)
    {
        $payload = $r->all();
        $job = SyncJob::create([
            'machine_id' => $payload['machine_id'] ?? ($r->user()?->email ?? ''),
            'direction' => 'push', 'scope' => implode(',', array_keys($payload)),
            'status' => 'running', 'started_at' => now(),
        ]);

        $stats = ['products' => $this->counts(), 'designs' => $this->counts(), 'listings' => $this->counts(), 'variants' => $this->counts(), 'product_shipping' => $this->counts(), 'product_variants' => $this->counts(), 'variant_shipping' => $this->counts()];
        $conflicts = [];
        $warnings = [];   // ★ R4：不拒写的告警（如 attrs_json 空 / 缺该 PT 必填）
        $pendingBlobs = [];   // ★ R9：未 OSS 化的图 URL（回执，最多留 20 条）
        $doMirror = ! empty($payload['sync_images']) || ! empty($payload['normalize_images']);   // ★ R9

        DB::transaction(function () use ($r, $payload, &$stats, &$conflicts, &$warnings, &$pendingBlobs, $doMirror) {
            // ① 商品
            foreach (($payload['products'] ?? []) as $p) {
                $code = (string) ($p['code'] ?? '');
                if ($code === '') { continue; }
                $existing = Product::where('code', $code)->first();
                $attrs = [
                    'code' => $code,
                    'spu_code' => $p['spu_code'] ?? null,
                    'is_custom' => isset($p['is_custom']) ? (bool) $p['is_custom'] : null,
                    'factory' => $p['factory'] ?? null,
                    'cn_name' => $p['cn_name'] ?? null,
                    'en_name' => $p['en_name'] ?? null,
                    'material_cn' => $p['material_cn'] ?? null,
                    'material_en' => $p['material_en'] ?? null,
                    'print_face_w' => $p['print_face_w'] ?? null,
                    'print_face_h' => $p['print_face_h'] ?? null,
                    'variants_count' => $p['variants_count'] ?? null,
                    'weight_g' => $p['weight_g'] ?? null,
                    'volume_cm3' => $p['volume_cm3'] ?? null,
                    'design_face_count' => $p['design_face_count'] ?? null,
                    'min_price' => $p['min_price'] ?? null,
                    'currency' => $p['currency'] ?? null,
                    'status' => $this->safeEnum($p['status'] ?? null, ['draft', 'ready', 'synced', 'archived'], 'synced'),
                    'detail_json' => $p['detail_json'] ?? null,
                    // R3：长尾字段桶（8国运费/售价组 + 尺寸/包装 + 默认值 + 批发档价 + 其他）
                    'profile_json' => $p['profile_json'] ?? null,
                    // ★ 商品级 8 国物流/售价（提升为物理列；中台此前只在 profile_json 桶）
                    'shipping_US' => $p['shipping_US'] ?? null,
                    'shipping_UK' => $p['shipping_UK'] ?? null,
                    'shipping_CA' => $p['shipping_CA'] ?? null,
                    'shipping_DE' => $p['shipping_DE'] ?? null,
                    'shipping_MX' => $p['shipping_MX'] ?? null,
                    'shipping_FR' => $p['shipping_FR'] ?? null,
                    'shipping_ES' => $p['shipping_ES'] ?? null,
                    'shipping_IT' => $p['shipping_IT'] ?? null,
                    'shipping_variant' => $p['shipping_variant'] ?? null,
                    'shipping_updated_at' => $p['shipping_updated_at'] ?? null,
                    'pushed_by' => $this->pushedBy($r, $payload),
                ];
                if ($existing) { $existing->update($attrs); $stats['products']['updated']++; }
                else { Product::create($attrs); $stats['products']['created']++; }

                // 供应商来源映射
                foreach (($p['sources'] ?? []) as $src) {
                    $sup = Supplier::firstOrCreate(['code' => $src['supplier_code'] ?? 'hicustom'], ['name' => $src['supplier_code'] ?? 'hicustom']);
                    $prod = Product::where('code', $code)->first();
                    ProductSupplier::updateOrCreate(
                        ['product_id' => $prod->id, 'supplier_id' => $sup->id],
                        ['external_id' => (string) ($src['external_id'] ?? $code), 'supplier_sku' => $src['supplier_sku'] ?? null, 'is_primary' => (bool) ($src['is_primary'] ?? true)],
                    );
                }

                // ★ R5：品类映射（推送侧带 categories[]；缺则从 listings 的 PT 推导——见 ProductController::show）
                foreach (($p['categories'] ?? []) as $cat) {
                    $catCode = (string) ($cat['code'] ?? '');
                    if ($catCode === '') { continue; }
                    $prod = Product::where('code', $code)->first();
                    $c = \App\Domain\Catalog\Models\Category::firstOrCreate(
                        ['platform' => $cat['platform'] ?? 'amazon', 'code' => $catCode],
                        ['name_en' => $cat['name_en'] ?? null, 'name_cn' => $cat['name_cn'] ?? null],
                    );
                    $prod->categories()->syncWithoutDetaching([$c->id => ['platform' => $cat['platform'] ?? 'amazon', 'is_primary' => (bool) ($cat['is_primary'] ?? true)]]);
                }
            }

            // ② 设计
            foreach (($payload['designs'] ?? []) as $d) {
                $dc = (string) ($d['design_code'] ?? '');
                if ($dc === '') { continue; }
                $prod = Product::where('code', (string) ($d['product_code'] ?? ''))->first();
                $row = [
                    'design_code' => $dc,
                    'product_id' => $prod?->id,
                    'design_key' => $d['design_key'] ?? null,
                    'version' => $d['version'] ?? null,
                    'parent_code' => $d['parent_code'] ?? null,
                    'source' => $d['source'] ?? 'sync',
                    'cn_name' => $d['cn_name'] ?? null,
                    'en_name' => $d['en_name'] ?? null,
                    'pattern' => $d['pattern'] ?? null,
                    'template' => $d['template'] ?? null,
                    'gallery_codes' => $d['gallery_codes'] ?? null,
                    'effect_count' => $d['effect_count'] ?? null,
                    // R4：adjust_json 列早已存在（建表即有）—— 之前 push 漏写，此处补上
                    'adjust_json' => $d['adjust_json'] ?? null,
                    'design_zh_name' => $d['design_zh_name'] ?? null,
                    'design_zh_tags' => $d['design_zh_tags'] ?? null,
                    'design_en_name' => $d['design_en_name'] ?? null,
                    'design_en_tags' => $d['design_en_tags'] ?? null,
                    // ★ R9：图 URL 归一（已是 OSS 则不动；否则计数回执，sync_images/normalize_images 时立即镜像）
                    'main_image' => $this->ossUrl((string) ($d['main_image'] ?? ''), $doMirror, $pendingBlobs),
                    'other_images' => $this->ossUrls((string) ($d['other_images'] ?? ''), $doMirror, $pendingBlobs),
                    'pushed_by' => $this->pushedBy($r, $payload),
                    'status' => $this->safeEnum($d['status'] ?? null, ['draft', 'active', 'superseded', 'archived'], 'active'),
                ];
                if ($prod === null) { unset($row['product_id']); }
                $existing = Design::where('design_code', $dc)->first();
                if ($existing) { $existing->update($row); $stats['designs']['updated']++; }
                elseif ($prod !== null) { Design::create($row); $stats['designs']['created']++; }
                else { $stats['designs']['skipped']++; }
            }

            // ②b 商品物流（商品级·各国）
            foreach (($payload['product_shipping'] ?? []) as $s) {
                $prod = Product::where('code', (string) ($s['product_code'] ?? ''))->first();
                $country = strtoupper((string) ($s['country'] ?? ''));
                if ($prod === null || $country === '') { continue; }
                ProductShipping::updateOrCreate(
                    ['product_id' => $prod->id, 'country' => $country],
                    ['amount' => $s['amount'] ?? null, 'currency' => $s['currency'] ?? null, 'channel' => $s['channel'] ?? null, 'updated_at' => now()],
                );
                $stats['product_shipping']['updated']++;
            }

            // ②b2 ★ 商品规格（product_variants）—— 指纹规格（颜色×尺寸），运费/包装的物理来源。
            //      幂等键：(product_id, external_variant_id=指纹 variantCode)。
            $variantIdMap = [];   // [product_code][external_variant_id] => product_variant_id
            $variantIdMapProduct = [];   // [product_code][external_variant_id] => product_id
            foreach (($payload['product_variants'] ?? []) as $pv) {
                $prod = Product::where('code', (string) ($pv['product_code'] ?? ''))->first();
                $ext = (string) ($pv['external_variant_id'] ?? '');
                if ($prod === null || $ext === '') { continue; }
                $row = ProductVariant::updateOrCreate(
                    ['product_id' => $prod->id, 'external_variant_id' => $ext],
                    [
                        'color' => $pv['color'] ?? null,
                        'color_name' => $pv['color_name'] ?? null,   // ★ R3b：规格颜色名（子体 pkg 兜底 derive 用）
                        'size' => $pv['size'] ?? ($pv['size_id'] ?? null),
                        'size_name' => $pv['size_name'] ?? null,     // ★ R3b：规格尺寸名
                        'spec_json' => $pv['spec_json'] ?? null,
                        'weight_g' => $pv['weight_g'] ?? null,
                        'size_l_cm' => $pv['size_l_cm'] ?? null,
                        'size_w_cm' => $pv['size_w_cm'] ?? null,
                        'size_h_cm' => $pv['size_h_cm'] ?? null,
                        'pkg_l_cm' => $pv['pkg_l_cm'] ?? ($pv['size_l_cm'] ?? null),
                        'pkg_w_cm' => $pv['pkg_w_cm'] ?? ($pv['size_w_cm'] ?? null),
                        'pkg_h_cm' => $pv['pkg_h_cm'] ?? ($pv['size_h_cm'] ?? null),
                        'volume_cm3' => $pv['volume_cm3'] ?? null,
                        'status' => $pv['status'] ?? 'synced',
                    ],
                );
                $variantIdMap[(string) $pv['product_code']][$ext] = $row->id;
                $variantIdMapProduct[(string) $pv['product_code']][$ext] = $row->product_id;   // ★ R2：供 variant_shipping 冗余回填 product_id
                $stats['product_variants']['updated']++;
            }

            // ②b3 ★ 逐规格×逐国运费（变体级）—— external_variant_id → product_variant_id。
            foreach (($payload['variant_shipping'] ?? []) as $vs) {
                $pc = (string) ($vs['product_code'] ?? '');
                $ext = (string) ($vs['external_variant_id'] ?? '');
                $country = strtoupper((string) ($vs['country'] ?? ''));
                if ($pc === '' || $ext === '' || $country === '') { continue; }
                $vid = $variantIdMap[$pc][$ext] ?? optional(ProductVariant::where('external_variant_id', $ext)->first())->id;
                if (! $vid) { continue; }
                ProductShipping::updateOrCreate(
                    ['product_variant_id' => $vid, 'country' => $country],
                    [
                        // ★ R2：冗余回填 product_id（否则变体级运费导出时 product_code 为空 → “无归属”行）
                        'product_id' => $variantIdMapProduct[$pc][$ext] ?? optional(ProductVariant::find($vid))->product_id,
                        'amount' => $vs['amount'] ?? null,
                        'currency' => $vs['currency'] ?? null,
                        'channel' => $vs['channel'] ?? null,
                        'updated_at' => now(),
                    ],
                );
                $stats['variant_shipping']['updated']++;
            }

            // ②c ★ 变体子体（独立表 listing_variants）—— 优先吃顶层 variants[]；
            //     兼容：老客户端仍把子体塞在 listings[] 里（is_parent=false），一并转存新表。
            $variantRows = array_values(array_filter(
                $payload['variants'] ?? [],
                fn ($v) => ! empty($v['sku']),
            ));
            if (empty($variantRows)) {
                $variantRows = array_values(array_filter(
                    $payload['listings'] ?? [],
                    fn ($l) => ! empty($l['sku']) && array_key_exists('is_parent', $l) && ! $l['is_parent'] && ! empty($l['parent_sku']),
                ));
            }
            foreach ($variantRows as $v) {
                $this->upsertVariant($r, $payload, $v, $stats, $pendingBlobs, $doMirror);
            }

            // ③ 上架
            foreach (($payload['listings'] ?? []) as $l) {
                $sku = (string) ($l['sku'] ?? '');
                if ($sku === '') { continue; }
                $mp = strtoupper((string) ($l['marketplace'] ?? 'A1F83G8C2ARO7P'));
                $accName = (string) ($l['account'] ?? 'HHY');
                $acc = Account::firstOrCreate(['name' => $accName], ['platform' => $l['platform'] ?? 'amazon', 'status' => 'active']);
                $prod = Product::where('code', (string) ($l['product_code'] ?? ''))->first();
                // 子体无 product_code → 继承父体的 product_id
                if ($prod === null && ! empty($l['parent_sku'])) {
                    $parent = Listing::where('account_id', $acc->id)->where('marketplace', $mp)->where('sku', $l['parent_sku'])->first();
                    if ($parent && $parent->product_id) { $prod = Product::find($parent->product_id); }
                }
                $design = ! empty($l['design_code']) ? Design::where('design_code', $l['design_code'])->first() : null;

                // 该 listing 的图片（显式 images 优先；否则取其设计的效果图）
                $imgs = [];
                if (! empty($l['images']) && is_array($l['images'])) {
                    $imgs = array_values(array_filter(array_map('trim', $l['images'])));
                } elseif ($design) {
                    if ($design->main_image) { $imgs[] = trim($design->main_image); }
                    foreach (array_filter(array_map('trim', explode('|', (string) $design->other_images))) as $u) { $imgs[] = $u; }
                }
                // ★ R9：图 URL 归一（已是 OSS 则不动；未 OSS → 计数回执，sync_images/normalize_images 时立即镜像）
                foreach ($imgs as $i => $u) { $imgs[$i] = $this->ossUrl((string) $u, $doMirror, $pendingBlobs); }

                $existing = Listing::where('account_id', $acc->id)->where('marketplace', $mp)->where('sku', $sku)->first();

                $row = [
                    'account_id' => $acc->id,
                    'platform' => $l['platform'] ?? 'amazon',
                    'marketplace' => $mp,
                    'product_id' => $prod?->id,
                    'design_id' => $design?->id,
                    'sku' => $sku,
                    'parent_sku' => $l['parent_sku'] ?? null,
                    'is_parent' => array_key_exists('is_parent', $l) ? (bool) $l['is_parent'] : empty($l['parent_sku']),
                    'parent_row_id' => $l['parent_row_id'] ?? null,
                    // ★ R1：父体本地 row_id（跨端稳定键；导出 listing_copy.row_id 用）
                    'local_row_id' => isset($l['local_row_id']) ? (int) $l['local_row_id'] : (isset($l['row_id']) ? (int) $l['row_id'] : null),
                    'variation_theme' => $l['variation_theme'] ?? null,
                    'variant_color' => $l['variant_color'] ?? null,
                    'variant_size' => $l['variant_size'] ?? null,
                    'variant_code' => $l['variant_code'] ?? null,
                    'amazon_product_type' => $l['amazon_product_type'] ?? null,
                    // R5：status 枚举已扩 planned；safeEnum 白名单同步
                    'status' => $this->safeEnum($l['status'] ?? null, ['draft', 'candidate', 'planned', 'ready', 'published', 'error', 'archived'], 'candidate'),
                    'price' => $l['price'] ?? null,
                    'product_price' => $l['product_price'] ?? null,
                    'shipping_fee' => $l['shipping_fee'] ?? null,
                    'currency' => $l['currency'] ?? null,
                    'quantity' => $l['quantity'] ?? null,
                    'asin' => $l['asin'] ?? null,
                    'is_custom' => (bool) ($l['is_custom'] ?? true),
                    'customization_json' => $l['customization_json'] ?? null,
                    'attrs_json' => $l['attrs_json'] ?? null,
                    // R2：关联键冗余（物理列，供跨表 join 与拉回还原）
                    'design_code' => $l['design_code'] ?? null,
                    'product_code' => $l['product_code'] ?? null,
                    // R2：刊登文案桶（26 个文案/属性字段，键名对齐本地 listing_copy.csv）
                    'copy_json' => $l['copy_json'] ?? null,
                    // R5：变体长尾桶（source/generated_at/notes 等）
                    'variant_json' => $l['variant_json'] ?? null,
                    'pushed_by' => $this->pushedBy($r, $payload),
                    'main_image' => $imgs[0] ?? null,
                    'other_images' => (count($imgs) > 1) ? implode('|', array_slice($imgs, 1)) : null,
                ];
                if ($prod === null) { unset($row['product_id']); }
                if ($design === null) { unset($row['design_id']); }

                // ★ R4：不拒写的告警 —— attrs_json 为空 / 缺该 PT 必填
                $pt = (string) ($row['amazon_product_type'] ?? '');
                if ($pt !== '') {
                    $attrs = is_array($row['attrs_json'] ?? null) ? $row['attrs_json'] : [];
                    $need = array_values(array_unique(array_merge(
                        (array) config('hub-pt-required._always', []),
                        (array) config('hub-pt-required.' . $pt, []),
                    )));
                    if (empty($attrs)) {
                        $warnings[] = '[WARN] ' . $sku . ' (' . $pt . ') attrs_json 为空：schema 模式上架将缺必填属性';
                    } else {
                        // ★ R12：key 归一化 —— 真源 attrs_json 是【路径格式】
                        //   （如 `unit_count[marketplace_id=A1F83G8C2ARO7P]#1.value`），
                        //   直接按裸属性名比对会误报“全缺”。取 `[`/`#`/`.` 之前的基名后小写比较。
                        $have = [];
                        foreach (array_keys($attrs) as $k) {
                            $base = preg_split('/[\[#\.]/', (string) $k);
                            $base = strtolower(trim((string) ($base[0] ?? '')));
                            if ($base !== '') { $have[$base] = true; }
                        }
                        $missing = array_values(array_filter($need, fn ($k) => ! isset($have[strtolower(trim((string) $k))])));
                        if ($missing) {
                            $warnings[] = '[WARN] ' . $sku . ' (' . $pt . ') 缺 ' . count($missing) . ' 项必填: ' . implode(', ', array_slice($missing, 0, 8));
                        }
                    }
                }

                if ($existing) {
                    // 乐观锁：带 revision 且过期 → 冲突，不写
                    if (isset($l['revision']) && (int) $l['revision'] !== (int) $existing->revision) {
                        $conflicts[] = ['key' => ['marketplace' => $mp, 'sku' => $sku], 'your_revision' => (int) $l['revision'], 'server_revision' => (int) $existing->revision];
                        continue;
                    }
                    $existing->fill($row);
                    $existing->revision = (int) $existing->revision + 1;
                    $existing->save();
                    $stats['listings']['updated']++;
                    $model = $existing;
                } else {
                    $row['revision'] = 1;
                    $model = Listing::create($row);
                    $stats['listings']['created']++;
                }

                // ★ 若显式要求（sync_images），推 listing 时把效果图一起镜像 OSS
                //   默认关（避免一次请求镜像过多图 → 网关超时）；图片走"分批"接口 /listings/oss-images
                if (! empty($payload['sync_images'])) {
                    $this->syncListingImages($l, $model, $design);
                }
            }
        });

        $job->update(['status' => 'done', 'stats_json' => $stats, 'finished_at' => now()]);

        return ['ok' => true, 'data' => $stats + [
            'conflicts' => $conflicts,
            'warnings' => $warnings,
            // ★ R9：未 OSS 化的图（下游可调 `/listings/oss-images` 分批镜像）
            'missing_blobs' => count($pendingBlobs),
            'blobs_pending' => array_slice($pendingBlobs, 0, 20),
        ]];
    }

    /**
     * 写入一条变体子体（独立表）。幂等键：local_row_id 优先 → 回落 (account_id, marketplace, sku)。
     */
    private function upsertVariant(Request $r, array $payload, array $v, array &$stats, array &$pending = [], bool $doMirror = false): void
    {
        $sku = (string) ($v['sku'] ?? '');
        if ($sku === '') { return; }
        $mp = strtoupper((string) ($v['marketplace'] ?? 'A1F83G8C2ARO7P'));
        $accName = (string) ($v['account'] ?? 'HHY');
        $acc = Account::firstOrCreate(['name' => $accName], ['platform' => $v['platform'] ?? 'amazon', 'status' => 'active']);

        // 父体 listing：优先按 parent_sku（同账号/站点）定位
        $parent = null;
        if (! empty($v['parent_sku'])) {
            $parent = Listing::where('account_id', $acc->id)->where('marketplace', $mp)->where('sku', $v['parent_sku'])->first();
        }
        // 兜底：用父体的 local_row_id（= parent_row_id）反查 listing（listing.local_row_id 由 push 写入 attrs? 不——用 parent_sku 为主，parent_row_id 仅留存）
        $prod = null;
        if (! empty($v['product_code'])) {
            $prod = Product::where('code', (string) $v['product_code'])->first();
        } elseif ($parent && $parent->product_id) {
            $prod = Product::find($parent->product_id);
        }
        $design = ! empty($v['design_code']) ? Design::where('design_code', $v['design_code'])->first() : null;

        // 图片（显式 images 优先；否则沿用传入的 main/other）
        $mainImg = $v['main_image'] ?? null;
        $otherImg = $v['other_images'] ?? null;
        if (! empty($v['images']) && is_array($v['images'])) {
            $imgs = array_values(array_filter(array_map('trim', $v['images'])));
            $mainImg = $imgs[0] ?? $mainImg;
            $otherImg = (count($imgs) > 1) ? implode('|', array_slice($imgs, 1)) : $otherImg;
        }
        // ★ R9：图 URL 归一 + 未 OSS 回执
        $mainImg = ($this->ossUrl((string) ($mainImg ?? ''), $doMirror, $pending)) ?: null;
        $otherImg = ($this->ossUrls((string) ($otherImg ?? ''), $doMirror, $pending)) ?: null;

        $localRowId = isset($v['local_row_id']) ? (int) $v['local_row_id'] : (isset($v['row_id']) ? (int) $v['row_id'] : null);
        // ★ R1：父体本地 row_id 兜底（客户端没带 parent_row_id 时，用父体已存的 local_row_id）
        $parentLocalRowId = isset($v['parent_row_id']) ? (int) $v['parent_row_id'] : ($parent?->local_row_id ? (int) $parent->local_row_id : null);

        $row = [
            'local_row_id' => $localRowId,
            'parent_local_row_id' => $parentLocalRowId,
            'parent_listing_id' => $parent?->id,
            'parent_sku' => $v['parent_sku'] ?? null,
            'account_id' => $acc->id,
            'platform' => $v['platform'] ?? 'amazon',
            'marketplace' => $mp,
            'product_code' => $v['product_code'] ?? ($parent?->product_code),
            'product_id' => $prod?->id,
            'sku' => $sku,
            'variation_theme' => $v['variation_theme'] ?? null,
            'variant_color' => $v['variant_color'] ?? null,
            'variant_size' => $v['variant_size'] ?? null,
            'variant_code' => $v['variant_code'] ?? null,
            'variant_value' => $v['variant_value'] ?? null,
            'design_code' => $v['design_code'] ?? null,
            'design_id' => $design?->id,
            'main_image' => $mainImg,
            'other_images' => $otherImg,
            'price' => $v['price'] ?? null,
            'product_price' => $v['product_price'] ?? null,
            'shipping_fee' => $v['shipping_fee'] ?? null,
            'currency' => $v['currency'] ?? null,
            'quantity' => $v['quantity'] ?? null,
            'pkg_length' => $v['pkg_length'] ?? null,
            'pkg_width' => $v['pkg_width'] ?? null,
            'pkg_height' => $v['pkg_height'] ?? null,
            'pkg_weight' => $v['pkg_weight'] ?? null,
            'pricing_json' => $v['pricing_json'] ?? null,
            'shipping_json' => $v['shipping_json'] ?? null,
            'status' => $this->safeEnum($v['status'] ?? null, ['draft', 'planned', 'candidate', 'ready', 'published', 'error', 'archived'], 'planned'),
            'source' => $v['source'] ?? null,
            'generated_at' => $v['generated_at'] ?? null,
            'edited_at' => $v['edited_at'] ?? null,
            'notes' => $v['notes'] ?? null,
            'pushed_by' => $this->pushedBy($r, $payload),
        ];

        // 定位已有行：跨端键 (account, marketplace, local_row_id) 优先 → 回落业务键 (account, marketplace, sku)
        $existing = null;
        if ($localRowId) {
            $existing = ListingVariant::where('account_id', $acc->id)
                ->where('marketplace', $mp)->where('local_row_id', $localRowId)->first();
        }
        if (! $existing) {
            $existing = ListingVariant::where('account_id', $acc->id)
                ->where('marketplace', $mp)->where('sku', $sku)->first();
        }

        if ($existing) {
            $existing->fill($row);
            $existing->revision = (int) $existing->revision + 1;
            $existing->save();
            $stats['variants']['updated']++;
        } else {
            $row['revision'] = 1;
            ListingVariant::create($row);
            $stats['variants']['created']++;
        }
    }

    public function pull(Request $r)
    {
        $scopes = array_filter(explode(',', (string) $r->query('scope', 'products,designs,listings')));
        $since = $r->query('since');
        $mp = $r->query('marketplace');
        $limit = (int) $r->query('limit', 0);                 // ★ R8：每数据集限量（0=不限，向后兼容）
        $inclDel = (bool) $r->query('include_deleted', false); // ★ R10：连软删行一起拉
        $cursor = now()->toIso8601String();

        if ($r->query('format') === 'csv') {
            return $this->pullCsvOne((string) $r->query('dataset', 'products'), $since, $mp);
        }

        $out = ['cursor' => $cursor];

        if (in_array('products', $scopes)) {
            $q = Product::query();
            if ($inclDel) { $q->withTrashed(); }
            if ($since) { $q->where('updated_at', '>=', $since); }
            $out['products'] = $q->get()->map(fn ($p) => [
                'code' => $p->code, 'spu_code' => $p->spu_code, 'is_custom' => $p->is_custom,
                'factory' => $p->factory,
                'cn_name' => $p->cn_name, 'en_name' => $p->en_name,
                'material_cn' => $p->material_cn, 'material_en' => $p->material_en,
                'print_face_w' => $p->print_face_w, 'print_face_h' => $p->print_face_h,
                'variants_count' => $p->variants_count, 'weight_g' => $p->weight_g,
                'volume_cm3' => $p->volume_cm3, 'design_face_count' => $p->design_face_count,
                'min_price' => $p->min_price, 'currency' => $p->currency, 'status' => $p->status,
                'detail_json' => $p->detail_json, 'profile_json' => $p->profile_json,
                'pushed_by' => $p->pushed_by,
                'updated_at' => $p->updated_at,
            ])->values();
        }
        if (in_array('designs', $scopes)) {
            $q = Design::query();
            if ($inclDel) { $q->withTrashed(); }
            if ($since) { $q->where('updated_at', '>=', $since); }
            $out['designs'] = $q->get()->map(fn ($d) => [
                'design_code' => $d->design_code, 'design_key' => $d->design_key, 'version' => $d->version,
                'parent_code' => $d->parent_code, 'source' => $d->source,
                'adjust_json' => $d->adjust_json,
                'cn_name' => $d->cn_name, 'en_name' => $d->en_name,
                'design_zh_name' => $d->design_zh_name, 'design_zh_tags' => $d->design_zh_tags,
                'design_en_name' => $d->design_en_name, 'design_en_tags' => $d->design_en_tags,
                'pattern' => $d->pattern, 'template' => $d->template,
                'gallery_codes' => $d->gallery_codes, 'effect_count' => $d->effect_count,
                'main_image' => $d->main_image, 'other_images' => $d->other_images,
                'status' => $d->status, 'notes' => $d->notes,
                'pushed_by' => $d->pushed_by,
                'updated_at' => $d->updated_at,
            ])->values();
        }
        if (in_array('listings', $scopes)) {
            $q = Listing::query();
            if ($inclDel) { $q->withTrashed(); }
            if ($since) { $q->where('updated_at', '>=', $since); }
            if ($mp) { $q->where('marketplace', strtoupper($mp)); }
            $out['listings'] = $q->get()->map(fn ($l) => [
                'sku' => $l->sku, 'marketplace' => $l->marketplace, 'status' => $l->status,
                'parent_sku' => $l->parent_sku, 'is_parent' => $l->is_parent,
                'variation_theme' => $l->variation_theme,
                'variant_color' => $l->variant_color, 'variant_size' => $l->variant_size,
                'variant_code' => $l->variant_code,
                'amazon_product_type' => $l->amazon_product_type,
                'price' => $l->price, 'product_price' => $l->product_price,
                'shipping_fee' => $l->shipping_fee, 'currency' => $l->currency,
                'quantity' => $l->quantity, 'asin' => $l->asin,
                'main_image' => $l->main_image, 'other_images' => $l->other_images,
                'attrs_json' => $l->attrs_json,
                'design_code' => $l->design_code, 'product_code' => $l->product_code,
                'parent_row_id' => $l->parent_row_id,
                // ★ R1：父体本地 row_id（跨端稳定键）+ 导出用 row_id
                'local_row_id' => $l->local_row_id,
                'row_id' => $l->local_row_id ?? $l->id,
                'copy_json' => $l->copy_json, 'variant_json' => $l->variant_json,
                // ★ 上架/下架状态（异地/ERP 拉回即可知是否已上架）
                'is_complete' => $l->is_complete, 'missing_fields' => $l->missing_fields,
                'published_at' => $l->published_at, 'first_published_at' => $l->first_published_at,
                'unpublished_at' => $l->unpublished_at, 'unpublish_reason' => $l->unpublish_reason,
                'last_action' => $l->last_action, 'last_action_by' => $l->last_action_by,
                'last_action_at' => $l->last_action_at,
                'pushed_by' => $l->pushed_by,
                'updated_at' => $l->updated_at,
            ])->values();
        }

        // ★ 变体子体（独立表）
        if (in_array('variants', $scopes)) {
            $q = ListingVariant::query();
            if ($inclDel) { $q->withTrashed(); }
            if ($since) { $q->where('updated_at', '>=', $since); }
            if ($mp) { $q->where('marketplace', strtoupper($mp)); }
            $parentLocalOf = Listing::whereNotNull('local_row_id')->pluck('local_row_id', 'id');   // ★ R1：listing.id → 父体本地 row_id
            $out['variants'] = $q->get()->map(function ($v) use ($parentLocalOf) {
                return [
                'local_row_id' => $v->local_row_id,
                'parent_local_row_id' => $v->parent_local_row_id,
                // ★ R1：parent_row_id 以父体真实本地 row_id 为准（回退推送机旧值）
                'parent_row_id' => $v->parent_listing_id ? ($parentLocalOf[$v->parent_listing_id] ?? $v->parent_local_row_id) : $v->parent_local_row_id,
                'parent_listing_id' => $v->parent_listing_id,
                'parent_sku' => $v->parent_sku,
                'marketplace' => $v->marketplace,
                'product_code' => $v->product_code,
                'sku' => $v->sku,
                'variation_theme' => $v->variation_theme,
                'variant_color' => $v->variant_color, 'variant_size' => $v->variant_size,
                'variant_code' => $v->variant_code, 'variant_value' => $v->variant_value,
                'design_code' => $v->design_code,
                'main_image' => $v->main_image, 'other_images' => $v->other_images,
                'price' => $v->price, 'product_price' => $v->product_price,
                'shipping_fee' => $v->shipping_fee, 'currency' => $v->currency, 'quantity' => $v->quantity,
                'pkg_length' => $v->pkg_length, 'pkg_width' => $v->pkg_width,
                'pkg_height' => $v->pkg_height, 'pkg_weight' => $v->pkg_weight,
                'pricing_json' => $v->pricing_json, 'shipping_json' => $v->shipping_json,
                'status' => $v->status, 'source' => $v->source,
                'generated_at' => $v->generated_at, 'edited_at' => $v->edited_at, 'notes' => $v->notes,
                'pushed_by' => $v->pushed_by,
                'updated_at' => $v->updated_at,
                ];
            })->values();
        }

        // ★ 商品规格（指纹规格：颜色×尺寸 + 包装/重量）
        if (in_array('product_variants', $scopes)) {
            $q = ProductVariant::query();
            if ($since) { $q->where('updated_at', '>=', $since); }
            $codeOf = Product::pluck('code', 'id');
            $out['product_variants'] = $q->get()->map(fn ($v) => [
                'product_code' => $codeOf[$v->product_id] ?? null,
                'external_variant_id' => $v->external_variant_id,
                // ★ R3b：规格名（尺寸/颜色）——子体 pkg 兜底 derive 的匹配键
                'size_name' => $v->size_name, 'color_name' => $v->color_name,
                'color' => $v->color, 'size' => $v->size,
                'spec_json' => $v->spec_json,
                'weight_g' => $v->weight_g,
                'size_l_cm' => $v->size_l_cm, 'size_w_cm' => $v->size_w_cm, 'size_h_cm' => $v->size_h_cm,
                'pkg_l_cm' => $v->pkg_l_cm, 'pkg_w_cm' => $v->pkg_w_cm, 'pkg_h_cm' => $v->pkg_h_cm,
                'volume_cm3' => $v->volume_cm3, 'status' => $v->status,
                'updated_at' => $v->updated_at,
            ])->values();
        }

        // ★ 商品运费（商品级 + 变体级；含 country/amount/channel）
        if (in_array('product_shipping', $scopes)) {
            $q = ProductShipping::query();
            if ($since) { $q->where('updated_at', '>=', $since); }
            $codeOf = Product::pluck('code', 'id');
            $extOf = ProductVariant::pluck('external_variant_id', 'id');
            $pvProductOf = ProductVariant::pluck('product_id', 'id');   // ★ R2：product_variant_id → product_id
            $out['product_shipping'] = $q->get()->map(fn ($s) => [
                // ★ R2：变体级行 product_id 为空 → 经 product_variant_id 反查 product_code
                'product_code' => $s->product_id
                    ? ($codeOf[$s->product_id] ?? null)
                    : ($s->product_variant_id ? ($codeOf[$pvProductOf[$s->product_variant_id] ?? 0] ?? null) : null),
                'external_variant_id' => $s->product_variant_id ? ($extOf[$s->product_variant_id] ?? null) : null,
                'country' => $s->country, 'amount' => $s->amount,
                'currency' => $s->currency, 'channel' => $s->channel,
                'updated_at' => $s->updated_at,
            ])->values();
        }

        // ★ R8：每数据集限量（默认 0=不限，向后兼容）
        if ($limit > 0) {
            foreach (['products', 'designs', 'listings', 'variants', 'product_variants', 'product_shipping'] as $k) {
                if (isset($out[$k]) && $out[$k] instanceof \Illuminate\Support\Collection) { $out[$k] = $out[$k]->take($limit)->values(); }
            }
        }

        return ['ok' => true, 'data' => $out];
    }

    /** 推送 listing 时同步其效果图：镜像 OSS + 挂为该 listing 资产；并把设计图指向 OSS */
    private function syncListingImages(array $payload, Listing $listing, ?Design $design): void
    {
        $urls = [];
        if (! empty($payload['images']) && is_array($payload['images'])) {
            $urls = array_values(array_filter(array_map('trim', $payload['images'])));
        } elseif ($design) {
            if ($design->main_image) { $urls[] = trim($design->main_image); }
            foreach (array_filter(array_map('trim', explode('|', (string) $design->other_images))) as $u) { $urls[] = $u; }
        }
        if (! $urls) { return; }

        $media = app(\App\Domain\Asset\Services\MediaService::class);
        $oss = [];
        foreach ($urls as $i => $u) {
            if ($u === '') { continue; }
            $role = $i === 0 ? 'main' : ('other_' . $i);
            try {
                $res = $media->mirror($u);
                $media->attach(['owner_type' => 'listing', 'owner_id' => $listing->id, 'role' => $role, 'blob_id' => $res['blob']->id]);
                $oss[$i] = $res['blob']->public_url;
            } catch (\Throwable $e) { /* 单张失败不影响整体 */ }
        }
        if ($design && $oss) {
            if (isset($oss[0])) { $design->main_image = $oss[0]; }
            $rest = array_slice($oss, 1);
            if ($rest) { $design->other_images = implode('|', $rest); }
            $design->save();
        }
    }

    private function counts(): array
    {
        return ['created' => 0, 'updated' => 0, 'skipped' => 0];
    }

    /** 推送者标识（按用户要求留痕）：machine_id + 登录账号 email */
    private function pushedBy(\Illuminate\Http\Request $r, array $payload): ?string
    {
        $machine = $payload['machine_id'] ?? null;
        $email = optional($r->user())->email;
        $parts = array_values(array_filter([$machine, $email]));

        return $parts ? implode('@', $parts) : null;
    }

    // ============ CSV 包导出（列对齐现有 database/*.csv，落地即用） ============

    private const H_PRODUCTS = ['id','spu_code','cn_name','en_name','alias','factory','material','material_en','technology','release_time','is_custom','default_color_id','default_color_name','default_size_id','default_size_name','variant_id','variant_code','variants_count','colors','sizes','size_L_cm','size_W_cm','size_H_cm','package_L_cm','package_W_cm','package_H_cm','volume_cm3','weight_g','design_face_w','design_face_h','design_face_count','min_price','qty_from','qty_to','retail_price','gold_price','platinum_price','diamond_price','black_diamond_price','star_diamond_price','shipping_US','shipping_UK','shipping_CA','shipping_DE','shipping_MX','shipping_FR','shipping_ES','shipping_IT','shipping_channel_US','shipping_channel_UK','shipping_channel_CA','shipping_channel_DE','shipping_channel_MX','shipping_channel_FR','shipping_channel_ES','shipping_channel_IT','freight_template_US','freight_template_UK','freight_template_CA','freight_template_DE','freight_template_MX','freight_template_FR','freight_template_ES','freight_template_IT','shipping_updated_at','shipping_variant','price_US','price_UK','price_CA','price_DE','price_MX','price_FR','price_ES','price_IT','price_currency','rate_note','status','notes','created_at','updated_at'];
    private const H_LISTING_COPY = ['id','design_code','marketplace','product_type','sku','item_name','highlight','bullet_1','bullet_2','bullet_3','bullet_4','bullet_5','product_description','generic_keyword','material','fabric_type','color','size','capacity','capacity_unit','model_number','model_name','handling_time','country_of_origin','price','currency','template','amazon_template','status','source','generated_at','edited_at','updated_by','review_notes','notes','shipping_fee','product_price','attrs_json','row_id'];
    private const H_VARIANTS = ['row_id','parent_row_id','id','marketplace','sku','parent_sku','variation_theme','variant_value','variant_code','variant_color','variant_size','design_code','main_image','other_images','price','product_price','shipping_fee','quantity','pkg_length','pkg_width','pkg_height','pkg_weight','status','source','generated_at','edited_at','notes'];
    private const H_DESIGNS = ['design_code','product_id','design_key','version','parent_code','source','adjust','cn_name','en_name','design_zh_name','design_zh_tags','design_en_name','design_en_tags','design_pattern','design_template','gallery_codes','effect_image_count','main_image','other_images','status','notes','created_at','updated_at'];
    private const H_PRODUCT_VARIANTS = ['product_code','external_variant_id','color','size','spec_json','weight_g','size_l_cm','size_w_cm','size_h_cm','pkg_l_cm','pkg_w_cm','pkg_h_cm','volume_cm3','status','updated_at'];
    private const H_PRODUCT_SHIPPING = ['product_code','external_variant_id','country','amount','currency','channel','updated_at'];

    private function pullCsvOne(string $dataset, ?string $since, ?string $mp)
    {
        $codeOf = Product::pluck('code', 'id');           // product_id -> code
        $designCode = Design::pluck('design_code', 'id');  // design_id -> design_code

        switch ($dataset) {
            case 'products':
                $q = Product::query(); if ($since) { $q->where('updated_at', '>=', $since); }
                $rows = $q->get()->map(function ($p) {
                    // R3：长尾字段还原自 profile_json 桶（键名对齐本地 products.csv 列名）
                    $prof = is_array($p->profile_json) ? $p->profile_json : [];
                    $g = fn ($col) => $prof[$col] ?? '';

                    return [
                        'id' => $p->code, 'spu_code' => $p->spu_code, 'alias' => $g('alias'),
                        'factory' => $p->factory,
                        'cn_name' => $p->cn_name, 'en_name' => $p->en_name,
                        'material' => $p->material_cn, 'material_en' => $p->material_en,
                        'technology' => $g('technology'), 'release_time' => $g('release_time'),
                        'is_custom' => $p->is_custom === null ? '' : ($p->is_custom ? '1' : '0'),
                        'default_color_id' => $g('default_color_id'), 'default_color_name' => $g('default_color_name'),
                        'default_size_id' => $g('default_size_id'), 'default_size_name' => $g('default_size_name'),
                        'variant_id' => $g('variant_id'), 'variant_code' => $g('variant_code'),
                        'variants_count' => $p->variants_count, 'colors' => $g('colors'), 'sizes' => $g('sizes'),
                        'size_L_cm' => $g('size_L_cm'), 'size_W_cm' => $g('size_W_cm'), 'size_H_cm' => $g('size_H_cm'),
                        'package_L_cm' => $g('package_L_cm'), 'package_W_cm' => $g('package_W_cm'), 'package_H_cm' => $g('package_H_cm'),
                        'volume_cm3' => $p->volume_cm3, 'weight_g' => $p->weight_g,
                        'design_face_w' => $p->print_face_w, 'design_face_h' => $p->print_face_h,
                        'design_face_count' => $p->design_face_count,
                        'min_price' => $p->min_price,
                        'qty_from' => $g('qty_from'), 'qty_to' => $g('qty_to'),
                        'retail_price' => $g('retail_price'), 'gold_price' => $g('gold_price'),
                        'platinum_price' => $g('platinum_price'), 'diamond_price' => $g('diamond_price'),
                        'black_diamond_price' => $g('black_diamond_price'), 'star_diamond_price' => $g('star_diamond_price'),
                        'shipping_US' => $p->shipping_US ?? $g('shipping_US'), 'shipping_UK' => $p->shipping_UK ?? $g('shipping_UK'),
                        'shipping_CA' => $p->shipping_CA ?? $g('shipping_CA'), 'shipping_DE' => $p->shipping_DE ?? $g('shipping_DE'),
                        'shipping_MX' => $p->shipping_MX ?? $g('shipping_MX'), 'shipping_FR' => $p->shipping_FR ?? $g('shipping_FR'),
                        'shipping_ES' => $p->shipping_ES ?? $g('shipping_ES'), 'shipping_IT' => $p->shipping_IT ?? $g('shipping_IT'),
                        'shipping_channel_US' => $g('shipping_channel_US'), 'shipping_channel_UK' => $g('shipping_channel_UK'),
                        'shipping_channel_CA' => $g('shipping_channel_CA'), 'shipping_channel_DE' => $g('shipping_channel_DE'),
                        'shipping_channel_MX' => $g('shipping_channel_MX'), 'shipping_channel_FR' => $g('shipping_channel_FR'),
                        'shipping_channel_ES' => $g('shipping_channel_ES'), 'shipping_channel_IT' => $g('shipping_channel_IT'),
                        'freight_template_US' => $g('freight_template_US'), 'freight_template_UK' => $g('freight_template_UK'),
                        'freight_template_CA' => $g('freight_template_CA'), 'freight_template_DE' => $g('freight_template_DE'),
                        'freight_template_MX' => $g('freight_template_MX'), 'freight_template_FR' => $g('freight_template_FR'),
                        'freight_template_ES' => $g('freight_template_ES'), 'freight_template_IT' => $g('freight_template_IT'),
                        'shipping_updated_at' => $p->shipping_updated_at ?? $g('shipping_updated_at'),
                        'shipping_variant' => $p->shipping_variant ?? $g('shipping_variant'),   // ★ R7：变体级运费标记（此前导出丢失）
                        'shipping_variant' => $p->shipping_variant ?? $g('shipping_variant'),
                        'price_US' => $p->price_US ?? $g('price_US'), 'price_UK' => $p->price_UK ?? $g('price_UK'),
                        'price_CA' => $p->price_CA ?? $g('price_CA'), 'price_DE' => $p->price_DE ?? $g('price_DE'),
                        'price_MX' => $p->price_MX ?? $g('price_MX'), 'price_FR' => $p->price_FR ?? $g('price_FR'),
                        'price_ES' => $p->price_ES ?? $g('price_ES'), 'price_IT' => $p->price_IT ?? $g('price_IT'),
                        'price_currency' => $p->currency, 'rate_note' => $g('rate_note'),
                        'status' => $p->status, 'notes' => $g('notes'),
                        'created_at' => $p->created_at, 'updated_at' => $p->updated_at,
                    ];
                })->all();

                return $this->csvResponse('products.csv', self::H_PRODUCTS, $rows);

            case 'listing_copy':
                $q = Listing::where('is_parent', true); if ($since) { $q->where('updated_at', '>=', $since); } if ($mp) { $q->where('marketplace', strtoupper($mp)); }
                $rows = $q->get()->map(function ($l) use ($codeOf, $designCode) {
                    // ★ R2 修复：文案 26 列还原自 copy_json 桶（键名对齐本地 listing_copy.csv 列名）
                    //   此前该 case 完全没读桶 → CSV 导出文案全空（JSON 通道有值）
                    $copy = is_array($l->copy_json) ? $l->copy_json : [];
                    $c = fn ($col) => $copy[$col] ?? '';

                    return [
                        'id' => $l->product_code ?: ($codeOf[$l->product_id] ?? null),
                        'design_code' => $l->design_code ?: ($designCode[$l->design_id] ?? null),
                        'marketplace' => $l->marketplace, 'product_type' => $l->amazon_product_type, 'sku' => $l->sku,
                        // ── 文案桶还原（26 列）──
                        'item_name' => $c('item_name'), 'highlight' => $c('highlight'),
                        'bullet_1' => $c('bullet_1'), 'bullet_2' => $c('bullet_2'), 'bullet_3' => $c('bullet_3'),
                        'bullet_4' => $c('bullet_4'), 'bullet_5' => $c('bullet_5'),
                        'product_description' => $c('product_description'), 'generic_keyword' => $c('generic_keyword'),
                        'material' => $c('material'), 'fabric_type' => $c('fabric_type'),
                        'color' => $c('color'), 'size' => $c('size'),
                        'capacity' => $c('capacity'), 'capacity_unit' => $c('capacity_unit'),
                        'model_number' => $c('model_number'), 'model_name' => $c('model_name'),
                        'handling_time' => $c('handling_time'), 'country_of_origin' => $c('country_of_origin'),
                        'template' => $c('template'), 'amazon_template' => $c('amazon_template'),
                        'source' => $c('source'), 'generated_at' => $c('generated_at'),
                        'updated_by' => $c('updated_by'), 'review_notes' => $c('review_notes'), 'notes' => $c('notes'),
                        // ── 物理列 ──
                        'price' => $l->price, 'currency' => $l->currency, 'status' => $l->status,
                        'shipping_fee' => $l->shipping_fee, 'product_price' => $l->product_price,
                        'attrs_json' => $l->attrs_json ? json_encode($l->attrs_json, JSON_UNESCAPED_UNICODE) : '',
                        'row_id' => $l->local_row_id ?? $l->id,   // ★ R1：优先父体本地 row_id（回退中台 id）
                        'edited_at' => ($c('edited_at') !== '' ? $c('edited_at') : $l->updated_at),   // ★ R6：优先真实 edited_at
                    ];
                })->all();

                return $this->csvResponse('listing_copy.csv', self::H_LISTING_COPY, $rows);

            case 'listing_variants':
                $q = ListingVariant::query(); if ($since) { $q->where('updated_at', '>=', $since); } if ($mp) { $q->where('marketplace', strtoupper($mp)); }
                $parentLocalOf = Listing::whereNotNull('local_row_id')->pluck('local_row_id', 'id');        // ★ R1
                $pvByProductCode = $this->specsByProductCode();                                            // ★ R3b
                $rows = $q->get()->map(function ($l) use ($codeOf, $designCode, $parentLocalOf, $pvByProductCode) {
                    // 列对齐本地 listing_variants.csv（含 pkg_* + 逐规格价）
                    $pkg = [$l->pkg_length, $l->pkg_width, $l->pkg_height, $l->pkg_weight];
                    if ($pkg[0] === null && $pkg[1] === null && $pkg[2] === null && $pkg[3] === null) {
                        $pkg = $this->deriveVariantPkg($l, $pvByProductCode);   // ★ R3b 兜底：由规格层 derive
                    }

                    return [
                        'row_id' => $l->local_row_id,
                        'parent_row_id' => $l->parent_listing_id ? ($parentLocalOf[$l->parent_listing_id] ?? $l->parent_local_row_id) : $l->parent_local_row_id,   // ★ R1
                        'id' => $l->product_code ?: ($codeOf[$l->product_id] ?? null),
                        'marketplace' => $l->marketplace,
                        'sku' => $l->sku, 'parent_sku' => $l->parent_sku, 'variation_theme' => $l->variation_theme,
                        'variant_value' => $l->variant_value ?: trim(($l->variant_color ?? '') . ' ' . ($l->variant_size ?? '')),
                        'variant_code' => $l->variant_code,
                        'variant_color' => $l->variant_color, 'variant_size' => $l->variant_size,
                        'design_code' => $l->design_code ?: ($designCode[$l->design_id] ?? null),
                        'main_image' => $l->main_image, 'other_images' => $l->other_images,
                        'price' => $l->price, 'product_price' => $l->product_price, 'shipping_fee' => $l->shipping_fee,
                        'quantity' => $l->quantity,
                        'pkg_length' => $pkg[0], 'pkg_width' => $pkg[1],
                        'pkg_height' => $pkg[2], 'pkg_weight' => $pkg[3],
                        'status' => $l->status,
                        'source' => $l->source, 'generated_at' => $l->generated_at,
                        'edited_at' => $l->edited_at ?: $l->updated_at, 'notes' => $l->notes,
                    ];
                })->all();

                return $this->csvResponse('listing_variants.csv', self::H_VARIANTS, $rows);

            case 'designs':
                $q = Design::query(); if ($since) { $q->where('updated_at', '>=', $since); }
                $rows = $q->get()->map(fn ($d) => [
                    'design_code' => $d->design_code, 'product_id' => $codeOf[$d->product_id] ?? null,
                    'design_key' => $d->design_key, 'version' => $d->version, 'parent_code' => $d->parent_code,
                    'source' => $d->source,
                    // R4：adjust 此前 push 未写 → 此处读出为 CSV 的 adjust 列
                    'adjust' => $d->adjust_json ? (is_string($d->adjust_json) ? $d->adjust_json : json_encode($d->adjust_json, JSON_UNESCAPED_UNICODE)) : '',
                    'cn_name' => $d->cn_name, 'en_name' => $d->en_name,
                    'design_zh_name' => $d->design_zh_name, 'design_zh_tags' => $d->design_zh_tags,
                    'design_en_name' => $d->design_en_name, 'design_en_tags' => $d->design_en_tags,
                    'design_pattern' => $d->pattern, 'design_template' => $d->template,
                    'gallery_codes' => $d->gallery_codes, 'effect_image_count' => $d->effect_count,
                    // ★ R1 修复：图列此前在表头里但从未赋值 → CSV 导出恒为空（JSON 通道有值）
                    'main_image' => $d->main_image, 'other_images' => $d->other_images,
                    'status' => $d->status, 'notes' => $d->notes,
                    'created_at' => $d->created_at, 'updated_at' => $d->updated_at,
                ])->all();

                return $this->csvResponse('designs.csv', self::H_DESIGNS, $rows);

            case 'product_variants':
                $q = ProductVariant::query(); if ($since) { $q->where('updated_at', '>=', $since); }
                $rows = $q->get()->map(fn ($v) => [
                    'product_code' => $codeOf[$v->product_id] ?? null,
                    'external_variant_id' => $v->external_variant_id,
                    'color' => $v->color, 'size' => $v->size,
                    'spec_json' => $v->spec_json ? (is_string($v->spec_json) ? $v->spec_json : json_encode($v->spec_json, JSON_UNESCAPED_UNICODE)) : '',
                    'weight_g' => $v->weight_g,
                    'size_l_cm' => $v->size_l_cm, 'size_w_cm' => $v->size_w_cm, 'size_h_cm' => $v->size_h_cm,
                    'pkg_l_cm' => $v->pkg_l_cm, 'pkg_w_cm' => $v->pkg_w_cm, 'pkg_h_cm' => $v->pkg_h_cm,
                    'volume_cm3' => $v->volume_cm3, 'status' => $v->status,
                    'updated_at' => $v->updated_at,
                ])->all();

                return $this->csvResponse('product_variants.csv', self::H_PRODUCT_VARIANTS, $rows);

            case 'product_shipping':
                $q = ProductShipping::query(); if ($since) { $q->where('updated_at', '>=', $since); }
                $extOf = ProductVariant::pluck('external_variant_id', 'id');
                $pvProductOf = ProductVariant::pluck('product_id', 'id');   // ★ R2
                $rows = $q->get()->map(fn ($s) => [
                    // ★ R2：变体级行 product_id 为空 → 经 product_variant_id 反查 product_code
                    'product_code' => $s->product_id
                        ? ($codeOf[$s->product_id] ?? null)
                        : ($s->product_variant_id ? ($codeOf[$pvProductOf[$s->product_variant_id] ?? 0] ?? null) : null),
                    'external_variant_id' => $s->product_variant_id ? ($extOf[$s->product_variant_id] ?? null) : null,
                    'country' => $s->country, 'amount' => $s->amount,
                    'currency' => $s->currency, 'channel' => $s->channel,
                    'updated_at' => $s->updated_at,
                ])->all();

                return $this->csvResponse('product_shipping.csv', self::H_PRODUCT_SHIPPING, $rows);

            case 'manifest':
                return response()->json(['ok' => true, 'data' => [
                    'generated_at' => now()->toIso8601String(),
                    'counts' => ['products' => Product::count(), 'designs' => Design::count(), 'listings' => Listing::count()],
                ]]);

            default:
                return response()->json(['ok' => false, 'error' => ['code' => 'bad_dataset', 'message' => 'dataset ∈ products|listing_copy|listing_variants|designs|product_variants|product_shipping|manifest']], 400);
        }
    }

    private function csvResponse(string $filename, array $header, array $rows)
    {
        $csv = "\u{FEFF}" . $this->csv($header, $rows);

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    private function csv(array $header, array $rows): string
    {
        $fh = fopen('php://temp', 'r+');
        fputcsv($fh, $header);
        foreach ($rows as $r) {
            $line = [];
            foreach ($header as $h) { $line[] = isset($r[$h]) && $r[$h] !== null ? (string) $r[$h] : ''; }
            fputcsv($fh, $line);
        }
        rewind($fh);
        $s = stream_get_contents($fh);
        fclose($fh);

        return $s;
    }

    /** ★ R9：单图 URL 归一（已是 OSS 则原样返回；未 OSS → 计数回执，doMirror 时立即镜像） */
    private function ossUrl(string $u, bool $doMirror, array &$pending): string
    {
        $u = trim($u);
        if ($u === '' || ! preg_match('#^https?://#i', $u)) { return $u; }
        if (preg_match('#oss-cn-|aliyuncs\.com#i', $u)) { return $u; }
        if ($doMirror) {
            try {
                $res = app(\App\Domain\Asset\Services\MediaService::class)->mirror($u);
                if (! empty($res['blob']->public_url)) { return $res['blob']->public_url; }
            } catch (\Throwable $e) { /* 落回：计数 + 原样 */ }
        }
        if (count($pending) < 20) { $pending[] = $u; }

        return $u;
    }

    /** ★ R9：多图（`|` 分隔）归一 */
    private function ossUrls(string $urls, bool $doMirror, array &$pending): string
    {
        $list = array_values(array_filter(array_map('trim', explode('|', $urls))));
        $out = [];
        foreach ($list as $u) { $out[] = $this->ossUrl($u, $doMirror, $pending); }

        return implode('|', $out);
    }

    /** 把外部状态映射到本系统合法枚举，未知→默认（避免 MySQL Data truncated） */
    private function safeEnum(?string $val, array $allowed, string $default): string
    {
        $v = strtolower(trim((string) $val));

        return in_array($v, $allowed, true) ? $v : $default;
    }

    /** ★ R3b：规格层按 product_code 分组（供子体 pkg 兜底 derive） */
    private function specsByProductCode(): array
    {
        $codeOf = Product::pluck('code', 'id');
        $out = [];
        foreach (ProductVariant::all() as $v) {
            $code = (string) ($codeOf[$v->product_id] ?? '');
            if ($code === '') { continue; }
            $out[$code][] = [
                'size_name' => $v->size_name, 'color_name' => $v->color_name,
                'pkg_l_cm' => $v->pkg_l_cm ?? $v->size_l_cm,
                'pkg_w_cm' => $v->pkg_w_cm ?? $v->size_w_cm,
                'pkg_h_cm' => $v->pkg_h_cm ?? $v->size_h_cm,
                'weight_g' => $v->weight_g,
            ];
        }

        return $out;
    }

    /**
     * ★ R3b：子体自身 pkg_* 为空时，由规格层 derive（拉取即用的阅读路径兜底；不落库）。
     * 匹配：variant_size ↔ product_variants.size_name（归一化后相等，颜色也相等则优先）；
     *       单规格商品直接取唯一规格。
     */
    private function deriveVariantPkg($v, array $pvByProductCode): array
    {
        $specs = $pvByProductCode[(string) ($v->product_code ?? '')] ?? [];
        if (! $specs) { return [null, null, null, null]; }
        $norm = fn ($s) => preg_replace('/[^a-z0-9]/', '', strtolower((string) $s));
        $wantSize = $norm($v->variant_size);
        $wantColor = $norm($v->variant_color);
        $hit = null;
        foreach ($specs as $s) {
            $sName = $norm($s['size_name'] ?? '');
            $cName = $norm($s['color_name'] ?? '');
            if ($wantSize !== '' && $sName !== '' && $sName === $wantSize && ($wantColor === '' || $cName === '' || $cName === $wantColor)) { $hit = $s; break; }
        }
        if ($hit === null && count($specs) === 1) { $hit = $specs[0]; }
        if ($hit === null) { return [null, null, null, null]; }

        return [$hit['pkg_l_cm'], $hit['pkg_w_cm'], $hit['pkg_h_cm'], $hit['weight_g']];
    }
}
