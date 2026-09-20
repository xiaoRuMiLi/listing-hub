<?php

namespace App\Http\Controllers\Api;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductShipping;
use App\Domain\Catalog\Models\ProductSupplier;
use App\Domain\Catalog\Models\Supplier;
use App\Domain\Design\Models\Design;
use App\Domain\Identity\Models\Account;
use App\Domain\Listing\Models\Listing;
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

        $stats = ['products' => $this->counts(), 'designs' => $this->counts(), 'listings' => $this->counts(), 'product_shipping' => $this->counts()];
        $conflicts = [];

        DB::transaction(function () use ($payload, &$stats, &$conflicts) {
            // ① 商品
            foreach (($payload['products'] ?? []) as $p) {
                $code = (string) ($p['code'] ?? '');
                if ($code === '') { continue; }
                $existing = Product::where('code', $code)->first();
                $attrs = [
                    'code' => $code,
                    'cn_name' => $p['cn_name'] ?? null,
                    'en_name' => $p['en_name'] ?? null,
                    'material_cn' => $p['material_cn'] ?? null,
                    'material_en' => $p['material_en'] ?? null,
                    'print_face_w' => $p['print_face_w'] ?? null,
                    'print_face_h' => $p['print_face_h'] ?? null,
                    'min_price' => $p['min_price'] ?? null,
                    'currency' => $p['currency'] ?? null,
                    'status' => $this->safeEnum($p['status'] ?? null, ['draft', 'ready', 'synced', 'archived'], 'synced'),
                    'detail_json' => $p['detail_json'] ?? null,
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
                    'main_image' => $d['main_image'] ?? null,
                    'other_images' => $d['other_images'] ?? null,
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
                    'variation_theme' => $l['variation_theme'] ?? null,
                    'variant_color' => $l['variant_color'] ?? null,
                    'variant_size' => $l['variant_size'] ?? null,
                    'amazon_product_type' => $l['amazon_product_type'] ?? null,
                    'status' => $this->safeEnum($l['status'] ?? null, ['draft', 'candidate', 'ready', 'published', 'error', 'archived'], 'candidate'),
                    'price' => $l['price'] ?? null,
                    'product_price' => $l['product_price'] ?? null,
                    'shipping_fee' => $l['shipping_fee'] ?? null,
                    'currency' => $l['currency'] ?? null,
                    'quantity' => $l['quantity'] ?? null,
                    'asin' => $l['asin'] ?? null,
                    'is_custom' => (bool) ($l['is_custom'] ?? true),
                    'customization_json' => $l['customization_json'] ?? null,
                    'attrs_json' => $l['attrs_json'] ?? null,
                ];
                if ($prod === null) { unset($row['product_id']); }
                if ($design === null) { unset($row['design_id']); }

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

        return ['ok' => true, 'data' => $stats + ['conflicts' => $conflicts]];
    }

    public function pull(Request $r)
    {
        $scopes = array_filter(explode(',', (string) $r->query('scope', 'products,designs,listings')));
        $since = $r->query('since');
        $mp = $r->query('marketplace');
        $cursor = now()->toIso8601String();

        if ($r->query('format') === 'csv') {
            return $this->pullCsvOne((string) $r->query('dataset', 'products'), $since, $mp);
        }

        $out = ['cursor' => $cursor];

        if (in_array('products', $scopes)) {
            $q = Product::query();
            if ($since) { $q->where('updated_at', '>=', $since); }
            $out['products'] = $q->get()->map(fn ($p) => [
                'code' => $p->code, 'cn_name' => $p->cn_name, 'en_name' => $p->en_name,
                'material_cn' => $p->material_cn, 'material_en' => $p->material_en,
                'print_face_w' => $p->print_face_w, 'print_face_h' => $p->print_face_h,
                'min_price' => $p->min_price, 'currency' => $p->currency, 'status' => $p->status,
                'updated_at' => $p->updated_at,
            ])->values();
        }
        if (in_array('designs', $scopes)) {
            $q = Design::query();
            if ($since) { $q->where('updated_at', '>=', $since); }
            $out['designs'] = $q->get()->map(fn ($d) => [
                'design_code' => $d->design_code, 'design_key' => $d->design_key, 'version' => $d->version,
                'pattern' => $d->pattern, 'template' => $d->template, 'status' => $d->status,
                'updated_at' => $d->updated_at,
            ])->values();
        }
        if (in_array('listings', $scopes)) {
            $q = Listing::query();
            if ($since) { $q->where('updated_at', '>=', $since); }
            if ($mp) { $q->where('marketplace', strtoupper($mp)); }
            $out['listings'] = $q->get()->map(fn ($l) => [
                'sku' => $l->sku, 'marketplace' => $l->marketplace, 'status' => $l->status,
                'parent_sku' => $l->parent_sku, 'is_parent' => $l->is_parent,
                'price' => $l->price, 'currency' => $l->currency, 'asin' => $l->asin,
                'attrs_json' => $l->attrs_json, 'updated_at' => $l->updated_at,
            ])->values();
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

    // ============ CSV 包导出（列对齐现有 database/*.csv，落地即用） ============

    private const H_PRODUCTS = ['id','spu_code','cn_name','en_name','alias','factory','material','material_en','technology','release_time','is_custom','default_color_id','default_color_name','default_size_id','default_size_name','variant_id','variant_code','variants_count','colors','sizes','size_L_cm','size_W_cm','size_H_cm','package_L_cm','package_W_cm','package_H_cm','volume_cm3','weight_g','design_face_w','design_face_h','design_face_count','min_price','qty_from','qty_to','retail_price','gold_price','platinum_price','diamond_price','black_diamond_price','star_diamond_price','shipping_US','shipping_UK','shipping_CA','shipping_DE','shipping_MX','shipping_FR','shipping_ES','shipping_IT','shipping_channel_US','shipping_channel_UK','shipping_channel_CA','shipping_channel_DE','shipping_channel_MX','shipping_channel_FR','shipping_channel_ES','shipping_channel_IT','freight_template_US','freight_template_UK','freight_template_CA','freight_template_DE','freight_template_MX','freight_template_FR','freight_template_ES','freight_template_IT','shipping_updated_at','price_US','price_UK','price_CA','price_DE','price_MX','price_FR','price_ES','price_IT','price_currency','rate_note','status','notes','created_at','updated_at'];
    private const H_LISTING_COPY = ['id','design_code','marketplace','product_type','sku','item_name','highlight','bullet_1','bullet_2','bullet_3','bullet_4','bullet_5','product_description','generic_keyword','material','fabric_type','color','size','capacity','capacity_unit','model_number','model_name','handling_time','country_of_origin','price','currency','template','amazon_template','status','source','generated_at','edited_at','updated_by','review_notes','notes','shipping_fee','product_price','attrs_json','row_id'];
    private const H_VARIANTS = ['row_id','parent_row_id','id','marketplace','sku','parent_sku','variation_theme','variant_value','variant_code','variant_color','variant_size','design_code','main_image','other_images','price','product_price','shipping_fee','quantity','status','source','generated_at','edited_at','notes'];
    private const H_DESIGNS = ['design_code','product_id','design_key','version','parent_code','source','adjust','cn_name','en_name','design_zh_name','design_zh_tags','design_en_name','design_en_tags','design_pattern','design_template','gallery_codes','effect_image_count','main_image','other_images','status','notes','created_at','updated_at'];

    private function pullCsvOne(string $dataset, ?string $since, ?string $mp)
    {
        $codeOf = Product::pluck('code', 'id');           // product_id -> code
        $designCode = Design::pluck('design_code', 'id');  // design_id -> design_code

        switch ($dataset) {
            case 'products':
                $q = Product::query(); if ($since) { $q->where('updated_at', '>=', $since); }
                $rows = $q->get()->map(fn ($p) => [
                    'id' => $p->code, 'cn_name' => $p->cn_name, 'en_name' => $p->en_name,
                    'material' => $p->material_cn, 'material_en' => $p->material_en,
                    'min_price' => $p->min_price, 'price_currency' => $p->currency,
                    'status' => $p->status, 'design_face_w' => $p->print_face_w, 'design_face_h' => $p->print_face_h,
                    'created_at' => $p->created_at, 'updated_at' => $p->updated_at,
                ])->all();

                return $this->csvResponse('products.csv', self::H_PRODUCTS, $rows);

            case 'listing_copy':
                $q = Listing::where('is_parent', true); if ($since) { $q->where('updated_at', '>=', $since); } if ($mp) { $q->where('marketplace', strtoupper($mp)); }
                $rows = $q->get()->map(fn ($l) => [
                    'id' => $codeOf[$l->product_id] ?? null, 'design_code' => $designCode[$l->design_id] ?? null,
                    'marketplace' => $l->marketplace, 'product_type' => $l->amazon_product_type, 'sku' => $l->sku,
                    'price' => $l->price, 'currency' => $l->currency, 'status' => $l->status,
                    'shipping_fee' => $l->shipping_fee, 'product_price' => $l->product_price,
                    'attrs_json' => $l->attrs_json ? json_encode($l->attrs_json, JSON_UNESCAPED_UNICODE) : '',
                    'row_id' => $l->id, 'edited_at' => $l->updated_at,
                ])->all();

                return $this->csvResponse('listing_copy.csv', self::H_LISTING_COPY, $rows);

            case 'listing_variants':
                $q = Listing::where('is_parent', false); if ($since) { $q->where('updated_at', '>=', $since); } if ($mp) { $q->where('marketplace', strtoupper($mp)); }
                $rows = $q->get()->map(fn ($l) => [
                    'row_id' => $l->id, 'id' => $codeOf[$l->product_id] ?? null, 'marketplace' => $l->marketplace,
                    'sku' => $l->sku, 'parent_sku' => $l->parent_sku, 'variation_theme' => $l->variation_theme,
                    'variant_value' => trim(($l->variant_color ?? '') . ' ' . ($l->variant_size ?? '')),
                    'variant_color' => $l->variant_color, 'variant_size' => $l->variant_size,
                    'design_code' => $designCode[$l->design_id] ?? null,
                    'price' => $l->price, 'product_price' => $l->product_price, 'shipping_fee' => $l->shipping_fee,
                    'quantity' => $l->quantity, 'status' => $l->status, 'edited_at' => $l->updated_at,
                ])->all();

                return $this->csvResponse('listing_variants.csv', self::H_VARIANTS, $rows);

            case 'designs':
                $q = Design::query(); if ($since) { $q->where('updated_at', '>=', $since); }
                $rows = $q->get()->map(fn ($d) => [
                    'design_code' => $d->design_code, 'product_id' => $codeOf[$d->product_id] ?? null,
                    'design_key' => $d->design_key, 'version' => $d->version, 'parent_code' => $d->parent_code,
                    'source' => $d->source, 'cn_name' => $d->cn_name, 'en_name' => $d->en_name,
                    'design_pattern' => $d->pattern, 'design_template' => $d->template,
                    'gallery_codes' => $d->gallery_codes, 'effect_image_count' => $d->effect_count,
                    'status' => $d->status, 'created_at' => $d->created_at, 'updated_at' => $d->updated_at,
                ])->all();

                return $this->csvResponse('designs.csv', self::H_DESIGNS, $rows);

            case 'manifest':
                return response()->json(['ok' => true, 'data' => [
                    'generated_at' => now()->toIso8601String(),
                    'counts' => ['products' => Product::count(), 'designs' => Design::count(), 'listings' => Listing::count()],
                ]]);

            default:
                return response()->json(['ok' => false, 'error' => ['code' => 'bad_dataset', 'message' => 'dataset ∈ products|listing_copy|listing_variants|designs|manifest']], 400);
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

    /** 把外部状态映射到本系统合法枚举，未知→默认（避免 MySQL Data truncated） */
    private function safeEnum(?string $val, array $allowed, string $default): string
    {
        $v = strtolower(trim((string) $val));

        return in_array($v, $allowed, true) ? $v : $default;
    }
}
