<?php

namespace App\Http\Controllers;

use App\Models\Comment;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class CommentController extends Controller {
    public function index(): Collection {
        return Comment::all();
    }

    public function store(Request $request): Comment {
        return Comment::create($request->all());
    }

    public function show(Comment $comment): Comment {
        return $comment;
    }

    public function update(Request $request, Comment $comment): Comment {
        $comment->update($request->all());

        return $comment;
    }

    public function destroy(Comment $comment): Response {
        $comment->delete();

        return response([
            'message' => 'deleted',
        ], status: Response::HTTP_OK);
    }
}
