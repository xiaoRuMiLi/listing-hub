<?php

namespace App\Http\Controllers\Api;

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

    public function children($id)
    {
        $l = Listing::findOrFail($id);

        return ['ok' => true, 'data' => $l->children()->get()];
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
