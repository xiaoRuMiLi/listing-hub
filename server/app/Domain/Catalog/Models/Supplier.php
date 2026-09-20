<?php

namespace App\Domain\Catalog\Models;

use Illuminate\Database\Eloquent\Model;

class Supplier extends Model
{
    protected $guarded = [];

    public function products()
    {
        return $this->hasMany(ProductSupplier::class);
    }
}
