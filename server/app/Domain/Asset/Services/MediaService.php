<?php

namespace App\Domain\Asset\Services;

use App\Domain\Asset\Models\Asset;
use App\Domain\Asset\Models\Blob;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * 媒体服务：内容寻址（sha256）去重 + 存对象存储 + 稳定 URL。
 * 对应设计：DATABASE.md §11/§12、API.md §6。
 */
class MediaService
{
    public function disk(): string
    {
        return (string) config('media.disk', 'oss');
    }

    public function sha256(string $bytes): string
    {
        return hash('sha256', $bytes);
    }

    /** 内容寻址 key：blobs/<sha前2>/<sha>.<ext> */
    public function keyFor(string $sha, string $ext = ''): string
    {
        $prefix = trim((string) config('media.path_prefix', 'blobs'), '/');

        return $prefix . '/' . substr($sha, 0, 2) . '/' . $sha . ($ext ? ('.' . $ext) : '');
    }

    public function extFor(string $mime): string
    {
        return match (strtolower($mime)) {
            'image/jpeg', 'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            'video/mp4' => 'mp4',
            'video/quicktime' => 'mov',
            'video/webm' => 'webm',
            default => 'bin',
        };
    }

    /** ★ 2026-10-16：已知的媒体 mime（image/* 与 video/* 的白名单） */
    public function isMediaMime(?string $mime): bool
    {
        return in_array(strtolower(trim(explode(';', (string) $mime)[0])), [
            'image/jpeg', 'image/jpg', 'image/png', 'image/webp', 'image/gif',
            'video/mp4', 'video/quicktime', 'video/webm',
        ], true);
    }

    /** ★ 2026-10-16：按【文件头】嗅探真实类型（客户端不带/带错 mime 时的兜底） */
    public function sniffMime(string $bytes): ?string
    {
        if (strlen($bytes) < 12) {
            return null;
        }
        $b = $bytes;
        if (ord($b[0]) === 0xFF && ord($b[1]) === 0xD8) {
            return 'image/jpeg';
        }
        if (substr($b, 0, 8) === "\x89PNG\r\n\x1a\n") {
            return 'image/png';
        }
        if (substr($b, 0, 3) === 'GIF') {
            return 'image/gif';
        }
        if (substr($b, 0, 4) === 'RIFF' && substr($b, 8, 4) === 'WEBP') {
            return 'image/webp';
        }
        if (substr($b, 4, 4) === 'ftyp' && strpos(substr($b, 8, 16), 'mp4') !== false) {
            return 'video/mp4';
        }

        return null;
    }

    /** ★ 2026-10-16：归一 mime —— 客户端给的不是已知媒体类型时，按文件头纠正 */
    public function resolveMime(string $mime, string $bytes): string
    {
        $m = strtolower(trim(explode(';', $mime)[0]));
        if ($this->isMediaMime($m)) {
            return $m === 'image/jpg' ? 'image/jpeg' : $m;
        }
        $sniffed = $this->sniffMime($bytes);

        return $sniffed ?: ($m !== '' ? $m : 'application/octet-stream');
    }

    /** 公网稳定 URL（读时渲染；换域名只改配置） */
    public function publicUrl(string $key): string
    {
        $base = rtrim((string) config('media.public_base_url', ''), '/');
        if ($base !== '') {
            return $base . '/' . ltrim($key, '/');
        }

        return Storage::disk($this->disk())->url($key);
    }

    public function findBlob(string $sha): ?Blob
    {
        return Blob::where('sha256', $sha)->first();
    }

    /** 已存在的 sha256 列表（秒传用） */
    public function existingHashes(array $hashes): array
    {
        if (! $hashes) {
            return [];
        }

        return Blob::whereIn('sha256', $hashes)->pluck('sha256')->all();
    }

