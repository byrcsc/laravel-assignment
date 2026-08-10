<?php

declare(strict_types=1);

namespace ByRcsc\LaravelAssignment\Tests\PublicApi;

use ByRcsc\LaravelAssignment\AssignmentManager;
use ByRcsc\LaravelAssignment\AssignmentProfile;
use ByRcsc\LaravelAssignment\AssignmentServiceProvider;
use ByRcsc\LaravelAssignment\Commands\AssignmentTickCommand;
use ByRcsc\LaravelAssignment\Concerns\Assignable;
use ByRcsc\LaravelAssignment\Concerns\Assignee;
use ByRcsc\LaravelAssignment\Contracts\SelectionPolicy;
use ByRcsc\LaravelAssignment\Enums\AssignmentStatus;
use ByRcsc\LaravelAssignment\Enums\EndReason;
use ByRcsc\LaravelAssignment\Enums\Mode;
use ByRcsc\LaravelAssignment\Events\AssignableQueued;
use ByRcsc\LaravelAssignment\Events\Assigned;
use ByRcsc\LaravelAssignment\Events\AssignmentAccepted;
use ByRcsc\LaravelAssignment\Events\AssignmentDeclined;
use ByRcsc\LaravelAssignment\Events\AssignmentOffered;
use ByRcsc\LaravelAssignment\Events\OfferExpired as OfferExpiredEvent;
use ByRcsc\LaravelAssignment\Events\OffersExhausted;
use ByRcsc\LaravelAssignment\Events\Reassigned;
use ByRcsc\LaravelAssignment\Events\Unassigned;
use ByRcsc\LaravelAssignment\Exceptions\AlreadyAssigned;
use ByRcsc\LaravelAssignment\Exceptions\AssignmentException;
use ByRcsc\LaravelAssignment\Exceptions\InvalidTransition;
use ByRcsc\LaravelAssignment\Exceptions\NoProfile;
use ByRcsc\LaravelAssignment\Exceptions\NotAssigned;
use ByRcsc\LaravelAssignment\Exceptions\NotTheAssignee;
use ByRcsc\LaravelAssignment\Exceptions\OfferNotOpen;
use ByRcsc\LaravelAssignment\Exceptions\SlotOccupied;
use ByRcsc\LaravelAssignment\Facades\Assignment as AssignmentFacade;
use ByRcsc\LaravelAssignment\Models\Assignment;
use ByRcsc\LaravelAssignment\Models\QueuedAssignment;
use ByRcsc\LaravelAssignment\Notifications\AssignmentNotification;
use ByRcsc\LaravelAssignment\Notifications\AssignmentReceived;
use ByRcsc\LaravelAssignment\Notifications\AssignmentRemoved;
use ByRcsc\LaravelAssignment\Notifications\OfferExpired as OfferExpiredNotification;
use ByRcsc\LaravelAssignment\Notifications\OfferReceived;
use ByRcsc\LaravelAssignment\PendingAssignment;
use ByRcsc\LaravelAssignment\Policies\FirstAvailable;
use ByRcsc\LaravelAssignment\Policies\LeastWorkload;
use ByRcsc\LaravelAssignment\Policies\Random;
use ByRcsc\LaravelAssignment\Policies\RoundRobin;
use ByRcsc\LaravelAssignment\ScopeState;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Collection;
use Traversable;

/**
 * The package's public API, written down. Everything listed here is covered by
 * the versioning promise in the README. Everything in `src` that is absent
 * from {@see self::classes()} carries an `@internal` annotation instead.
 */
