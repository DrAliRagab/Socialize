<?php

declare(strict_types=1);

namespace DrAliRagab\Socialize;

use DrAliRagab\Socialize\Contracts\ProviderDriver;
use DrAliRagab\Socialize\Enums\Provider;
use DrAliRagab\Socialize\Exceptions\InvalidConfigException;
use DrAliRagab\Socialize\Providers\FacebookProvider;
use DrAliRagab\Socialize\Providers\InstagramProvider;
use DrAliRagab\Socialize\Providers\LinkedInProvider;
use DrAliRagab\Socialize\Providers\TwitterProvider;
use DrAliRagab\Socialize\Support\FluentShare;
use Illuminate\Contracts\Config\Repository;

use function is_array;
use function is_string;
use function sprintf;

final readonly class SocializeManager
{
    public function __construct(private Repository $repository) {}

    public function provider(Provider|string $provider, ?string $profile = null): FluentShare
    {
        $providerEnum = is_string($provider) ? Provider::fromString($provider) : $provider;

        return new FluentShare($providerEnum, $this->makeDriver($providerEnum, $profile));
    }

    public function facebook(?string $profile = null): FluentShare
    {
        return $this->provider(Provider::Facebook, $profile);
    }

    public function instagram(?string $profile = null): FluentShare
    {
        return $this->provider(Provider::Instagram, $profile);
    }

    public function twitter(?string $profile = null): FluentShare
    {
        return $this->provider(Provider::Twitter, $profile);
    }

    public function linkedin(?string $profile = null): FluentShare
    {
        return $this->provider(Provider::LinkedIn, $profile);
    }

    private function makeDriver(Provider $provider, ?string $profile = null): ProviderDriver
    {
        /** @var array<string, mixed>|null $providerConfig */
        $providerConfig = $this->repository->get('socialize.providers.' . $provider->value);

        if (! is_array($providerConfig))
        {
            throw new InvalidConfigException(sprintf('Provider configuration [%s] is missing.', $provider->value));
        }

        /** @var array<string, mixed> $profiles */
        $profiles       = is_array($providerConfig['profiles'] ?? null) ? $providerConfig['profiles'] : [];
        $defaultProfile = $providerConfig['default_profile'] ?? $this->repository->get('socialize.default_profile', 'default');
        $defaultProfile = is_string($defaultProfile) ? $defaultProfile : 'default';

        $profile = mb_trim($profile ?? $defaultProfile);

        if ($profile === '')
        {
            $profile = mb_trim($defaultProfile);
        }

        if ($profile === '')
        {
            $profile = 'default';
        }

        /** @var array<string, mixed>|null $credentials */
        $credentials = $profiles[$profile] ?? null;

        if (! is_array($credentials))
        {
            throw new InvalidConfigException(sprintf('Profile [%s] is not configured for provider [%s].', $profile, $provider->value));
        }

        /** @var array<string, mixed> $httpConfig */
        $httpConfig = is_array($this->repository->get('socialize.http')) ? $this->repository->get('socialize.http') : [];

        return match ($provider)
        {
            Provider::Facebook  => new FacebookProvider($providerConfig, $credentials, $httpConfig, $profile),
            Provider::Instagram => new InstagramProvider($providerConfig, $credentials, $httpConfig, $profile),
            Provider::Twitter   => new TwitterProvider($providerConfig, $credentials, $httpConfig, $profile),
            Provider::LinkedIn  => new LinkedInProvider($providerConfig, $credentials, $httpConfig, $profile),
        };
    }
}
