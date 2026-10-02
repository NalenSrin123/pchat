<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Story extends Model
{
    protected $fillable = ['user_id', 'content', 'media_path', 'media_disk', 'media_type', 'mime_type', 'privacy', 'expires_at'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function views(): HasMany
    {
        return $this->hasMany(StoryView::class);
    }

    public function reactions(): HasMany
    {
        return $this->hasMany(StoryReaction::class);
    }

    public function scopeVisibleTo(Builder $query, User $viewer): Builder
    {
        return $query->where('expires_at', '>', now())->where(function (Builder $visible) use ($viewer) {
            $visible->where('user_id', $viewer->id)
                ->orWhere('privacy', 'public')
                ->orWhere(function (Builder $friends) use ($viewer) {
                    $friends->where('privacy', 'friends')->whereExists(function ($friendship) use ($viewer) {
                        $friendship->selectRaw('1')->from('friendships')
                            ->where('status', 'accepted')->where(function ($relationship) use ($viewer) {
                                $relationship->where(function ($direct) use ($viewer) {
                                    $direct->whereColumn('friendships.sender_id', 'stories.user_id')->where('friendships.receiver_id', $viewer->id);
                                })->orWhere(function ($reverse) use ($viewer) {
                                    $reverse->whereColumn('friendships.receiver_id', 'stories.user_id')->where('friendships.sender_id', $viewer->id);
                                });
                            });
                    });
                });
        });
    }
}
