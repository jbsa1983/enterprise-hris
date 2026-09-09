<?php
// Append-only audit log writer.

class Audit
{
    public static function record(string $action, ?array $user = null, array $opts = []): void
    {
        Database::insert('audit_logs', [
            'user_id'       => $user['id'] ?? null,
            'user_email'    => $user['email'] ?? null,
            'organization_id' => $opts['organization_id'] ?? null,
            'action'        => $action,
            'entity'        => $opts['entity'] ?? null,
            'entity_id'     => isset($opts['entity_id']) ? (string) $opts['entity_id'] : null,
            'before_json'   => isset($opts['before']) ? json_encode($opts['before']) : null,
            'after_json'    => isset($opts['after']) ? json_encode($opts['after']) : null,
            'ip_address'    => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent'    => $_SERVER['HTTP_USER_AGENT'] ?? null,
        ]);
    }
}
