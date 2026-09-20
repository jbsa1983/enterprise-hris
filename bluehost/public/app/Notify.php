<?php
// Notifications: writes an in-app notification row and sends an e-mail.
// All best-effort — a missing notifications table or a mail failure never breaks
// the underlying request.
class Notify
{
    /** Active users in an org whose roles grant $permCode (the approvers). */
    public static function approvers(int $orgId, string $permCode): array
    {
        return Database::all(
            "SELECT DISTINCT u.id, u.email, u.full_name
               FROM users u
               LEFT JOIN organization_users ou ON ou.user_id = u.id AND ou.organization_id = ?
               LEFT JOIN user_roles ur ON ur.user_id = u.id
               LEFT JOIN role_permissions rp ON rp.role_id = ur.role_id
               LEFT JOIN permissions p ON p.id = rp.permission_id AND p.code = ?
              WHERE u.is_active = 1
                AND (u.is_superadmin = 1 OR (ou.organization_id IS NOT NULL AND p.id IS NOT NULL))", [$orgId, $permCode]);
    }

    public static function userForPerson(int $personId): ?array
    {
        return Database::one('SELECT id, email, full_name FROM users WHERE person_id = ? AND is_active = 1', [$personId]);
    }

    private static function record(int $userId, string $type, string $title, string $body, string $link): void
    {
        try {
            Database::insert('notifications', ['user_id' => $userId, 'type' => $type,
                'title' => mb_substr($title, 0, 200), 'body' => mb_substr($body, 0, 500), 'link' => $link, 'is_read' => 0]);
        } catch (\Throwable $e) {
        }
    }

    /** In-app + e-mail + Telegram to one active user. */
    public static function toUser(int $userId, string $type, string $title, string $body, string $link): void
    {
        $u = Database::one('SELECT id,email,full_name FROM users WHERE id=? AND is_active=1', [$userId]);
        if (!$u) return;
        self::record((int) $u['id'], $type, $title, $body, $link);
        Mailer::send($u['email'], $title, $body . "\n\nOpen the HRIS:\n" . self::url($link));
        Telegram::notifyUser((int) $u['id'], "🔔 {$title}\n{$body}\n" . self::url($link));
    }

    private static function url(string $path): string
    {
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        return "$scheme://$host" . $path;
    }

    /** In-app + e-mail to every approver of an org. */
    public static function toApprovers(int $orgId, string $permCode, string $type, string $title, string $body, string $link): void
    {
        foreach (self::approvers($orgId, $permCode) as $a) {
            self::record((int) $a['id'], $type, $title, $body, $link);
            Mailer::send($a['email'], $title, $body . "\n\nReview it here:\n" . self::url($link));
            Telegram::notifyUser((int) $a['id'], "🔔 {$title}\n{$body}\n" . self::url($link));
        }
    }

    /** In-app + e-mail to the employee behind a person record. */
    public static function toPerson(int $personId, string $type, string $title, string $body, string $link): void
    {
        $u = self::userForPerson($personId);
        if (!$u) return;
        self::record((int) $u['id'], $type, $title, $body, $link);
        Mailer::send($u['email'], $title, $body . "\n\nOpen the HRIS:\n" . self::url($link));
        Telegram::notifyUser((int) $u['id'], "🔔 {$title}\n{$body}\n" . self::url($link));
    }
}
