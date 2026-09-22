<?php

namespace App\Domain\Auth\Http\Controllers;

use App\Http\Controllers\Controller;
use Spatie\Permission\Models\Role;

class RoleController extends Controller
{
    public function index()
    {
        return response()->json(['data' => Role::orderBy('name')->pluck('name')]);
    }
}
