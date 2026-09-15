<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Http\Requests\Client\ThemeMessage\StoreRequest;
use App\Http\Resources\ThemeMessage\ThemeMessageResource;
use App\Mappers\ThemeMapper;
use App\Models\Theme;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Response;

class ThemeController extends Controller {
    /**
     * Страница темы. Как и страница группы, открыта всем вошедшим.
     */
    public function show(Request $request, Theme $theme): Response {
        return inertia('Client/Theme/Show', ThemeMapper::show($theme, $request->user()->profile));
    }

    /**
     * Отправка сообщения в тему.
     *
     * Устроено как ChatController::storeMessage(), только без broadcast():
     * другие участники увидят сообщение после перезагрузки страницы.
     */
    public function storeMessage(StoreRequest $request, Theme $theme): JsonResponse {
        // create() на связи hasMany сам заполнит theme_id.
        $message = $theme->messages()->create($request->validated());

        // Ник автора нужен сразу: сообщение встанет в ленту без перезагрузки.
        $message->load('author');

        // 201 Created и само сообщение в теле — как у сообщения чата.
        return response()->json(ThemeMessageResource::make($message)->resolve(), 201);
    }
}
