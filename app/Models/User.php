<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'phone',
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
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'two_factor_enabled' => 'boolean',
            'password' => 'hashed',
        ];
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
            'id' => (int)$this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'phone_verified_at' => $this->phone_verified_at?->toISOString(),
            'avatar_url' => $avatarUrl,
            'email_verified_at' => $this->email_verified_at?->toISOString(),
            'two_factor_enabled' => (bool)$this->two_factor_enabled,
            'roles' => ['user'],
            'created_at' => $this->created_at->toISOString(),
        ];
    }
}
