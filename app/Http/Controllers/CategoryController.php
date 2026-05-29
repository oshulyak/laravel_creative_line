<?php

namespace App\Http\Controllers;

use App\Models\Category;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class CategoryController extends Controller {
    public function index(): Collection {
        return Category::all();
    }

    public function store(Request $request): Category {
        return Category::create($request->all());
    }

    public function show(Category $category): Category {
        return $category;
    }

    public function update(Request $request, Category $category): Category {
        $category->update($request->all());

        return $category;
    }

    public function destroy(Category $category): Response {
        $category->delete();

        return response([
            'message' => 'deleted',
        ], status: Response::HTTP_OK);
    }
}
