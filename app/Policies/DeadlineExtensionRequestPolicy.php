<?php

namespace App\Policies;

use App\Models\DeadlineExtensionRequest;
use App\Models\User;

class DeadlineExtensionRequestPolicy
{
    public function view(User $user, DeadlineExtensionRequest $deadlineExtensionRequest): bool
    {
        return (int) $deadlineExtensionRequest->requested_by === (int) $user->id
            || $user->can('reviewDeadlineExtensions', $deadlineExtensionRequest->task);
    }

    public function review(User $user, DeadlineExtensionRequest $deadlineExtensionRequest): bool
    {
        return $deadlineExtensionRequest->status === DeadlineExtensionRequest::STATUS_PENDING
            && $user->can('reviewDeadlineExtensions', $deadlineExtensionRequest->task);
    }
}
