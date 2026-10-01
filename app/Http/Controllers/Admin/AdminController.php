<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\{AdminAuditLog, AppSetting, Comment, Conversation, Message, MessageReport, Post, User, UserModerationAction};
use Illuminate\Http\{RedirectResponse, Request};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AdminController extends Controller
{
    public function dashboard(): Response
    {
        return Inertia::render('Admin/Dashboard', ['stats' => [
            ['label'=>'Total Users','value'=>User::count()], ['label'=>'Active Users','value'=>User::where('status','active')->count()],
            ['label'=>'Online Now','value'=>User::where('last_seen_at','>',now()->subMinutes(2))->count()], ['label'=>'Suspended Users','value'=>User::where('status','suspended')->count()],
            ['label'=>'Banned Users','value'=>User::where('status','banned')->count()], ['label'=>'Total Conversations','value'=>Conversation::count()],
            ['label'=>'Private Conversations','value'=>Conversation::where('type','private')->count()], ['label'=>'Group Conversations','value'=>Conversation::where('type','group')->count()],
            ['label'=>'Messages Today','value'=>Message::whereDate('created_at',today())->count()], ['label'=>'Total Messages','value'=>Message::withTrashed()->count()],
            ['label'=>'Images / Files','value'=>Message::whereIn('type',['image','file'])->count()], ['label'=>'Voice Messages','value'=>Message::where('type','voice')->count()],
            ['label'=>'Reported Messages','value'=>MessageReport::where('status','pending')->count()],
        ], 'activity'=>AdminAuditLog::with('admin:id,name')->latest('created_at')->limit(10)->get()]);
    }

    public function users(Request $request): Response
    {
        $filters=$request->validate(['search'=>'nullable|string|max:100','status'=>['nullable',Rule::in(['active','suspended','banned'])]]);
        $users=User::query()->withCount(['messages','conversations'])->when($filters['search']??null, fn($q,$s)=>$q->where(fn($x)=>$x->where('name','like',"%$s%")->orWhere('email','like',"%$s%")->orWhere('username','like',"%$s%")))->when($filters['status']??null,fn($q,$s)=>$q->where('status',$s))->latest()->paginate(15)->withQueryString();
        return Inertia::render('Admin/Users', compact('users','filters'));
    }

    public function user(User $user): Response
    {
        $stats=['messages'=>$user->messages()->withTrashed()->count(),'conversations'=>$user->conversations()->count(),'groups'=>$user->conversations()->where('type','group')->count(),'attachments'=>$user->messages()->whereIn('type',['image','file'])->count(),'voice'=>$user->messages()->where('type','voice')->count()];
        return Inertia::render('Admin/UserDetail',['user'=>$user,'stats'=>$stats,'history'=>$user->moderationActions()->with('admin:id,name')->latest()->get()]);
    }

    public function moderate(Request $request, User $user): RedirectResponse
    {
        abort_if($user->id === $request->user()->id, 422, 'You cannot moderate your own account.');
        $data=$request->validate(['action'=>['required',Rule::in(['suspend','unsuspend','ban','unban'])],'reason'=>'nullable|string|max:1000','expires_at'=>'nullable|date|after:now']);
        abort_if(in_array($data['action'],['suspend','ban'],true) && blank($data['reason']),422,'A reason is required.');
        $status=match($data['action']) {'suspend'=>'suspended','ban'=>'banned',default=>'active'};
        DB::transaction(function() use($request,$user,$data,$status) { $user->update(['status'=>$status,'suspended_until'=>$data['action']==='suspend'?$data['expires_at']:null,'ban_reason'=>$data['action']==='ban'?$data['reason']:null]); UserModerationAction::create(['user_id'=>$user->id,'admin_id'=>$request->user()->id,'action'=>$data['action'],'reason'=>$data['reason']??null,'expires_at'=>$data['action']==='suspend'?$data['expires_at']:null]); $this->audit($request,match($data['action']) {'suspend'=>'USER_SUSPENDED','unsuspend'=>'USER_UNSUSPENDED','ban'=>'USER_BANNED','unban'=>'USER_UNBANNED'},$user,$data['reason']??null); });
        return back();
    }

    public function conversations(Request $request): Response
    {
        $filters=$request->validate(['search'=>'nullable|string|max:100','type'=>['nullable',Rule::in(['private','group'])]]);
        $conversations=Conversation::query()->with(['creator:id,name','members:id,name,email'])->withCount('messages')->when($filters['type']??null,fn($q,$t)=>$q->where('type',$t))->when($filters['search']??null,fn($q,$s)=>$q->where(fn($x)=>$x->where('conversations.id',$s)->orWhere('name','like',"%$s%") ->orWhereHas('members',fn($m)=>$m->where('name','like',"%$s%")->orWhere('email','like',"%$s%"))))->latest()->paginate(15)->withQueryString();
        return Inertia::render('Admin/Conversations',compact('conversations','filters'));
    }
    public function groups(Request $request): Response { $filters=$request->validate(['search'=>'nullable|string|max:100']); $groups=Conversation::where('type','group')->with(['creator:id,name'])->withCount(['members','messages'])->when($filters['search']??null,fn($q,$s)=>$q->where('name','like',"%$s%"))->latest()->paginate(15)->withQueryString(); return Inertia::render('Admin/Groups',compact('groups','filters')); }
    public function moderateGroup(Request $request, Conversation $conversation): RedirectResponse { abort_unless($conversation->type==='group',404); $data=$request->validate(['action'=>['required',Rule::in(['suspend','restore'])],'reason'=>'nullable|string|max:1000']); abort_if($data['action']==='suspend'&&blank($data['reason']),422,'A reason is required.'); $conversation->update(['status'=>$data['action']==='suspend'?'suspended':'active','moderation_reason'=>$data['action']==='suspend'?$data['reason']:null]); $this->audit($request,$data['action']==='suspend'?'GROUP_SUSPENDED':'GROUP_RESTORED',$conversation,$data['reason']??null); return back(); }
    public function reports(Request $request): Response { $filters=$request->validate(['status'=>['nullable',Rule::in(['pending','reviewed','dismissed','actioned'])]]); $reports=MessageReport::with(['reporter:id,name,email','message.sender:id,name'])->when($filters['status']??null,fn($q,$s)=>$q->where('status',$s))->latest()->paginate(15)->withQueryString(); return Inertia::render('Admin/Reports',compact('reports','filters')); }
    public function report(MessageReport $report): Response { $report->load(['reporter:id,name,email','message.sender:id,name,email','message.conversation']); $message=$report->message; abort_unless($message,404); $context=$message->conversation->messages()->with(['sender:id,name'])->whereBetween('id',[max(1,$message->id-1),$message->id+1])->withTrashed()->get(); return Inertia::render('Admin/ReportDetail',compact('report','context')); }
    public function resolveReport(Request $request, MessageReport $report): RedirectResponse { $data=$request->validate(['action'=>['required',Rule::in(['dismiss','remove_message','warn','suspend','ban'])],'reason'=>'nullable|string|max:1000','expires_at'=>'nullable|date|after:now']); abort_if(in_array($data['action'],['remove_message','suspend','ban'],true)&&blank($data['reason']),422,'A reason is required.'); $message=$report->message; DB::transaction(function() use($request,$report,$data,$message) { if($data['action']==='remove_message'&&!$message->trashed()) {$message->update(['deleted_by'=>$request->user()->id,'deletion_reason'=>$data['reason']]);$message->delete();} if(in_array($data['action'],['suspend','ban'],true)) {$target=$message->sender; $target->update(['status'=>$data['action']==='ban'?'banned':'suspended','suspended_until'=>$data['action']==='suspend'?$data['expires_at']:null,'ban_reason'=>$data['action']==='ban'?$data['reason']:null]); UserModerationAction::create(['user_id'=>$target->id,'admin_id'=>$request->user()->id,'action'=>$data['action'],'reason'=>$data['reason'],'expires_at'=>$data['expires_at']??null]);} $report->update(['status'=>$data['action']==='dismiss'?'dismissed':'actioned','reviewed_by'=>$request->user()->id,'reviewed_at'=>now()]); $this->audit($request,match($data['action']){'dismiss'=>'REPORT_DISMISSED','remove_message'=>'MESSAGE_REMOVED',default=>'REPORT_ACTIONED'},$report,$data['reason']??null,['resolution'=>$data['action']]); }); return redirect()->route('admin.reports.index'); }
    public function auditLogs(): Response { return Inertia::render('Admin/AuditLogs',['logs'=>AdminAuditLog::with('admin:id,name')->latest('created_at')->paginate(20)]); }
    public function posts(Request $request): Response { $filters=$request->validate(['status'=>['nullable',Rule::in(['active','removed'])],'type'=>['nullable',Rule::in(['text','image','video','reported'])]]); $posts=Post::with('user:id,name')->withCount(['reports','media'])->when(($filters['status']??null)==='removed',fn($q)=>$q->onlyTrashed(),fn($q)=>$q->where('status','active'))->when(($filters['type']??null)==='reported',fn($q)=>$q->has('reports'))->when(in_array($filters['type']??'', ['image','video']),fn($q)=>$q->whereHas('media',fn($m)=>$m->where('type',$filters['type'])))->latest()->paginate(15)->withQueryString(); return Inertia::render('Admin/SocialPosts',compact('posts','filters')); }
    public function comments(Request $request): Response { $comments=Comment::with(['user:id,name','post:id,user_id,content','reports'])->withCount('reports')->latest()->paginate(20); return Inertia::render('Admin/SocialComments',compact('comments')); }
    public function moderatePost(Request $request, Post $post): RedirectResponse { $data=$request->validate(['action'=>['required',Rule::in(['remove','restore'])],'reason'=>'nullable|string|max:1000']); abort_if($data['action']==='remove'&&blank($data['reason']),422,'A moderation reason is required.'); if($data['action']==='remove'){$post->update(['status'=>'removed']);$post->delete();}else{$post->restore();$post->update(['status'=>'active']);}$this->audit($request,$data['action']==='remove'?'POST_REMOVED':'POST_RESTORED',$post,$data['reason']??null);return back(); }
    public function moderateComment(Request $request, Comment $comment): RedirectResponse { $data=$request->validate(['action'=>['required',Rule::in(['remove','restore'])],'reason'=>'nullable|string|max:1000']); abort_if($data['action']==='remove'&&blank($data['reason']),422,'A moderation reason is required.'); if($data['action']==='remove')$comment->delete();else $comment->restore();$this->audit($request,$data['action']==='remove'?'COMMENT_REMOVED':'COMMENT_RESTORED',$comment,$data['reason']??null);return back(); }
    public function settings(): Response { $defaults=['allow_registration'=>'1','max_image_upload_kb'=>'5120','max_file_upload_kb'=>'20480','max_voice_upload_kb'=>'16384','max_group_members'=>'100']; $settings=collect($defaults)->map(fn($value,$key)=>AppSetting::valueFor($key,$value)); return Inertia::render('Admin/Settings',compact('settings')); }
    public function updateSettings(Request $request): RedirectResponse { $data=$request->validate(['allow_registration'=>'required|boolean','max_image_upload_kb'=>'required|integer|min:1|max:51200','max_file_upload_kb'=>'required|integer|min:1|max:102400','max_voice_upload_kb'=>'required|integer|min:1|max:51200','max_group_members'=>'required|integer|min:2|max:1000']); foreach($data as $key=>$value) AppSetting::updateOrCreate(['key'=>$key],['value'=>(string)$value]); $this->audit($request,'SETTINGS_UPDATED',null,null,['keys'=>array_keys($data)]); return back(); }
    private function audit(Request $request,string $action,mixed $target,?string $reason=null,array $metadata=[]):void { AdminAuditLog::create(['admin_id'=>$request->user()->id,'action'=>$action,'target_type'=>$target?->getMorphClass(),'target_id'=>$target?->id,'reason'=>$reason,'metadata'=>$metadata?:null,'ip_address'=>$request->ip()]); }
}
