# Lesson 5 - Factories, Seeders
## GENERAL
[Lesson](<obsidian://open?vault=course-materials&file=Lesson 5>)
Purpose:
- populating DB with mock data
- pre-populating DB with necessary initial data

- *Seeders*: what and how many to generate. --- Seeders are *optional*. Needed only if some logic is needed.
- *Factory*: what data to generate (for one row)
- *DatabaseSeeder*: entry point to launch seeding

`php artisan db:seed` 
	OR
`php artisan migrate:fresh --seed`

## DatabaseSeeder
`database\seeders\DatabaseSeeder.php` - invoked by `php artisan db:seed`
```php
    public function run(): void{
   // Block1: First user:
        $userData = ['name' => 'Test User','email' => 'user@mail.ru','password' => 'password'];  

        $user = User::firstOrCreate(
            ['email' => $userData['email']],
            $userData
        );  

        Profile::firstOrCreate(
            ['user_id' => $user->id],
            [
                'first_name' => 'Test',
                'second_name' => 'User',
            ]
        );    
    // user and profile created THROUGH the relation 
        $user = User::factory()->create();
        $user->profile()->create([
            'first_name' => 'BORIS',
        ]);
    
    //Block3: 
        $this->call([
        	CategorySeeder::class,
            PostSeeder::class
        ]);
    }
```

## Posts
`php artisan make:seeder PostSeeder`
IN *PostSeeder* `database\seeders\PostSeeder.php`:
```php
    public function run(): void {
    	Post::factory(10)->make(); // create object instances (optional)
    	Post::factory(10)->create();    // write to DB
    }
```

IN *Post*`app/models/Post.php`:
```php
	use HasFactory; // allows factories
// ....
    public function tags(): BelongsToMany {
        return $this->belongsToMany(Tag::class)->withTimestamps(); // `withTimestamps` adds timestamp field to pivot table
    }
```

`php artisan make:factory PostFactory`
IN *PostFactory* `database\factories\PostFactory.php`:
```php
    public function definition(): array {
        return [
        	'author_id' => Profile::inRandomOrder()->first()->id,
        	'category_id' => Category::inRandomOrder()->first()->id,
            'title' => fake()->unique()->sentence(), // or fake()->realTextBetween(100, 255),
            'content' => fake()->paragraphs(3, true),
            'status' => fake()->randomKey(Post::getStatuses()), // ? проверить, возвращает ли он 1,2...
            'published_at' => fake()->dateTimeBetween('-1 month', 'now'),
        ];
    }
```

## Tags
`php artisan make:seeder TagSeeder`
IN *TagSeeder* `database\seeders\TagSeeder.php`:
```php
    public function run(): void {
    	Tag::factory(10)->create(); 
    }
```

`php artisan make:factory TagFactory`
IN *TagFactory* `database\factories\TagFactory.php`:
```php
    public function definition(): array {
        return [
            'title' => fake()->word(), 
        ];
    }
```

Attaching tags with pivot table methods (many to many):
IN *PostSeeder* `database\seeders\PostSeeder.php`:
```php 
    $post->tags()->attach(1); // attaches tag with id=1 to the post
	$post->tags()->detach([1,2,3]); // detaches tags from the post. If no args, detaches all
	$post->tags()->sync([1]); // attaches mentioned tags; detaches not mentioned
	$post->tags()->syncWithoutDetaching([1,2,3]); // attaches mentioned; DO NOT detach other; DO NOT create duplicates (unlike `attach`)
	$post->tags()->toggle(1); // attach/detach
	$post->tags()->updateExistingPivot(3, ['status'=>2]); // updates additional field (if any) of tag #3
```
## Homework
Fill the DB with mock data using seeders, factories.
Use `fake()->` in factories.
Use magic methods and syntax sugar (such as `->hasProfile()`).

Generate and populate:
- Users
- Profiles
- Posts
- Tags
- Likes
- Comments