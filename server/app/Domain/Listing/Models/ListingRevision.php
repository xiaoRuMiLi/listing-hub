<?php

namespace App\Domain\Listing\Models;

use Illuminate\Database\Eloquent\Model;

class ListingRevision extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'snapshot_json' => 'array',
        'changed_fields' => 'array',
        'created_at' => 'datetime',
    ];

    public function listing()
    {
        return $this->belongsTo(Listing::class);
    }
}
