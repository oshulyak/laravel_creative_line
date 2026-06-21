# Lesson 7 - Morph Relationships
`php artisan make:model Image -m`:
IN Image.php:
```php
    public function imageable(): MorphTo {
        return $this->morphTo();
    }
```

IN Comment.php :
```php
  public function commentable(): MorphTo {
        return $this->morphTo();
    }
```

IN `images_table`:
```php
    public function up(): void {
        Schema::create('images', function (Blueprint $table) {
            $table->id();
            $table->string('img_path');
            $table->morphs('imageable');
            $table->timestamps();
        });
    }
```

IN `comments_table`:
```php
            $table->morphs('commentable');
            // $table->foreignId('post_id')->index()->constrained('posts');
```

`php artisan make:migration create_likeables_table`:
```php
    public function up(): void {
        Schema::create('likeables', function (Blueprint $table) {
            $table->id();
            $table->morphs('likeable');
            $table->foreignId('profile_id')->index()->constrained('profiles');
            $table->timestamps();
            $table->unique(['profile_id', 'likeable_type', 'likeable_id']);
        });
    }
```

IN Post.php :
```php
    public function images(): MorphMany {
        return $this->morphMany(Image::class, 'imageable');
    }  

    public function comments(): MorphMany {
        return $this->morphMany(Comment::class, 'commentable');
    } 

    public function likedByProfiles(): MorphToMany {
        return $this->morphToMany(Profile::class, 'likeable');
    }
```

IN Profile.php :
```php
    public function likedPosts(): MorphToMany {
        return $this->morphedByMany(Post::class, 'likeable');
    }  

    public function likedComments(): MorphToMany {
        return $this->morphedByMany(Comment::class, 'likeable');
    }  

    public function images(): MorphOne {
        return $this->morphOne(Image::class, 'imageable');
    }
```



*Post*: morphMany ↔ *Image*: morphTo
*Post*: morphMany ↔ *Comment*: morphTo
*Post*: morphToMany (Likes) ↔  *Profiles*: morphedByMany

morphOne, morphMany 	↔ 	morphTo
morphToMany		        ↔	morphedByMany

## Homework
Create Morph Relationships:
1. Imageable: Category (one to one), Comment (one to many), Post (one to many), Profile (one to one)
2. Fileable: Comment (one to many), Post (one to many), Image (one to one)
3. Likeable (many to many): Profile ↔ Comment, Profile ↔ Post, Profile ↔ Image
4. Commentable: Comment (one to many), Post (one to many)
5. Tagable: Comment (many to many), Post (many to many)

Change seeders accordingly.
Make demonstration by `dd()` in `app\Console\Commands\MyTest.php`.