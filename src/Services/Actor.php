<?php

namespace Elcreator\aDataForSeo\Services;

use Elcreator\aDataForSeo\Support\Principal;

/**
 * The manager the module is acting for: from the session for a click, from a
 * user id for a worker or an MCP call. The answers always come from
 * {@see Principal}, i.e. the database as the account stands now.
 */
final class Actor
{
    private ?Principal $principal = null;

    private function __construct(private readonly int $userId)
    {
    }

    public static function current(): self
    {
        return new self((int) evo()->getLoginUserID('mgr'));
    }

    public static function for(int $userId): self
    {
        return new self($userId);
    }

    public function userId(): int
    {
        return $this->userId;
    }

    public function principal(): Principal
    {
        return $this->principal ??= Principal::forUser($this->userId);
    }

    public function mayUse(): bool
    {
        return $this->userId > 0 && $this->principal()->mayUse();
    }

    public function maySpend(): bool
    {
        return $this->userId > 0 && $this->principal()->maySpend();
    }

    public function mayManage(): bool
    {
        return $this->userId > 0 && $this->principal()->mayManage();
    }

    /** @throws WorkbenchException */
    public function authorize(string $level = 'use'): self
    {
        $ok = match ($level) {
            'spend' => $this->maySpend(),
            'manage' => $this->mayManage(),
            default => $this->mayUse(),
        };

        if (!$ok) {
            throw WorkbenchException::forbidden($level . ' by user ' . $this->userId);
        }

        return $this;
    }
}
