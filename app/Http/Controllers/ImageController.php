<?php

namespace App\Http\Controllers;

use App\Models\Image;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ImageController extends Controller {
    public function index(): Collection {
        return Image::all();
    }

    public function store(Request $request): Image {
        return Image::create($request->all());
    }

    public function show(Image $image): Image {
        return $image;
    }

    public function destroy(Image $image): Response {
        $image->delete();

        return response([
            'message' => 'deleted',
        ], status: Response::HTTP_OK);
    }
}
