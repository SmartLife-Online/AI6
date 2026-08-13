<?php

namespace App\AI6\Agents;

use App\AI6\Git\CanonicalJson;
use App\AI6\Shared\Config\ConfigurationException;
use App\AI6\Shared\Config\ConfigurationViolation;
use App\AI6\Shared\Config\StrictPositiveIntegerParser;

final readonly class ProviderRuntimeProfileRegistry
{
    /** @var array<string, ProviderRuntimeProfile> */
    private array $profiles;

    /** @param array<string, ProviderRuntimeProfile> $profiles */
    private function __construct(array $profiles)
    {
        ksort($profiles, SORT_STRING);
        $this->profiles = $profiles;
    }

    public static function fromConfiguredValues(StrictPositiveIntegerParser $integers, CanonicalJson $canonicalJson): self
    {
        $configured = config('ai6.provider_runtime_profiles');

        return self::fromArray(is_array($configured) ? $configured : [], $integers, $canonicalJson);
    }

    /** @param array<array-key, mixed> $configured */
    public static function fromArray(array $configured, StrictPositiveIntegerParser $integers, CanonicalJson $canonicalJson): self
    {
        if ($configured === [] || array_is_list($configured)) {
            throw new ConfigurationException('Configuration key ai6.provider_runtime_profiles must be a non-empty mapping.');
        }

        $profiles = [];
        foreach ($configured as $id => $value) {
            $key = 'ai6.provider_runtime_profiles.'.(is_string($id) ? $id : 'invalid');
            if (! is_string($id) || preg_match('/\A[a-z][a-z0-9._-]{0,63}\z/D', $id) !== 1) {
                throw new ConfigurationException('Configuration key ai6.provider_runtime_profiles contains an invalid identifier.');
            }
            if (! is_array($value) || array_is_list($value) || array_keys($value) !== [
                'version', 'adapter_flags', 'permissions', 'extensions',
            ]) {
                throw new ConfigurationException('Configuration key '.$key.' must contain the canonical runtime fields.');
            }

            $version = $integers->parse($key.'.version', $value['version'], PHP_INT_MAX);
            if ($version instanceof ConfigurationViolation) {
                throw new ConfigurationException($version->message);
            }
            $flags = self::scalarMap($key.'.adapter_flags', $value['adapter_flags']);
            $permissions = self::scalarMap($key.'.permissions', $value['permissions']);
            $extensions = self::extensions($key.'.extensions', $value['extensions']);
            $hashInput = [
                'id' => $id,
                'version' => $version,
                'adapter_flags' => (object) $flags,
                'permissions' => (object) $permissions,
                'extensions' => (object) $extensions,
            ];
            $hash = hash('sha256', "AI6-PROVIDER-RUNTIME-PROFILE-V1\0".$canonicalJson->normalizeAndEncode($hashInput));
            $profiles[$id] = new ProviderRuntimeProfile($id, $version, $flags, $permissions, $extensions, $hash);
        }

        return new self($profiles);
    }

    /** @return list<ProviderRuntimeProfile> */
    public function all(): array
    {
        return array_values($this->profiles);
    }

    public function get(string $id): ProviderRuntimeProfile
    {
        if (! isset($this->profiles[$id])) {
            throw new ConfigurationException('The requested provider runtime profile is not registered.');
        }

        return $this->profiles[$id];
    }

    /** @return array<string, bool|string> */
    private static function scalarMap(string $key, mixed $value): array
    {
        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new ConfigurationException('Configuration key '.$key.' must be a mapping.');
        }
        $result = [];
        foreach ($value as $name => $setting) {
            if (! is_string($name) || preg_match('/\A[a-z][a-z0-9._-]{0,63}\z/D', $name) !== 1 || (! is_bool($setting) && ! is_string($setting))) {
                throw new ConfigurationException('Configuration key '.$key.' contains an invalid setting.');
            }
            $result[$name] = $setting;
        }
        ksort($result, SORT_STRING);

        return $result;
    }

    /** @return array<string, list<string>> */
    private static function extensions(string $key, mixed $value): array
    {
        $expected = array_column(RuntimeExtensionType::cases(), 'value');
        if (! is_array($value) || array_is_list($value) || array_keys($value) !== $expected) {
            throw new ConfigurationException('Configuration key '.$key.' must list every runtime extension type.');
        }
        $result = [];
        foreach ($expected as $type) {
            if (! is_array($value[$type]) || ! array_is_list($value[$type])) {
                throw new ConfigurationException('Configuration key '.$key.'.'.$type.' must be a list.');
            }
            $items = [];
            foreach ($value[$type] as $identifier) {
                if (! is_string($identifier) || preg_match('/\A[a-z][a-z0-9._:-]{0,127}\z/D', $identifier) !== 1) {
                    throw new ConfigurationException('Configuration key '.$key.'.'.$type.' contains an invalid identifier.');
                }
                $items[] = $identifier;
            }
            if (count(array_unique($items)) !== count($items)) {
                throw new ConfigurationException('Configuration key '.$key.'.'.$type.' must not contain duplicates.');
            }
            sort($items, SORT_STRING);
            $result[$type] = $items;
        }

        return $result;
    }
}
