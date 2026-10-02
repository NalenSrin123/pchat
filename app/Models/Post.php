<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Post extends Model
{
    use SoftDeletes;

    protected $fillable = ['user_id', 'shared_post_id', 'content', 'privacy', 'status', 'shares_count'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function sharedPost(): BelongsTo
    {
        return $this->belongsTo(self::class, 'shared_post_id')->withTrashed();
    }

    public function media(): HasMany
    {
        return $this->hasMany(PostMedia::class)->orderBy('sort_order');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    public function reactions(): HasMany
    {
        return $this->hasMany(PostReaction::class);
    }

    public function reports(): HasMany
    {
        return $this->morphMany(SocialReport::class, 'reportable');
    }

    public function mentions(): MorphMany
    {
        return $this->morphMany(Mention::class, 'mentionable');
    }

    public function savedBy(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'saved_posts')->withTimestamps();
    }

    /** The single SQL visibility rule used by feeds, timelines, search, and saved posts. */
    public function scopeVisibleTo(Builder $query, User $viewer, bool $checkSharedPost = true): Builder
    {
        return $query->where('status', 'active')->where(function (Builder $visible) use ($viewer) {
            $visible->where('user_id', $viewer->id)
                ->orWhere('privacy', 'public')
                ->orWhere(function (Builder $friends) use ($viewer) {
                    $friends->where('privacy', 'friends')->whereExists(function ($friendship) use ($viewer) {
                        $friendship->selectRaw('1')->from('friendships')
                            ->where('status', 'accepted')->where(function ($relationship) use ($viewer) {
                                $relationship->where(function ($direct) use ($viewer) {
                                    $direct->whereColumn('friendships.sender_id', 'posts.user_id')->where('friendships.receiver_id', $viewer->id);
                                })->orWhere(function ($reverse) use ($viewer) {
                                    $reverse->whereColumn('friendships.receiver_id', 'posts.user_id')->where('friendships.sender_id', $viewer->id);
                                });
                            });
                    });
                });
        });

        if ($checkSharedPost) {
            $query->where(function (Builder $shared) use ($viewer) {
                $shared->whereNull('shared_post_id')->orWhereHas('sharedPost', fn (Builder $original) => $original->visibleTo($viewer, false));
            });
        }

        return $query;
    }
}
