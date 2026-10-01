<?php
namespace App\Http\Middleware;
use Closure; use Illuminate\Http\Request; use Symfony\Component\HttpFoundation\Response;
class EnsureAccountActive { public function handle(Request $request, Closure $next): Response { $user=$request->user(); if (!$user) return $next($request); if ($user->status === 'suspended' && $user->suspended_until?->isPast()) $user->forceFill(['status'=>'active','suspended_until'=>null])->save(); abort_if(in_array($user->fresh()->status, ['suspended','banned'], true), 403, 'Your account is currently restricted.'); return $next($request); } }
