<?php

namespace App\Domain\Companies\Models;

use App\Domain\Branches\Models\Branch;
use App\Domain\Subscriptions\Models\Subscription;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Company extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'address', 'phone', 'logo_path', 'is_active'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function subscription(): HasOne
    {
        return $this->hasOne(Subscription::class);
    }

    // No logoUrl() helper here on purpose — building a full URL needs the
    // current request's own host (see CompanyController::formatted()),
    // since the app is reachable at more than one (localhost for the dev
    // machine, its LAN IP for other devices) and a model method has no
    // request to read that from.
}
