<?php
require_once "conexao.php";
date_default_timezone_set('America/Sao_Paulo');

$mes_filtro = '2026-08'; // Mês atual
$ano = 2026;
$mes = 8;
$hoje = date('d'); // Dia 17

$usuarios_ids = [1, 3, 5, 7, 8, 9]; // TI, Técnicas, Liberação, Limpeza

foreach ($usuarios_ids as $uid) {
    // Buscar os registros desse usuário neste mês
    $stmt_p = $pdo->prepare("
        SELECT DATE(data_hora) as data, tipo_registro, data_hora 
        FROM ponto_registros 
        WHERE usuario_id = :uid AND YEAR(data_hora) = :ano AND MONTH(data_hora) = :mes
        ORDER BY data_hora ASC
    ");
    $stmt_p->execute([':uid' => $uid, ':ano' => $ano, ':mes' => $mes]);
    $registros = $stmt_p->fetchAll(PDO::FETCH_ASSOC);

    $dias_registros = [];
    foreach ($registros as $r) {
        $dias_registros[$r['data']][$r['tipo_registro']] = $r['data_hora'];
    }

    echo "Verificando Usuario ID $uid...\n";

    // Vamos varrer do dia 1 até ontem (dia 16). O dia de hoje deixamos rolar naturalmente.
    for ($d = 1; $d < $hoje; $d++) {
        $data_str = sprintf("%04d-%02d-%02d", $ano, $mes, $d);
        $dia_semana = intval(date('N', strtotime($data_str))); // 1 (Seg) a 7 (Dom)

        if ($dia_semana <= 5) { // Dia Útil
            $batidas = $dias_registros[$data_str] ?? [];
            $has_entrada = isset($batidas['entrada']);
            $has_saida_almoco = isset($batidas['saida_almoco']);
            $has_retorno_almoco = isset($batidas['retorno_almoco']);
            $has_saida = isset($batidas['saida']);

            $is_ausente = empty($batidas);
            $is_incompleto = !$is_ausente && (!$has_entrada || !$has_saida_almoco || !$has_retorno_almoco || !$has_saida);

            if ($is_ausente || $is_incompleto) {
                echo "  $data_str: Ausente=$is_ausente Incompleto=$is_incompleto\n";
            }
        }
    }
}
