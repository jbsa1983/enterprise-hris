<?php
// Tiny router: register method+path patterns with {params}; dispatch by URI.

class Router
{
    private array $routes = [];

    public function add(string $method, string $pattern, callable $handler): void
    {
        // Convert "/organizations/{id}/people" → regex with named groups.
        $regex = preg_replace('#\{([a-zA-Z_]+)\}#', '(?P<$1>[^/]+)', $pattern);
        $this->routes[] = ['method' => strtoupper($method), 'regex' => "#^$regex$#", 'handler' => $handler];
    }

    public function get(string $p, callable $h): void    { $this->add('GET', $p, $h); }
    public function post(string $p, callable $h): void   { $this->add('POST', $p, $h); }
    public function put(string $p, callable $h): void    { $this->add('PUT', $p, $h); }
    public function delete(string $p, callable $h): void { $this->add('DELETE', $p, $h); }

    public function dispatch(string $method, string $path): void
    {
        foreach ($this->routes as $r) {
            if ($r['method'] !== $method) continue;
            if (preg_match($r['regex'], $path, $m)) {
                $params = [];
                foreach ($m as $k => $v) if (!is_int($k)) $params[$k] = $v;
                ($r['handler'])($params);
                return;
            }
        }
        Http::error('Not found', 404);
    }
}
