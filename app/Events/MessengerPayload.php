<?php

namespace App\Events;

use App\Models\Message;

class MessengerPayload
{
    public static function message(Message $message): array
    {
        $message->loadMissing(['sender:id,name,username,email,avatar', 'replyTo.sender:id,name', 'reactions']);

        return [
            'id' => $message->id,
            'conversation_id' => $message->conversation_id,
            'sender_id' => $message->sender_id,
            'message' => $message->message,
            'type' => $message->type,
            'file_name' => $message->file_name,
            'file_size' => $message->file_size,
            'file_mime_type' => $message->file_mime_type,
                // use a relative URL to avoid cross-origin/auth issues when APP_URL/port differs
                'file_url' => $message->file_path ? ('/messenger/attachments/' . $message->id) : null,
            'created_at' => $message->created_at?->toISOString(),
            'edited_at' => $message->edited_at?->toISOString(),
            'deleted_at' => $message->deleted_at?->toISOString(),
            'sender' => [
                'id' => $message->sender->id,
                'name' => $message->sender->name,
                'username' => $message->sender->username,
                'email' => $message->sender->email,
                'avatar_url' => $message->sender->avatar_url,
            ],
            'reply_to' => $message->replyTo ? [
                'id' => $message->replyTo->id,
                'message' => $message->replyTo->message,
                'sender' => $message->replyTo->sender?->name,
            ] : null,
            'reactions' => $message->reactions
                ->map(fn ($reaction) => ['emoji' => $reaction->emoji, 'user_id' => $reaction->user_id])
                ->values(),
        ];
    }
}
