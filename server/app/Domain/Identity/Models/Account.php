<?php

namespace App\Domain\Identity\Models;

use Illuminate\Database\Eloquent\Model;

class Account extends Model
{
    protected $guarded = [];

    public function listings()
    {
        return $this->hasMany(\App\Domain\Listing\Models\Listing::class);
    }
}
