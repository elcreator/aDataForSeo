<?php

namespace Elcreator\aDataForSeo\Support;

use Illuminate\Support\Facades\DB;

/**
 * One manager, resolved from the database — never from `$_SESSION`.
 *
 * The same object answers for the person clicking in the manager and for the
 * worker running their job at three in the morning: a job allowed when it was
 * started is asked again, as the account stands now, on every slice. Role
 * changed, permission revoked, account blocked: the next slice notices.
 *
 * It rebuilds what the core keeps in the session after login (`mgrRole`,
 * `mgrPermissions`, `mgrDocgroups`) with the same queries.
 */
final class Principal
{
    /** @param string[] $permissions @param int[] $docGroups */
    private function __construct(
        public readonly int $id,
        public readonly int $role,
        public readonly bool $active,
        private readonly array $permissions,
        private readonly array $docGroups,
        public readonly bool $udperms
    ) {
    }

    public static function forUser(int $userId): self
    {
        $attributes = $userId > 0 ? DB::table('user_attributes')->where('internalKey', $userId)->first() : null;
        $exists = $attributes !== null && DB::table('users')->where('id', $userId)->exists();

        $role = $exists ? (int) $attributes->role : 0;
        $active = $exists && $role > 0 && !self::blocked($attributes);

        $permissions = $role > 0
            ? DB::table('role_permissions')->where('role_id', $role)->pluck('permission')->map(static fn ($p) => (string) $p)->all()
            : [];

        $docGroups = $exists
            ? DB::table('member_groups')
                ->join('membergroup_access', 'membergroup_access.membergroup', '=', 'member_groups.user_group')
                ->where('member_groups.member', $userId)
                ->pluck('membergroup_access.documentgroup')
                ->map(static fn ($id) => (int) $id)->unique()->values()->all()
            : [];

        return new self($userId, $role, $active, $permissions, $docGroups, (bool) evo()->getConfig('use_udperms', true));
    }

    /** Role 1 is the super administrator: all permissions, exempt from document groups. */
    public function isAdministrator(): bool
    {
        return $this->active && $this->role === 1;
    }

    public function can(string $permission): bool
    {
        return $this->active && ($this->isAdministrator() || in_array($permission, $this->permissions, true));
    }

    public function mayUse(): bool
    {
        return $this->can('adataforseo');
    }

    public function maySpend(): bool
    {
        return $this->mayUse() && $this->can('adataforseo_spend');
    }

    public function mayManage(): bool
    {
        return $this->mayUse() && $this->can('adataforseo_manage');
    }

    /**
     * What the CMS asks before saving a document: `save_document`, and with
     * `use_udperms` on, that the document is public or in one of the
     * manager's document groups.
     */
    public function mayEditDocument(int $documentId): bool
    {
        if (!$this->can('save_document') || $documentId <= 0) {
            return false;
        }

        if (!$this->udperms || $this->isAdministrator()) {
            return true;
        }

        $groups = DB::table('document_groups')->where('document', $documentId)->pluck('document_group')->map(static fn ($g) => (int) $g)->all();

        return $groups === [] || array_intersect($groups, $this->docGroups) !== [];
    }

    /** Creating a draft under `$parentId` (0 = the site root). */
    public function mayCreateDocumentUnder(int $parentId): bool
    {
        if (!$this->can('new_document') || !$this->can('save_document')) {
            return false;
        }

        return $parentId === 0 ? ($this->isAdministrator() || $this->can('save_document')) : $this->mayEditDocument($parentId);
    }

    /** @return int[] */
    public function documentGroups(): array
    {
        return $this->docGroups;
    }

    /** Same account, other permissions, for tests that vary one fact. */
    public function withPermissions(array $permissions): self
    {
        return new self($this->id, $this->role, $this->active, $permissions, $this->docGroups, $this->udperms);
    }

    private static function blocked(object $attributes): bool
    {
        $now = time();

        return (int) ($attributes->blocked ?? 0) !== 0
            || (int) ($attributes->blockeduntil ?? 0) > $now
            || ((int) ($attributes->blockedafter ?? 0) > 0 && (int) $attributes->blockedafter < $now);
    }
}
