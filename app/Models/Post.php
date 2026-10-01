<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany};

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
    public function savedBy(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(User::class, 'saved_posts')->withTimestamps();
    }
}
