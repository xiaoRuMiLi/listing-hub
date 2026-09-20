<?php

namespace App\Domain\Asset\Models;

use Illuminate\Database\Eloquent\Model;

class Template extends Model
{
    protected $guarded = [];

    protected $casts = [
        'spec_json' => 'array',
    ];

    public function blob()
    {
        return $this->belongsTo(Blob::class);
    }
}
