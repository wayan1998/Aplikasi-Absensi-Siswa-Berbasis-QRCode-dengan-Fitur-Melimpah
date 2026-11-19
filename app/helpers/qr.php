<?php
function new_qr_token(): string { return bin2hex(random_bytes(24)); } // 48 karakter hex, CSPRNG

function create_qr_for_student(PDO $pdo, int $studentId, ?int $by = null): string {
    $pdo->prepare('UPDATE student_qr_codes SET is_active=0 WHERE student_id=? AND is_active=1')->execute([$studentId]);
    for ($i = 0; $i < 5; $i++) {
        $token = new_qr_token();
        try {
            $pdo->prepare('INSERT INTO student_qr_codes (student_id, token, regenerated_by) VALUES (?,?,?)')->execute([$studentId, $token, $by]);
            return $token;
        } catch (PDOException $e) { if ($e->getCode() !== '23000') throw $e; }
    }
    throw new RuntimeException('Gagal membuat token QR unik.');
}
function active_ay_id(PDO $pdo): ?int {
    $v = $pdo->query('SELECT id FROM academic_years WHERE is_active=1 LIMIT 1')->fetchColumn();
    return $v ? (int)$v : null;
}
