<?php

declare(strict_types=1);

namespace App\Cloud;

use App\Cloud\Dropbox\DropboxProvider;

final class CloudRegistry
{
    /** @var array<string, CloudProvider> */
    private array $providers = [];

    /** @param list<CloudProvider> $providers */
    public function __construct(array $providers)
    {
        foreach ($providers as $provider) {
            $this->providers[$provider->id()] = $provider;
        }
    }

    public static function default(): self
    {
        return new self([
            new DropboxProvider(),
        ]);
    }

    public function get(string $id): ?CloudProvider
    {
        return $this->providers[$id] ?? null;
    }

    /** @return list<CloudProvider> */
    public function all(): array
    {
        return array_values($this->providers);
    }

    /** First configured+connected provider for the user (currently Dropbox only). */
    public function defaultConnected(array $user): ?CloudProvider
    {
        foreach ($this->providers as $provider) {
            if ($provider->isConfigured() && $provider->isConnected($user)) {
                return $provider;
            }
        }

        return null;
    }

    public function anyConnected(array $user): bool
    {
        return $this->defaultConnected($user) !== null;
    }
}
