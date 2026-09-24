<?php

namespace App\Domain\Backup\Models;

use App\Domain\Shared\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class BackupLog extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'trigger',
        'status',
        'file_name',
        'file_size_bytes',
        'message',
    ];
}
