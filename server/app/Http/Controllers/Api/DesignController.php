<?php

namespace App\Http\Controllers\Api;

use App\Domain\Design\Models\Design;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class DesignController extends Controller
{
    public function index(Request $r)
    {
        $q = Design::query()->with('product:id,code,cn_name,en_name');

        if ($pid = $r->query('product_id')) {
            $q->where('product_id', $pid);
        }
        if ($k = $r->query('design_key')) {
            $q->where('design_key', $k);
        }
        if ($s = $r->query('status')) {
            $q->where('status', $s);
        }

        $per = min((int) $r->query('per_page', 50), 200);
        $p = $q->orderByDesc('updated_at')->paginate($per);

        return ['ok' => true, 'data' => $p->items(),
            'meta' => ['page' => $p->currentPage(), 'per_page' => $p->perPage(), 'total' => $p->total(), 'pages' => $p->lastPage()]];
    }

    public function show($id)
    {
        return ['ok' => true, 'data' => Design::with(['product:id,code,cn_name', 'listings:id,sku,status'])->findOrFail($id)];
    }

    public function store(Request $r)
    {
        $data = $r->validate([
            'design_code' => 'required|string|max:32|unique:designs,design_code',
            'product_id' => 'required|integer|exists:products,id',
            'design_key' => 'nullable|string|max:64',
            'version' => 'nullable|string|max:16',
            'parent_code' => 'nullable|string|max:32',
            'source' => 'nullable|string|max:24',
            'adjust_json' => 'nullable|array',
            'cn_name' => 'nullable|string|max:255',
            'en_name' => 'nullable|string|max:255',
            'pattern' => 'nullable|string|max:512',
            'template' => 'nullable|string|max:64',
            'gallery_codes' => 'nullable|string|max:255',
            'effect_count' => 'nullable|integer',
            'status' => 'nullable|in:draft,active,superseded,archived',
        ]);
        $d = Design::create($data);

        return ['ok' => true, 'data' => $d];
    }

    public function update(Request $r, $id)
    {
        $d = Design::findOrFail($id);
        $d->update($r->only([
            'design_key', 'version', 'parent_code', 'source', 'adjust_json',
            'cn_name', 'en_name', 'design_zh_name', 'design_zh_tags', 'design_en_name', 'design_en_tags',
            'pattern', 'template', 'gallery_codes', 'effect_count', 'status', 'notes',
        ]));

        return ['ok' => true, 'data' => $d->fresh()];
    }

    public function destroy($id)
    {
        Design::findOrFail($id)->delete();

        return ['ok' => true];
    }
}
