<?php

namespace App\Http\Controllers\API\V1\JWT;

use App\Http\Controllers\API\V1\APIController;
use App\Http\Controllers\Controller;
use App\Http\Requests\API\Auth\LoginRequest;
use App\Http\Requests\API\Auth\ReferralCodeRequest;
use App\Http\Requests\API\Auth\RegisterRequest;
use App\Http\Requests\API\ForgotPasswordRequest;
use App\Http\Resources\API\UserResource;
use App\Http\Services\PushNotificationService;
use App\Jobs\ForgotPasswordEmailJob;
use App\Jobs\WelcomeEmailJob;
use App\Mail\ForgotPasswordMail;
use App\Mail\NewUserVerificationMail;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    use APIController;
    /**
     * Create a new AuthController instance.
     *
     * @return void
     */
    public function __construct()
    {

    }

    /**
     * Register a User.
     * @param  RegisterRequest  $request
     * @return JsonResponse
     */
    public function register(RegisterRequest $request)
    {

        try {
            $user = User::query()->create(array_merge(
                $request->all(),
                [
                    'password' => bcrypt($request->password),
                    'user_type_id'  => 1 //user type id
                ]
            ));


            $token = auth('api')->login($user);

            $this->setTokenAttributes($user, $token);
            if ($request->referral_code){
                $validate_referral = $this->validateReferralCode($user->id, $request->referral_code);
                if ($validate_referral !== true) {
                    $user->delete();
                    return $validate_referral;
                }
            }
//            $this->dispatch(new WelcomeEmailJob($request->email));
            Mail::to($request->email)->send(new NewUserVerificationMail($request->email));
            return $this->respond(UserResource::make($user), __('message.register.registered_successfully'));
        }catch (\Exception $e){
            return $this->respondServerError($e,__('message.something_went_wrong'));
        }
    }

    /**
     * Register a User.
     * @param  RegisterRequest  $request
     * @return JsonResponse
     */
    public function registerAsTrader(RegisterRequest $request)
    {

        try {
            $user = User::query()->create(array_merge(
                $request->all(),
                [
                    'password' => bcrypt($request->password),
                    'user_type_id'  => 2 //user type id
                ]
            ));


            $token = auth('api')->login($user);

            $this->setTokenAttributes($user, $token);
            if ($request->referral_code){
                $validate_referral = $this->validateReferralCode($user->id, $request->referral_code);
                if ($validate_referral !== true) {
                    $user->delete();
                    return $validate_referral;
                }
            }
            Mail::to($request->email)->send(new NewUserVerificationMail($request->email));
            return $this->respond(UserResource::make($user), __('message.register.registered_successfully'));
        }catch (\Exception $e){
            return $this->respondServerError($e,__('message.something_went_wrong'));
        }
    }

    /**
     * Get a JWT via given credentials.
     * @param  LoginRequest  $request
     * @return JsonResponse
     */
    public function login(LoginRequest $request)
    {
        try {
            if (!$token = auth('api')->attempt($request->only('email', 'password'))) {
                return $this->respondUnauthorized(__('message.unauthorized'));
            }
            $user = auth('api')->user();

            $this->setTokenAttributes($user, $token);

            return $this->respond(UserResource::make($user));
        }catch (\Exception $e){
            return $this->respondServerError($e);
        }
    }

    /**
     * Get the authenticated User.
     * @param Request $request
     * @return JsonResponse
     */
    public function profile(Request $request)
    {
        try {
            $user = User::query()->find(request()->user_id);
            if ($request->has('token')){
                $user->firebase_token = $request->token;
                $user->save();
            }
            return $this->respond(UserResource::make($user));
        }catch (\Exception $e){
            return $this->respondServerError($e);
        }
    }

    /**
     * Log the user out (Invalidate the token).
     *
     * @return JsonResponse
     */
    public function logout()
    {
        try {
            $user = User::query()->find(request()->user_id);
            $user->setRememberToken('');
            $user->save();
            return $this->respond([], 'Successfully logged out');
        }catch (\Exception $e){
            return $this->respondServerError($e);
        }
    }

    /**
     * Refresh a token.
     *
     * @return JsonResponse
     */
    public function refresh()
    {
        return $this->respond($this->createNewToken(auth('api')->refresh()));
    }

    /**
     * Get the token array structure.
     *
     * @param  string $token
     *
     * @return array
     */
    protected function createNewToken($token)
    {
        return [
            'access_token' => $token,
            'token_type' => 'Bearer',
            'expires_in' => auth('api')->factory()->getTTL() * 60
        ];
    }

    protected function setTokenAttributes(&$user,$token)
    {
        $user->remember_token = $token;
        $user->save();

        $user->setAttribute('token', $token);
        $user->setAttribute('expires_in', auth('api')->factory()->getTTL() * 60);
    }

    public function forgotPassword(ForgotPasswordRequest $request)
    {
        $user = User::query()->where('email',$request->email)->first();
        if (!$user){
            return $this->respondBadRequest(__('message.no_account_found_associated_with_provided_email'));
        }else{
            try {
                $token = Str::random(20);

                DB::table('password_resets')
                        ->where('email',$request->email)
                        ->delete();
                DB::table('password_resets')->insert([
                    'email' => $request->email,
                    'token' => $token,
                    'created_at' => now()
                    ]);
                Mail::to($request->email)->send(new ForgotPasswordMail($token));
            }catch (\Exception $e){
                return $this->respondServerError($e,__('message.error_in_sending_email'));
            }
        }
        return $this->respond([],__('message.reset_password_email_sent'));
    }

    public function referralCode(ReferralCodeRequest $request)
    {
        try {
            $validate_code = $this->validateReferralCode($request->user_id, $request->referral_code);
            if ($validate_code === true) {
                return $this->respondCreated([], __('message.referral_code_added_successfully'));
            } else {
                return $validate_code;
            }
        }catch (\Exception $e){
            return $this->respondServerError($e);
        }
    }

    private function validateReferralCode($user_id,$referral_code)
    {
        try {
            $user = User::query()->select('id', 'coin_balance', 'referer_id',
                'created_at','firebase_token')->where('id', $user_id)->first();

            if ($user->referer_id) {
                return $this->respondBadRequest(__('message.already_used_referrer_code'));
            }

            $referral_user = User::query()
                ->select('id', 'coin_balance','firebase_token')
                ->where(function ($query) use ($referral_code) {
                    $query->where('user_code', '=', $referral_code);
                    $query->orWhere('referral_code', '=', $referral_code);
                })
                ->where('created_at', '<', $user->created_at)
                ->first();
            if (!$referral_user) {
                return $this->respondBadRequest(__('message.incorrect_referral_code'));
            }
            $referral_user->coin_balance += 100;
            $referral_user->save();
            PushNotificationService::sendTransactionNotification(
                __('message.you_have_earned_new_coins'),
                '+',
                '100',
                'coins',
                $referral_user->firebase_token
            );
            $user->coin_balance += 100;
            $user->referer_id = $referral_user->id;
            $user->save();
            PushNotificationService::sendTransactionNotification(
                __('message.you_have_earned_new_coins'),
                '+',
                '100',
                'coins',
                $user->firebase_token
            );
            return true;
        }catch (\Exception $e){
            return $this->respondServerError($e);
        }
    }

    public function testNotification($id = null)
    {
        if ($id){
            $user = User::query()->where('id',$id)->first();
        }else{
            $user = User::query()->where('id',request()->user_id)->first();
        }
        return PushNotificationService::sendTransactionNotification('test',
            '+',
            '100',
            'Points',
            $user->firebase_token
        );
    }
}
