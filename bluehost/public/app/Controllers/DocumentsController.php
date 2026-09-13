<?php
// Employee 201-file documents: contracts, government IDs, certificates, etc.
// Files live under storage/documents (deny-all .htaccess) and are streamed only
// through PHP after an auth + org check — never served directly by the web server.
class DocumentsController
{
    const ALLOWED = ['pdf', 'png', 'jpg', 'jpeg', 'webp', 'gif', 'doc', 'docx', 'xls', 'xlsx', 'txt'];
    const INLINE  = ['pdf', 'png', 'jpg', 'jpeg', 'webp', 'gif', 'txt'];
    const MAX_BYTES = 10485760; // 10 MB per file

    public static function routes(Router $r): void
    {
        $b = '/organizations/{organization_id}';
        $r->get("$b/people/{engagement_id}/documents", [self::class, 'index']);
        $r->post("$b/people/{engagement_id}/documents", [self::class, 'upload']);
        $r->get("$b/documents/{doc_id}/download", [self::class, 'download']);
        $r->delete("$b/documents/{doc_id}", [self::class, 'remove']);
    }

    private static function storageDir(): string
    {
        // <docroot>/storage/documents — the storage folder denies direct web access.
        return dirname(dirname(__DIR__)) . '/storage/documents';
    }

    /** Resolve the engagement within the org and return its person_id. */
    private static function personFor(int $orgId, int $engagementId): int
    {
        $eng = Database::one('SELECT person_id FROM engagements WHERE id = ? AND organization_id = ?', [$engagementId, $orgId]);
        if (!$eng) throw new HttpError('Employee not found', 404);
        return (int) $eng['person_id'];
    }

    public static function index(array $p): void
    {
        [, $orgId] = Auth::org($p, 'documents.view');
        $personId = self::personFor($orgId, (int) $p['engagement_id']);
        $rows = Database::all(
            'SELECT id, uuid, category, title, file_name, content_type, size_bytes, created_at
               FROM employee_documents WHERE organization_id = ? AND person_id = ? ORDER BY id DESC',
            [$orgId, $personId]);
        Http::json($rows);
    }

    public static function upload(array $p): void
    {
        [$user, $orgId] = Auth::org($p, 'documents.upload');
        $personId = self::personFor($orgId, (int) $p['engagement_id']);

        if (empty($_FILES['file']['tmp_name']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
            throw new HttpError('No file was uploaded', 422);
        }
        if (($_FILES['file']['error'] ?? 0) === UPLOAD_ERR_INI_SIZE || ($_FILES['file']['size'] ?? 0) > self::MAX_BYTES) {
            throw new HttpError('File is too large. Each file must be 10 MB or smaller.', 422);
        }
        $orig = (string) ($_FILES['file']['name'] ?? 'file');
        $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
        if (!in_array($ext, self::ALLOWED, true)) {
            throw new HttpError('Unsupported file type. Allowed: PDF, images, Word, Excel, and text files.', 422);
        }

        $dir = self::storageDir() . '/' . $orgId . '/' . $personId;
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new HttpError('Could not create the documents folder — check permissions', 500);
        }
        $uuid = Util::uuid();
        $key = $orgId . '/' . $personId . '/' . $uuid . '.' . $ext;
        if (!move_uploaded_file($_FILES['file']['tmp_name'], self::storageDir() . '/' . $key)) {
            throw new HttpError('Could not save the uploaded file', 500);
        }

        $title = trim((string) ($_POST['title'] ?? '')) ?: pathinfo($orig, PATHINFO_FILENAME);
        $category = trim((string) ($_POST['category'] ?? '')) ?: 'Other';
        $id = Database::insert('employee_documents', [
            'uuid' => $uuid, 'organization_id' => $orgId, 'person_id' => $personId,
            'category' => substr($category, 0, 60), 'title' => substr($title, 0, 255),
            'file_name' => substr($orig, 0, 255), 'object_key' => $key,
            'content_type' => (string) ($_FILES['file']['type'] ?? '') ?: 'application/octet-stream',
            'size_bytes' => (int) ($_FILES['file']['size'] ?? 0), 'uploaded_by' => $user['id'],
        ]);
        Audit::record('document.upload', $user, ['organization_id' => $orgId, 'entity' => 'employee_document', 'entity_id' => $id,
            'after' => ['title' => $title, 'category' => $category, 'file' => $orig]]);
        Http::json(['id' => $id, 'uuid' => $uuid]);
    }

    public static function download(array $p): void
    {
        [, $orgId] = Auth::org($p, 'documents.view');
        $doc = Database::one('SELECT * FROM employee_documents WHERE id = ? AND organization_id = ?', [(int) $p['doc_id'], $orgId]);
        if (!$doc) throw new HttpError('Document not found', 404);
        $path = self::storageDir() . '/' . $doc['object_key'];
        if (!is_file($path)) throw new HttpError('The file is missing from storage', 404);
        $ext = strtolower(pathinfo($doc['file_name'], PATHINFO_EXTENSION));
        Http::file(file_get_contents($path), $doc['content_type'] ?: 'application/octet-stream',
            $doc['file_name'], in_array($ext, self::INLINE, true));
    }

    public static function remove(array $p): void
    {
        [$user, $orgId] = Auth::org($p, 'documents.upload');
        $doc = Database::one('SELECT * FROM employee_documents WHERE id = ? AND organization_id = ?', [(int) $p['doc_id'], $orgId]);
        if (!$doc) throw new HttpError('Document not found', 404);
        $path = self::storageDir() . '/' . $doc['object_key'];
        if (is_file($path)) @unlink($path);
        Database::exec('DELETE FROM employee_documents WHERE id = ?', [(int) $doc['id']]);
        Audit::record('document.delete', $user, ['organization_id' => $orgId, 'entity' => 'employee_document', 'entity_id' => $doc['id'],
            'before' => ['title' => $doc['title'], 'file' => $doc['file_name']]]);
        Http::json(['ok' => true]);
    }
}
