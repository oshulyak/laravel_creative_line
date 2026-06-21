# Lesson 8 - Update Migrations

`php artisan make:migration add_description_to_posts_table`
`php artisan make:migration change_title_in_posts_table`

`add_to_tablename_table` - добавление
`drop_from_tablename_table` - удаление
`change_in_tablename_table` - изменение
сложные изменения - произвольное название, но тогда в миграции заготовок создаваться не будет.
Заготовка генерируется при наличии суффикса вида `_in_posts_table`, а глаголы и названия полей - для читаемости.

IN `add_description_to_posts_table`:
```php
public function up(): void{ // накатить миграцию
   Schema::table('posts', function (Blueprint $table) {
       $table->string('description')->nullable();
   });
}

public function down(): void{ // откатить миграцию
    Schema::table('posts', function (Blueprint $table) {
        $table->dropColumn('description');
    });
}
```

IN `change_title_in_posts_table`:
```php
public function up(): void{
    Schema::table('posts', function (Blueprint $table) {
        $table->string(column: 'title')->unique()->change();
    });
}


public function down(): void{
    Schema::table('posts', function (Blueprint $table) {
        $table->text('title')->unique(false)->change();
    });
}

```

`php artisan migrate:rollback`
	откатывает последний пакет (batch) миграций: смотрит какие миграции были запущены прошлой командой `php artisan migrate` (они хранятся в таблице migrations) и вызывает в них down();
**ОПАСНО!** Лучше сделать новую миграцию отменяющую предыдущую.

## Homework

1. Создать миграции без атрибутов (только id, timestamps)  
2. Добавить атрибуты через add migration  
3. Добавить в каждую таблицу 2 лишних атрибута  
4. Добавить в таблицы атрибуты с некорректным типом данных(но одного вида, у строк строчные, у чисел численные и т.д.)  
5. Проставить отдельно индексы там где надо  
6. Добавить отдельными миграциями fk
7. Удалить лишние атрибуты  
8. Изменить некорректные атрибуты в нужный тип