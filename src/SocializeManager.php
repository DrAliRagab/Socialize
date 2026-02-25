<?php

declare(strict_types=1);

namespace DrAliRagab\Socialize;

use function class_exists;

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
use function is_subclass_of;

use ReflectionClass;
use Throwable;

final readonly class SocializeManager
{
    public function __construct(private Repository $repository) {}

    public function provider(string $provider, ?string $profile = null): FluentShare
    {
        $providerEnum     = Provider::fromString($provider);
        $strictOptionKeys = (bool)$this->repository->get('socialize.strict_option_keys', true);

        return new FluentShare($providerEnum, $this->makeDriver($providerEnum, $profile), $strictOptionKeys);
    }

    public function facebook(?string $profile = null): FluentShare
    {
        return $this->provider('facebook', $profile);
    }

    public function instagram(?string $profile = null): FluentShare
    {
        return $this->provider('instagram', $profile);
    }

    public function twitter(?string $profile = null): FluentShare
    {
        return $this->provider('twitter', $profile);
    }

    public function linkedin(?string $profile = null): FluentShare
    {
        return $this->provider('linkedin', $profile);
    }

    private function makeDriver(Provider $provider, ?string $profile = null): ProviderDriver
    {
        /** @var array<string, mixed>|null $providerConfig */
        $providerConfig = $this->repository->get('socialize.providers.' . $provider->value);

        if (! is_array($providerConfig))
        {
            throw InvalidConfigException::missingProviderConfiguration($provider->value);
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
            throw InvalidConfigException::missingProfile($provider->value, $profile);
        }

        /** @var array<string, mixed> $httpConfig */
        $httpConfig           = is_array($this->repository->get('socialize.http')) ? $this->repository->get('socialize.http') : [];
        $temporaryMediaConfig = $this->repository->get('socialize.temporary_media');

        if (is_array($temporaryMediaConfig))
        {
            $httpConfig['temporary_media'] = $temporaryMediaConfig;
        }

        /** @var array<string, mixed> $drivers */
        $drivers = is_array($this->repository->get('socialize.drivers')) ? $this->repository->get('socialize.drivers') : [];

        $defaultDriverClass = match ($provider)
        {
            Provider::Facebook  => FacebookProvider::class,
            Provider::Instagram => InstagramProvider::class,
            Provider::Twitter   => TwitterProvider::class,
            Provider::LinkedIn  => LinkedInProvider::class,
        };

        $configuredDriver = $drivers[$provider->value] ?? null;
        $driverClass      = is_string($configuredDriver) && mb_trim($configuredDriver) !== ''
            ? mb_trim($configuredDriver)
            : $defaultDriverClass;

        if (! class_exists($driverClass) || ! is_subclass_of($driverClass, ProviderDriver::class))
        {
            throw InvalidConfigException::invalidDriver($provider->value, $driverClass, ProviderDriver::class);
        }

        $reflectionClass = new ReflectionClass($driverClass);
        $constructor     = $reflectionClass->getConstructor();

        if (
            $constructor === null
            || $constructor->getNumberOfRequiredParameters() > 4
            || $constructor->getNumberOfParameters() < 4
        ) {
            throw InvalidConfigException::invalidDriverConstructor($provider->value, $driverClass);
        }

        try
        {
            /** @var ProviderDriver $driver */
            $driver = $reflectionClass->newInstanceArgs([$providerConfig, $credentials, $httpConfig, $profile]);

            return $driver;
        } catch (Throwable)
        {
            throw InvalidConfigException::invalidDriverConstructor($provider->value, $driverClass);
        }
    }
}
