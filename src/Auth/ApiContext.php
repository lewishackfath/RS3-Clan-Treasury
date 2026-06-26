<?php

declare(strict_types=1);

namespace Treasury\Auth;

final class ApiContext
{
    public function __construct(
        public readonly int $appId,
        public readonly string $appSlug,
        public readonly string $appName,
        public readonly int $apiKeyId,
        public readonly array $scopes
    ) {
    }

    public function can(string $scope): bool
    {
        return in_array($scope, $this->scopes, true) || in_array('*', $this->scopes, true);
    }
}
