<?php

namespace App\Domain\Backup\Models;

use Illuminate\Database\Eloquent\Model;

class BackupLog extends Model
{
    protected $fillable = [
        'trigger',
        'status',
        'file_name',
        'file_size_bytes',
        'message',
    ];
}
