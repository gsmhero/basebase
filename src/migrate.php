<?php
// Applies database/migrations/*.sql statement by statement; progress is tracked per
// statement (`file#n`) so a failed run can be resumed safely.
function run_migrations(): void {
    $pdo = db();
    $pdo->exec('CREATE TABLE IF NOT EXISTS migrations (name VARCHAR(160) PRIMARY KEY, applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB');
    $done = array_flip($pdo->query('SELECT name FROM migrations')->fetchAll(PDO::FETCH_COLUMN));
    $files = glob(ROOT . '/database/migrations/*.sql') ?: [];
    sort($files);
    foreach ($files as $f) {
        $base = basename($f);
        if (isset($done[$base])) continue;
        foreach (preg_split('/;\s*\n/', file_get_contents($f)) as $i => $stmt) {
            $stmt = trim($stmt);
            $key = $base . '#' . $i;
            if ($stmt === '' || isset($done[$key])) continue;
            $pdo->exec($stmt);
            $pdo->prepare('INSERT INTO migrations (name) VALUES (?)')->execute([$key]);
        }
        $pdo->prepare('INSERT INTO migrations (name) VALUES (?)')->execute([$base]);
    }
}
