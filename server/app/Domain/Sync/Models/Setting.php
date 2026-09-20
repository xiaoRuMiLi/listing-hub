<?php

namespace App\Domain\Sync\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    public $timestamps = false;

    protected $table = 'settings';

    protected $guarded = [];

    protected $casts = [
        'value_json' => 'array',
        'updated_at' => 'datetime',
    ];

    /** 取值：settings 表 > config 默认 > 兜底 */
    public static function get(string $group, string $key, $default = null)
    {
        $row = static::query()->where('group', $group)->where('key', $key)->first();
        return $row ? $row->value_json : $default;
    }
}
