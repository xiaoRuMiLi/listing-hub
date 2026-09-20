<?php

namespace App\Domain\Listing\Models;

use Illuminate\Database\Eloquent\Model;

class FieldDefinition extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'required_rule' => 'array',
        'enum_values' => 'array',
        'is_media' => 'boolean',
        'updated_at' => 'datetime',
    ];
}