    /**
     * 存字节 → 内容寻址去重 → 落 blob。
     * @return array{blob:Blob,deduped:bool}
     */
    public function storeBytes(string $bytes, string $mime = 'application/octet-stream', string $sourceType = 'local', ?string $originUrl = null): array
    {
        if ($bytes === '') {
            throw new RuntimeException('空内容');
        }
        // ★ 2026-10-16：先按文件头纠正 mime，避免 octet-stream → .bin（图片变“下载”而不是图片）
        $mime = $this->resolveMime($mime, $bytes);
        $sha = $this->sha256($bytes);
        $existing = $this->findBlob($sha);
        if ($existing) {
            // ★ 内容已在库里，但旧 blob 的类型是“未知”（.bin）而这次拿到的是真图片类型 → 升级重建
            if (! $this->isMediaMime($existing->mime) && $this->isMediaMime($mime)) {
                $upKey = $this->keyFor($sha, $this->extFor($mime));
                Storage::disk($this->disk())->put($upKey, $bytes);
                $existing->update([
                    'storage_key' => $upKey,
                    'mime' => $mime,
                    'public_url' => $this->publicUrl($upKey),
                ]);

                return ['blob' => $existing->fresh(), 'deduped' => false];
            }

            return ['blob' => $existing, 'deduped' => true];
        }

        $ext = $this->extFor($mime);
        $key = $this->keyFor($sha, $ext);
        Storage::disk($this->disk())->put($key, $bytes);

        $blob = Blob::create([
            'sha256' => $sha,
            'storage_disk' => $this->disk(),
            'storage_key' => $key,
            'mime' => $mime,
            'size_bytes' => strlen($bytes),
            'public_url' => $this->publicUrl($key),
            'source_type' => $sourceType,
            'origin_url' => $originUrl,
            'ref_count' => 0,
            'created_at' => now(),
        ]);

        if ($originUrl) {
            \App\Domain\Asset\Models\BlobSource::firstOrCreate(
                ['url_hash' => hash('sha256', $this->normalizeUrl($originUrl))],
                ['blob_id' => $blob->id, 'url' => $originUrl, 'fetched_at' => now()],
            );
        }

        return ['blob' => $blob, 'deduped' => false];
    }

    /** 远端链接镜像（服务端拉取一次 → 去重 → 存 OSS） */
    public function mirror(string $url): array
    {
        $norm = $this->normalizeUrl($url);

        // 命中来源映射 → 免下载
        $src = \App\Domain\Asset\Models\BlobSource::where('url_hash', hash('sha256', $norm))->first();
        if ($src && $src->blob) {
            return ['blob' => $src->blob, 'deduped' => true];
        }

        $resp = Http::timeout(60)->get($url);
        if (! $resp->successful()) {
            throw new RuntimeException('镜像失败 HTTP ' . $resp->status() . ': ' . $url);
        }
        $mime = $resp->header('Content-Type') ?: 'application/octet-stream';
        $mime = trim(explode(';', $mime)[0]);

        $r = $this->storeBytes($resp->body(), $mime, 'remote', $url);
        // 保证来源映射存在（若 storeBytes 因内容已存在未写 source）
        \App\Domain\Asset\Models\BlobSource::firstOrCreate(
            ['url_hash' => hash('sha256', $norm)],
            ['blob_id' => $r['blob']->id, 'url' => $url, 'fetched_at' => now()],
        );
        // 内容已存在时也补 origin（留痕）
        if ($r['deduped'] && ! $r['blob']->origin_url) {
            $r['blob']->update(['origin_url' => $url, 'source_type' => $r['blob']->source_type ?: 'remote']);
        }

        return $r;
    }

    /** 绑定资产（owner ↔ blob，多态 + 角色去重） */
    public function attach(array $a): Asset
    {
        $asset = Asset::updateOrCreate(
            [
                'owner_type' => $a['owner_type'],
                'owner_id' => $a['owner_id'],
                'role' => $a['role'],
                'position' => $a['position'] ?? null,
            ],
            [
                'blob_id' => $a['blob_id'],
                'media_type' => $a['media_type'] ?? 'image',
                'status' => $a['status'] ?? 'uploaded',
                'duration_ms' => $a['duration_ms'] ?? null,
            ],
        );
        $this->refreshRefCount($asset->blob_id);

        return $asset;
    }

    public function refreshRefCount(int $blobId): void
    {
        $n = Asset::where('blob_id', $blobId)->count();
        Blob::where('id', $blobId)->update(['ref_count' => $n]);
    }

    private function normalizeUrl(string $url): string
    {
        // 去掉 query（指纹 CDN 图名不可变，query 多为占位）
        return strtolower(explode('?', trim($url))[0]);
    }

    /** 上架前回源校验（HEAD → status=verified/failed） */
    public function verifyAsset(Asset $asset): Asset
    {
        $url = $asset->blob?->public_url;
        $ok = false;
        if ($url) {
            try {
                $ok = Http::timeout(15)->head($url)->successful();
            } catch (\Throwable $e) {
                $ok = false;
            }
        }
        $asset->update(['status' => $ok ? 'verified' : 'failed']);

        return $asset;
    }
}
