
# Lesson 3 - API
`php ./artisan install:api` -- Creates `/routes/api.php`, installs auth (Sanctum).

`php ./artisan make:controller Api/PostController` -- Creates just `/app/Http/Controllers/Api/PostController.php`
`php ./artisan make:request Api/Post/StoreRequest` -- Creates `/app/Http/Requests/Api/Post/StoreRequest.php`
OR
`php ./artisan make:controller Api/PostController --api -m Post -r -R`:
	+ Creates *Controller* `app/Http/Controllers/Api/PostController.php`
	+ `-R` Creates *Requests* (classes for validation): `app/Http/Requests/StorePostRequest.php,  app/Http/Requests/UpdatePostRequest.php`
	+ `--api` creates only 5 methods for API in Controller: `index, store, show, update, destroy`. No methods for HTML forms: `create, edit`
	+ `-m Post` Passes Post object instead of just id string (route-model binding)
	+ `-r` not needed with `--api`

**IMPORTANT NOTE on Requests paths**:
`php ./artisan make:controller Api/PostController --api -m Post -r -R` creates both StorePostRequest.php and UpdatePostRequest.php in `app/Http/Requests/`
`php ./artisan make:request Api/Post/StoreRequest` Creates StoreRequest.php in `/app/Http/Requests/Api/Post/`
`php ./artisan make:request Api/Post/UpdateRequest` Creates UpdateRequest.php in `/app/Http/Requests/Api/Post/`

*Routes* `/routes/api.php`:
```
// Route::get('/posts', [PostController::class, 'index']);
// Route::get('/posts/{post}', [PostController::class, 'show']);
//
// Route::post('/posts', [PostController::class, 'store']);
// Route::patch('/posts/{post}', [PostController::class, 'update']);
// Route::delete('/posts/{post}', [PostController::class, 'destroy']);
// OR just one line:
Route::apiResource('posts', PostController::class);
//Route::resource('posts', PostController::class);  // also registers 'create' and 'edit' for forms
```
*Actions* in `/app/Http/Controllers/Api/PostController.php`:
```
    public function show(Post $post) {
        return PostResource::make($post)->resolve();
    }
    
    public function index() {
        return PostResource::collection(Post::all())->resolve();
    }
    
    public function store(StoreRequest $request) {
        $post = Post::create($request->validated());
        return PostResource::make($post)->resolve();

    }
    
    public function update(UpdateRequest $request, Post $post){
        $data = $request->validated();
        $post->update($data);
        $post->refresh(); // update relations
        // $post = PostService::update($post, $data);
        return PostResource::make($post)->resolve();
    }
    
    public function destroy(Post $post) {
        $post->delete();
        return response()->json([
            'message' => 'deleted',
        ], status: Response::HTTP_OK);
    }
    ...
```

Postman (get): `http://127.0.0.1:8000/api/posts`

*Validation rules* in `/app/Http/Requests/Api/Post/StoreRequest.php`:
COMMENT OUT: `public function authorize()`
```
// VALIDATION RULES:
    public function rules(): array {
        return [
            'author' => 'required|integer|min:1',
            'category' => 'nullable|integer|min:1',
            'group' => 'nullable|integer|min:1',
            'title' => 'required|string|max:255',
            'content' => 'nullable|string',
            'image_path' => 'nullable|string|max:500',
            'published_at' => 'nullable|date',
            'status' => 'nullable|integer|in:1,2,3,4',
            'is_active' => 'required|boolean',
            'likes' => 'nullable|integer|min:0',
            'views' => 'nullable|integer|min:0',
        ];
    }
```
`'title' => 'required|string|unique:posts,title,' . $this->post->id` - при редактировании (в UpdateRequest.php), если заголовк должен быть уникальным, то правило уникальности игнорируется для текущего изменяемого поста. То же, но правильнее, надежнее и безопаснее так:
```
use Illuminate\Validation\Rule;
'title' => ['required', 'string', Rule::unique('posts', 'title')->ignore($this->route('post'))],
```

Postman (post, raw, JSON, Headers: "Accept:application/json"): `http://127.0.0.1:8000/api/posts`
	body:
	```
	{
	    "title": "Title from JSON",
	    "author": 1,
	    "category": 1,
	    "is_active": 1
	}
	```

## Services (for BUSINESS LOGIC):
`php artisan make:class Services/PostService` creates `/app/Services/PostService.php`

IN `/app/Services/PostService.php`:
```
    public static function update(Post $post, array $data): Post {
   	 // business logic here
        $post->update($data);
        return $post;
    }
```

IN `/app/Http/Controllers/Api/PostController.php`:
```
    public function update(UpdateRequest $request, Post $post){
        $data = $request->validated();
        //$post->update($data);
        $post = PostService::update($post, $data);
        return PostResource::make($post)->resolve();
    }
```

## Homework
Create:
- api Routes
- api Controllers with CRUD actions
- api Requests with validation rules

DO NOT create Services

NOTE:
- for 'status' validation rule get allowed statuses list from Post model
- for UpdateRequest let non-unique title: ` 'title' => ['required', 'string', Rule::unique('posts', 'title')->ignore($this->route('post'))], `
