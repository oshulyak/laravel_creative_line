<?php

namespace App\Http\Controllers;

use App\Models\Tag;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class TagController extends Controller {
    public function index(): Collection {
        return Tag::all();
    }

    public function store(Request $request): Tag {
        return Tag::create($request->all());
    }

    public function show(Tag $tag): Tag {
        return $tag;
    }

    public function update(Request $request, Tag $tag): Tag {
        $tag->update($request->all());

        return $tag;
    }

    public function destroy(Tag $tag): Response {
        $tag->delete();

        return response([
            'message' => 'deleted',
        ], status: Response::HTTP_OK);
    }
}
