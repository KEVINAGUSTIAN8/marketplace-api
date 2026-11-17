<?php
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

require_once __DIR__ . '/../koneksi.php';
/** @var mysqli $conn — dipakai di bawah; koneksi.php sudah set mysqli_report STRICT */

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit();
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'code' => 405, 'message' => 'Gunakan POST']);
    exit();
}

$raw_input = json_decode(file_get_contents("php://input"), true);
$input     = is_array($raw_input) ? $raw_input : $_POST;

if (!isset($input['produk_id'], $input['jumlah'])
    || intval($input['produk_id']) <= 0
    || intval($input['jumlah']) <= 0) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'code' => 400,
        'message' => 'Parameter produk_id atau jumlah tidak valid']);
    exit();
}
// ============ BLOK 2 — LOGIC ORDER ============

$produk_id = intval($input['produk_id']);
$jumlah    = intval($input['jumlah']);

$conn->begin_transaction();
try {
    // 1. LOCK baris produk — cegah race condition order bersamaan
    //    FOR UPDATE = ambil exclusive row lock sampai transaction selesai
    $stmt_cek = $conn->prepare(
        "SELECT harga, stok FROM produk WHERE id = ? FOR UPDATE"
    );
    $stmt_cek->bind_param("i", $produk_id);
    $stmt_cek->execute();
    $res = $stmt_cek->get_result();

    // 2. EDGE CASE: produk_id ada di payload tapi nggak ada di DB
    if ($res->num_rows === 0) {
        $stmt_cek->close();
        throw new Exception("Produk tidak ditemukan", 404);
    }
    $produk = $res->fetch_assoc();
    $stmt_cek->close();

    // 3. EDGE CASE: stok cukup atau nggak (guard BEFORE commit)
    if ((int)$produk['stok'] < $jumlah) {
        throw new Exception(
            "Stok tidak mencukupi. Sisa stok: " . (int)$produk['stok'], 409
        );
    }

    // 4. Hitung total pakai harga DB, BUKAN dari payload
    //    (anti tamper: user nggak bisa ubah harga via JSON body)
    $total_harga = (int)$produk['harga'] * $jumlah;

    // 5. KURANGI stok atomik
    //    stok = stok - ?  (bukan $produk['stok'] - $jumlah) 
    //    supaya di-lock DB dan aman kalau ada order lain yang udah commit duluan
    $stmt_update = $conn->prepare(
        "UPDATE produk SET stok = stok - ? WHERE id = ?"
    );
    $stmt_update->bind_param("ii", $jumlah, $produk_id);
    $stmt_update->execute();
    $stmt_update->close();

    // 6. INSERT order
    $stmt_insert = $conn->prepare(
        "INSERT INTO pesanan (produk_id, jumlah, total_harga) VALUES (?, ?, ?)"
    );
    $stmt_insert->bind_param("iii", $produk_id, $jumlah, $total_harga);
    $stmt_insert->execute();
    $pesanan_id = $stmt_insert->insert_id;
    $stmt_insert->close();

    // 7. ✅ Semua query sukses → COMMIT
    $conn->commit();

    http_response_code(201);
    echo json_encode([
        "status"  => "success",
        "code"    => 201,
        "message" => "Pesanan berhasil dibuat",
        "data"    => [
            "pesanan_id"  => $pesanan_id,
            "produk_id"   => $produk_id,
            "jumlah"      => $jumlah,
            "total_harga" => $total_harga,
            "sisa_stok"   => (int)$produk['stok'] - $jumlah
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (mysqli_sql_exception $e) {
    // 8. 🔥 DATABASE GAGAG (syntax, FK, constraint, connection drop)
    //    JANGAN bocorin pesan mentah MySQL ke user.
    $conn->rollback();
    error_log('[DB] pesanan.php mysqli_sql_exception: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        "status"  => "error",
        "code"    => 500,
        "message" => "Terjadi kesalahan sistem, silakan coba lagi."
    ]);
} catch (Exception $e) {
    // 9. 🛡️ FLOW CONTROL dari kode lo sendiri (throw Exception di atas: 404, 409)
    //    Pesan boleh ke user (business message).
    $conn->rollback();
    $http_code = ($e->getCode() >= 400 && $e->getCode() < 600) ? $e->getCode() : 500;
    http_response_code($http_code);
    echo json_encode([
        "status"  => "error",
        "code"    => $http_code,
        "message" => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}

$conn->close();
