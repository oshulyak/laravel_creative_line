# Lesson 4 - Eloquent Relationships
Relationships types:
- one to one
- one to many
- many to many  

## migrations
in Profiles migration:
```php
        Schema::create('profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->index()->constrained('users');
            ...
        });
```

in Posts migration:
```php
        Schema::create('posts', function (Blueprint $table) {
            $table->foreignId('author_id')->index()->constrained('profiles');
            $table->foreignId('category_id')->index()->constrained('categories');
            ....
```

in Profiles migration:
```php
    public function up(): void {
        Schema::create('profiles', function (Blueprint $table) {
            $table->foreignId('user_id')->index()->constrained('users');
            ....
```
  
in Comments migration:
```php
        Schema::create('comments', function (Blueprint $table) {
            $table->foreignId('post_id')->index()->constrained('posts');
            $table->foreignId('author_id')->index()->constrained('profiles');
            $table->foreignId('parent_id')->nullable()->index()->constrained('comments');
            ...
```

Pivot table for tags (naming: singular, alphabetical order):
`php artisan make:migration create_post_tag_table`
```php
        Schema::create('post_tag', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->index()->constrained('posts');
            $table->foreignId('tag_id')->index()->constrained('tags');
            $table->unique(['post_id', 'tag_id']);
            $table->timestamps();
        });

```

Pivot table for likes (naming: singular, alphabetical order, with context):
`php artisan make:migration create_post_profile_likes_table`
```php
        Schema::create('post_profile_likes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->index()->constrained('profiles');
            $table->foreignId('post_id')->index()->constrained('posts');
            $table->unique(['profile_id', 'post_id']);
            $table->timestamps();
        });
```

*Creating model for pivot table is optional.*


*"Undefined table"* errors are possible during migrations run. FIX: Change migration name (datetime prefix) to change table creation order.
 

## models
Models Relationships Types:
- hasOne
- hasMany
- belongsTo
- belongsToMany  

in User model:
```php
    public function profile(): HasOne {
        return $this->hasOne(Profile::class);
    }
```

in Profile model:
```php
    public function user(): BelongsTo {
        return $this->belongsTo(User::class);
    }

    public function posts(): HasMany {
        return $this->hasMany(Post::class, 'author_id');
    }
```

in Post model:
```php
    public function tags(): BelongsToMany {
        return $this->belongsToMany(Tag::class)->withTimestamps();
    }
    
    public function likedByProfiles(): BelongsToMany{
		return $this->belongsToMany(Profile::class, 'post_profile_likes', 'post_id', 'profile_id');
	}
```

in Tag model:
```php
    public function posts(): BelongsToMany {
        return $this->belongsToMany(Post::class);
    }
```

Now we can call as follows:
```php
$user->profile;     // hasOne
$profile->user;		// belongsTo
$profile->posts;    // hasMany
$post->tags;        // belongsToMany
// ----
$post->tags; // cached data
$post->tags(); // new query every time
```

## Custom commands

`php artisan make:command MyTest` - creates custom command in /app/Console/Commands/GoCommand
В файле команды:
`#[Signature('my:test')]`

```php
public function handle(){
 	Post::create([ 'author' => 12313, 'category' => 12313, 'title' => '12313', ]); 
 	Post::find(id);
 	Post::first();
 	$post->update(['title'=>'new title']);
 	$post->delete();
 	$posts->Post::all();
}
```

## Homework (ДЗ)
1. Determine which relationships should exist between the models.
2. Implement these relationships.
3. Create some mock data in MyTest command (no seeds at this stage): create several users, profiles, posts, categories, tags, likes with relationships. Display data with some dd().
