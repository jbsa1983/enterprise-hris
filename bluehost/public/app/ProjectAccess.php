<?php
// Per-project access scoping. Users with org-wide reach (superadmin or an org manager)
// can touch every project; everyone else — e.g. a Project Manager or Project HR — is
// limited to the project(s) they're assigned to in the project_access table.
class ProjectAccess
{
    public static function orgWide(array $user): bool
    {
        return !empty($user['is_superadmin']) || Auth::has($user, 'organization.manage');
    }

    /** Project ids the user may access in an org: null means "all" (org-wide reach). */
    public static function accessibleIds(array $user, int $org): ?array
    {
        if (self::orgWide($user)) return null;
        return array_map('intval', array_column(
            Database::all('SELECT project_id FROM project_access WHERE user_id = ? AND organization_id = ?', [(int) $user['id'], $org]),
            'project_id'));
    }

    public static function canAccess(array $user, int $org, int $projectId): bool
    {
        if (self::orgWide($user)) return true;
        return (int) Database::scalar('SELECT COUNT(*) FROM project_access WHERE user_id = ? AND project_id = ? AND organization_id = ?',
            [(int) $user['id'], $projectId, $org]) > 0;
    }

    public static function assert(array $user, int $org, int $projectId): void
    {
        if (!self::canAccess($user, $org, $projectId)) {
            throw new HttpError('You are not assigned to this project.', 403);
        }
    }
}