final class Surface
{
    /**
     * Signatures rendered the way {@see Introspection::signature()} renders
     * them, so a renamed parameter, a new required argument, or a widened
     * return type fails the suite instead of reaching a release.
     *
     * @return array<class-string, array<string, string>>
     */
    public static function methods(): array
    {
        return [
            Assignable::class => [
                'autoAssign' => 'autoAssign(?string $role = null): ?'.Assignment::class,
                'assign' => 'assign('.Model::class.' $assignee, ?string $role = null, ?'.Model::class.' $by = null): '.Assignment::class,
                'unassign' => 'unassign(?string $role = null, ?'.Model::class.' $by = null): '.Assignment::class,
                'offer' => 'offer('.Model::class.' $assignee, ?string $role = null, ?'.Model::class.' $by = null, ?int $ttlSeconds = null): '.Assignment::class,
                'reassign' => 'reassign('.Model::class.' $newAssignee, ?string $role = null, ?'.Model::class.' $by = null): '.Assignment::class,
                'assignments' => 'assignments(): '.MorphMany::class,
                'openAssignment' => 'openAssignment(?string $role = null): ?'.Assignment::class,
                'assignment' => 'assignment(?string $role = null): ?'.Assignment::class,
                'assignee' => 'assignee(?string $role = null): ?'.Model::class,
                'isAssigned' => 'isAssigned(?string $role = null): bool',
                'queuedAssignment' => 'queuedAssignment(?string $role = null): ?'.QueuedAssignment::class,
            ],

            Assignee::class => [
                'accept' => 'accept('.Assignment::class.' $assignment): '.Assignment::class,
                'decline' => 'decline('.Assignment::class.' $assignment): '.Assignment::class,
                'assignments' => 'assignments(): '.MorphMany::class,
                'activeAssignments' => 'activeAssignments(): '.MorphMany::class,
                'openAssignments' => 'openAssignments(): '.MorphMany::class,
                'workload' => 'workload(?string $assignableType = null): int',
            ],

            Assignment::class => [
                'getTable' => 'getTable(): string',
                'assignable' => 'assignable(): '.MorphTo::class,
                'assignee' => 'assignee(): '.MorphTo::class,
                'assignedBy' => 'assignedBy(): '.MorphTo::class,
                'isOpen' => 'isOpen(): bool',
                'isActive' => 'isActive(): bool',
                'isOffered' => 'isOffered(): bool',
                'isEnded' => 'isEnded(): bool',
                'isExpired' => 'isExpired(): bool',
                'complete' => 'complete(?'.Model::class.' $by = null): self',
                'scopeOpen' => 'scopeOpen('.Builder::class.' $query): '.Builder::class,
                'scopeActive' => 'scopeActive('.Builder::class.' $query): '.Builder::class,
                'scopeOffered' => 'scopeOffered('.Builder::class.' $query): '.Builder::class,
                'scopeEnded' => 'scopeEnded('.Builder::class.' $query): '.Builder::class,
                'scopeExpired' => 'scopeExpired('.Builder::class.' $query): '.Builder::class,
            ],

            QueuedAssignment::class => [
                'getTable' => 'getTable(): string',
                'assignable' => 'assignable(): '.MorphTo::class,
            ],

            AssignmentManager::class => [
                'for' => 'for('.Model::class.' $assignable): '.PendingAssignment::class,
                'autoAssign' => 'autoAssign('.Model::class.' $assignable, ?string $role = null): ?'.Assignment::class,
                'flushQueue' => 'flushQueue(?string $assignableType = null): int',
            ],

            PendingAssignment::class => [
                'among' => 'among('.Traversable::class.'|'.Closure::class.'|array $candidates): self',
                'role' => 'role(?string $role): self',
                'scope' => 'scope(?string $scope): self',
                'using' => 'using('.SelectionPolicy::class.'|'.Closure::class.'|string $policy): self',
                'by' => 'by(?'.Model::class.' $actor): self',
                'expiresIn' => 'expiresIn(?int $seconds): self',
                'assign' => 'assign(): ?'.Assignment::class,
                'offer' => 'offer(): ?'.Assignment::class,
                'queue' => 'queue(int $priority = 0): '.QueuedAssignment::class,
            ],

            AssignmentProfile::class => [
                'candidates' => 'candidates('.Model::class.' $assignable, ?string $role): iterable',
                'policy' => 'policy('.Model::class.' $assignable, ?string $role): '.SelectionPolicy::class.'|'.Closure::class.'|string',
                'scope' => 'scope('.Model::class.' $assignable, ?string $role): ?string',
                'mode' => 'mode('.Model::class.' $assignable, ?string $role): '.Mode::class,
                'offerTtl' => 'offerTtl('.Model::class.' $assignable, ?string $role): ?int',
                'queueWhenEmpty' => 'queueWhenEmpty('.Model::class.' $assignable, ?string $role): bool',
                'queuePriority' => 'queuePriority('.Model::class.' $assignable, ?string $role): int',
            ],

            ScopeState::class => [
                'get' => 'get(string $key, mixed $default = null): mixed',
                'put' => 'put(string $key, mixed $value): self',
                'persist' => 'persist(): void',
            ],

            SelectionPolicy::class => ['select' => self::selectSignature()],
            RoundRobin::class => ['select' => self::selectSignature()],
            Random::class => ['select' => self::selectSignature()],
            FirstAvailable::class => ['select' => self::selectSignature()],
            LeastWorkload::class => [
                'of' => 'of(string $assignableType): self',
                'select' => self::selectSignature(),
            ],

            AssignmentNotification::class => [
                'via' => 'via(object $notifiable): array',
                'toMail' => 'toMail(object $notifiable): '.MailMessage::class,
                'toArray' => 'toArray(object $notifiable): array',
            ],
        ];
    }

