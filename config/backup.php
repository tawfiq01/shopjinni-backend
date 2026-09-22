<?php

return [
    // Defaults to relying on PATH (true on most production Linux servers).
    // Override with the full binary path in .env for environments where
    // mysqldump isn't on PATH (e.g. a Scoop-installed MySQL on Windows).
    'mysqldump_path' => env('MYSQLDUMP_PATH', 'mysqldump'),
];
