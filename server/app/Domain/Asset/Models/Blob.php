<?php

namespace App\Domain\Asset\Models;

use Illuminate\Database\Eloquent\Model;

class Blob extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function sources()
    {
        return $this->hasMany(BlobSource::class);
    }

    public function assets()
    {
        return $this->hasMany(Asset::class);
    }
}
