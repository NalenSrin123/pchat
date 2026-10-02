<?php

namespace App\Services;

use App\Enums\SocialNotificationType;
use App\Models\Comment;
use App\Models\Post;
use App\Models\User;
use App\Notifications\SocialNotification;

class SocialNotificationService
{
    public function postReaction(User $actor, Post $post, string $reaction): void
    {
        $this->send($post->user, $actor, SocialNotificationType::POST_REACTION, ['post_id' => $post->id, 'reaction' => $reaction], true);
    }

    public function comment(User $actor, Post $post, Comment $comment): void
    {
        if ($comment->parent_id) {
            $this->send($comment->parent->user, $actor, SocialNotificationType::COMMENT_REPLY, $this->target($post, $comment), false);
        } else {
            $this->send($post->user, $actor, SocialNotificationType::POST_COMMENT, $this->target($post, $comment), false);
        }
    }

    public function commentReaction(User $actor, Comment $comment, string $reaction): void
    {
        $this->send($comment->user, $actor, $comment->parent_id ? SocialNotificationType::REPLY_REACTION : SocialNotificationType::COMMENT_REACTION, $this->target($comment->post, $comment) + ['reaction' => $reaction], true);
    }

    public function share(User $actor, Post $post): void
    {
        $this->send($post->user, $actor, SocialNotificationType::POST_SHARE, ['post_id' => $post->id], false);
    }

    public function mention(User $recipient, User $actor, Post|Comment $target): void
    {
        $type = $target instanceof Post ? SocialNotificationType::POST_MENTION : ($target->parent_id ? SocialNotificationType::REPLY_MENTION : SocialNotificationType::COMMENT_MENTION);
        // A reply already tells its parent author what happened; do not double-notify them for an @mention.
        if ($target instanceof Comment && $target->parent_id && $target->parent->user_id === $recipient->id) {
            return;
        }
        $this->send($recipient, $actor, $type, $target instanceof Post ? ['post_id' => $target->id] : $this->target($target->post, $target), false);
    }

    private function target(Post $post, Comment $comment): array
    {
        return ['post_id' => $post->id, 'comment_id' => $comment->parent_id ?: $comment->id, 'reply_id' => $comment->parent_id ? $comment->id : null, 'preview' => str($comment->content)->squish()->limit(140)->toString()];
    }

    private function send(User $recipient, User $actor, SocialNotificationType $type, array $data, bool $replace): void
    {
        if ($recipient->is($actor) || ! $this->enabled($recipient, $type)) {
            return;
        }
        $payload = ['type' => $type->value, 'actor_id' => $actor->id, 'user_id' => $actor->id] + $data;
        if ($replace) {
            $existing = $recipient->notifications()->where('data->type', $type->value)->where('data->actor_id', $actor->id)
                ->when(isset($data['post_id']), fn ($q) => $q->where('data->post_id', $data['post_id']))
                ->when(isset($data['comment_id']), fn ($q) => $q->where('data->comment_id', $data['comment_id']))->latest()->first();
            if ($existing) {
                $existing->update(['data' => $payload, 'read_at' => null]);

                return;
            }
        }
        $recipient->notify(new SocialNotification($payload));
    }

    private function enabled(User $user, SocialNotificationType $type): bool
    {
        $preferences = $user->notification_preferences ?? [];
        $key = match ($type) {
            SocialNotificationType::POST_COMMENT => 'post_comments', SocialNotificationType::COMMENT_REPLY => 'comment_replies',
            SocialNotificationType::POST_REACTION => 'post_reactions', SocialNotificationType::COMMENT_REACTION, SocialNotificationType::REPLY_REACTION => 'comment_reactions',
            SocialNotificationType::POST_MENTION, SocialNotificationType::COMMENT_MENTION, SocialNotificationType::REPLY_MENTION => 'mentions',
            SocialNotificationType::FRIEND_REQUEST => 'friend_requests', SocialNotificationType::FRIEND_ACCEPTED => 'friend_accepted', SocialNotificationType::FOLLOW => 'follows', default => null,
        };

        return ! $key || ($preferences[$key] ?? true);
    }
}
