<?php

namespace App\Http\Controllers\Api;

use App\Domain\Catalog\Models\Product;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function index(Request $r)
    {
        $q = Product::query()->with(['sources.supplier', 'categories']);

        if ($s = $r->query('status')) {
            $q->where('status', $s);
        }
        if ($kw = $r->query('q')) {
            $q->where(function ($w) use ($kw) {
                $w->where('cn_name', 'like', "%{$kw}%")
                    ->orWhere('en_name', 'like', "%{$kw}%")
                    ->orWhere('code', 'like', "%{$kw}%");
            });
        }
        if ($since = $r->query('updated_since')) {
            $q->where('updated_at', '>=', $since);
        }

        $per = min((int) $r->query('per_page', (int) config('sync.default_page_size', 50)), 200);
        $p = $q->orderByDesc('updated_at')->paginate($per);

        return [
            'ok' => true,
            'data' => $p->items(),
            'meta' => ['page' => $p->currentPage(), 'per_page' => $p->perPage(), 'total' => $p->total(), 'pages' => $p->lastPage()],
        ];
    }

    public function show($id)
    {
        $p = Product::with(['sources.supplier', 'categories', 'variants.shipping', 'designs', 'shipping'])->findOrFail($id);

        $data = $p->toArray();

        // ★ R5：categories 为空时，从该商品 listings 的 amazon_product_type 推导（只读派生，不落库）
        if (empty($data['categories'])) {
            $pts = \App\Domain\Listing\Models\Listing::where('product_id', $p->id)
                ->whereNotNull('amazon_product_type')
                ->pluck('amazon_product_type')->unique()->values();
            $data['categories_derived'] = $pts->map(fn ($c) => ['platform' => 'amazon', 'code' => $c, 'is_primary' => true])->all();
        }

        return ['ok' => true, 'data' => $data];
    }

    public function store(Request $r)
    {
        $data = $r->validate([
            'code' => 'nullable|string|max:64',
            'cn_name' => 'nullable|string|max:255',
            'en_name' => 'nullable|string|max:255',
            'material_cn' => 'nullable|string|max:64',
            'material_en' => 'nullable|string|max:64',
            'print_face_w' => 'nullable|numeric',
            'print_face_h' => 'nullable|numeric',
            'min_price' => 'nullable|numeric',
            'currency' => 'nullable|string|size:3',
            'status' => 'nullable|in:draft,ready,synced,archived',
            'detail_json' => 'nullable|array',
        ]);
        $p = Product::create($data);

        return ['ok' => true, 'data' => $p];
    }

    public function update(Request $r, $id)
    {
        $p = Product::findOrFail($id);
        $p->update($r->only([
            'code', 'cn_name', 'en_name', 'material_cn', 'material_en',
            'print_face_w', 'print_face_h', 'min_price', 'currency', 'status', 'detail_json',
        ]));

        return ['ok' => true, 'data' => $p->fresh()];
    }

    public function destroy($id)
    {
        Product::findOrFail($id)->delete();

        return ['ok' => true];
    }
}
