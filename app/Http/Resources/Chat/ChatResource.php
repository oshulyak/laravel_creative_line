<?php

namespace App\Http\Resources\Chat;

use App\Http\Resources\Profile\ProfileResource;
use App\Models\Profile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChatResource extends JsonResource {
    /**
     * Чат для страницы чата и для списка чатов: заголовок и участники.
     *
     * Ресурс рассчитан на загруженную связь profiles: из участников собирается
     * заголовок, поэтому whenLoaded() здесь не нужен — без участников чат
     * показать нельзя. На странице чата участников уже прочитал
     * Chat::hasParticipant(), в списке их загружает with('profiles')
     * в ChatMapper::index().
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array {
        return [
            'id' => $this->id,
            // Своё название есть не у каждого чата: у диалога title пустой,
            // и тогда заголовком служат ники собеседников — всех участников,
            // кроме смотрящего.
            //
            // Собирает сервер, а не шаблон: клиенту нужна готовая строка, как
            // готовая ссылка в NotificationResource. reject(), pluck(), join() —
            // методы коллекции: участники уже в памяти, база здесь не участвует.
            'title' => $this->title ?? $this->profiles
                ->reject(fn (Profile $profile): bool => $profile->id === $request->user()?->profile?->id)
                ->pluck('nickname')
                ->join(', '),
            // Вложенный ресурс разворачиваем через resolve(), иначе на клиенте
            // появится лишняя обёртка data — как с автором в PostResource.
            'profiles' => ProfileResource::collection($this->profiles)->resolve(),
        ];
    }
}
