<?php

namespace App\Domain\Identity\Models;

use Illuminate\Database\Eloquent\Model;

class Marketplace extends Model
{
    protected $guarded = [];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}
