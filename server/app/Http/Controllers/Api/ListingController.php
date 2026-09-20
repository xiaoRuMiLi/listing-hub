<?php

namespace App\Http\Controllers\Api;

use App\Domain\Design\Models\Design;
use App\Domain\Identity\Models\Account;
use App\Domain\Listing\Models\Listing;
use App\Domain\Listing\Models\ListingRevision;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ListingController extends Controller
{
    public function index(Request $r)
    {
        $q = Listing::query();

        foreach (['platform', 'marketplace', 'status', 'product_id', 'sku', 'parent_sku'] as $f) {
            if ($v = $r->query($f)) {
                $q->where($f, $v);
            }
        }
        if ($acc = $r->query('account')) {
            $q->whereIn('account_id', Account::where('name', $acc)->pluck('id'));
        }
        if ($since = $r->query('updated_since')) {
            $q->where('updated_at', '>=', $since);
        }
        if ($r->boolean('parents_only')) {
            $q->where('is_parent', true);
        }

        $per = min((int) $r->query('per_page', (int) config('sync.default_page_size', 50)), 200);
        $p = $q->orderByDesc('updated_at')->paginate($per);

        return ['ok' => true, 'data' => $p->items(),
            'meta' => ['page' => $p->currentPage(), 'per_page' => $p->perPage(), 'total' => $p->total(), 'pages' => $p->lastPage()]];
    }

    public function show(Request $r, $id)
    {
        $l = Listing::query()->findOrFail($id);
        if ($r->boolean('with_assets')) {
            $l->load('assets.blob');
        }
        if ($r->boolean('with_revisions')) {
            $l->load('revisions');
        }
        if ($r->boolean('with_children')) {
            $l->load('children');
        }

        return ['ok' => true, 'data' => $l];
    }

    public function store(Request $r)
    {
        $data = $this->validatePayload($r, true);
        $data['account_id'] = $this->resolveAccountId($data);
        $author = $r->user()?->email ?? 'system';

        $l = DB::transaction(function () use ($data, $author) {
            $l = Listing::create($data);
            $l->revision = 1;
            $l->save();
            $this->snapshot($l, 'created', $author);

            return $l;
        });

        return ['ok' => true, 'data' => $l];
    }

    public function update(Request $r, $id)
    {
        $l = Listing::findOrFail($id);

        // 乐观锁：若带 revision 且过期 → 409
        if ($r->filled('revision') && (int) $r->input('revision') !== (int) $l->revision) {
            return response()->json(['ok' => false, 'error' => [
                'code' => 'conflict', 'message' => 'revision 过期',
                'latest' => ['id' => $l->id, 'revision' => $l->revision, 'updated_at' => $l->updated_at],
            ]], 409);
        }

        $patch = $r->only([
            'design_id', 'parent_sku', 'is_parent', 'variation_theme', 'variant_color', 'variant_size',
            'amazon_product_type', 'status', 'price', 'product_price', 'shipping_fee', 'currency', 'quantity',
            'asin', 'is_custom', 'customization_json', 'attrs_json', 'is_complete', 'missing_fields',
        ]);

        DB::transaction(function () use ($l, $patch, $r) {
            $l->fill($patch);
            $l->revision = (int) $l->revision + 1;
            $l->save();
            $this->snapshot($l, 'updated', $r->user()?->email ?? 'system');
        });

        return ['ok' => true, 'data' => $l->fresh()];
    }

    public function destroy($id)
    {
        Listing::findOrFail($id)->delete();

        return ['ok' => true];
    }

    /** ★ 本地发布后回写发布结果 */
    public function publishResult(Request $r, $id)
    {
        $l = Listing::findOrFail($id);
        $data = $r->validate([
            'status' => 'nullable|in:draft,candidate,ready,published,error,archived',
            'asin' => 'nullable|string|max:16',
            'published_at' => 'nullable|date',
            'issues' => 'nullable|array',
        ]);

        $l->status = $data['status'] ?? 'published';
        if (! empty($data['asin'])) {
            $l->asin = $data['asin'];
        }
        $when = isset($data['published_at']) ? \Illuminate\Support\Carbon::parse($data['published_at']) : now();
        $l->published_at = $when;
        $l->first_published_at = $l->first_published_at ?? $when;
        if (isset($data['issues'])) {
            $attrs = $l->attrs_json ?? [];
            $attrs['_publish_issues'] = $data['issues'];
            $l->attrs_json = $attrs;
        }
        $l->save();
        $this->snapshot($l, 'published', $r->user()?->email ?? 'system');

        return ['ok' => true, 'data' => $l->fresh()];
    }

    /** ★ 分批把 listing 的效果图推到 OSS（避免一次请求镜像过多图导致网关超时） */
    public function ossImages(Request $r)
    {
        $limit = max(1, min(10, (int) $r->input('limit', 3)));
        $media = app(\App\Domain\Asset\Services\MediaService::class);

        $pendingBase = fn () => Listing::whereNotNull('design_id')
            ->whereHas('design', fn ($q) => $q->whereNotNull('main_image')->where('main_image', '!=', ''))
            ->whereDoesntHave('assets', fn ($w) => $w->where('role', 'main'));

        $rows = $pendingBase()->orderBy('id')->limit($limit)->get();

        $processed = [];
        foreach ($rows as $l) {
            $design = Design::find($l->design_id);
            if (! $design) { continue; }
            $urls = [];
            if ($design->main_image) { $urls[] = trim($design->main_image); }
            foreach (array_filter(array_map('trim', explode('|', (string) $design->other_images))) as $u) { $urls[] = $u; }
            if (! $urls) { continue; }
            $oss = [];
            foreach ($urls as $i => $u) {
                $role = $i === 0 ? 'main' : ('other_' . $i);
                try {
                    $res = $media->mirror($u);
                    $media->attach(['owner_type' => 'listing', 'owner_id' => $l->id, 'role' => $role, 'blob_id' => $res['blob']->id]);
                    $oss[$i] = $res['blob']->public_url;
                } catch (\Throwable $e) { /* 单张失败不影响 */ }
            }
            if ($oss) {
                if (isset($oss[0])) { $design->main_image = $oss[0]; }
                $rest = array_slice($oss, 1);
                if ($rest) { $design->other_images = implode('|', $rest); }
                $design->save();
            }
            $processed[] = $l->sku;
        }

        return ['ok' => true, 'data' => ['processed' => $processed, 'remaining' => $pendingBase()->count()]];
    }

    public function children($id)
    {
        $l = Listing::findOrFail($id);

        return ['ok' => true, 'data' => $l->children()->get()];
    }

    /** ★ 图片 URL 归一：把 attrs_json 里的指纹图片字段 → 镜像 OSS → 改写为自有 URL */
    public function normalizeImages(Request $r, $id)
    {
        $l = Listing::findOrFail($id);
        $attrs = $l->attrs_json ?? [];
        $media = app(\App\Domain\Asset\Services\MediaService::class);
        $allowed = (array) config('media.allowed_hosts', []);

        $map = ['main_product_image_locator' => 'main'];
        for ($i = 1; $i <= 8; $i++) { $map['other_product_image_locator_' . $i] = 'other_' . $i; }

        $changed = [];
        foreach ($map as $key => $role) {
            $val = $attrs[$key] ?? null;
            if (! is_string($val) || $val === '') { continue; }
            $host = parse_url($val, PHP_URL_HOST) ?: '';
            if ($host && in_array($host, $allowed, true)) { continue; }   // 已是我们域名 → 跳过（幂等）
            try {
                $res = $media->mirror($val);
                $attrs[$key] = $res['blob']->public_url;
                $media->attach(['owner_type' => 'listing', 'owner_id' => $l->id, 'role' => $role, 'blob_id' => $res['blob']->id]);
                $changed[$key] = $res['blob']->public_url;
            } catch (\Throwable $e) {
                $changed[$key] = 'ERR: ' . $e->getMessage();
            }
        }
        if ($changed) { $l->attrs_json = $attrs; $l->save(); }

        return ['ok' => true, 'data' => ['id' => $l->id, 'sku' => $l->sku, 'changed' => $changed]];
    }

    public function revisions($id)
    {
        return ['ok' => true, 'data' => ListingRevision::where('listing_id', $id)->orderByDesc('revision')->get()];
    }

    // ---- helpers ----
    private function validatePayload(Request $r, bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return $r->validate([
            'account' => $creating ? 'required_without:account_id|string' : 'sometimes|string',
            'account_id' => $creating ? 'required_without:account|integer|exists:accounts,id' : 'sometimes|integer|exists:accounts,id',
            'platform' => 'sometimes|string|max:24',
            'marketplace' => "$req|string|max:16",
            'product_id' => "$req|integer|exists:products,id",
            'design_id' => 'nullable|integer|exists:designs,id',
            'sku' => "$req|string|max:64",
            'parent_sku' => 'nullable|string|max:64',
            'is_parent' => 'nullable|boolean',
            'variation_theme' => 'nullable|string|max:24',
            'variant_color' => 'nullable|string|max:32',
            'variant_size' => 'nullable|string|max:16',
            'amazon_product_type' => 'nullable|string|max:40',
            'status' => 'nullable|in:draft,candidate,ready,published,error,archived',
            'price' => 'nullable|numeric',
            'product_price' => 'nullable|numeric',
            'shipping_fee' => 'nullable|numeric',
            'currency' => 'nullable|string|size:3',
            'quantity' => 'nullable|integer',
            'asin' => 'nullable|string|max:16',
            'is_custom' => 'nullable|boolean',
            'customization_json' => 'nullable|array',
            'attrs_json' => 'nullable|array',
        ]);
    }

    private function resolveAccountId(array &$data): int
    {
        if (! empty($data['account_id'])) {
            unset($data['account']);

            return (int) $data['account_id'];
        }
        $id = Account::where('name', $data['account'])->value('id');
        unset($data['account']);

        return (int) $id;
    }

    private function snapshot(Listing $l, string $source, string $author): void
    {
        ListingRevision::create([
            'listing_id' => $l->id,
            'revision' => $l->revision,
            'snapshot_json' => $l->toArray(),
            'changed_fields' => $l->getChanges(),
            'source' => $source,
            'author' => $author,
            'created_at' => now(),
        ]);
    }
}
