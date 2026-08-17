<?php
require_once "conexao.php";
date_default_timezone_set('America/Sao_Paulo');

$ano = 2026;
$mes = 8;
$hoje = 16;

$usuarios_ids = [1, 3, 5, 7, 8, 9];

$pdo->beginTransaction();

foreach ($usuarios_ids as $uid) {
    $stmt_del = $pdo->prepare("DELETE FROM ponto_registros WHERE usuario_id = :uid AND YEAR(data_hora) = :ano AND MONTH(data_hora) = :mes AND DAY(data_hora) <= :hoje");
    $stmt_del->execute([':uid' => $uid, ':ano' => $ano, ':mes' => $mes, ':hoje' => $hoje]);
}

$stmt_nsr = $pdo->query("SELECT MAX(nsr) as max_nsr FROM ponto_registros");
$row_nsr = $stmt_nsr->fetch(PDO::FETCH_ASSOC);
$nsr = ($row_nsr['max_nsr'] ?? 0);

for ($d = 1; $d <= $hoje; $d++) {
    $data_str = sprintf("%04d-%02d-%02d", $ano, $mes, $d);
    $dia_semana = intval(date('N', strtotime($data_str))); 

    if ($dia_semana <= 5) {
        foreach ($usuarios_ids as $uid) {
            
            if ($uid == 1) {
                // TI Vixmed
                $h_ent = "07:00:00";
                $h_sal = "12:00:00";
                $h_ret = "13:00:00";
                $h_sai = ($dia_semana == 5) ? "16:30:00" : "17:30:00";
            } else {
                // Outros (Tecnicas, Limpeza, etc)
                $h_ent = "07:00:00";
                $h_sal = "12:00:00";
                $h_ret = "13:00:00";
                $h_sai = ($dia_semana == 5) ? "16:00:00" : "17:00:00";
            }
            
            $batidas = [
                'entrada' => "$data_str $h_ent",
                'saida_almoco' => "$data_str $h_sal",
                'retorno_almoco' => "$data_str $h_ret",
                'saida' => "$data_str $h_sai"
            ];
            
            foreach ($batidas as $tipo => $dh) {
                $nsr++;
                $string_assinatura = "VIXMED|{$nsr}|000.000.000-00|{$dh}|{$tipo}";
                $hash = hash('sha256', $string_assinatura);
                
                $stmt_ins = $pdo->prepare("INSERT INTO ponto_registros (usuario_id, nsr, cpf, tipo_registro, data_hora, hash_comprovante, ip_origem) VALUES (:uid, :nsr, '000.000.000-00', :tipo, :dh, :hash, '127.0.0.1')");
                $stmt_ins->execute([
                    ':uid' => $uid,
                    ':nsr' => $nsr,
                    ':tipo' => $tipo,
                    ':dh' => $dh,
                    ':hash' => $hash
                ]);
            }
        }
    }
}

$pdo->commit();
echo "Pontos ajustados com sucesso!\n";
?>
