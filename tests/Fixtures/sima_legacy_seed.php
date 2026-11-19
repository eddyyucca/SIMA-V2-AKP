<?php
/**
 * Mengisi database SIMA (skema asli Snipe-IT + kustom AKP) dengan data TIRUAN yang memuat kasus sulit migrasi.
 * Dipakai tes importer dan latihan lokal: php tests/Fixtures/sima_legacy_seed.php [host] [port] [db] [user] [pass]
 * Tidak berisi data nyata.
 */
[$host, $port, $db, $user, $pass] = [$argv[1] ?? '127.0.0.1', $argv[2] ?? '3307', $argv[3] ?? 'sima_legacy', $argv[4] ?? 'root', $argv[5] ?? 'legacy'];
$pdo = new PDO("mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec("SET SESSION sql_mode=''");   // kolom NOT NULL tanpa default (mis. created_by) terisi nilai implisit, seperti data lama SIMA
$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
foreach (['assets', 'users', 'departments', 'locations', 'categories', 'models', 'manufacturers', 'status_labels', 'suppliers', 'companies', 'accessories', 'accessories_checkout', 'consumables', 'components', 'components_assets', 'licenses', 'license_seats', 'maintenances', 'action_logs', 'bast_histories', 'asset_agent_tokens', 'asset_heartbeats', 'asset_helper_tokens', 'asset_agent_commands', 'agent_announcements', 'agent_announcement_targets', 'agent_chat_sessions', 'agent_chat_messages'] as $t) $pdo->exec("TRUNCATE TABLE `$t`");

function ins(PDO $pdo, string $t, array $r): int {
    $r += ['created_at' => '2025-01-10 08:00:00', 'updated_at' => '2025-06-01 08:00:00'];
    $has = array_column($pdo->query("SHOW COLUMNS FROM `$t`")->fetchAll(PDO::FETCH_ASSOC), 'Field');
    $r = array_intersect_key($r, array_flip($has));
    $c = array_keys($r);
    $pdo->prepare("INSERT INTO `$t` (`" . implode('`,`', $c) . '`) VALUES (' . implode(',', array_fill(0, count($c), '?')) . ')')->execute(array_values($r));
    return (int) $pdo->lastInsertId();
}

$dIT = ins($pdo, 'departments', ['name' => 'IT']); $dFin = ins($pdo, 'departments', ['name' => 'Finance']); $dHr = ins($pdo, 'departments', ['name' => 'it']); // 'it' duplikat huruf kecil
$lHq = ins($pdo, 'locations', ['name' => 'Kantor Pusat', 'city' => 'Makassar']); $lWh = ins($pdo, 'locations', ['name' => 'Gudang IT', 'parent_id' => $lHq]); $lSite = ins($pdo, 'locations', ['name' => 'Site A', 'city' => 'Kolaka']);
$sv1 = ins($pdo, 'suppliers', ['name' => 'PT Sumber Teknologi', 'contact' => 'Budi', 'phone' => '0811', 'email' => 's@x.test']); $sv2 = ins($pdo, 'suppliers', ['name' => 'CV Maju Jaya']);
$cLap = ins($pdo, 'categories', ['name' => 'Laptop', 'category_type' => 'asset']); $cMon = ins($pdo, 'categories', ['name' => 'Monitor', 'category_type' => 'asset']);
$cAcc = ins($pdo, 'categories', ['name' => 'Aksesoris', 'category_type' => 'accessory']); $cCon = ins($pdo, 'categories', ['name' => 'Tinta & Toner', 'category_type' => 'consumable']);
$cCom = ins($pdo, 'categories', ['name' => 'RAM', 'category_type' => 'component']); $cLic = ins($pdo, 'categories', ['name' => 'Software', 'category_type' => 'license']);
$mfL = ins($pdo, 'manufacturers', ['name' => 'Lenovo']); $mfD = ins($pdo, 'manufacturers', ['name' => 'Dell']); $mfH = ins($pdo, 'manufacturers', ['name' => 'HP']);
$mdE14 = ins($pdo, 'models', ['name' => 'ThinkPad E14', 'manufacturer_id' => $mfL, 'category_id' => $cLap]); $mdLat = ins($pdo, 'models', ['name' => 'Latitude 5440', 'manufacturer_id' => $mfD, 'category_id' => $cLap]);
$mdMon = ins($pdo, 'models', ['name' => 'P24h', 'manufacturer_id' => $mfH, 'category_id' => $cMon]);
$stReady = ins($pdo, 'status_labels', ['name' => 'Ready to Deploy', 'deployable' => 1]); $stRepair = ins($pdo, 'status_labels', ['name' => 'Out for Repair', 'deployable' => 0, 'pending' => 0]);
$stLost = ins($pdo, 'status_labels', ['name' => 'Lost/Stolen']); $stDisp = ins($pdo, 'status_labels', ['name' => 'Asset Disposed', 'archived' => 1]); $stOdd = ins($pdo, 'status_labels', ['name' => 'Status Aneh Cabang', 'deployable' => 0]);
$stBorrow = ins($pdo, 'status_labels', ['name' => 'Dipinjam', 'deployable' => 1]);

// pengguna: 6 ber-NIK, 1 tanpa NIK tapi memegang aset, 1 tanpa NIK tak dirujuk, 1 resign
$u = [];
$mkUser = function (string $username, ?string $nik, string $first, bool $active = true, ?int $dept = null, ?string $deleted = null) use ($pdo, &$u) {
    return $u[$username] = ins($pdo, 'users', ['username' => $username, 'email' => "$username@akp.test", 'password' => 'x', 'first_name' => $first, 'last_name' => 'Uji', 'employee_num' => $nik, 'activated' => $active ? 1 : 0, 'department_id' => $dept, 'jobtitle' => 'Staff', 'deleted_at' => $deleted]);
};
$mkUser('3260001', '3260001', 'Andi', true, $dIT); $mkUser('3260002', '3260002', 'Siti', true, $dFin); $mkUser('3260003', '3260003', 'Rudi', true, $dIT);
$mkUser('3260004', '3260004', 'Dewi', true, $dFin); $mkUser('3260005', '3260005', 'Eko', true, $dIT); $mkUser('3260006', '3260006', 'Resign', false, $dFin, '2025-03-01 00:00:00');
$mkUser('tanpa.nik', null, 'Tanpa NIK', true, $dIT); $mkUser('admin.teknis', null, 'Admin Teknis', true);

$tag = 0; $assets = [];
$mkAsset = function (array $o) use ($pdo, &$tag, &$assets, $mdE14, $mdLat, $mdMon, $stReady, $sv1, $lHq) {
    $tag++;
    $row = $o + ['asset_tag' => sprintf('LAP-%03d', $tag), 'name' => "PC-$tag", 'model_id' => $mdE14, 'serial' => "SN-$tag", 'purchase_date' => '2024-05-10', 'purchase_cost' => 12500000 + $tag * 1000,
        'order_number' => "PO-$tag", 'status_id' => $stReady, 'warranty_months' => 36, 'supplier_id' => $sv1, 'rtd_location_id' => $lHq, 'assigned_type' => null, 'assigned_to' => null, 'notes' => null];
    return $assets[$tag] = ins($pdo, 'assets', $row);
};
// 10 laptop dipegang karyawan (riwayat lengkap)
$held = [];
foreach (['3260001', '3260002', '3260003', '3260004', '3260005'] as $i => $nik) {
    $a = $mkAsset(['assigned_type' => 'App\\Models\\User', 'assigned_to' => $u[$nik], 'asset_tag' => "AKP-IT-NB-" . sprintf('%04d', $i + 1), 'last_checkout' => '2024-06-01 09:00:00']);
    $held[$a] = $nik;
    ins($pdo, 'action_logs', ['action_type' => 'checkout', 'item_type' => 'App\\Models\\Asset', 'item_id' => $a, 'target_type' => 'App\\Models\\User', 'target_id' => $u[$nik], 'created_at' => '2024-06-01 09:00:00', 'action_date' => '2024-06-01 09:00:00', 'note' => 'Awal']);
    ins($pdo, 'bast_histories', ['asset_id' => $a, 'user_id' => $u[$nik], 'document_number' => "IT/BAST/2024/06/" . sprintf('%04d', $i + 1), 'pdf_path' => "bast/legacy-$a.pdf", 'created_at' => '2024-06-01 09:05:00']);
}
// transfer: aset 6 dipegang Andi lalu dioper ke Siti
$a6 = $mkAsset(['assigned_type' => 'App\\Models\\User', 'assigned_to' => $u['3260002']]);
foreach ([['checkout', '3260001', '2024-02-01 09:00:00'], ['checkin from', '3260001', '2024-04-01 09:00:00'], ['checkout', '3260002', '2024-04-02 09:00:00']] as [$act, $nik, $at]) ins($pdo, 'action_logs', ['action_type' => $act, 'item_type' => 'App\\Models\\Asset', 'item_id' => $a6, 'target_type' => 'App\\Models\\User', 'target_id' => $u[$nik], 'created_at' => $at, 'action_date' => $at]);
// dipegang tanpa riwayat sama sekali (butuh baris penyesuaian)
$mkAsset(['assigned_type' => 'App\\Models\\User', 'assigned_to' => $u['3260003']]);
// dipegang pengguna tanpa NIK
$mkAsset(['assigned_type' => 'App\\Models\\User', 'assigned_to' => $u['tanpa.nik']]);
// dikeluarkan ke lokasi
$mkAsset(['assigned_type' => 'App\\Models\\Location', 'assigned_to' => $lSite]);
// tersedia (6), checkin selesai
for ($i = 0; $i < 6; $i++) { $a = $mkAsset([]); if ($i < 2) { foreach ([['checkout', '3260004', '2023-01-01 09:00:00'], ['checkin from', '3260004', '2023-12-01 09:00:00']] as [$act, $nik, $at]) ins($pdo, 'action_logs', ['action_type' => $act, 'item_type' => 'App\\Models\\Asset', 'item_id' => $a, 'target_type' => 'App\\Models\\User', 'target_id' => $u[$nik], 'created_at' => $at, 'action_date' => $at]); } }
// status khusus
$mkAsset(['status_id' => $stRepair]); $mkAsset(['status_id' => $stLost]); $mkAsset(['status_id' => $stDisp]); $mkAsset(['status_id' => $stOdd]);
$mkAsset(['status_id' => $stBorrow, 'assigned_type' => 'App\\Models\\User', 'assigned_to' => $u['3260005']]);
// serial duplikat (dua kali), tanpa serial, model tidak ada, soft-deleted, monitor
$mkAsset(['serial' => 'DUP-1']); $mkAsset(['serial' => 'dup-1']); $mkAsset(['serial' => null]); $mkAsset(['model_id' => null]);
$mkAsset(['deleted_at' => '2025-02-01 00:00:00', 'status_id' => $stDisp]);
for ($i = 0; $i < 4; $i++) $mkAsset(['model_id' => $mdMon, 'asset_tag' => sprintf('MON-%03d', $i + 1), 'purchase_cost' => 2500000]);

// maintenance
ins($pdo, 'maintenances', ['asset_id' => $assets[12] ?? $assets[1], 'asset_maintenance_type' => 'Repair', 'name' => 'Ganti SSD', 'start_date' => '2025-04-01', 'completion_date' => '2025-04-03', 'cost' => 750000, 'supplier_id' => $sv2, 'asset_maintenance_time' => 2]);
ins($pdo, 'maintenances', ['asset_id' => $assets[2], 'asset_maintenance_type' => 'Maintenance', 'name' => 'Pembersihan', 'start_date' => '2025-05-01', 'cost' => 0, 'asset_maintenance_time' => 0]);

// accessories / consumables / components
$acc = ins($pdo, 'accessories', ['name' => 'Mouse Logitech M185', 'category_id' => $cAcc, 'qty' => 10, 'min_amt' => 3, 'purchase_cost' => 95000, 'manufacturer_id' => $mfL, 'model_number' => 'M185', 'supplier_id' => $sv1]);
foreach (['3260001', '3260002', '3260005'] as $nik) ins($pdo, 'accessories_checkout', ['accessory_id' => $acc, 'assigned_to' => $u[$nik], 'assigned_type' => 'App\\Models\\User']);
ins($pdo, 'consumables', ['name' => 'Toner HP 05A', 'category_id' => $cCon, 'qty' => 5, 'min_amt' => 2, 'purchase_cost' => 850000]);
$ram = ins($pdo, 'components', ['name' => 'RAM DDR4 8GB', 'category_id' => $cCom, 'qty' => 6, 'min_amt' => 1, 'purchase_cost' => 450000, 'serial' => 'RAM-LOT-1']);
ins($pdo, 'components_assets', ['component_id' => $ram, 'asset_id' => $assets[1], 'assigned_qty' => 2]);

// lisensi
$l1 = ins($pdo, 'licenses', ['name' => 'Microsoft Office 2021', 'serial' => 'XXXXX-YYYYY', 'seats' => 5, 'purchase_cost' => 3000000, 'category_id' => $cLic, 'reassignable' => 1]);
ins($pdo, 'license_seats', ['license_id' => $l1, 'assigned_to' => $u['3260001']]); ins($pdo, 'license_seats', ['license_id' => $l1, 'asset_id' => $assets[2]]); ins($pdo, 'license_seats', ['license_id' => $l1]);
ins($pdo, 'licenses', ['name' => 'Antivirus 1 Tahun', 'seats' => 10, 'expiration_date' => '2026-12-31', 'category_id' => $cLic, 'reassignable' => 1]);

// agent: token bcrypt asli (plaintext "agent-token-N"), heartbeat, helper token, perintah, pengumuman, chat
foreach ([1, 2, 3, 4, 5] as $n) {
    $plain = "agent-token-$n-" . str_repeat('x', 20);
    ins($pdo, 'asset_agent_tokens', ['asset_id' => $assets[$n], 'token_hash' => password_hash($plain, PASSWORD_BCRYPT), 'token_lookup' => hash('sha256', $plain), 'revoked_at' => $n === 5 ? '2025-05-01 00:00:00' : null]);
    if ($n <= 4) ins($pdo, 'asset_heartbeats', ['asset_id' => $assets[$n], 'hostname' => "LAPTOP-$n", 'ip_address' => "10.0.0.$n", 'os_version' => 'Windows 11 Pro', 'reported_at' => '2026-11-18 08:00:00', 'agent_version' => 9]);
}
$pdo->exec("UPDATE assets SET agent_status='online', agent_last_seen='2026-11-18 08:00:00' WHERE id IN (" . implode(',', array_slice($assets, 0, 4)) . ")");
ins($pdo, 'asset_helper_tokens', ['asset_id' => $assets[1], 'token_hash' => hash('sha256', 'helper-1'), 'expires_at' => '2026-12-31 00:00:00']);
ins($pdo, 'asset_agent_commands', ['asset_id' => $assets[1], 'type' => 'notify_user', 'params' => json_encode(['message' => 'Halo']), 'status' => 'pending', 'requested_at' => '2026-11-18 08:00:00', 'expires_at' => '2026-11-30 08:00:00']);
ins($pdo, 'asset_agent_commands', ['asset_id' => $assets[1], 'type' => 'notify_user', 'params' => json_encode(['message' => 'Lama']), 'status' => 'done', 'requested_at' => '2026-10-18 08:00:00', 'expires_at' => '2026-10-19 08:00:00']);
$an = ins($pdo, 'agent_announcements', ['title' => 'Maintenance malam ini', 'body' => 'Server restart', 'severity' => 'important', 'require_ack' => 1, 'target_mode' => 'selected']);
ins($pdo, 'agent_announcement_targets', ['announcement_id' => $an, 'asset_id' => $assets[1]]);
$cs = ins($pdo, 'agent_chat_sessions', ['asset_id' => $assets[1], 'status' => 'open', 'started_at' => '2026-11-18 08:00:00', 'last_activity_at' => '2026-11-18 08:00:00']);
ins($pdo, 'agent_chat_messages', ['chat_session_id' => $cs, 'sender' => 'it', 'body' => 'Halo, ada masalah?', 'created_at' => '2026-11-18 08:00:00']);

$pdo->exec('SET FOREIGN_KEY_CHECKS=1');
echo 'OK assets=' . $pdo->query('select count(*) from assets')->fetchColumn() . ' users=' . $pdo->query('select count(*) from users')->fetchColumn() . PHP_EOL;