    /**
     * The columns the README describes as readable, keyed by config table name.
     * `slot`, `scope`, and `cascade_id` are engine bookkeeping and stay out.
     *
     * @return array<string, list<string>>
     */
    public static function columns(): array
    {
        return [
            'assignments' => [
                'assignable_type',
                'assignable_id',
                'assignee_type',
                'assignee_id',
                'assigned_by_type',
                'assigned_by_id',
                'role',
                'status',
                'offered_at',
                'accepted_at',
                'expires_at',
                'ended_at',
                'ended_reason',
            ],
            'queue' => [
                'assignable_type',
                'assignable_id',
                'role',
                'priority',
                'queued_at',
            ],
        ];
    }

    /**
     * Events, and the payload property each one carries.
     *
     * @return array<class-string, list<string>>
     */
    public static function events(): array
    {
        return [
            Assigned::class => ['assignment'],
            AssignmentOffered::class => ['assignment'],
            AssignmentAccepted::class => ['assignment'],
            AssignmentDeclined::class => ['assignment'],
            OfferExpiredEvent::class => ['assignment'],
            Unassigned::class => ['assignment'],
            Reassigned::class => ['oldAssignment', 'newAssignment'],
            AssignableQueued::class => ['entry'],
            OffersExhausted::class => ['lastOffer'],
        ];
    }

    /**
     * Enum cases, in declaration order, keyed by enum.
     *
     * @return array<class-string, array<string, string>>
     */
    public static function enums(): array
    {
        return [
            AssignmentStatus::class => [
                'Offered' => 'offered',
                'Active' => 'active',
                'Ended' => 'ended',
            ],
            EndReason::class => [
                'Unassigned' => 'unassigned',
                'Reassigned' => 'reassigned',
                'Declined' => 'declined',
                'Expired' => 'expired',
                'Completed' => 'completed',
            ],
            Mode::class => [
                'Assign' => 'assign',
                'Offer' => 'offer',
            ],
        ];
    }

    /** @return list<class-string<AssignmentException>> */
    public static function exceptions(): array
    {
        return [
            AlreadyAssigned::class,
            InvalidTransition::class,
            NoProfile::class,
            NotAssigned::class,
            NotTheAssignee::class,
            OfferNotOpen::class,
            SlotOccupied::class,
        ];
    }

    /** @return list<class-string<AssignmentNotification>> */
    public static function notifications(): array
    {
        return [
            OfferReceived::class,
            OfferExpiredNotification::class,
            AssignmentReceived::class,
            AssignmentRemoved::class,
        ];
    }

    /**
     * The policy classes that ship. A closure is the fifth form, and its place
     * in the API is pinned by the `using()` and `policy()` signatures.
     *
     * @return list<class-string<SelectionPolicy>>
     */
    public static function policies(): array
    {
        return [
            RoundRobin::class,
            LeastWorkload::class,
            Random::class,
            FirstAvailable::class,
        ];
    }

    /** @return list<string> */
    public static function configKeys(): array
    {
        return [
            'assignment.tables.assignments',
            'assignment.tables.scopes',
            'assignment.tables.queue',
            'assignment.assignable_key_type',
            'assignment.assignee_key_type',
            'assignment.profiles',
            'assignment.notifications.offered',
            'assignment.notifications.offer_expired',
            'assignment.notifications.assigned',
            'assignment.notifications.unassigned',
            'assignment.notification_channels',
            'assignment.dispatch_expiry_jobs',
        ];
    }

    /** @return list<string> */
    public static function publishTags(): array
    {
        return [
            'assignment-config',
            'assignment-migrations',
            'assignment-translations',
            'assignment-views',
        ];
    }

    /** @return list<string> */
    public static function facadeMethods(): array
    {
        return ['for', 'autoAssign', 'flushQueue'];
    }

    public static function commandName(): string
    {
        return 'assignment:tick';
    }

    /**
     * Every class the versioning promise covers.
     *
     * @return list<class-string>
     */
    public static function classes(): array
    {
        return [
            AssignmentManager::class,
            AssignmentProfile::class,
            AssignmentServiceProvider::class,
            AssignmentTickCommand::class,
            Assignable::class,
            Assignee::class,
            SelectionPolicy::class,
            Assignment::class,
            QueuedAssignment::class,
            AssignmentFacade::class,
            PendingAssignment::class,
            ScopeState::class,
            AssignmentNotification::class,
            AssignmentException::class,
            ...array_keys(self::enums()),
            ...array_keys(self::events()),
            ...self::exceptions(),
            ...self::notifications(),
            ...self::policies(),
        ];
    }

    private static function selectSignature(): string
    {
        return 'select('.Model::class.' $assignable, '.Collection::class.' $candidates, '.ScopeState::class.' $state): ?'.Model::class;
    }
}
