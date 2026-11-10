# Plugin-specific smoke assertions for aDataForSeo. Sourced by ci/smoke.sh
# (after the migrations check) with $evo_dir, $here, step() and fail() already
# defined. The one place, besides plugin.env, that differs between plugins.

step "aDataForSeo tables and permissions exist"
php -r '
$evo = $argv[1];
$dbs = array_merge(glob($evo . "/core/database/*.sqlite") ?: [], glob($evo . "/database/*.sqlite") ?: []);
if (!$dbs) { fwrite(STDERR, "no sqlite database\n"); exit(1); }
$pdo = new PDO("sqlite:" . $dbs[0], null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$tables = $pdo->query("SELECT name FROM sqlite_master WHERE type = \"table\"")->fetchAll(PDO::FETCH_COLUMN);
$bad = 0;
foreach (["adfs_projects", "adfs_keys", "adfs_jobs", "adfs_steps", "adfs_calls", "adfs_keywords", "adfs_targets", "adfs_rankings", "adfs_issues", "adfs_changes"] as $want) {
    $hit = array_filter($tables, fn($t) => substr($t, -strlen($want)) === $want);
    printf("%s table %s\n", $hit ? "ok  " : "MISS", $want);
    $bad += $hit ? 0 : 1;
}
$prefix = "";
foreach ($tables as $t) { if (substr($t, -13) === "adfs_projects") { $prefix = substr($t, 0, -13); } }
$keys = $pdo->query("SELECT \"key\" FROM \"" . $prefix . "permissions\"")->fetchAll(PDO::FETCH_COLUMN);
foreach (["adataforseo", "adataforseo_spend", "adataforseo_manage"] as $want) {
    printf("%s permission %s\n", in_array($want, $keys, true) ? "ok  " : "MISS", $want);
    $bad += in_array($want, $keys, true) ? 0 : 1;
}
exit($bad ? 1 : 0);
' "$evo_dir" || fail "aDataForSeo tables or permissions are missing"

step "adfs:tick"
( cd "$evo_dir" && php core/artisan adfs:tick ) || fail "php core/artisan adfs:tick exited non-zero"
