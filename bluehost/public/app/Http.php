<?php
// Request/response helpers.

class Http
{
    /** Decode JSON body once. */
    public static function body(): array
    {
        static $cache = null;
        if ($cache === null) {
            $raw = file_get_contents('php://input');
            $cache = $raw ? (json_decode($raw, true) ?: []) : [];
        }
        return $cache;
    }

    public static function query(string $key, $default = null)
    {
        return $_GET[$key] ?? $default;
    }

    public static function json($data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }

    public static function error(string $message, int $status = 400, $extra = null): void
    {
        $out = ['detail' => $message];
        if ($extra !== null) $out['extra'] = $extra;
        self::json($out, $status);
    }

    public static function file(string $bytes, string $contentType, string $filename, bool $inline = false): void
    {
        header('Content-Type: ' . $contentType);
        $disp = $inline ? 'inline' : 'attachment';
        header("Content-Disposition: $disp; filename=\"$filename\"");
        header('Content-Length: ' . strlen($bytes));
        echo $bytes;
        exit;
    }
}

// Exception used to short-circuit with an HTTP error from anywhere.
class HttpError extends Exception
{
    public int $status;
    public $extra;
    public function __construct(string $message, int $status = 400, $extra = null)
    {
        parent::__construct($message);
        $this->status = $status;
        $this->extra = $extra;
    }
}
