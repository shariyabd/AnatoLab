<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DifficultyPreference;
use App\Enums\EducationLevel;
use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * A learner or an administrator.
 *
 * State only — no business rules (docs/engineering.md §3). Levelling, mastery,
 * and recommendations belong to Handover 10's services; this class knows what
 * a user *is*, not what happens to them.
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property \Illuminate\Support\Carbon|null $email_verified_at
 * @property string $password
 * @property UserRole $role
 * @property EducationLevel $education_level
 * @property DifficultyPreference $difficulty_preference
 * @property int $xp
 * @property int $level
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * `role` is deliberately absent: mass-assigning it would let a crafted
     * registration payload create an administrator. Promotion is an explicit
     * assignment in admin code, never a fillable attribute.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'education_level',
        'difficulty_preference',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'education_level' => EducationLevel::class,
            'difficulty_preference' => DifficultyPreference::class,
            'xp' => 'integer',
            'level' => 'integer',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }
}
