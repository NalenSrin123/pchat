<?php

namespace App\Http\Controllers;

use App\Models\{Comment, CommentReaction, Friendship, Post, PostReaction, SocialReport, User};
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class SocialController extends Controller
{
    public function feed(Request $request)
    {
        return Inertia::render('Feed/Index', ['posts' => $this->posts($request->user()), 'reactionTypes' => ['👍', '❤️', '😂', '😮', '😢', '😡']]);
    }
    public function show(Request $request, Post $post): JsonResponse
    {
        $this->authorize('view', $post);
        return response()->json(['data' => $this->postData($post->load($this->relations($request->user())), $request->user())]);
    }
    public function search(Request $request): JsonResponse
    {
        $term = trim((string) $request->query('q'));
        if ($term === '') return response()->json(['users' => [], 'posts' => []]);
        $users = User::whereKeyNot($request->user()->id)->where(fn ($q) => $q->where('name', 'like', "%{$term}%")->orWhere('username', 'like', "%{$term}%"))->limit(8)->get()->map(fn ($u) => $this->person($u));
        $posts = Post::with($this->relations($request->user()))->where('content', 'like', "%{$term}%")->where('status', 'active')->where(fn ($q) => $q->where('user_id', $request->user()->id)->orWhere('privacy', 'public')->orWhere(fn ($f) => $f->where('privacy', 'friends')->whereIn('user_id', $this->friendIds($request->user()))))->latest()->limit(10)->get()->map(fn ($p) => $this->postData($p, $request->user()));
        return response()->json(compact('users', 'posts'));
    }
    public function profile(Request $request, User $user)
    {
        return Inertia::render('Social/Profile', ['profileUser' => $this->person($user), 'posts' => $this->posts($request->user(), $user), 'friendship' => $this->friendship($request->user(), $user)]);
    }
    public function saved(Request $request)
    {
        $posts = Post::query()->whereHas('savedBy', fn($q) => $q->where('users.id', $request->user()->id));
        return Inertia::render('Feed/Index', ['posts' => $this->serialize($posts->with($this->relations($request->user()))->latest()->cursorPaginate(12), $request->user()), 'saved' => true, 'reactionTypes' => ['👍', '❤️', '😂', '😮', '😢', '😡']]);
    }
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['content' => 'nullable|string|max:5000', 'privacy' => ['required', Rule::in(['public', 'friends', 'only_me'])], 'media' => 'nullable|array|max:10', 'media.*' => ['file', 'max:' . config('social.max_media_kb', 51200)]]);
        if (blank($data['content'] ?? null) && !$request->hasFile('media')) abort(422, 'Add text, photos, or a video.');
        $request->validate(['media.*' => ['file', 'mimetypes:image/jpeg,image/png,image/webp,video/mp4,video/webm,video/quicktime', 'max:' . config('social.max_media_kb', 51200)]]);
        $post = DB::transaction(function () use ($request, $data) {
            $post = $request->user()->posts()->create(['content' => filled($data['content'] ?? null) ? trim($data['content']) : null, 'privacy' => $data['privacy']]);
            foreach ($request->file('media', []) as $i => $file) {
                $mime = $file->getMimeType();
                $type = str_starts_with($mime, 'image/') ? 'image' : 'video';
                $path = $file->store('social/' . $type . 's', 'public');
                [$w, $h] = $type === 'image' ? (getimagesize($file->getRealPath()) ?: [null, null]) : [null, null];
                $post->media()->create(['type' => $type, 'path' => $path, 'mime_type' => $mime, 'file_size' => $file->getSize(), 'width' => $w, 'height' => $h, 'sort_order' => $i]);
            }
            return $post;
        });
        return response()->json(['data' => $this->postData($post->load($this->relations($request->user())), $request->user())], 201);
    }
    public function update(Request $request, Post $post): JsonResponse
    {
        $this->authorize('update', $post);
        $data = $request->validate(['content' => 'nullable|string|max:5000', 'privacy' => ['required', Rule::in(['public', 'friends', 'only_me'])]]);
        abort_if(blank($data['content']) && !$post->media()->exists(), 422, 'A post needs text or media.');
        $post->update(['content' => filled($data['content']) ? trim($data['content']) : null, 'privacy' => $data['privacy']]);
        return response()->json(['data' => $this->postData($post->fresh($this->relations($request->user())), $request->user())]);
    }
    public function destroy(Request $request, Post $post): JsonResponse
    {
        $this->authorize('delete', $post);
        $post->delete();
        return response()->json(['data' => ['id' => $post->id]]);
    }
    public function react(Request $request, Post $post): JsonResponse
    {
        $this->authorize('view', $post);
        $reaction = $request->validate(['reaction' => ['required', Rule::in(['👍', '❤️', '😂', '😮', '😢', '😡'])]])['reaction'];
        $existing = PostReaction::where(['post_id' => $post->id, 'user_id' => $request->user()->id])->first();
        if ($existing?->reaction === $reaction) $existing->delete();
        else PostReaction::updateOrCreate(['post_id' => $post->id, 'user_id' => $request->user()->id], ['reaction' => $reaction]);
        return response()->json(['data' => $this->postData($post->fresh($this->relations($request->user())), $request->user())]);
    }
    public function comment(Request $request, Post $post): JsonResponse
    {
        $this->authorize('comment', $post);
        $data = $request->validate(['content' => 'required|string|max:2000', 'parent_id' => 'nullable|integer']);
        if (isset($data['parent_id'])) abort_unless($post->comments()->whereKey($data['parent_id'])->whereNull('parent_id')->exists(), 422, 'Replies must target a top-level comment.');
        $comment = $post->comments()->create(['user_id' => $request->user()->id, 'content' => trim($data['content']), 'parent_id' => $data['parent_id'] ?? null]);
        return response()->json(['data' => $comment->load('user:id,name,username,avatar')], 201);
    }
    public function updateComment(Request $request, Comment $comment): JsonResponse
    {
        $this->authorize('update', $comment);
        $comment->update($request->validate(['content' => 'required|string|max:2000']));
        return response()->json(['data' => $comment->fresh('user:id,name,username,avatar')]);
    }
    public function deleteComment(Request $request, Comment $comment): JsonResponse
    {
        $this->authorize('delete', $comment);
        $comment->delete();
        return response()->json(['data' => ['id' => $comment->id]]);
    }
    public function reactComment(Request $request, Comment $comment): JsonResponse
    {
        $this->authorize('view', $comment->post);
        $reaction = $request->validate(['reaction' => ['required', Rule::in(['👍', '❤️', '😂'])]])['reaction'];
        $existing = CommentReaction::where(['comment_id' => $comment->id, 'user_id' => $request->user()->id])->first();
        if ($existing?->reaction === $reaction) $existing->delete();
        else CommentReaction::updateOrCreate(['comment_id' => $comment->id, 'user_id' => $request->user()->id], ['reaction' => $reaction]);
        return response()->json(['data' => ['id' => $comment->id, 'reaction' => $existing?->reaction === $reaction ? null : $reaction]]);
    }
    public function share(Request $request, Post $post): JsonResponse
    {
        $this->authorize('share', $post);
        $data = $request->validate(['content' => 'nullable|string|max:5000', 'privacy' => ['required', Rule::in(['public', 'friends', 'only_me'])]]);
        $original = $post->shared_post_id ? $post->sharedPost : $post;
        abort_if(!$original || $original->trashed(), 422);
        $share = $request->user()->posts()->create(['content' => filled($data['content'] ?? null) ? trim($data['content']) : null, 'privacy' => $data['privacy'], 'shared_post_id' => $original->id]);
        $original->increment('shares_count');
        return response()->json(['data' => $this->postData($share->load($this->relations($request->user())), $request->user())], 201);
    }
    public function save(Request $request, Post $post): JsonResponse
    {
        $this->authorize('view', $post);
        $attached = $request->user()->savedPosts()->toggle($post->id);
        return response()->json(['saved' => !empty($attached['attached'])]);
    }
    public function report(Request $request, Post $post): JsonResponse
    {
        return $this->reportModel($request, $post);
    }
    public function reportComment(Request $request, Comment $comment): JsonResponse
    {
        return $this->reportModel($request, $comment);
    }
    public function friend(Request $request, User $user): JsonResponse
    {
        abort_if($user->is($request->user()), 422);
        $f = Friendship::firstOrCreate(['sender_id' => $request->user()->id, 'receiver_id' => $user->id], ['status' => 'pending']);
        return response()->json(['data' => $f], $f->wasRecentlyCreated ? 201 : 200);
    }
    public function respondFriend(Request $request, Friendship $friendship): JsonResponse
    {
        abort_unless($friendship->receiver_id === $request->user()->id, 403);
        $data = $request->validate(['action' => ['required', Rule::in(['accept', 'decline'])]]);
        $friendship->update(['status' => $data['action'] === 'accept' ? 'accepted' : 'rejected']);
        return response()->json(['data' => $friendship]);
    }
    private function posts(User $viewer, ?User $author = null)
    {
        $q = Post::query()->with($this->relations($viewer))->where('status', 'active')->when($author, fn($q) => $q->where('user_id', $author->id), fn($q) => $q->where(fn($p) => $p->where('user_id', $viewer->id)->orWhere('privacy', 'public')->orWhere(fn($f) => $f->where('privacy', 'friends')->whereIn('user_id', $this->friendIds($viewer)))))->latest();
        return $this->serialize($q->cursorPaginate(12), $viewer);
    }
    private function relations(User $user): array
    {
        return ['user:id,name,username,avatar', 'media', 'sharedPost.user:id,name,username,avatar', 'sharedPost.media', 'comments' => fn($q) => $q->whereNull('parent_id')->with(['user:id,name,username,avatar', 'replies.user:id,name,username,avatar'])->latest()->limit(3), 'reactions'];
    }
    private function serialize($paginator, User $viewer)
    {
        $paginator->setCollection($paginator->getCollection()->map(fn($post) => $this->postData($post, $viewer)));
        return $paginator;
    }
    private function postData(Post $post, User $viewer): array
    {
        $counts = $post->reactions->countBy('reaction');
        return ['id' => $post->id, 'user_id' => $post->user_id, 'content' => $post->content, 'privacy' => $post->privacy, 'created_at' => $post->created_at, 'author' => $this->person($post->user), 'media' => $post->media, 'comments' => $post->comments, 'comments_count' => $post->comments()->count(), 'shares_count' => $post->shares_count, 'shared_post' => $post->sharedPost ? $this->postData($post->sharedPost, $viewer) : null, 'reactions' => $counts, 'my_reaction' => $post->reactions->firstWhere('user_id', $viewer->id)?->reaction, 'saved' => $viewer->savedPosts()->whereKey($post->id)->exists()];
    }
    private function person(User $u): array
    {
        return ['id' => $u->id, 'name' => $u->name, 'username' => $u->username, 'avatar_url' => $u->avatar_url, 'cover_photo_url' => $u->cover_photo_url];
    }
    private function friendIds(User $u): array
    {
        return Friendship::where('status', 'accepted')->where(fn($q) => $q->where('sender_id', $u->id)->orWhere('receiver_id', $u->id))->get()->map(fn($f) => $f->sender_id === $u->id ? $f->receiver_id : $f->sender_id)->all();
    }
    private function friendship(User $a, User $b): ?Friendship
    {
        return Friendship::where(fn($q) => $q->where(['sender_id' => $a->id, 'receiver_id' => $b->id])->orWhere(['sender_id' => $b->id, 'receiver_id' => $a->id]))->first();
    }
    private function reportModel(Request $request, mixed $model): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', Rule::in(['spam', 'harassment', 'violence', 'scam', 'inappropriate', 'other'])], 'description' => 'nullable|string|max:1000']);
        $r = $model->reports()->firstOrCreate(['reported_by' => $request->user()->id], $data);
        return response()->json(['data' => $r], $r->wasRecentlyCreated ? 201 : 200);
    }
}
