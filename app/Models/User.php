<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'phone',
        'country_id',
        'state_id',
        'phone_verified_at',
        'avatar_path',
        'two_factor_enabled',
        'password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'country_id' => 'integer',
            'state_id' => 'integer',
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'two_factor_enabled' => 'boolean',
            'password' => 'hashed',
        ];
    }

    public function country()
    {
        return $this->belongsTo(Country::class);
    }

    public function state()
    {
        return $this->belongsTo(State::class);
    }

    /**
     * Get the roles assigned to this user from the model_has_roles + roles tables.
     * Returns an array of role name strings, e.g. ['super_admin'] or ['user'].
     */
    public function getRoleNames(): array
    {
        $roles = DB::table('model_has_roles')
            ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
            ->where('model_has_roles.model_type', self::class)
            ->where('model_has_roles.model_id', $this->id)
            ->pluck('roles.name')
            ->toArray();

        return count($roles) > 0 ? $roles : ['user'];
    }

    public function formatForFrontend(): array
    {
        $avatarUrl = $this->avatar_path;
        if ($avatarUrl && !str_starts_with($avatarUrl, 'http://') && !str_starts_with($avatarUrl, 'https://')) {
            $baseUrl = rtrim(config('app.url', 'http://localhost:8000'), '/');
            if (!str_starts_with($avatarUrl, '/storage/') && !str_starts_with($avatarUrl, 'storage/')) {
                $avatarUrl = '/storage/' . ltrim($avatarUrl, '/');
            }
            $avatarUrl = $baseUrl . '/' . ltrim($avatarUrl, '/');
        }

        return [
            'id'                 => (int)$this->id,
            'name'               => $this->name,
            'email'              => $this->email,
            'phone'              => $this->phone,
            'country_id'         => $this->country_id ? (int)$this->country_id : null,
            'state_id'           => $this->state_id ? (int)$this->state_id : null,
            'country_name'       => $this->country?->name,
            'state_name'         => $this->state?->name,
            'phone_verified_at'  => $this->phone_verified_at?->toISOString(),
            'avatar_url'         => $avatarUrl,
            'email_verified_at'  => $this->email_verified_at?->toISOString(),
            'two_factor_enabled' => (bool)$this->two_factor_enabled,
            'roles'              => $this->getRoleNames(),
            'created_at'         => $this->created_at->toISOString(),
        ];
    }
}
