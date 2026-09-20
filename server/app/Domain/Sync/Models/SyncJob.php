<?php

namespace App\Domain\Sync\Models;

use Illuminate\Database\Eloquent\Model;

class SyncJob extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'stats_json' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];
}
