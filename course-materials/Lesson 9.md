# Lesson 9 - Model Events, Observers, and Listeners
## Standard Lifecycle Events
	**N.B.!**
	```php
	class DatabaseSeeder extends Seeder {
	    // use WithoutModelEvents;  -- comment out when if using events with seeders
	```

*Events can be listened and handled by:*
- booted() inside Model
	OR
- Observer

### booted() inside Model
```php
protected static function booted(): void{
    static::created(function (User $user) {
$user->profile()->firstOrCreate([], [
    'nickname' => $user->name,
]);
    });
}
```

### Observer
`php artisan make:observer UserObserver -m User`
	Listens and handles Standard Lifecycle Events: `retrieved`, `creating`, `created`, `updating`, `updated`, `saving`, `saved`, `deleting`, `deleted`, `trashed`, `forceDeleting`, `forceDeleted`, `restoring`, `restored`, `replicating`.

IN User.php:
```php
#[ObservedBy([UserObserver::class])]
```

IN UserObserver.php:
```php
    public function created(User $user): void {
        $user->profile()->create([
            // ....
        ]);
    }

//    public function updated(User $user): void {
//    public function deleted(User $user): void {
//	...
```

## Custom Events

`php artisan make:event User/CreatedUserEvent`
IN CreatedUserEvent.php:
```php
	public function __construct(public User $user){
	}
```

`php artisan make:listener User/CreateProfileListener -e User/CreatedUserEvent`
```php
	public function handle (CreatedUserEvent $event): void{
		// dd($event->user);
	}
```


IN DatabaseSeeder.php (or anywhere):
```php
	CreatedUserEvent::dispatch($user); // trigger custom event
```

## Homework
1. Create a Log model with its own migration. Log model attributes: model, action, old attributes of the logged model (`$post->getOriginal()`), new attributes of the logged model, changed attributes (`$post->getDirty()`).
   Create an observer for the Post model and use it to log the events (created, updated, deleted, retrieved) through Log.  
2. Create a trait for logging all models: `php artisan make:trait Models/Traits/HasLog`. Implement event logging (created, updated, deleted, retrieved) through the `booted()` method in the HasLog trait.
3. Improve the trait so that `bootHasLog()` from the trait and `booted()` from the model itself can be used at the same time: logging from the trait should be registered in `bootHasLog()`, while the model's `booted()` method should remain available for the model's own event handling.  
4. Create two custom events: the first one should be triggered when logging starts, and the second one when logging finishes.  
