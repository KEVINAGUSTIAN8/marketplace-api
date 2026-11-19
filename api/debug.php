<?php
header('Content-Type: application/json');
require_once '../koneksi.php';

$out = [
    'db_aktif'     => null,
    'tabel'        => [],
    'kolom_produk' => [],   // ← yang baru
    'contoh_data'  => [],
];

$res = $conn->query("SELECT DATABASE() AS db");
$out['db_aktif'] = $res ? $res->fetch_assoc()['db'] : null;

$res = $conn->query("SHOW TABLES");
while ($res && $row = $res->fetch_array()) $out['tabel'][] = $row[0];

// 🔑 Ambil struktur kolom tabel produk
$res = $conn->query("SHOW COLUMNS FROM produk");
while ($res && $row = $res->fetch_assoc()) {
    $out['kolom_produk'][] = [
        'field' => $row['Field'],
        'type'  => $row['Type'],
        'key'   => $row['Key'],
    ];
}

// Coba fetch pakai SELECT * (pasti jalan kalau tabel ada)
$res = $conn->query("SELECT * FROM produk LIMIT 3");
if ($res) {
    while ($row = $res->fetch_assoc()) $out['contoh_data'][] = $row;
} else {
    $out['error_fetch'] = $conn->error;  // 🔑 jangan silent lagi!
}

echo json_encode($out, JSON_PRETTY_PRINT);
