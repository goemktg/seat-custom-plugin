<?php

declare(strict_types=1);

namespace Goemktg\Seat\SeatCustomPlugin\Support\OAuth;

/** One application registered in the clients file. */
final class Client
{
    /**
     * @param  list<string>  $redirectUris
     * @param  list<int>  $allowedRoleIds  empty: any user who may sign in at all
     */
    public function __construct(
        private readonly string $id,
        private readonly string $name,
        private readonly string $secretSha256,
        private readonly array $redirectUris,
        private readonly array $allowedRoleIds,
    ) {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    /** Lower-case hex SHA-256 of the client secret. */
    public function secretSha256(): string
    {
        return $this->secretSha256;
    }

    /** True when the address is one of the registered ones, byte for byte. */
    public function hasRedirect(mixed $uri): bool
    {
        return is_string($uri) && in_array($uri, $this->redirectUris, true);
    }

    /**
     * Whether a user holding these SeAT roles may sign in to this application.
     * Without allowed_role_ids every user passes; a SeAT administrator always passes.
     *
     * @param  list<int>  $roleIds
     */
    public function admits(bool $admin, array $roleIds): bool
    {
        return $this->allowedRoleIds === [] || $admin || array_intersect($this->allowedRoleIds, $roleIds) !== [];
    }
}
