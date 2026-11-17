<?php
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET");

require_once '../koneksi.php';

/** @var mysqli $conn */
// ↑ SATU baris ini menyembuhkan warning di baris 15 & 28 sekaligus
//   + dapet autocomplete $conn->query, $conn->prepare, dll.

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(["status" => "error", "code" => 405, "message" => "Method tidak diizinkan"]);
    exit();
}

$result = $conn->query("SELECT id, nama_kategori FROM kategori ORDER BY id ASC");

$data = [];
while ($row = $result->fetch_assoc()) {
    $data[] = [
        "id"            => (int)$row['id'],
        "nama_kategori" => $row['nama_kategori']
    ];
}

http_response_code(200);
echo json_encode(["status" => "success", "code" => 200, "data" => $data]);
$conn->close();
