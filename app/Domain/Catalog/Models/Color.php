<?php

namespace App\Domain\Catalog\Models;

use App\Domain\Shared\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Color extends Model
{
    use HasFactory, BelongsToCompany;

    protected $fillable = ['name', 'hex_code'];
}
