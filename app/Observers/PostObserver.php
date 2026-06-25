<?php

namespace App\Observers;

use App\Models\Log;
use App\Models\Post;

class PostObserver {
    /**
     * Handle the Post "created" event.
     */
    public function created(Post $post): void {
        $this->log($post, 'created');
    }

    /**
     * Handle the Post "updated" event.
     */
    public function updated(Post $post): void {
        $this->log($post, 'updated');
    }

    /**
     * Handle the Post "deleted" event.
     */
    public function deleted(Post $post): void {
        $this->log($post, 'deleted');
    }

    /**
     * Handle the Post "retrieved" event.
     */
    public function retrieved(Post $post): void {
        $this->log($post, 'retrieved');
    }

    private function log(Post $post, string $action): void {
        Log::writeForModel($post, $action);
    }
}
