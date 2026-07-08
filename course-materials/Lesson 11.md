# Lesson 11 - Authorization (JWT)
## JWT
`composer require tymon/jwt-auth`
`php artisan vendor:publish --provider="Tymon\JWTAuth\Providers\LaravelServiceProvider"`


`/config/jwt.php`:
```php
	'ttl' => env('JWT_TTL', 60);  // время жизни токена
	'refresh ttl' => env('JWT_REFRESH_TTL', 20160); // время жизни refresh токена
```

`php artisan jwt:secret`  // adds JWT_SECRET to .env


IN User.php:
```php
namespace App;

use Tymon\JWTAuth\Contracts\JWTSubject;
use Illuminate\Notifications\Notifiable;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable implements JWTSubject{
    use Notifiable;
    // Get the identifier that will be stored in the subject claim of the JWT.
    public function getJWTIdentifier(){
        return $this->getKey();
    }

    // Return a key value array, containing any custom claims to be added to the JWT.
     public function getJWTCustomClaims(){
        return [];
    }
}
```

IN .env:
```
AUTH_GUARD=api
```

IN config/auth.php:
```php
    'defaults' => [
        'guard' => env('AUTH_GUARD', 'web'), // по умаолчанию web, но подставится api из .env
        'passwords' => env('AUTH_PASSWORD_BROKER', 'users'),
    ],    
    
    

   'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'users',
        ],
        
        'api' => [
	        'driver' => 'jwt',
	        'provider' => 'users',
	    ],
    ],


```

`php artisan make:controller AuthController`

IN routes/api.php:
```php
	Route::post('auth/login', [AuthController:class, 'login']);
	Route::group(['middleware' => 'jwt.auth', 'prefix' => 'auth'], function () {
	    Route::post('logout', [AuthController::class, 'logout']);
	    Route::post('refresh', [AuthController::class, 'refresh']);
	    Route::post('me', [AuthController::class, 'me']);
	});
	
	
	Route::group(['middleware' => ['jwt.auth', isAdminMiddleware::class]], function () {
		Route::apiResource('posts', PostController::class);
	});
```


`php artisan make:controller Api/AuthController`:
```php
class AuthController extends Controller{
    /**
     * Create a new AuthController instance.
     *
     * @return void
     */
   // public function __construct(){  // OUTDATED (instead: move login route out of auth group in api.php ↑)
   //     $this->middleware('auth:api', ['except' => ['login']]);
   // }

    /**
     * Get a JWT token via given credentials.
     *
     * @param  \Illuminate\Http\Request  $request
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function login(Request $request){
        $credentials = $request->only('email', 'password');

        if ($token = $this->guard()->attempt($credentials)) {
            return $this->respondWithToken($token);
        }

        return response()->json(['error' => 'Unauthorized'], 401);
    }

    /**
     * Get the authenticated User
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function me(){
        return response()->json($this->guard()->user());
    }

    /**
     * Log the user out (Invalidate the token)
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function logout(){
        $this->guard()->logout();

        return response()->json(['message' => 'Successfully logged out']);
    }

    /**
     * Refresh a token.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function refresh(){
        return $this->respondWithToken($this->guard()->refresh());
    }

    /**
     * Get the token array structure.
     *
     * @param  string $token
     *
     * @return \Illuminate\Http\JsonResponse
     */
    protected function respondWithToken($token){
        return response()->json([
            'access_token' => $token,
            'token_type' => 'bearer',
            'expires_in' => $this->guard()->factory()->getTTL() * 60
        ]);
    }

    /**
     * Get the guard to be used during authentication.
     *
     * @return \Illuminate\Contracts\Auth\Guard
     */
    public function guard(){
        return Auth::guard('api');
    }
```
