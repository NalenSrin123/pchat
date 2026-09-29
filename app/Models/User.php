<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'google_id',
        'username',
        'email',
        'password',
        'avatar',
        'last_seen_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'avatar',
        'last_seen_at',
        'remember_token',
    ];

    protected $appends = ['avatar_url'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'last_seen_at' => 'datetime',
        ];
    }
    public function conversationMemberships(): HasMany { return $this->hasMany(ConversationMember::class); }
    public function conversations(): BelongsToMany { return $this->belongsToMany(Conversation::class, 'conversation_members')->withPivot(['role', 'joined_at', 'last_read_message_id'])->withTimestamps(); }
    public function messages(): HasMany { return $this->hasMany(Message::class, 'sender_id'); }
    public function getAvatarUrlAttribute(): ?string { return $this->avatar ? "/storage/" . ltrim($this->avatar, "/") : null; }
}
