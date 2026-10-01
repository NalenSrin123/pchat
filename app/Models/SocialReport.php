<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\{BelongsTo, MorphTo};

class SocialReport extends Model
{
    protected $fillable = ['reported_by', 'reason', 'description', 'status', 'reviewed_by', 'reviewed_at'];
    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime'];
    }
    public function reportable(): MorphTo
    {
        return $this->morphTo();
    }
    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }
}
