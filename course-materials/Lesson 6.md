# Lesson 6 - Soft Delete, Relations Through, Casts

## Soft Delete
IN migrations: 
```php
$table->softDeletes(); // adds `deleted_at` field (timestamp)
```

```php
use SoftDeletes; // // adds a global scope that auto-excludes soft-deleted rows (WHERE deleted_at IS NULL)

$model->delete(); // soft deletes
Model::withTrashed(); Model::onlyTrashed(); // Get deleted
$model->restore(); // restore
$model->forceDelete(); // delete record from DB
```


## Relations Through
IN Category.php:
```php
    public function comments(): HasManyThrough {
        return $this->hasManyThrough(Comment::class, Post::class); // Вытаскиваем комеенты постов определённой категории
    }  

    public function comment(): HasOneThrough {
        return $this->hasOneThrough(Comment::class, Post::class); // Случайный комент (в данном случае смысла нет)
    }
```

Eager loading:
```php
	$comment->post->category; // lazy load
	
	Comment::with('post.category')->get(); // Eager load all comments with categories	

	$comments->load('post.category'); // add categories to loaded comments collection	
```



## Casts
Сonvert raw database values into specific PHP data types—and vice versa—when reading from or writing to DB.
IN User.php
```php
    protected function casts(): array {
        return [
            'email_verified_at' => 'datetime',Ctrl + Shift + V
            'password' => 'hashed',
        ];
    }
```

## Homework
1. Find Models for which Relations Through are applicable
2. Create Relations Through

### Created Relations Through

| Родитель (метод) | Через | Цель | Смысл | Ключи |
|---|---|---|---|---|
| `Category::comments()` | Post | Comment | комментарии ко всем постам категории | по умолчанию (`category_id`, `post_id`) |
| `User::posts()` | Profile | Post | посты пользователя (через профиль) | свои: `user_id`, `author_id` |
| `User::comments()` | Profile | Comment | комментарии пользователя (через профиль) | свои: `user_id`, `author_id` |
| `Profile::postComments()` | Post | Comment | комментарии к постам профиля | свои: `author_id`, `post_id` |