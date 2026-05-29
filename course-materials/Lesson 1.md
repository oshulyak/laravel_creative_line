# Lesson 1: Models, migrations
`php artisan make:model Post -m`
	creates model in app/Models/Post.php
	`-m` creates also migration file in /database/migrations

Модели: `/app/Models`
Миграции: `/database/migrations`

Внутри файлов миграций метод `up` - накатить, `down` - откатить миграцию.

`php ./artisan migrate`
`php ./artisan migrate:rollback`
`php artisan migrate:fresh`

## Homework
Создать Модели и их Миграции:
Подобрать наиболее подходящие типы данных для полей.

- `User`: `email`, `password`, `phone`, `email_verification_at`, `phone_verification_at`.
- `Profile`: `nickname`, `first_name`, `second_name`, `img_path`, `birth_date`, `gender`, `city`, `user`.
- `Image`: `img_path`.
- `Category`: `title`.
- `Post`: `author`, `title`, `content`, `img_path`, `published_at`, `category`.
- `Like`: 
- `Role`: `title`.
- `Comment`: `author`, `parent`, `content`, `status`, `published_at`.
- `Tag`: `title`.

Связи пока создавать не нужно.
