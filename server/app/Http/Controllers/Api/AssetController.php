<?php

namespace App\Http\Controllers\Api;

use App\Domain\Asset\Models\Asset;
use App\Domain\Asset\Services\MediaService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class AssetController extends Controller
{
    public function __construct(private MediaService $media) {}

    /** 秒传/去重：先问哪些内容服务端已有 */
    public function check(Request $r)
    {
        $data = $r->validate(['items' => 'required|array', 'items.*.sha256' => 'required|string|size:64']);
        $hashes = array_column($data['items'], 'sha256');
        $exists = $this->media->existingHashes($hashes);
        $missing = array_values(array_diff($hashes, $exists));

        return ['ok' => true, 'data' => ['exists' => $exists, 'missing' => $missing]];
    }

    /** 服务端上传（multipart；不依赖 OSS 预签名，任何磁盘都可用） */
    public function upload(Request $r)
    {
        $data = $r->validate([
            'file' => 'required|file|max:512000',
            'owner_type' => 'required|string|in:product,design,listing',
            'owner_id' => 'required|integer',
            'role' => 'required|string|max:32',
            'position' => 'nullable|integer',
            'media_type' => 'nullable|in:image,video',
        ]);

        $file = $r->file('file');
        $bytes = file_get_contents($file->getRealPath());
        $mime = $file->getClientMimeType() ?: 'application/octet-stream';

        $res = $this->media->storeBytes($bytes, $mime, 'local');
        $blob = $res['blob'];

        $asset = $this->media->attach([
            'owner_type' => $data['owner_type'],
            'owner_id' => $data['owner_id'],
            'role' => $data['role'],
            'position' => $data['position'] ?? null,
            'blob_id' => $blob->id,
            'media_type' => $data['media_type'] ?? 'image',
        ]);

        return ['ok' => true, 'data' => [
            'asset_id' => $asset->id,
            'blob_id' => $blob->id,
            'sha256' => $blob->sha256,
            'public_url' => $blob->public_url,
            'deduped' => $res['deduped'],
        ]];
    }

    /** 远端链接镜像（服务端拉取 → 去重 → 存 OSS） */
    public function mirror(Request $r)
    {
        $data = $r->validate([
            'url' => 'required|url',
            'owner_type' => 'nullable|string|in:product,design,listing',
            'owner_id' => 'nullable|integer',
            'role' => 'nullable|string|max:32',
            'position' => 'nullable|integer',
            'media_type' => 'nullable|in:image,video',
        ]);

        $res = $this->media->mirror($data['url']);
        $blob = $res['blob'];

        $asset = null;
        if (! empty($data['owner_type']) && ! empty($data['owner_id']) && ! empty($data['role'])) {
            $asset = $this->media->attach([
                'owner_type' => $data['owner_type'],
                'owner_id' => $data['owner_id'],
                'role' => $data['role'],
                'position' => $data['position'] ?? null,
                'blob_id' => $blob->id,
                'media_type' => $data['media_type'] ?? 'image',
            ]);
        }

        return ['ok' => true, 'data' => [
            'blob_id' => $blob->id,
            'asset_id' => $asset?->id,
            'sha256' => $blob->sha256,
            'public_url' => $blob->public_url,
            'deduped' => $res['deduped'],
        ]];
    }

    public function show($id)
    {
        return ['ok' => true, 'data' => Asset::with('blob')->findOrFail($id)];
    }

    public function destroy($id)
    {
        $a = Asset::findOrFail($id);
        $bid = $a->blob_id;
        $a->delete();
        $this->media->refreshRefCount($bid);

        return ['ok' => true];
    }
}
