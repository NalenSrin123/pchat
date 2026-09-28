<?php
use App\Models\Conversation; use Illuminate\Support\Facades\Broadcast;
Broadcast::channel('conversation.{conversation}', fn ($user, Conversation $conversation) => $conversation->memberships()->where('user_id',$user->id)->exists());
