<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany};

class Comment extends Model
{
    use SoftDeletes;
    protected $fillable = ['post_id', 'user_id', 'parent_id', 'content'];
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }
    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }
    public function reactions(): HasMany
    {
        return $this->hasMany(CommentReaction::class);
    }
    public function reports(): HasMany
    {
        return $this->morphMany(SocialReport::class, 'reportable');
    }
}
