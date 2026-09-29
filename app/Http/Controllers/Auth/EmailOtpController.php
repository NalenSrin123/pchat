<?php

namespace App\Http\Controllers\Auth;

use App\Mail\EmailOtpMail;
use App\Models\EmailOtp;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class EmailOtpController extends Controller
{
    public function send(Request $request) { $email = Str::lower($request->validate(['email'=>['required','email']])['email']); $key='otp:send:'.$email.'|'.$request->ip(); abort_if(RateLimiter::tooManyAttempts($key,1),429,'Please wait before requesting another code.'); $code=(string)random_int(100000,999999); EmailOtp::updateOrCreate(['email'=>$email],['otp'=>Hash::make($code),'expires_at'=>now()->addMinutes(5)]); Mail::to($email)->send(new EmailOtpMail($code)); RateLimiter::hit($key,60); return response()->json(['message'=>'Verification code sent.','resend_in'=>60]); }
    public function verify(Request $request) { $data=$request->validate(['email'=>['required','email'],'otp'=>['required','digits:6']]); $email=Str::lower($data['email']); $key='otp:verify:'.$email.'|'.$request->ip(); abort_if(RateLimiter::tooManyAttempts($key,5),429,'Too many attempts.'); $otp=EmailOtp::where('email',$email)->first(); if (!$otp || $otp->expires_at->isPast() || !Hash::check($data['otp'],$otp->otp)) { RateLimiter::hit($key,300); abort(422,'The verification code is invalid or expired.'); } $user=User::firstOrCreate(['email'=>$email],['name'=>Str::headline(Str::before($email,'@')),'username'=>$this->username($email),'password'=>Str::random(40),'email_verified_at'=>now()]); if (!$user->email_verified_at) $user->forceFill(['email_verified_at'=>now()])->save(); $otp->delete(); RateLimiter::clear($key); Auth::login($user,true); $request->session()->regenerate(); return response()->json(['redirect'=>route('dashboard')]); }
}
