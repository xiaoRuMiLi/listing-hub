<?php

namespace App\Domain\Asset\Models;

use Illuminate\Database\Eloquent\Model;

class BlobSource extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'fetched_at' => 'datetime',
    ];

    public function blob()
    {
        return $this->belongsTo(Blob::class);
    }
}
