<?php

namespace App\Http\Controllers;

use App\Events\MessageSent;
use App\Events\MessengerPayload;
use App\Models\Comment;
use App\Models\CommentReaction;
use App\Models\Conversation;
use App\Models\Friendship;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\PostReaction;
use App\Models\Story;
use App\Models\StoryReaction;
use App\Models\StoryView;
use App\Models\User;
use App\Notifications\FollowedNotification;
use App\Notifications\FriendAcceptedNotification;
use App\Notifications\FriendRequestNotification;
use App\Services\SocialNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class SocialController extends Controller
{
    public function feed(Request $request)
    {
        $user = $request->user();

        return Inertia::render('Feed/Index', ['posts' => $this->posts($user), 'stories' => $this->stories($user), 'contacts' => $this->onlineFriends($user), 'reactionTypes' => ['👍', '❤️', '😂', '😮', '😢', '😡']]);
    }

    public function createPost(Request $request)
    {
        return Inertia::render('Feed/CreatePost');
    }

    public function show(Request $request, Post $post): JsonResponse
    {
        $this->authorize('view', $post);

        return response()->json(['data' => $this->postData($post->load($this->relations($request->user())), $request->user())]);
    }

    public function search(Request $request): JsonResponse
    {
        $term = $this->searchTerm($request);
        if (mb_strlen($term) < 2) {
            return response()->json(['users' => [], 'posts' => []]);
        }
        $users = $this->people($request->user(), $term, 5);
        $posts = Post::visibleTo($request->user())->with($this->relations($request->user()))->where('content', 'like', '%'.$term.'%')->latest()->limit(3)->get()->map(fn ($p) => $this->postData($p, $request->user()));

        return response()->json(compact('users', 'posts'));
    }

    public function searchPage(Request $request)
    {
        $term = $this->searchTerm($request);
        $type = $request->validate(['type' => ['nullable', Rule::in(['all', 'people', 'posts'])]])['type'] ?? 'all';
        abort_if(mb_strlen($term) < 2, 422, 'Enter at least two characters.');
        DB::table('search_histories')->updateOrInsert(['user_id' => $request->user()->id, 'query' => $term], ['updated_at' => now(), 'created_at' => now()]);
        $people = $type === 'posts' ? null : $this->peopleQuery($request->user(), $term)->paginate(15, ['*'], 'people_page')->through(fn ($user) => $this->personFor($user, $request->user()));
        $posts = $type === 'people' ? null : $this->serialize(Post::visibleTo($request->user())->with($this->relations($request->user()))->where('content', 'like', '%'.$term.'%')->latest()->paginate(12, ['*'], 'posts_page'), $request->user());

        return Inertia::render('Social/Search', compact('term', 'type', 'people', 'posts'));
    }

    public function searchHistory(Request $request): JsonResponse
    {
        return response()->json(['data' => DB::table('search_histories')->where('user_id', $request->user()->id)->latest('updated_at')->limit(8)->pluck('query')]);
    }

    public function removeSearchHistory(Request $request): JsonResponse
    {
        $query = $request->validate(['query' => 'required|string|max:100'])['query'];
        DB::table('search_histories')->where(['user_id' => $request->user()->id, 'query' => trim($query)])->delete();

        return response()->json(['ok' => true]);
    }

    public function clearSearchHistory(Request $request): JsonResponse
    {
        DB::table('search_histories')->where('user_id', $request->user()->id)->delete();

        return response()->json(['ok' => true]);
    }

    public function notifications(Request $request)
    {
        $notifications = $request->user()->notifications()->latest()->paginate(20);
        $people = User::whereIn('id', $notifications->getCollection()->map(fn ($n) => $n->data['actor_id'] ?? $n->data['user_id'] ?? null)->filter())->get()->keyBy('id');
        $notifications->setCollection($notifications->getCollection()->map(fn ($notification) => $this->notificationData($notification, $people)));
        $preferences = $request->user()->notification_preferences ?? [];

        return Inertia::render('Social/Notifications', compact('notifications', 'preferences'));
    }

    public function notificationsData(Request $request): JsonResponse
    {
        $notifications = $request->user()->notifications()->latest()->limit(12)->get();
        $people = User::whereIn('id', $notifications->map(fn ($n) => $n->data['actor_id'] ?? $n->data['user_id'] ?? null)->filter())->get()->keyBy('id');

        return response()->json([
            'data' => $notifications->map(fn ($notification) => $this->notificationData($notification, $people)),
            'unread_count' => $request->user()->unreadNotifications()->count(),
        ]);
    }

    public function readNotification(Request $request, string $notification): JsonResponse
    {
        $item = $request->user()->notifications()->findOrFail($notification);
        $item->markAsRead();

        return response()->json(['ok' => true]);
    }

    public function readAllNotifications(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return response()->json(['ok' => true]);
    }

    public function notificationPreferences(Request $request): JsonResponse
    {
        $data = $request->validate(['preferences' => 'required|array', 'preferences.*' => 'boolean']);
        $request->user()->update(['notification_preferences' => $data['preferences']]);

        return response()->json(['data' => $request->user()->notification_preferences]);
    }

    public function friendSuggestions(Request $request)
    {
        $suggestions = $this->suggestionQuery($request->user())->paginate(15)->through(fn ($user) => $this->suggestionPerson($user));

        return Inertia::render('Social/FriendSuggestions', compact('suggestions'));
    }

    public function dismissFriendSuggestion(Request $request, User $user): JsonResponse
    {
        abort_if($user->is($request->user()), 422);
        DB::table('dismissed_friend_suggestions')->updateOrInsert(['user_id' => $request->user()->id, 'suggested_user_id' => $user->id], ['created_at' => now(), 'updated_at' => now()]);

        return response()->json(['ok' => true]);
    }

    public function profile(Request $request, User $user)
    {
        $friendIds = $this->friendIds($user);
        $photoCount = Post::visibleTo($request->user())->where('user_id', $user->id)->whereHas('media', fn ($q) => $q->where('type', 'image'))->count();

        return Inertia::render('Social/Profile', ['profileUser' => array_merge($this->person($user), ['joined_at' => $user->created_at?->toISOString(), 'friends_count' => count($friendIds), 'followers_count' => $user->followers()->count(), 'following_count' => $user->following()->count(), 'photos_count' => $photoCount]), 'posts' => $this->posts($request->user(), $user), 'friendship' => $this->friendship($request->user(), $user), 'following' => $request->user()->following()->whereKey($user->id)->exists()]);
    }

    public function saved(Request $request)
    {
        $user = $request->user();
        $posts = Post::visibleTo($user)->whereHas('savedBy', fn ($q) => $q->where('users.id', $user->id));

        return Inertia::render('Feed/Index', ['posts' => $this->serialize($posts->with($this->relations($user))->latest()->cursorPaginate(12), $user), 'contacts' => $this->onlineFriends($user), 'saved' => true, 'reactionTypes' => ['👍', '❤️', '😂', '😮', '😢', '😡']]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['content' => 'nullable|string|max:5000', 'privacy' => ['required', Rule::in(['public', 'friends', 'only_me'])], 'mentions' => 'nullable|array|max:20', 'mentions.*' => 'integer|distinct|exists:users,id', 'media' => 'nullable|array|max:10', 'media.*' => ['file', 'max:'.config('social.max_media_kb', 51200)]]);
        if (blank($data['content'] ?? null) && ! $request->hasFile('media')) {
            abort(422, 'Add text, photos, or a video.');
        }
        $request->validate(['media.*' => ['file', 'mimetypes:image/jpeg,image/png,image/webp,video/mp4,video/webm,video/quicktime', 'max:'.config('social.max_media_kb', 51200)]]);
        $post = DB::transaction(function () use ($request, $data) {
            $post = $request->user()->posts()->create(['content' => filled($data['content'] ?? null) ? trim($data['content']) : null, 'privacy' => $data['privacy']]);
            foreach ($request->file('media', []) as $i => $file) {
                $mime = $file->getMimeType();
                $type = str_starts_with($mime, 'image/') ? 'image' : 'video';
                // Social media is always served through the post-media endpoint.
                // This keeps a file private if its post privacy is later tightened.
                $disk = 'local';
                $path = $file->store('social/'.$type.'s', $disk);
                [$w, $h] = $type === 'image' ? (getimagesize($file->getRealPath()) ?: [null, null]) : [null, null];
                $post->media()->create(['type' => $type, 'path' => $path, 'disk' => $disk, 'mime_type' => $mime, 'file_size' => $file->getSize(), 'width' => $w, 'height' => $h, 'sort_order' => $i]);
            }

            return $post;
        });
        $this->syncMentions($post, $data['mentions'] ?? [], $request->user());

        return response()->json(['data' => $this->postData($post->load($this->relations($request->user())), $request->user())], 201);
    }

    public function storeStory(Request $request): JsonResponse
    {
        $data = $request->validate(['content' => 'nullable|string|max:1000', 'privacy' => ['required', Rule::in(['public', 'friends', 'only_me'])], 'media' => 'nullable|file|mimetypes:image/jpeg,image/png,image/webp,video/mp4,video/webm,video/quicktime|max:'.config('social.max_media_kb', 51200)]);
        abort_if(blank($data['content'] ?? null) && ! $request->hasFile('media'), 422, 'Add text, a photo, or a video to your story.');
        $story = new Story(['content' => filled($data['content'] ?? null) ? trim($data['content']) : null, 'privacy' => $data['privacy'], 'expires_at' => now()->addDay()]);
        if ($file = $request->file('media')) {
            $mime = $file->getMimeType();
            $story->fill(['media_path' => $file->store('social/stories', 'local'), 'media_disk' => 'local', 'media_type' => str_starts_with($mime, 'image/') ? 'image' : 'video', 'mime_type' => $mime]);
        }
        $request->user()->stories()->save($story);

        return response()->json(['data' => $this->storyData($story->load('user'), $request->user())], 201);
    }

    public function storyMedia(Request $request, Story $story)
    {
        abort_unless(Story::visibleTo($request->user())->whereKey($story->id)->exists(), 403);
        abort_unless($story->media_path && Storage::disk($story->media_disk)->exists($story->media_path), 404);

        return Storage::disk($story->media_disk)->response($story->media_path, null, ['Content-Type' => $story->mime_type]);
    }

    public function viewStory(Request $request, Story $story): JsonResponse
    {
        $viewer = $request->user();
        abort_unless(Story::visibleTo($viewer)->whereKey($story->id)->exists(), 403);
        if ($story->user_id !== $viewer->id) {
            StoryView::firstOrCreate(['story_id' => $story->id, 'user_id' => $viewer->id]);
        }

        return response()->json(['data' => $this->storyData($story->fresh(['user', 'views.user:id,name,username,avatar', 'reactions.user:id,name,username,avatar']), $viewer)]);
    }

    public function reactStory(Request $request, Story $story): JsonResponse
    {
        $viewer = $request->user();
        abort_unless(Story::visibleTo($viewer)->whereKey($story->id)->exists(), 403);
        $reaction = $request->validate(['reaction' => ['required', Rule::in(['👍', '❤️', '😂', '😮', '😢', '😡'])]])['reaction'];
        $existing = StoryReaction::where(['story_id' => $story->id, 'user_id' => $viewer->id])->first();
        if ($existing?->reaction === $reaction) {
            $existing->delete();
        } else {
            StoryReaction::updateOrCreate(['story_id' => $story->id, 'user_id' => $viewer->id], ['reaction' => $reaction]);
        }

        return response()->json(['data' => $this->storyData($story->fresh(['user', 'views.user:id,name,username,avatar', 'reactions.user:id,name,username,avatar']), $viewer)]);
    }

    public function replyToStory(Request $request, Story $story): JsonResponse
    {
        $sender = $request->user();
        abort_unless(Story::visibleTo($sender)->whereKey($story->id)->exists(), 403);
        abort_if($story->user_id === $sender->id, 422, 'You cannot reply to your own story.');
        $text = trim($request->validate(['message' => 'required|string|max:5000'])['message']);
        $conversation = DB::transaction(function () use ($sender, $story, $text) {
            $conversation = Conversation::where('type', 'private')->whereHas('memberships', fn ($q) => $q->where('user_id', $sender->id))->whereHas('memberships', fn ($q) => $q->where('user_id', $story->user_id))->withCount('memberships')->get()->firstWhere('memberships_count', 2);
            if (! $conversation) {
                $conversation = Conversation::create(['type' => 'private', 'created_by' => $sender->id]);
                $conversation->memberships()->createMany([['user_id' => $sender->id, 'role' => 'admin'], ['user_id' => $story->user_id]]);
            }
            $message = $conversation->messages()->create(['sender_id' => $sender->id, 'message' => $text, 'type' => 'text', 'story_id' => $story->id]);
            $conversation->update(['last_message_id' => $message->id]);

            return [$conversation, $message];
        });
        [$conversation, $message] = $conversation;
        $message->load(['sender', 'replyTo.sender', 'reactions', 'story.user']);
        broadcast(new MessageSent($message))->toOthers();

        return response()->json(['data' => MessengerPayload::message($message), 'conversation_id' => $conversation->id], 201);
    }

    public function update(Request $request, Post $post): JsonResponse
    {
        $this->authorize('update', $post);
        $data = $request->validate(['content' => 'nullable|string|max:5000', 'privacy' => ['required', Rule::in(['public', 'friends', 'only_me'])], 'mentions' => 'nullable|array|max:20', 'mentions.*' => 'integer|distinct|exists:users,id']);
        abort_if(blank($data['content']) && ! $post->media()->exists(), 422, 'A post needs text or media.');
        // Older public uploads may be in the public disk. Move them before
        // restricting a post so an already-known /storage URL stops working.
        if ($post->privacy === 'public' && $data['privacy'] !== 'public') {
            $post->media()->where('disk', 'public')->each(function (PostMedia $media) {
                if (Storage::disk('public')->exists($media->path)) {
                    Storage::disk('local')->writeStream($media->path, Storage::disk('public')->readStream($media->path));
                    Storage::disk('public')->delete($media->path);
                }
                $media->update(['disk' => 'local']);
            });
        }
        $post->update(['content' => filled($data['content']) ? trim($data['content']) : null, 'privacy' => $data['privacy']]);
        $this->syncMentions($post, $data['mentions'] ?? [], $request->user());

        return response()->json(['data' => $this->postData($post->fresh($this->relations($request->user())), $request->user())]);
    }

    public function destroy(Request $request, Post $post): JsonResponse
    {
        $this->authorize('delete', $post);
        $post->delete();

        return response()->json(['data' => ['id' => $post->id]]);
    }

    public function react(Request $request, Post $post, SocialNotificationService $notifications): JsonResponse
    {
        $this->authorize('view', $post);
        $reaction = $request->validate(['reaction' => ['required', Rule::in(['👍', '❤️', '😂', '😮', '😢', '😡'])]])['reaction'];
        $existing = PostReaction::where(['post_id' => $post->id, 'user_id' => $request->user()->id])->first();
        if ($existing?->reaction === $reaction) {
            $existing->delete();
        } else {
            PostReaction::updateOrCreate(['post_id' => $post->id, 'user_id' => $request->user()->id], ['reaction' => $reaction]);
            $notifications->postReaction($request->user(), $post, $reaction);
        }

        return response()->json(['data' => $this->postData($post->fresh($this->relations($request->user())), $request->user())]);
    }

    public function comment(Request $request, Post $post, SocialNotificationService $notifications): JsonResponse
    {
        $this->authorize('comment', $post);
        $data = $request->validate(['content' => 'required|string|max:2000', 'parent_id' => 'nullable|integer', 'mentions' => 'nullable|array|max:20', 'mentions.*' => 'integer|distinct|exists:users,id']);
        if (isset($data['parent_id'])) {
            abort_unless($post->comments()->whereKey($data['parent_id'])->whereNull('parent_id')->exists(), 422, 'Replies must target a top-level comment.');
        }
        $comment = $post->comments()->create(['user_id' => $request->user()->id, 'content' => trim($data['content']), 'parent_id' => $data['parent_id'] ?? null]);
        $comment->load(['post.user', 'parent.user']);
        $notifications->comment($request->user(), $post, $comment);
        $this->syncMentions($comment, $data['mentions'] ?? [], $request->user());

        return response()->json(['data' => $this->commentData($comment->load(['user:id,name,username,avatar', 'reactions']), $request->user())], 201);
    }

    public function comments(Request $request, Post $post): JsonResponse
    {
        $this->authorize('view', $post);
        $comments = $post->comments()->whereNull('parent_id')->with(['user:id,name,username,avatar', 'reactions', 'replies' => fn ($q) => $q->with(['user:id,name,username,avatar', 'reactions'])->latest()->limit(3)])->latest()->cursorPaginate(10);

        return response()->json(['data' => collect($comments->items())->map(fn ($comment) => $this->commentData($comment, $request->user()))->values(), 'next_page_url' => $comments->nextPageUrl()]);
    }

    public function replies(Request $request, Comment $comment): JsonResponse
    {
        $this->authorize('view', $comment->post);
        $replies = $comment->replies()->with(['user:id,name,username,avatar', 'reactions'])->latest()->cursorPaginate(10);

        return response()->json(['data' => collect($replies->items())->map(fn ($reply) => $this->commentData($reply, $request->user()))->values(), 'next_page_url' => $replies->nextPageUrl()]);
    }

    public function updateComment(Request $request, Comment $comment): JsonResponse
    {
        $this->authorize('update', $comment);
        $data = $request->validate(['content' => 'required|string|max:2000', 'mentions' => 'nullable|array|max:20', 'mentions.*' => 'integer|distinct|exists:users,id']);
        $comment->update(['content' => trim($data['content'])]);
        $this->syncMentions($comment, $data['mentions'] ?? [], $request->user());

        return response()->json(['data' => $this->commentData($comment->fresh(['user:id,name,username,avatar', 'reactions']), $request->user())]);
    }

    public function deleteComment(Request $request, Comment $comment): JsonResponse
    {
        $this->authorize('delete', $comment);
        $comment->delete();

        return response()->json(['data' => ['id' => $comment->id]]);
    }

    public function reactComment(Request $request, Comment $comment, SocialNotificationService $notifications): JsonResponse
    {
        $this->authorize('view', $comment->post);
        $reaction = $request->validate(['reaction' => ['required', Rule::in(['👍', '❤️', '😂', '😮', '😢', '😡'])]])['reaction'];
        $existing = CommentReaction::where(['comment_id' => $comment->id, 'user_id' => $request->user()->id])->first();
        if ($existing?->reaction === $reaction) {
            $existing->delete();
        } else {
            CommentReaction::updateOrCreate(['comment_id' => $comment->id, 'user_id' => $request->user()->id], ['reaction' => $reaction]);
            $comment->load('post', 'user');
            $notifications->commentReaction($request->user(), $comment, $reaction);
        }

        return response()->json(['data' => $this->commentData($comment->fresh(['user:id,name,username,avatar', 'reactions']), $request->user())]);
    }

    public function share(Request $request, Post $post, SocialNotificationService $notifications): JsonResponse
    {
        $this->authorize('share', $post);
        $data = $request->validate(['content' => 'nullable|string|max:5000', 'privacy' => ['required', Rule::in(['public', 'friends', 'only_me'])]]);
        $original = $post->shared_post_id ? $post->sharedPost : $post;
        abort_if(! $original || $original->trashed() || $original->privacy !== 'public', 422, 'Only public posts can be shared.');
        $share = $request->user()->posts()->create(['content' => filled($data['content'] ?? null) ? trim($data['content']) : null, 'privacy' => $data['privacy'], 'shared_post_id' => $original->id]);
        $original->increment('shares_count');
        $notifications->share($request->user(), $original);

        return response()->json(['data' => $this->postData($share->load($this->relations($request->user())), $request->user())], 201);
    }

    public function save(Request $request, Post $post): JsonResponse
    {
        $this->authorize('view', $post);
        $attached = $request->user()->savedPosts()->toggle($post->id);

        return response()->json(['saved' => ! empty($attached['attached'])]);
    }

    public function report(Request $request, Post $post): JsonResponse
    {
        $this->authorize('view', $post);

        return $this->reportModel($request, $post);
    }

    public function reportComment(Request $request, Comment $comment): JsonResponse
    {
        $this->authorize('view', $comment->post);

        return $this->reportModel($request, $comment);
    }

    public function friend(Request $request, User $user): JsonResponse
    {
        abort_if($user->is($request->user()), 422);
        $f = DB::transaction(function () use ($request, $user) {
            User::whereKey([$request->user()->id, $user->id])->orderBy('id')->lockForUpdate()->get();
            $existing = $this->friendship($request->user(), $user);
            if ($existing) {
                return $existing;
            }

            return Friendship::create(['sender_id' => $request->user()->id, 'receiver_id' => $user->id, 'status' => 'pending']);
        });
        if ($f->wasRecentlyCreated) {
            $user->notify(new FriendRequestNotification($f->load('sender')));
        }

        return response()->json(['data' => $f], $f->wasRecentlyCreated ? 201 : 200);
    }

    public function respondFriend(Request $request, Friendship $friendship): JsonResponse
    {
        abort_unless($friendship->receiver_id === $request->user()->id, 403);
        $data = $request->validate(['action' => ['required', Rule::in(['accept', 'decline'])]]);
        if ($data['action'] === 'accept') {
            $friendship->update(['status' => 'accepted']);
            $friendship->sender->notify(new FriendAcceptedNotification($friendship->load('receiver')));
        } else {
            $friendship->delete();
        }

        return response()->json(['data' => $friendship]);
    }

    public function cancelFriend(Request $request, Friendship $friendship): JsonResponse
    {
        abort_unless($friendship->sender_id === $request->user()->id || ($friendship->status === 'accepted' && $friendship->receiver_id === $request->user()->id), 403);
        $friendship->delete();

        return response()->json(['data' => ['id' => $friendship->id]]);
    }

    public function follow(Request $request, User $user): JsonResponse
    {
        abort_if($user->is($request->user()), 422, 'You cannot follow yourself.');
        $changes = $request->user()->following()->syncWithoutDetaching([$user->id]);
        $created = ! empty($changes['attached']);
        if ($created) {
            $user->notify(new FollowedNotification($request->user()));
        }

        return response()->json(['following' => true], $created ? 201 : 200);
    }

    public function unfollow(Request $request, User $user): JsonResponse
    {
        $request->user()->following()->detach($user->id);

        return response()->json(['following' => false]);
    }

    public function friends(Request $request)
    {
        $user = $request->user();
        $friends = Friendship::with(['sender:id,name,username,avatar', 'receiver:id,name,username,avatar'])->where('status', 'accepted')->where(fn ($q) => $q->where('sender_id', $user->id)->orWhere('receiver_id', $user->id))->latest()->get()->map(fn ($f) => $this->person($f->sender_id === $user->id ? $f->receiver : $f->sender));
        $requests = $user->receivedFriendships()->where('status', 'pending')->with('sender:id,name,username,avatar')->latest()->get();
        $sent = $user->sentFriendships()->where('status', 'pending')->with('receiver:id,name,username,avatar')->latest()->get();
        $suggestions = $this->suggestionQuery($user)->limit(6)->get()->map(fn ($person) => $this->suggestionPerson($person));

        return Inertia::render('Social/Friends', compact('friends', 'requests', 'sent', 'suggestions'));
    }

    public function connections(Request $request, User $user, string $type)
    {
        abort_unless(in_array($type, ['followers', 'following']), 404);
        $people = $type === 'followers' ? $user->followers()->select('users.id', 'name', 'username', 'avatar')->get() : $user->following()->select('users.id', 'name', 'username', 'avatar')->get();

        return Inertia::render('Social/Connections', ['profileUser' => $this->person($user), 'type' => $type, 'people' => $people->map(fn ($person) => $this->person($person))]);
    }

    public function media(Request $request, PostMedia $media)
    {
        $this->authorize('view', $media->post);
        abort_unless($media->disk !== 'public' && Storage::disk($media->disk)->exists($media->path), 404);

        return Storage::disk($media->disk)->response($media->path, null, ['Content-Type' => $media->mime_type]);
    }

    private function posts(User $viewer, ?User $author = null)
    {
        $q = Post::visibleTo($viewer)->with($this->relations($viewer))->when($author, fn ($q) => $q->where('user_id', $author->id))->latest();

        return $this->serialize($q->cursorPaginate(12), $viewer);
    }

    private function stories(User $viewer)
    {
        return Story::visibleTo($viewer)->with(['user:id,name,username,avatar', 'views.user:id,name,username,avatar', 'reactions.user:id,name,username,avatar'])->latest()->get()
            ->groupBy('user_id')->map(fn ($stories) => ['author' => $this->person($stories->first()->user), 'stories' => $stories->map(fn ($story) => $this->storyData($story, $viewer))->values()])->values();
    }

    private function storyData(Story $story, User $viewer): array
    {
        $views = $story->relationLoaded('views') ? $story->views : $story->views()->with('user:id,name,username,avatar')->get();
        $reactions = $story->relationLoaded('reactions') ? $story->reactions : $story->reactions()->with('user:id,name,username,avatar')->get();

        return ['id' => $story->id, 'content' => $story->content, 'privacy' => $story->privacy, 'created_at' => $story->created_at, 'expires_at' => $story->expires_at, 'media_type' => $story->media_type, 'media_url' => $story->media_path ? route('stories.media', $story) : null, 'author' => $this->person($story->user), 'views_count' => $views->count(), 'viewers' => $story->user_id === $viewer->id ? $views->map(fn ($view) => $this->person($view->user))->values() : [], 'reactions' => $reactions->map(fn ($reaction) => ['reaction' => $reaction->reaction, 'user' => $story->user_id === $viewer->id ? $this->person($reaction->user) : null])->values(), 'my_reaction' => $reactions->firstWhere('user_id', $viewer->id)?->reaction];
    }

    private function relations(User $user): array
    {
        return ['user:id,name,username,avatar', 'media', 'sharedPost.user:id,name,username,avatar', 'sharedPost.media', 'comments' => fn ($q) => $q->whereNull('parent_id')->with(['user:id,name,username,avatar', 'reactions', 'replies' => fn ($replies) => $replies->with(['user:id,name,username,avatar', 'reactions'])->latest()->limit(3)])->latest()->limit(3), 'reactions'];
    }

    private function serialize($paginator, User $viewer)
    {
        $paginator->setCollection($paginator->getCollection()->map(fn ($post) => $this->postData($post, $viewer)));

        return $paginator;
    }

    private function postData(Post $post, User $viewer): array
    {
        $counts = $post->reactions->countBy('reaction');

        return ['id' => $post->id, 'user_id' => $post->user_id, 'content' => $post->content, 'privacy' => $post->privacy, 'created_at' => $post->created_at, 'author' => $this->person($post->user), 'media' => $post->media, 'comments' => $post->comments->map(fn ($comment) => $this->commentData($comment, $viewer))->values(), 'comments_count' => $post->comments()->count(), 'shares_count' => $post->shares_count, 'shared_post' => $post->sharedPost ? $this->postData($post->sharedPost, $viewer) : null, 'reactions' => $counts, 'my_reaction' => $post->reactions->firstWhere('user_id', $viewer->id)?->reaction, 'saved' => $viewer->savedPosts()->whereKey($post->id)->exists()];
    }

    private function commentData(Comment $comment, User $viewer): array
    {
        $reactions = $comment->relationLoaded('reactions') ? $comment->reactions : $comment->reactions()->get();

        return ['id' => $comment->id, 'post_id' => $comment->post_id, 'parent_id' => $comment->parent_id, 'user_id' => $comment->user_id, 'content' => $comment->content, 'created_at' => $comment->created_at, 'updated_at' => $comment->updated_at, 'user' => $this->person($comment->user), 'reactions' => $reactions->countBy('reaction'), 'my_reaction' => $reactions->firstWhere('user_id', $viewer->id)?->reaction, 'replies_count' => $comment->replies()->count(), 'replies' => ($comment->relationLoaded('replies') ? $comment->replies : collect())->map(fn ($reply) => $this->commentData($reply, $viewer))->values()];
    }

    private function syncMentions(Post|Comment $mentionable, array $ids, User $author): void
    {
        $ids = collect($ids)->map(fn ($id) => (int) $id)->unique()->reject(fn ($id) => $id === $author->id)->values();
        $mentionable->mentions()->whereNotIn('user_id', $ids)->delete();
        $allowed = User::whereIn('id', $ids)->where('status', 'active')->get();
        foreach ($allowed as $user) {
            $mention = $mentionable->mentions()->firstOrCreate(['user_id' => $user->id]);
            $postId = $mentionable instanceof Comment ? $mentionable->post_id : $mentionable->id;
            if ($mention->wasRecentlyCreated && Post::visibleTo($user)->whereKey($postId)->exists()) {
                app(SocialNotificationService::class)->mention($user, $author, $mentionable);
            }
        }
    }

    private function person(User $u): array
    {
        return ['id' => $u->id, 'name' => $u->name, 'username' => $u->username, 'avatar_url' => $u->avatar_url, 'cover_photo_url' => $u->cover_photo_url];
    }

    private function notificationData($notification, $people): array
    {
        $actorId = $notification->data['actor_id'] ?? $notification->data['user_id'] ?? null;

        return [
            'id' => $notification->id,
            'type' => $notification->data['type'] ?? null,
            'message' => $this->notificationMessage($notification->data, $people),
            'read_at' => $notification->read_at,
            'created_at' => $notification->created_at,
            'person' => $actorId && $people->has($actorId) ? $this->person($people[$actorId]) : null,
            'target' => collect($notification->data)->only(['post_id', 'comment_id', 'reply_id', 'friendship_id'])->all(),
        ];
    }

    private function notificationMessage(array $data, $people): string
    {
        if (isset($data['message'])) {
            return $data['message'];
        }
        $actor = $people->get($data['actor_id'] ?? null)?->name ?? 'Someone';
        $reaction = $data['reaction'] ?? '';

        return match ($data['type'] ?? '') {
            'post_reaction' => "$actor reacted $reaction to your post.", 'post_comment' => "$actor commented on your post.", 'post_share' => "$actor shared your post.",
            'comment_reaction' => "$actor reacted $reaction to your comment.", 'reply_reaction' => "$actor reacted $reaction to your reply.", 'comment_reply' => "$actor replied to your comment.",
            'post_mention' => "$actor mentioned you in a post.", 'comment_mention', 'reply_mention' => "$actor mentioned you in a comment.", default => 'You have a new notification.',
        };
    }

    private function searchTerm(Request $request): string
    {
        return trim($request->validate(['q' => 'nullable|string|max:100'])['q'] ?? '');
    }

    private function people(User $viewer, string $term, int $limit)
    {
        return $this->peopleQuery($viewer, $term)->limit($limit)->get()->map(fn ($user) => $this->personFor($user, $viewer));
    }

    private function peopleQuery(User $viewer, string $term)
    {
        return User::query()->where('status', 'active')->whereKeyNot($viewer->id)
            ->where(fn ($query) => $query->where('name', 'like', '%'.$term.'%')->orWhere('username', 'like', '%'.$term.'%'))
            ->orderBy('name');
    }

    private function personFor(User $person, User $viewer): array
    {
        $data = $this->person($person);
        $friendIds = $this->friendIds($viewer);
        $mutual = $friendIds ? Friendship::where('status', 'accepted')->where(function ($query) use ($person, $friendIds) {
            $query->where(fn ($q) => $q->where('sender_id', $person->id)->whereIn('receiver_id', $friendIds))
                ->orWhere(fn ($q) => $q->where('receiver_id', $person->id)->whereIn('sender_id', $friendIds));
        })->count() : 0;
        $friendship = $this->friendship($viewer, $person);

        return array_merge($data, ['mutual_friends_count' => $mutual, 'friendship' => $friendship ? ['id' => $friendship->id, 'status' => $friendship->status, 'sender_id' => $friendship->sender_id, 'receiver_id' => $friendship->receiver_id] : null, 'following' => $viewer->following()->whereKey($person->id)->exists()]);
    }

    private function suggestionQuery(User $viewer)
    {
        $friendIds = $this->friendIds($viewer);
        if (! $friendIds) {
            return User::query()->whereRaw('1 = 0');
        }
        $relatedIds = Friendship::where(fn ($q) => $q->where('sender_id', $viewer->id)->orWhere('receiver_id', $viewer->id))->pluck('sender_id')->merge(Friendship::where(fn ($q) => $q->where('sender_id', $viewer->id)->orWhere('receiver_id', $viewer->id))->pluck('receiver_id'))->unique();
        $friendIdList = implode(',', array_map('intval', $friendIds));

        return User::query()->select('users.*')->selectRaw("(select count(*) from friendships as mutual_friendships where mutual_friendships.status = 'accepted' and ((mutual_friendships.sender_id = users.id and mutual_friendships.receiver_id in ({$friendIdList})) or (mutual_friendships.receiver_id = users.id and mutual_friendships.sender_id in ({$friendIdList})))) as mutual_friends_count")
            ->where('users.status', 'active')->whereKeyNot($viewer->id)->whereNotIn('users.id', $relatedIds)
            ->whereNotIn('users.id', DB::table('dismissed_friend_suggestions')->where('user_id', $viewer->id)->select('suggested_user_id'))
            ->where(function ($query) use ($friendIds) {
                $query->whereIn('users.id', Friendship::where('status', 'accepted')->whereIn('sender_id', $friendIds)->pluck('receiver_id'))->orWhereIn('users.id', Friendship::where('status', 'accepted')->whereIn('receiver_id', $friendIds)->pluck('sender_id'));
            })
            ->orderByDesc('mutual_friends_count')->orderByDesc('last_seen_at')->orderBy('id');
    }

    private function suggestionPerson(User $person): array
    {
        return array_merge($this->person($person), ['mutual_friends_count' => (int) $person->mutual_friends_count, 'friendship' => null, 'following' => false]);
    }

    private function friendIds(User $u): array
    {
        return Friendship::where('status', 'accepted')->where(fn ($q) => $q->where('sender_id', $u->id)->orWhere('receiver_id', $u->id))->get()->map(fn ($f) => $f->sender_id === $u->id ? $f->receiver_id : $f->sender_id)->all();
    }

    private function onlineFriends(User $user): array
    {
        $friendIds = $this->friendIds($user);
        if (! $friendIds) {
            return [];
        }

        return User::query()->whereIn('id', $friendIds)->where('status', 'active')
            ->where('last_seen_at', '>', now()->subMinutes(2))->latest('last_seen_at')->limit(7)->get()
            ->map(fn (User $friend) => $this->person($friend))->values()->all();
    }

    private function friendship(User $a, User $b): ?Friendship
    {
        return Friendship::where(fn ($q) => $q->where(['sender_id' => $a->id, 'receiver_id' => $b->id])->orWhere(['sender_id' => $b->id, 'receiver_id' => $a->id]))->first();
    }

    private function reportModel(Request $request, mixed $model): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', Rule::in(['spam', 'harassment', 'violence', 'scam', 'inappropriate', 'other'])], 'description' => 'nullable|string|max:1000']);
        $r = $model->reports()->firstOrCreate(['reported_by' => $request->user()->id], $data);

        return response()->json(['data' => $r], $r->wasRecentlyCreated ? 201 : 200);
    }
}
