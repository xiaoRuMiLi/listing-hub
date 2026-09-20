<?php

namespace App\Domain\Asset\Models;

use Illuminate\Database\Eloquent\Model;

class Asset extends Model
{
    protected $guarded = [];

    public function blob()
    {
        return $this->belongsTo(Blob::class);
    }

    /** 多态主体（product/design/listing） */
    public function owner()
    {
        return $this->morphTo();
    }
}
