<?php

namespace App\Http\Resources\User;

use App\Http\Resources\Profile\ProfileWithNotificationsCountResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Текущий пользователь для общих пропсов Inertia (auth.user).
 *
 * Отдельный ресурс, а не общий UserResource: тот обслуживает API
 * (Api\UserController отдаёт им список ВСЕХ пользователей), и профиль
 * со счётчиком уведомлений там не нужен — зато N+1 на сотню строк был бы
 * обеспечен. Имя ресурса называет аудиторию: «пользователь для своей же шапки».
 */
class AuthUserResource extends JsonResource {
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array {
        return [
            'id' => $this->id,
            'name' => $this->name,
            // email и email_verified_at нужны не шапке, а форме настроек аккаунта
            // от Breeze: UpdateProfileInformationForm.vue читает их из этого же
            // пропа. Уберём — форма приедет с пустым полем почты.
            'email' => $this->email,
            'email_verified_at' => $this->email_verified_at,
            // Профиля может не быть: пользователь заводится регистрацией, профиль —
            // отдельная сущность. Тогда проп приедет null, и шапка это переживёт.
            'profile' => $this->profile
                ? ProfileWithNotificationsCountResource::make($this->profile)->resolve()
                : null,
        ];
    }
}
