<?php
$_SESSION['logado'] = true;
$_SESSION['usuario_tipo'] = 'master';
$_GET['view'] = 'banco';
$_GET['mes_filtro'] = date('Y-m');
// mock script name to bypass SaaS check
$_SERVER['SCRIPT_NAME'] = 'test_banco.php';

require_once "conexao.php";
$usuario_id = 1;
$nome = 'Master';

$is_saas = false;

// Just copy the banco calculation logic
// Include the function
if (!function_exists('getMinutosEsperadosDia')) {
    function getMinutosEsperadosDia($email, $dia_semana_num) {
        if ($dia_semana_num >= 6) return 0;
        $em = strtolower(trim($email ?? ''));
        if ($em === 'ti@vixmed.com.br') return ($dia_semana_num == 5) ? 510 : 570;
        return ($dia_semana_num == 5) ? 480 : 540;
    }
}

function formatarHorasLocal($minutos) {
    $horas = floor($minutos / 60);
    $mins = round($minutos % 60);
    return sprintf("%02dh %02dm", $horas, $mins);
}

$mes_filtro = date('Y-m');
$ano_mes = explode('-', $mes_filtro);
$ano_calc = intval($ano_mes[0]);
$mes_calc = intval($ano_mes[1]);

$hoje_ano_mes = date('Y-m');
if ($mes_filtro === $hoje_ano_mes) {
    $ultimo_dia = intval(date('d'));
} else {
    $ultimo_dia = intval(date('t', strtotime("$mes_filtro-01")));
}

$stmt_us = $pdo->query("SELECT id, nome, email FROM usuarios WHERE email NOT IN ('tecnico1@vixmed.com.br', 'comercial@vixmed.com.br') ORDER BY nome ASC");
$usuarios_calc = $stmt_us->fetchAll(PDO::FETCH_ASSOC);

$banco_dados = [];

foreach ($usuarios_calc as $u) {
    $uid = $u['id'];
    
    $stmt_p = $pdo->prepare("
        SELECT DATE(data_hora) as data, tipo_registro, data_hora 
        FROM ponto_registros 
        WHERE usuario_id = :uid AND YEAR(data_hora) = :ano AND MONTH(data_hora) = :mes
        ORDER BY data_hora ASC
    ");
    $stmt_p->execute([':uid' => $uid, ':ano' => $ano_calc, ':mes' => $mes_calc]);
    $registros = $stmt_p->fetchAll(PDO::FETCH_ASSOC);

    $dias_registros = [];
    foreach ($registros as $r) {
        $dias_registros[$r['data']][$r['tipo_registro']] = $r['data_hora'];
    }

    $total_minutos_trabalhados = 0;
    $total_minutos_esperados = 0;
    $dias_incompletos = 0;
    $dias_ausentes = 0;
    $dias_uteis = 0;

    for ($d = 1; $d <= $ultimo_dia; $d++) {
        $dia_str = sprintf("%s-%02d", $mes_filtro, $d);
        $dia_semana = intval(date('N', strtotime($dia_str))); 
        $minutos_esp_dia = getMinutosEsperadosDia($u['email'], $dia_semana);
        $is_today = ($mes_filtro === $hoje_ano_mes && $d == $ultimo_dia);
        
        if ($dia_semana <= 5) {
            $dias_uteis++;
            if (isset($dias_registros[$dia_str])) {
                $batidas = $dias_registros[$dia_str];
                $has_entrada = isset($batidas['entrada']);
                $has_saida_almoco = isset($batidas['saida_almoco']);
                $has_retorno_almoco = isset($batidas['retorno_almoco']);
                $has_saida = isset($batidas['saida']);

                if ($is_today && !$has_saida) {
                } else {
                    $total_minutos_esperados += $minutos_esp_dia;
                }

                if ($has_entrada && $has_saida_almoco && $has_retorno_almoco && $has_saida) {
                    $t1 = strtotime($batidas['entrada']);
                    $t2 = strtotime($batidas['saida_almoco']);
                    $t3 = strtotime($batidas['retorno_almoco']);
                    $t4 = strtotime($batidas['saida']);
                    $manha = ($t2 - $t1) / 60;
                    $tarde = ($t4 - $t3) / 60;
                    $total_minutos_trabalhados += ($manha + $tarde);
                } elseif ($has_entrada && $has_saida && !$has_saida_almoco && !$has_retorno_almoco) {
                    $t1 = strtotime($batidas['entrada']);
                    $t4 = strtotime($batidas['saida']);
                    $total_dia = ($t4 - $t1) / 60;
                    if ($total_dia > 360) {
                        $total_dia -= 60; 
                    }
                    $total_minutos_trabalhados += $total_dia;
                } else {
                    $dias_incompletos++;
                }
            } else {
                if (!$is_today) {
                    $dias_ausentes++;
                    $total_minutos_esperados += $minutos_esp_dia;
                }
            }
        } else {
            if (isset($dias_registros[$dia_str])) {
                $batidas = $dias_registros[$dia_str];
                $has_entrada = isset($batidas['entrada']);
                $has_saida = isset($batidas['saida']);
                
                if ($has_entrada && $has_saida) {
                    $t1 = strtotime($batidas['entrada']);
                    $t4 = strtotime($batidas['saida']);
                    $total_dia = ($t4 - $t1) / 60;
                    
                    $has_saida_almoco = isset($batidas['saida_almoco']);
                    $has_retorno_almoco = isset($batidas['retorno_almoco']);
                    if ($has_saida_almoco && $has_retorno_almoco) {
                        $t2 = strtotime($batidas['saida_almoco']);
                        $t3 = strtotime($batidas['retorno_almoco']);
                        $total_dia = (($t2 - $t1) + ($t4 - $t3)) / 60;
                    } elseif ($total_dia > 360) {
                        $total_dia -= 60;
                    }
                    $total_minutos_trabalhados += $total_dia;
                }
            }
        }
    }

    $saldo_minutos = $total_minutos_trabalhados - $total_minutos_esperados;
    $banco_dados[] = [
        'nome' => $u['nome'],
        'horas_esperadas' => formatarHorasLocal($total_minutos_esperados),
        'horas_trabalhadas' => formatarHorasLocal($total_minutos_trabalhados),
        'saldo' => ($saldo_minutos >= 0 ? '+' : '-') . formatarHorasLocal(abs($saldo_minutos)),
        'ausentes' => $dias_ausentes,
        'incompletos' => $dias_incompletos
    ];
}

print_r($banco_dados);
