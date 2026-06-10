# Lesson 6 Morph Relationships

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
    public function image(): MorphOne {
        return $this->morphOne(Image::class, 'imageable');
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

    public function image(): MorphOne {
        return $this->morphOne(Image::class, 'imageable');
    }
```



*Post*: morphOne ↔ *Image*: morphTo
*Post*: morphMany ↔ *Comment*: morphTo
*Post*: morphToMany (Likes) ↔  *Profiles*: morphedByMany

===
morphOne, morphMany 	↔ 	morphTo
morphToMany		        ↔	morphedByMany

## Homework
Create Morph Relationships:
- Make Post and Profile Imageable (Post - one to many, Profile - one to one)
- Make Post and Comment Commentable
- Make Post and Comment Likeable
- Make Post and Comment Tagable

Change seeders accordingly.
