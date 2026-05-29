# Lesson 2 : Routes, Controllers, Resources

## Routes
`/routes/web.php`
```
Route::get('posts', [PostController::class, 'index']);
Route::get('posts/{post}/show', [PostController::class, 'show']);
// index
// show
// store
// update
// destroy
```

## Controllers
 Create controller: `php artisan make:controller PostController`
`app/Http/Controllers/PostController.php`:
```
    public function index() {
        // return Post::all();
        return PostResource::collection(Post::all())->resolve();
    }
    public function show(Post $post) {
        return PostResource::make($post)->resolve();
        // return $post->toResource()->resolve();
    }
// index ...
// show ...
// store ...
// update ...
// destroy (return 'success' and 'HTTP OK' for frontend API calls) ...
```

Standard names for controller methods:
- index (list)
- show (one)
- create (show form)
- store (save)
- edit (show form)
- update (save)
- destroy (delete)

## Resources
cleanup and format data for JSON
`php artisan make:resource Post/PostResource`

```
    public function toArray(Request $request): array {
        return [
            'id' => $this->id,
            'author' => $this->author,
            'title' => $this->title,
        ];
```

## Fillable fields
1. fillable list (in Model) 
```   
    protected $fillable = [
        'author_id', ....
    ];
```
	OR (for all fields in a Model):	
2. guarded false (in a Model) 
`protected static $guarded = false;` 
	OR (FOR ALL MODELS):
3. Model::unguard()
`Model::unguard();`  in `public function boot()`  in `app\Providers\AppServiceProvider.php` 

## Homework

Create CRUD operations for all models existing in the project.
Create Resource for Post.
