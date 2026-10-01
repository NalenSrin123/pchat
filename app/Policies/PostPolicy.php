<?php

namespace App\Policies;

use App\Models\{Post, User};

class PostPolicy
{
    public function view(User $user, Post $post): bool
    {
        if ($post->status !== 'active' || $post->trashed()) return $user->isAdmin();
        if ($post->user_id === $user->id || $post->privacy === 'public') return true;
        return $post->privacy === 'friends' && $user->isFriendsWith($post->user_id);
    }
    public function update(User $user, Post $post): bool
    {
        return $post->user_id === $user->id;
    }
    public function delete(User $user, Post $post): bool
    {
        return $post->user_id === $user->id;
    }
    public function comment(User $user, Post $post): bool
    {
        return $this->view($user, $post);
    }
    public function share(User $user, Post $post): bool
    {
        return $this->view($user, $post) && $post->privacy !== 'only_me';
    }
}
