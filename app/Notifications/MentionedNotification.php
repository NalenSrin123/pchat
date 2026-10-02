<?php

namespace App\Notifications;

use App\Models\Comment;
use App\Models\Post;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class MentionedNotification extends Notification
{
    use Queueable;

    public function __construct(private User $author, private Post|Comment $mentionable) {}

    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    public function toArray(object $notifiable): array
    {
        $post = $this->mentionable instanceof Comment ? $this->mentionable->post : $this->mentionable;

        return ['type' => 'mention', 'user_id' => $this->author->id, 'post_id' => $post->id, 'comment_id' => $this->mentionable instanceof Comment ? $this->mentionable->id : null, 'message' => $this->author->name.' mentioned you in a '.($this->mentionable instanceof Comment ? 'comment.' : 'post.')];
    }
}
