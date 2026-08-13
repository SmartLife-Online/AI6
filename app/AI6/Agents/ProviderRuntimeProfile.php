<?php

namespace App\AI6\Agents;

use JsonSerializable;

final readonly class ProviderRuntimeProfile implements JsonSerializable
{
    /**
     * @param  array<string, bool|string>  $adapterFlags
     * @param  array<string, bool|string>  $permissions
     * @param  array<string, list<string>>  $extensions
     */
    public function __construct(
        public string $id,
        public int $version,
        public array $adapterFlags,
        public array $permissions,
        public array $extensions,
        public string $hash,
    ) {}

    public function extensionEnabled(RuntimeExtensionType $type, string $identifier): bool
    {
        return in_array($identifier, $this->extensions[$type->value] ?? [], true);
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'version' => $this->version,
            'adapter_flags' => (object) $this->adapterFlags,
            'permissions' => (object) $this->permissions,
            'extensions' => (object) $this->extensions,
            'hash' => $this->hash,
        ];
    }
}
