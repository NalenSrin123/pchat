<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class PostMedia extends Model
{
    protected $fillable = ['type', 'path', 'disk', 'mime_type', 'file_size', 'width', 'height', 'duration', 'sort_order'];
    protected $appends = ['url'];
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }
    public function getUrlAttribute(): string
    {
        // Do not pin public media to APP_URL: the feed can be opened through
        // 127.0.0.1, a custom hostname, or a reverse proxy.
        if (($this->disk ?? 'public') === 'public') {
            return '/storage/' . ltrim($this->path, '/');
        }

        return Storage::disk($this->disk)->url($this->path);
    }
}
