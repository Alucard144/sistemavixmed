<?php
session_start();

// 1. Configuração do Fuso Horário Oficial (Horário de Brasília - MTE Portaria 671)
date_default_timezone_set('America/Sao_Paulo');

// Restrição de Acesso: apenas usuários logados
if (!isset($_SESSION['logado']) || $_SESSION['logado'] !== true) {
    header("Location: index.php");
    exit();
}

require_once "conexao.php";

// Restrição de Acesso: impedir tecnico1 e comercial (Bianca) de acessar o ponto
if (isset($_SESSION['usuario_email']) && ($_SESSION['usuario_email'] === 'tecnico1@vixmed.com.br' || $_SESSION['usuario_email'] === 'comercial@vixmed.com.br')) {
    header("Location: pagina.php");
    exit();
}

$usuario_id = $_SESSION['usuario_id'];
$nome = $_SESSION['usuario_nome'] ?? 'Usuário';
$iniciais = mb_strtoupper(mb_substr($nome, 0, 2));

$is_saas = (strpos($_SERVER['SCRIPT_NAME'], 'saas_portal') !== false);
$tabela_registros = $is_saas ? 'saas_ponto_registros' : 'ponto_registros';
$tabela_usuarios = $is_saas ? 'saas_usuarios' : 'usuarios';
$tabela_ajustes = $is_saas ? 'saas_ponto_ajustes' : 'ponto_ajustes';

// Buscar dados do usuário (CPF)
$stmt_u = $pdo->prepare("SELECT email, nome FROM $tabela_usuarios WHERE id = :id");
$stmt_u->execute([':id' => $usuario_id]);
$dados_user = $stmt_u->fetch(PDO::FETCH_ASSOC);
$cpf_trabalhador = "000.000.000-00";

// ===== REGRAS DE JORNADA E CARGA HORÁRIA =====
if (!function_exists('getMinutosEsperadosDia')) {
    function getMinutosEsperadosDia($email, $dia_semana_num) {
        if ($dia_semana_num >= 6) {
            return 0; // Sábado (6) e Domingo (7) = 0h (folga)
        }
        $em = strtolower(trim($email ?? ''));

        // TI Vixmed ("eu"): Seg-Qui 07:00 às 17:30 (9.5h = 570m), Sex 07:00 às 16:30 (8.5h = 510m)
        if ($em === 'ti@vixmed.com.br') {
            return ($dia_semana_num == 5) ? 510 : 570;
        }

        // Helen, Marianna, Stephany, Dayana, Rafaela: Seg-Qui 9h (540m), Sex 8h (480m)
        return ($dia_semana_num == 5) ? 480 : 540;
    }
}

// ===== EXPORTAÇÕES FISCAIS (PORTARIA 671 MTE) =====

// 1. Exportação AFD (Arquivo Fonte de Dados - TXT Inalterável)
if (isset($_GET['export']) && $_GET['export'] === 'afd' && ($_SESSION['usuario_tipo'] ?? '') === 'master') {
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="AFD_Portaria671_Vixmed_' . date('Ymd_His') . '.txt"');

    $stmt_afd = $pdo->prepare("SELECT r.*, u.nome FROM $tabela_registros r JOIN $tabela_usuarios u ON r.usuario_id = u.id " . ($is_saas ? "WHERE r.empresa_id = :empresa_id" : "") . " ORDER BY r.nsr ASC");
    if ($is_saas) {
        $stmt_afd->execute([':empresa_id' => $_SESSION['usuario_empresa_id']]);
    } else {
        $stmt_afd->execute();
    }
    $registros = $stmt_afd->fetchAll(PDO::FETCH_ASSOC);

    $cnpj_empregador = "00000000000100";
    $razao_social = str_pad("VIXMED SOLUCOES MEDICAS LTDA", 150, " ");
    $data_inicial = count($registros) > 0 ? date('dmY', strtotime($registros[0]['data_hora'])) : date('dmY');
    $data_final = date('dmY');
    $data_geracao = date('dmYHis');

    echo "00000000011" . $cnpj_empregador . $razao_social . $data_inicial . $data_final . $data_geracao . "\r\n";

    $count = 0;
    foreach ($registros as $reg) {
        $count++;
        $nsr = str_pad($reg['nsr'], 9, "0", STR_PAD_LEFT);
        $tipo_cod = "3";
        $data_reg = date('dmY', strtotime($reg['data_hora']));
        $hora_reg = date('His', strtotime($reg['data_hora']));
        $cpf_reg = str_pad(preg_replace('/\D/', '', $reg['cpf'] ?: '00000000000'), 11, "0", STR_PAD_LEFT);

        echo $nsr . $tipo_cod . $data_reg . $hora_reg . $cpf_reg . "\r\n";
    }

    $total_linhas = str_pad($count, 9, "0", STR_PAD_LEFT);
    echo "999999999" . $total_linhas . "9\r\n";
    exit();
}

// 2. Exportação AEJ (Arquivo Eletrônico de Jornada)
if (isset($_GET['export']) && $_GET['export'] === 'aej' && ($_SESSION['usuario_tipo'] ?? '') === 'master') {
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="AEJ_Portaria671_Vixmed_' . date('Ymd_His') . '.txt"');

    $stmt_aej = $pdo->prepare("SELECT r.*, u.nome FROM $tabela_registros r JOIN $tabela_usuarios u ON r.usuario_id = u.id " . ($is_saas ? "WHERE r.empresa_id = :empresa_id" : "") . " ORDER BY r.data_hora ASC");
    if ($is_saas) {
        $stmt_aej->execute([':empresa_id' => $_SESSION['usuario_empresa_id']]);
    } else {
        $stmt_aej->execute();
    }
    $registros = $stmt_aej->fetchAll(PDO::FETCH_ASSOC);

    echo "AEJ - ARQUIVO ELETRONICO DE JORNADA (PORTARIA MTE 671)\r\n";
    echo "EMPRESA: VIXMED SOLUCOES MEDICAS LTDA\r\n";
    echo "CNPJ: 00.000.000/0001-00\r\n";
    echo "DATA DE EMISSAO: " . date('d/m/Y H:i:s') . "\r\n";
    echo "--------------------------------------------------------------------------------\r\n";
    echo "NSR       | DATA/HORA           | TRABALHADOR          | TIPO DE MARCAÇÃO    | AUTENTICAÇÃO SHA-256\r\n";
    echo "--------------------------------------------------------------------------------\r\n";

    foreach ($registros as $r) {
        $tipo_rotulo = match($r['tipo_registro']) {
            'entrada' => 'ENTRADA DIÁRIA',
            'saida_almoco' => 'SAÍDA INTERVALO',
            'retorno_almoco' => 'RETORNO INTERVALO',
            'saida' => 'SAÍDA DIÁRIA',
            default => 'MARCAÇÃO'
        };
        echo sprintf("%-9s | %-19s | %-20s | %-19s | %s\r\n", 
            str_pad($r['nsr'], 9, "0", STR_PAD_LEFT),
            date('d/m/Y H:i:s', strtotime($r['data_hora'])),
            mb_substr($r['nome'], 0, 20),
            $tipo_rotulo,
            mb_substr($r['hash_comprovante'], 0, 16) . '...'
        );
    }
    echo "--------------------------------------------------------------------------------\r\n";
    echo "TOTAL DE MARCAÇÕES COMPUTADAS: " . count($registros) . "\r\n";
    exit();
}

// ===== PROCESSAMENTO DE MARCAÇÃO DE PONTO =====
$mensagem_sucesso = "";
$comprovante_emitido = null;

if (isset($_POST['acao'])) {

    // Aceitar LGPD
    if ($_POST['acao'] === 'aceitar_lgpd') {
        $stmt_lgpd = $pdo->prepare("INSERT INTO ponto_lgpd_termos (usuario_id, aceito, data_aceite, ip) VALUES (:uid, 1, NOW(), :ip)");
        $stmt_lgpd->execute([':uid' => $usuario_id, ':ip' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1']);
        header("Location: folhadeponto.php");
        exit();
    }

    // Registrar Marcação de Ponto
    if ($_POST['acao'] === 'marcar_ponto') {
        $tipo_batida = $_POST['tipo_registro'] ?? 'entrada';
        $tipos_validos = ['entrada', 'saida_almoco', 'retorno_almoco', 'saida'];

        if (in_array($tipo_batida, $tipos_validos)) {
            // Calcular próximo NSR (Número Sequencial de Registro)
            $stmt_nsr = $pdo->query("SELECT MAX(nsr) as max_nsr FROM $tabela_registros");
            $row_nsr = $stmt_nsr->fetch(PDO::FETCH_ASSOC);
            $proximo_nsr = ($row_nsr['max_nsr'] ?? 0) + 1;

            $data_hora_atual = date('Y-m-d H:i:s');

            // Gerar Hash SHA-256 de autenticidade
            $string_assinatura = "VIXMED|{$proximo_nsr}|{$cpf_trabalhador}|{$data_hora_atual}|{$tipo_batida}";
            $hash_comprovante = hash('sha256', $string_assinatura);

            // Inserir registro no banco
            if ($is_saas) {
                $stmt_ins = $pdo->prepare("INSERT INTO $tabela_registros (empresa_id, usuario_id, nsr, cpf, tipo_registro, data_hora, hash_comprovante, ip_origem) VALUES (:empresa_id, :uid, :nsr, :cpf, :tipo, :dh, :hash, :ip)");
                $stmt_ins->execute([
                    ':empresa_id' => $_SESSION['usuario_empresa_id'],
                    ':uid' => $usuario_id,
                    ':nsr' => $proximo_nsr,
                    ':cpf' => $cpf_trabalhador,
                    ':tipo' => $tipo_batida,
                    ':dh' => $data_hora_atual,
                    ':hash' => $hash_comprovante,
                    ':ip' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
                ]);
            } else {
                $stmt_ins = $pdo->prepare("INSERT INTO $tabela_registros (usuario_id, nsr, cpf, tipo_registro, data_hora, hash_comprovante, ip_origem) VALUES (:uid, :nsr, :cpf, :tipo, :dh, :hash, :ip)");
                $stmt_ins->execute([
                    ':uid' => $usuario_id,
                    ':nsr' => $proximo_nsr,
                    ':cpf' => $cpf_trabalhador,
                    ':tipo' => $tipo_batida,
                    ':dh' => $data_hora_atual,
                    ':hash' => $hash_comprovante,
                    ':ip' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
                ]);
            }

            $mensagem_sucesso = "Ponto marcado com sucesso! Comprovante gerado.";

            // Dados do Comprovante para Exibição
            $comprovante_emitido = [
                'nsr' => str_pad($proximo_nsr, 9, "0", STR_PAD_LEFT),
                'trabalhador' => $nome,
                'cpf' => $cpf_trabalhador,
                'data_hora' => date('d/m/Y H:i:s', strtotime($data_hora_atual)),
                'tipo' => strtoupper(str_replace('_', ' ', $tipo_batida)),
                'hash' => $hash_comprovante
            ];

            // Resposta JSON para requisições AJAX
            if (isset($_POST['ajax']) && $_POST['ajax'] === '1') {
                header('Content-Type: application/json');
                echo json_encode([
                    'sucesso' => true,
                    'mensagem' => $mensagem_sucesso,
                    'comprovante' => $comprovante_emitido
                ]);
                exit();
            }
        }
    }

    // Solicitar Ajuste de Ponto
    if ($_POST['acao'] === 'solicitar_ajuste') {
        $data_solicitada = $_POST['data_solicitada'] ?? '';
        $horario_solicitado = $_POST['horario_solicitado'] ?? '';
        $tipo_registro = $_POST['tipo_registro'] ?? 'entrada';
        $motivo = $_POST['motivo'] ?? '';
        $tipos_validos = ['entrada', 'saida_almoco', 'retorno_almoco', 'saida'];

        if (!empty($data_solicitada) && !empty($horario_solicitado) && !empty($motivo) && in_array($tipo_registro, $tipos_validos)) {
            $is_saas = (strpos($_SERVER['SCRIPT_NAME'], 'saas_portal') !== false);
            $tabela_ajustes = $is_saas ? 'saas_ponto_ajustes' : 'ponto_ajustes';

            $stmt_ajuste = $pdo->prepare("INSERT INTO $tabela_ajustes (usuario_id, data_solicitada, horario_solicitado, tipo_registro, motivo, status) VALUES (:uid, :data_s, :hora_s, :tipo, :motivo, 'pendente')");
            $stmt_ajuste->execute([
                ':uid' => $usuario_id,
                ':data_s' => $data_solicitada,
                ':hora_s' => $horario_solicitado,
                ':tipo' => $tipo_registro,
                ':motivo' => $motivo
            ]);

            $mensagem_sucesso = "Solicitação de ajuste enviada com sucesso! Aguarde a aprovação.";
            
            $_SESSION['mensagem_sucesso_ajuste'] = $mensagem_sucesso;
            header("Location: folhadeponto.php?view=ajustes");
            exit();
        }
    }

    // Atualizar Status da Solicitação (Aprovar / Rejeitar) - Apenas Master
    if ($_POST['acao'] === 'atualizar_status_ajuste' && ($_SESSION['usuario_tipo'] ?? '') === 'master') {
        $solicitacao_id = intval($_POST['solicitacao_id'] ?? 0);
        $novo_status = $_POST['status'] ?? '';
        $observacao_admin = $_POST['observacao_admin'] ?? '';

        if ($solicitacao_id > 0 && in_array($novo_status, ['aprovado', 'rejeitado'])) {
            $is_saas = (strpos($_SERVER['SCRIPT_NAME'], 'saas_portal') !== false);
            $tabela_ajustes = $is_saas ? 'saas_ponto_ajustes' : 'ponto_ajustes';
            $tabela_registros = $is_saas ? 'saas_ponto_registros' : 'ponto_registros';
            
            $pdo->beginTransaction();

            $stmt_sel = $pdo->prepare("SELECT * FROM $tabela_ajustes WHERE id = :id");
            $stmt_sel->execute([':id' => $solicitacao_id]);
            $solicitacao = $stmt_sel->fetch(PDO::FETCH_ASSOC);

            if ($solicitacao) {
                if ($novo_status === 'aprovado') {
                    $stmt_nsr = $pdo->query("SELECT MAX(nsr) as max_nsr FROM $tabela_registros");
                    $row_nsr = $stmt_nsr->fetch(PDO::FETCH_ASSOC);
                    $proximo_nsr = ($row_nsr['max_nsr'] ?? 0) + 1;

                    $data_hora_registro = $solicitacao['data_solicitada'] . ' ' . $solicitacao['horario_solicitado'];
                    $string_assinatura = "VIXMED|{$proximo_nsr}|000.000.000-00|{$data_hora_registro}|{$solicitacao['tipo_registro']}";
                    $hash_comprovante = hash('sha256', $string_assinatura);

                    if ($is_saas) {
                        $stmt_ins = $pdo->prepare("INSERT INTO $tabela_registros (empresa_id, usuario_id, nsr, cpf, tipo_registro, data_hora, hash_comprovante, ip_origem) VALUES (:empresa_id, :uid, :nsr, '000.000.000-00', :tipo, :dh, :hash, '127.0.0.1')");
                        $stmt_ins->execute([
                            ':empresa_id' => $_SESSION['usuario_empresa_id'],
                            ':uid' => $solicitacao['usuario_id'],
                            ':nsr' => $proximo_nsr,
                            ':tipo' => $solicitacao['tipo_registro'],
                            ':dh' => $data_hora_registro,
                            ':hash' => $hash_comprovante
                        ]);
                    } else {
                        $stmt_ins = $pdo->prepare("INSERT INTO $tabela_registros (usuario_id, nsr, cpf, tipo_registro, data_hora, hash_comprovante, ip_origem) VALUES (:uid, :nsr, '000.000.000-00', :tipo, :dh, :hash, '127.0.0.1')");
                        $stmt_ins->execute([
                            ':uid' => $solicitacao['usuario_id'],
                            ':nsr' => $proximo_nsr,
                            ':tipo' => $solicitacao['tipo_registro'],
                            ':dh' => $data_hora_registro,
                            ':hash' => $hash_comprovante
                        ]);
                    }

                    if (empty($observacao_admin)) {
                        $observacao_admin = "Ajuste aprovado.";
                    }
                }

                $stmt_upd = $pdo->prepare("UPDATE $tabela_ajustes SET status = :status, observacao_admin = :obs WHERE id = :id");
                $stmt_upd->execute([
                    ':status' => $novo_status,
                    ':obs' => $observacao_admin,
                    ':id' => $solicitacao_id
                ]);

                $pdo->commit();
                $_SESSION['mensagem_sucesso_ajuste'] = "Ajuste de ponto " . ($novo_status === 'aprovado' ? 'aprovado e registrado' : 'rejeitado') . " com sucesso!";
                header("Location: folhadeponto.php?view=ajustes");
                exit();
            } else {
                $pdo->rollBack();
            }
        }
    }

    // Ajustar pontos de hoje para todos ou um usuário específico
    if ($_POST['acao'] === 'ajustar_ponto_hoje' && ($_SESSION['usuario_tipo'] ?? '') === 'master') {
        $target_uid = isset($_POST['usuario_id']) ? $_POST['usuario_id'] : 'todos';
        $pdo->beginTransaction();
        try {
            // Obter lista de usuários a ajustar
            if ($target_uid === 'todos') {
                if ($is_saas) {
                    $stmt_u_list = $pdo->prepare("SELECT id FROM $tabela_usuarios WHERE empresa_id = :empresa_id ORDER BY nome ASC");
                    $stmt_u_list->execute([':empresa_id' => $_SESSION['usuario_empresa_id']]);
                } else {
                    $stmt_u_list = $pdo->query("SELECT id FROM $tabela_usuarios WHERE email NOT IN ('tecnico1@vixmed.com.br', 'comercial@vixmed.com.br') ORDER BY nome ASC");
                    $stmt_u_list->execute();
                }
                $users = $stmt_u_list->fetchAll(PDO::FETCH_COLUMN);
            } else {
                $users = [intval($target_uid)];
            }

            $hoje = date('Y-m-d');
            $ajustados = 0;

            foreach ($users as $uid) {
                // Obter registros do usuário para hoje
                $stmt_check = $pdo->prepare("SELECT tipo_registro FROM $tabela_registros WHERE usuario_id = :uid AND DATE(data_hora) = :hoje");
                $stmt_check->execute([':uid' => $uid, ':hoje' => $hoje]);
                $batidas_hoje = $stmt_check->fetchAll(PDO::FETCH_COLUMN);

                $tipos = ['entrada', 'saida_almoco', 'retorno_almoco', 'saida'];
                $horarios_padrao = [
                    'entrada' => '08:00:00',
                    'saida_almoco' => '12:00:00',
                    'retorno_almoco' => '13:00:00',
                    'saida' => '17:00:00'
                ];

                $precisa_ajuste = false;
                foreach ($tipos as $t) {
                    if (!in_array($t, $batidas_hoje)) {
                        $precisa_ajuste = true;
                        
                        // Obter próximo NSR
                        $stmt_nsr = $pdo->query("SELECT MAX(nsr) as max_nsr FROM $tabela_registros");
                        $proximo_nsr = (intval($stmt_nsr->fetchColumn()) ?: 0) + 1;

                        $dh = $hoje . ' ' . $horarios_padrao[$t];
                        $hash = hash('sha256', "VIXMED|{$proximo_nsr}|000.000.000-00|{$dh}|{$t}");

                        if ($is_saas) {
                            $stmt_ins = $pdo->prepare("INSERT INTO $tabela_registros (empresa_id, usuario_id, nsr, cpf, tipo_registro, data_hora, hash_comprovante, ip_origem) VALUES (:empresa_id, :uid, :nsr, '000.000.000-00', :tipo, :dh, :hash, '127.0.0.1')");
                            $stmt_ins->execute([
                                ':empresa_id' => $_SESSION['usuario_empresa_id'],
                                ':uid' => $uid,
                                ':nsr' => $proximo_nsr,
                                ':tipo' => $t,
                                ':dh' => $dh,
                                ':hash' => $hash
                            ]);
                        } else {
                            $stmt_ins = $pdo->prepare("INSERT INTO $tabela_registros (usuario_id, nsr, cpf, tipo_registro, data_hora, hash_comprovante, ip_origem) VALUES (:uid, :nsr, '000.000.000-00', :tipo, :dh, :hash, '127.0.0.1')");
                            $stmt_ins->execute([
                                ':uid' => $uid,
                                ':nsr' => $proximo_nsr,
                                ':tipo' => $t,
                                ':dh' => $dh,
                                ':hash' => $hash
                            ]);
                        }
                    }
                }
                if ($precisa_ajuste) {
                    $ajustados++;
                }
            }

            $pdo->commit();
            $_SESSION['mensagem_sucesso_ajuste'] = "Ajuste de hoje realizado com sucesso para $ajustados colaborador(es)!";
        } catch (Exception $e) {
            $pdo->rollBack();
            $_SESSION['mensagem_erro_ajuste'] = "Erro ao ajustar pontos de hoje: " . $e->getMessage();
        }
        header("Location: folhadeponto.php?view=monitor");
        exit();
    }

    // Ajustar pontos por período
    if ($_POST['acao'] === 'ajustar_ponto_periodo' && ($_SESSION['usuario_tipo'] ?? '') === 'master') {
        $target_uid = isset($_POST['usuario_id']) ? $_POST['usuario_id'] : 'todos';
        $data_inicio = $_POST['data_inicio'] ?? '';
        $data_fim = $_POST['data_fim'] ?? '';
        $hora_ent = ($_POST['hora_entrada'] ?? '08:00') . ':00';
        $hora_s_alm = ($_POST['hora_saida_almoco'] ?? '12:00') . ':00';
        $hora_r_alm = ($_POST['hora_retorno_almoco'] ?? '13:00') . ':00';
        $hora_sai = ($_POST['hora_saida'] ?? '17:00') . ':00';
        $ajustar_fds = isset($_POST['ajustar_fim_semana']) && $_POST['ajustar_fim_semana'] == '1';

        if (!empty($data_inicio) && !empty($data_fim)) {
            $pdo->beginTransaction();
            try {
                if ($target_uid === 'todos') {
                    if ($is_saas) {
                        $stmt_u_list = $pdo->prepare("SELECT id FROM $tabela_usuarios WHERE empresa_id = :empresa_id ORDER BY nome ASC");
                        $stmt_u_list->execute([':empresa_id' => $_SESSION['usuario_empresa_id']]);
                    } else {
                        $stmt_u_list = $pdo->query("SELECT id FROM $tabela_usuarios WHERE email NOT IN ('tecnico1@vixmed.com.br', 'comercial@vixmed.com.br') ORDER BY nome ASC");
                        $stmt_u_list->execute();
                    }
                    $users = $stmt_u_list->fetchAll(PDO::FETCH_COLUMN);
                } else {
                    $users = [intval($target_uid)];
                }

                $start = new DateTime($data_inicio);
                $end = new DateTime($data_fim);
                $end->modify('+1 day'); // inclusive
                $interval = new DateInterval('P1D');
                $period = new DatePeriod($start, $interval, $end);

                $total_inseridos = 0;

                foreach ($users as $uid) {
                    foreach ($period as $date) {
                        $data_str = $date->format('Y-m-d');
                        $dia_semana = intval($date->format('N')); // 1-7
                        
                        if ($dia_semana > 5 && !$ajustar_fds) {
                            continue; // Pular fim de semana
                        }

                        // Obter batidas do usuário para este dia
                        $stmt_check = $pdo->prepare("SELECT tipo_registro FROM $tabela_registros WHERE usuario_id = :uid AND DATE(data_hora) = :dia");
                        $stmt_check->execute([':uid' => $uid, ':dia' => $data_str]);
                        $batidas_dia = $stmt_check->fetchAll(PDO::FETCH_COLUMN);

                        $tipos = ['entrada', 'saida_almoco', 'retorno_almoco', 'saida'];
                        $horarios_padrao = [
                            'entrada' => $hora_ent,
                            'saida_almoco' => $hora_s_alm,
                            'retorno_almoco' => $hora_r_alm,
                            'saida' => $hora_sai
                        ];

                        foreach ($tipos as $t) {
                            if (!in_array($t, $batidas_dia)) {
                                // Obter próximo NSR
                                $stmt_nsr = $pdo->query("SELECT MAX(nsr) as max_nsr FROM $tabela_registros");
                                $proximo_nsr = (intval($stmt_nsr->fetchColumn()) ?: 0) + 1;

                                $dh = $data_str . ' ' . $horarios_padrao[$t];
                                $hash = hash('sha256', "VIXMED|{$proximo_nsr}|000.000.000-00|{$dh}|{$t}");

                                if ($is_saas) {
                                    $stmt_ins = $pdo->prepare("INSERT INTO $tabela_registros (empresa_id, usuario_id, nsr, cpf, tipo_registro, data_hora, hash_comprovante, ip_origem) VALUES (:empresa_id, :uid, :nsr, '000.000.000-00', :tipo, :dh, :hash, '127.0.0.1')");
                                    $stmt_ins->execute([
                                        ':empresa_id' => $_SESSION['usuario_empresa_id'],
                                        ':uid' => $uid,
                                        ':nsr' => $proximo_nsr,
                                        ':tipo' => $t,
                                        ':dh' => $dh,
                                        ':hash' => $hash
                                    ]);
                                } else {
                                    $stmt_ins = $pdo->prepare("INSERT INTO $tabela_registros (usuario_id, nsr, cpf, tipo_registro, data_hora, hash_comprovante, ip_origem) VALUES (:uid, :nsr, '000.000.000-00', :tipo, :dh, :hash, '127.0.0.1')");
                                    $stmt_ins->execute([
                                        ':uid' => $uid,
                                        ':nsr' => $proximo_nsr,
                                        ':tipo' => $t,
                                        ':dh' => $dh,
                                        ':hash' => $hash
                                    ]);
                                }
                                $total_inseridos++;
                            }
                        }
                    }
                }

                $pdo->commit();
                $_SESSION['mensagem_sucesso_ajuste'] = "Ajuste por período concluído! $total_inseridos batida(s) inserida(s).";
            } catch (Exception $e) {
                $pdo->rollBack();
                $_SESSION['mensagem_erro_ajuste'] = "Erro ao processar ajuste por período: " . $e->getMessage();
            }
        }
        header("Location: folhadeponto.php?view=ajustes");
        exit();
    }
}

// Recuperar mensagem de sucesso da sessão se houver
if (isset($_SESSION['mensagem_sucesso_ajuste'])) {
    $mensagem_sucesso = $_SESSION['mensagem_sucesso_ajuste'];
    unset($_SESSION['mensagem_sucesso_ajuste']);
}

$mensagem_erro = "";
if (isset($_SESSION['mensagem_erro_ajuste'])) {
    $mensagem_erro = $_SESSION['mensagem_erro_ajuste'];
    unset($_SESSION['mensagem_erro_ajuste']);
}

// Verificar se já aceitou LGPD
$stmt_check_lgpd = $pdo->prepare("SELECT id FROM ponto_lgpd_termos WHERE usuario_id = :uid AND aceito = 1");
$stmt_check_lgpd->execute([':uid' => $usuario_id]);
$lgpd_aceito = $stmt_check_lgpd->fetchColumn();

// Buscar histórico de registros do mês atual
$stmt_hist = $pdo->prepare("SELECT * FROM $tabela_registros WHERE usuario_id = :uid AND MONTH(data_hora) = MONTH(CURRENT_DATE()) AND YEAR(data_hora) = YEAR(CURRENT_DATE()) ORDER BY nsr DESC");
$stmt_hist->execute([':uid' => $usuario_id]);
$historico = $stmt_hist->fetchAll(PDO::FETCH_ASSOC);

// Verificar se o almoço está em andamento hoje (Portaria 671 MTE)
$almoco_em_andamento = false;
$hora_saida_almoco = null;
$hoje_str = date('Y-m-d');
$tem_saida_almoco_hoje = false;
$tem_retorno_almoco_hoje = false;

foreach ($historico as $reg) {
    $data_reg = date('Y-m-d', strtotime($reg['data_hora']));
    if ($data_reg === $hoje_str) {
        if ($reg['tipo_registro'] === 'saida_almoco') {
            $tem_saida_almoco_hoje = true;
            $hora_saida_almoco = $reg['data_hora'];
        } elseif ($reg['tipo_registro'] === 'retorno_almoco') {
            $tem_retorno_almoco_hoje = true;
        }
    }
}

if ($tem_saida_almoco_hoje && !$tem_retorno_almoco_hoje) {
    $almoco_em_andamento = true;
}

$view = $_GET['view'] ?? 'marcar';

// Buscar solicitações de ajuste
$is_saas = (strpos($_SERVER['SCRIPT_NAME'], 'saas_portal') !== false);
$tabela_ajustes = $is_saas ? 'saas_ponto_ajustes' : 'ponto_ajustes';
$tabela_usuarios = $is_saas ? 'saas_usuarios' : 'usuarios';

if (($_SESSION['usuario_tipo'] ?? '') === 'master') {
    $where_clause = $is_saas ? "" : "WHERE u.email NOT IN ('tecnico1@vixmed.com.br', 'comercial@vixmed.com.br')";
    $stmt_aj_list = $pdo->prepare("
        SELECT a.*, u.nome as funcionario_nome, u.email as funcionario_email 
        FROM $tabela_ajustes a 
        JOIN $tabela_usuarios u ON a.usuario_id = u.id 
        $where_clause
        ORDER BY FIELD(a.status, 'pendente', 'aprovado', 'rejeitado') ASC, a.criado_em DESC
    ");
    $stmt_aj_list->execute();
    $solicitacoes_ajuste = $stmt_aj_list->fetchAll(PDO::FETCH_ASSOC);
} else {
    $stmt_aj_list = $pdo->prepare("
        SELECT a.* 
        FROM $tabela_ajustes a 
        WHERE a.usuario_id = :uid 
        ORDER BY a.criado_em DESC
    ");
    $stmt_aj_list->execute([':uid' => $usuario_id]);
    $solicitacoes_ajuste = $stmt_aj_list->fetchAll(PDO::FETCH_ASSOC);
}

// Buscar dados para o monitoramento de presença
$todos_usuarios = [];
$mapeamento_pontos = [];
$data_filtro = $_GET['data_filtro'] ?? date('Y-m-d');

if ($view === 'monitor' && ($_SESSION['usuario_tipo'] ?? '') === 'master') {
    // Buscar todos os usuários
    if ($is_saas) {
        $stmt_us = $pdo->query("SELECT id, nome, email, tipo FROM $tabela_usuarios ORDER BY nome ASC");
    } else {
        $stmt_us = $pdo->query("SELECT id, nome, email, tipo FROM $tabela_usuarios WHERE email NOT IN ('tecnico1@vixmed.com.br', 'comercial@vixmed.com.br') ORDER BY nome ASC");
    }
    $todos_usuarios = $stmt_us->fetchAll(PDO::FETCH_ASSOC);

    // Buscar registros de ponto da data filtrada
    $tabela_registros = $is_saas ? 'saas_ponto_registros' : 'ponto_registros';
    $stmt_pts = $pdo->prepare("SELECT usuario_id, tipo_registro, data_hora FROM $tabela_registros WHERE DATE(data_hora) = :data_filtro");
    $stmt_pts->execute([':data_filtro' => $data_filtro]);
    $pontos_data = $stmt_pts->fetchAll(PDO::FETCH_ASSOC);

    // Mapear pontos para os usuários
    foreach ($pontos_data as $p) {
        $uid = $p['usuario_id'];
        $tipo = $p['tipo_registro'];
        $hora = date('H:i:s', strtotime($p['data_hora']));
        $mapeamento_pontos[$uid][$tipo] = $hora;
    }
}

// ====== CÁLCULO DO BANCO DE HORAS ======
$banco_dados = [];
if ($view === 'banco') {
    // Obter filtro de mês/ano (padrão: mês atual)
    $mes_filtro = $_GET['mes_filtro'] ?? date('Y-m');
    $ano_mes = explode('-', $mes_filtro);
    $ano_calc = intval($ano_mes[0] ?? date('Y'));
    $mes_calc = intval($ano_mes[1] ?? date('m'));

    // Obter último dia a calcular para o mês selecionado
    $hoje_ano_mes = date('Y-m');
    if ($mes_filtro === $hoje_ano_mes) {
        $ultimo_dia = intval(date('d'));
    } else {
        $ultimo_dia = intval(date('t', strtotime("$mes_filtro-01")));
    }

    // 1. Obter usuários para calcular (filtrados por empresa_id em caso de SaaS)
    if (($_SESSION['usuario_tipo'] ?? '') === 'master') {
        if ($is_saas) {
            $stmt_us = $pdo->prepare("SELECT id, nome, email FROM $tabela_usuarios WHERE empresa_id = :empresa_id ORDER BY nome ASC");
            $stmt_us->execute([':empresa_id' => $_SESSION['usuario_empresa_id']]);
        } else {
            $stmt_us = $pdo->query("SELECT id, nome, email FROM $tabela_usuarios WHERE email NOT IN ('tecnico1@vixmed.com.br', 'comercial@vixmed.com.br') ORDER BY nome ASC");
            $stmt_us->execute();
        }
        $usuarios_calc = $stmt_us->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $email_usuario = $dados_user['email'] ?? $_SESSION['usuario_email'] ?? '';
        $usuarios_calc = [['id' => $usuario_id, 'nome' => $nome, 'email' => $email_usuario]];
    }
    
    foreach ($usuarios_calc as $u) {
        $uid = $u['id'];
        
        // Buscar registros do mês selecionado
        $stmt_p = $pdo->prepare("
            SELECT DATE(data_hora) as data, tipo_registro, data_hora 
            FROM $tabela_registros 
            WHERE usuario_id = :uid AND YEAR(data_hora) = :ano AND MONTH(data_hora) = :mes
            ORDER BY data_hora ASC
        ");
        $stmt_p->execute([
            ':uid' => $uid,
            ':ano' => $ano_calc,
            ':mes' => $mes_calc
        ]);
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
            $dia_semana = intval(date('N', strtotime($dia_str))); // 1-7
            $minutos_esp_dia = getMinutosEsperadosDia($u['email'], $dia_semana);
            $is_today = ($mes_filtro === $hoje_ano_mes && $d == $ultimo_dia);
            
            if ($dia_semana <= 5) {
                // Dia útil
                $dias_uteis++;
                if (isset($dias_registros[$dia_str])) {
                    $batidas = $dias_registros[$dia_str];
                    $has_entrada = isset($batidas['entrada']);
                    $has_saida_almoco = isset($batidas['saida_almoco']);
                    $has_retorno_almoco = isset($batidas['retorno_almoco']);
                    $has_saida = isset($batidas['saida']);

                    if ($is_today && !$has_saida) {
                        // Hoje em andamento: não acumula débito falso para o dia de hoje
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
                        // Apenas Entrada e Saída
                        $t1 = strtotime($batidas['entrada']);
                        $t4 = strtotime($batidas['saida']);
                        $total_dia = ($t4 - $t1) / 60;
                        if ($total_dia > 360) {
                            $total_dia -= 60; // Descontar 1 hora de almoço automática se trabalhou > 6h
                        }
                        $total_minutos_trabalhados += $total_dia;
                    } else {
                        // Marcações incompletas (ex: esqueceu de registrar alguma)
                        $dias_incompletos++;
                    }
                } else {
                    $dias_ausentes++;
                }
            } else {
                // Fim de semana (Sábado ou Domingo) - somar se houver registro de trabalho
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
            'id' => $u['id'],
            'nome' => $u['nome'],
            'email' => $u['email'],
            'dias_uteis' => $dias_uteis,
            'dias_incompletos' => $dias_incompletos,
            'dias_ausentes' => $dias_ausentes,
            'horas_esperadas' => formatarHorasLocal($total_minutos_esperados),
            'horas_trabalhadas' => formatarHorasLocal($total_minutos_trabalhados),
            'saldo' => ($saldo_minutos >= 0 ? '+' : '-') . formatarHorasLocal(abs($saldo_minutos)),
            'saldo_raw' => $saldo_minutos
        ];
    }
}

function formatarHorasLocal($minutos) {
    $horas = floor($minutos / 60);
    $mins = round($minutos % 60);
    return sprintf("%02dh %02dm", $horas, $mins);
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vixmed Ponto - REP-P (Portaria MTE 671)</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --azul-escuro: #0a1628;
            --azul-medio: #1a2d4a;
            --azul-claro: #243b5e;
            --verde: #00c853;
            --verde-hover: #00a844;
            --verde-suave: rgba(0, 200, 83, 0.12);
            --branco: #ffffff;
            --cinza-borda: rgba(255, 255, 255, 0.1);
            --cinza-texto: rgba(255, 255, 255, 0.75);
            --vermelho: #ef4444;
            --amarelo: #f59e0b;
            --sombra: 0 4px 24px rgba(0, 0, 0, 0.3);
            --radius: 14px;
            --transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--azul-escuro);
            color: var(--branco);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        /* CARD DE TIMER DE ALMOÇO */
        .almoco-timer-card {
            background: rgba(245, 158, 11, 0.08);
            border: 1px solid rgba(245, 158, 11, 0.25);
            border-radius: 16px;
            padding: 16px 24px;
            display: flex;
            align-items: center;
            gap: 16px;
            margin-bottom: 24px;
            width: 100%;
            max-width: 480px;
            box-shadow: 0 4px 15px rgba(245, 158, 11, 0.05);
            border-left: 4px solid var(--amarelo);
            transition: var(--transition);
        }
        .timer-icon {
            font-size: 28px;
            animation: bounceTimer 2s infinite ease-in-out;
        }
        @keyframes bounceTimer {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-4px); }
        }
        .timer-title {
            font-size: 13px;
            font-weight: 700;
            color: var(--amarelo);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            text-align: left;
        }
        .timer-countdown {
            font-size: 18px;
            font-weight: 700;
            color: var(--branco);
            margin: 4px 0;
            text-align: left;
        }
        .timer-highlight {
            color: var(--amarelo);
            font-family: monospace;
            font-size: 20px;
        }
        .timer-subtitle {
            font-size: 11px;
            color: rgba(255, 255, 255, 0.55);
            text-align: left;
        }

        /* SOLICITAÇÕES DE AJUSTE DE PONTO */
        .badge-status {
            display: inline-flex;
            align-items: center;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
        }
        .badge-status.pendente {
            background: rgba(245, 158, 11, 0.15);
            color: var(--amarelo);
        }
        .badge-status.aprovado {
            background: rgba(0, 200, 83, 0.15);
            color: var(--verde);
        }
        .badge-status.rejeitado {
            background: rgba(239, 68, 68, 0.15);
            color: var(--vermelho);
        }
        .ajuste-card-solicitacao {
            background: var(--azul-medio);
            border: 1px solid var(--cinza-borda);
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 16px;
            box-shadow: var(--sombra);
            display: flex;
            flex-direction: column;
            gap: 12px;
            transition: var(--transition);
        }
        .ajuste-card-solicitacao:hover {
            border-color: rgba(255,255,255,0.2);
        }
        .ajuste-card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid var(--cinza-borda);
            padding-bottom: 10px;
        }
        .ajuste-card-title {
            font-size: 14px;
            font-weight: 700;
            color: var(--branco);
        }
        .ajuste-card-body {
            font-size: 13px;
            color: var(--cinza-texto);
            line-height: 1.5;
            text-align: left;
        }
        .ajuste-card-meta {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            gap: 8px;
            background: rgba(255, 255, 255, 0.03);
            padding: 10px;
            border-radius: 8px;
            margin-top: 6px;
        }
        .ajuste-meta-item {
            font-size: 12px;
        }
        .ajuste-meta-item strong {
            color: var(--branco);
        }
        .ajuste-card-reason {
            font-style: italic;
            background: rgba(0,0,0,0.15);
            padding: 8px 12px;
            border-radius: 8px;
            margin-top: 6px;
            border-left: 3px solid var(--azul-claro);
        }
        .ajuste-card-admin-obs {
            background: rgba(239, 68, 68, 0.08);
            border: 1px dashed rgba(239, 68, 68, 0.25);
            padding: 10px 12px;
            border-radius: 8px;
            margin-top: 6px;
            font-size: 12px;
            color: #fca5a5;
        }
        .ajuste-actions {
            display: flex;
            gap: 8px;
            margin-top: 8px;
        }
        /* Formulário de solicitação */
        .form-ajuste-box {
            display: flex;
            flex-direction: column;
            gap: 16px;
            width: 100%;
            max-width: 600px;
        }
        .form-control-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
            text-align: left;
        }
        .form-control-group label {
            font-size: 12px;
            font-weight: 600;
            color: var(--cinza-texto);
        }
        .form-control-group input, 
        .form-control-group select, 
        .form-control-group textarea {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--cinza-borda);
            border-radius: 10px;
            padding: 12px;
            color: var(--branco);
            font-family: inherit;
            font-size: 13px;
            outline: none;
            transition: var(--transition);
        }
        .form-control-group input:focus, 
        .form-control-group select:focus, 
        .form-control-group textarea:focus {
            border-color: var(--verde);
            box-shadow: 0 0 0 3px rgba(0, 200, 83, 0.15);
        }
        /* Abas de filtro para o admin */
        .admin-ajustes-tabs {
            display: flex;
            gap: 8px;
            margin-bottom: 20px;
            border-bottom: 1px solid var(--cinza-borda);
            padding-bottom: 10px;
            width: 100%;
        }
        .admin-tab-btn {
            background: transparent;
            border: none;
            color: var(--cinza-texto);
            padding: 8px 16px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            border-radius: 8px;
            transition: var(--transition);
        }
        .admin-tab-btn.active {
            background: var(--azul-claro);
            color: var(--branco);
        }
        /* Modal de rejeição */
        .modal-rejeitar-overlay {
            position: fixed; top: 0; left: 0; width: 100vw; height: 100vh;
            background: rgba(10, 22, 40, 0.85); backdrop-filter: blur(8px);
            display: none; align-items: center; justify-content: center; z-index: 1100; padding: 20px;
        }
        .modal-rejeitar-overlay.open {
            display: flex;
        }

        /* BARRA SUPERIOR NAV */
        .ponto-topbar { padding: 16px 24px; width: 100%; }
        .ponto-topbar-inner {
            max-width: 1400px;
            margin: 0 auto;
            background: var(--azul-medio);
            border-radius: 16px;
            padding: 10px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
            border: 1px solid var(--cinza-borda);
        }

        .ponto-brand { display: flex; align-items: center; gap: 10px; }
        .ponto-brand img { width: 32px; height: 32px; border-radius: 8px; object-fit: contain; }
        .ponto-brand h1 { font-size: 18px; font-weight: 700; color: var(--branco); }
        .ponto-brand h1 span { color: var(--verde); }

        .ponto-nav { list-style: none; display: flex; gap: 6px; align-items: center; }
        .ponto-nav li a {
            display: flex; align-items: center; gap: 8px; padding: 10px 16px;
            color: rgba(255, 255, 255, 0.7); text-decoration: none; border-radius: 12px;
            font-size: 13px; font-weight: 600; transition: var(--transition);
            cursor: pointer !important;
        }
        .ponto-nav li a:hover { background: rgba(255, 255, 255, 0.1); color: var(--branco); }
        .ponto-nav li a.active { background: var(--verde); color: var(--branco); box-shadow: 0 4px 12px rgba(0, 200, 83, 0.3); }

        .ponto-user { display: flex; align-items: center; gap: 10px; }
        .ponto-user-avatar {
            width: 32px; height: 32px; background: var(--verde); border-radius: 50%;
            display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 12px; color: var(--branco);
        }
        .ponto-user-name { font-size: 13px; font-weight: 600; color: rgba(255, 255, 255, 0.9); }
        .ponto-user-logout { color: rgba(255, 255, 255, 0.5); text-decoration: none; font-size: 13px; padding: 6px 12px; border-radius: 8px; cursor: pointer !important; }
        .ponto-user-logout:hover { background: rgba(239, 68, 68, 0.2); color: var(--vermelho); }

        /* MAIN CONTENT */
        .ponto-main {
            flex: 1;
            max-width: 1400px;
            margin: 0 auto;
            width: 100%;
            padding: 16px 24px 32px;
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        /* SELO CONFORMIDADE MTE */
        .mte-badge {
            background: rgba(0, 200, 83, 0.12);
            border: 1px solid rgba(0, 200, 83, 0.25);
            color: var(--verde);
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            margin-bottom: 20px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        /* RELÓGIO */
        .ponto-clock { text-align: center; margin-bottom: 24px; }
        .ponto-clock-time {
            font-size: clamp(3rem, 6vw, 4.5rem);
            font-weight: 800;
            letter-spacing: -2px;
            color: var(--branco);
        }
        .ponto-clock-date { font-size: 15px; color: rgba(255, 255, 255, 0.6); font-weight: 500; margin-top: 4px; text-transform: capitalize; }

        /* SELEÇÃO DO TIPO DE MARCAÇÃO */
        .tipo-selector {
            display: flex;
            gap: 8px;
            margin-bottom: 28px;
            background: var(--azul-medio);
            padding: 6px;
            border-radius: 14px;
            border: 1px solid var(--cinza-borda);
        }
        .tipo-btn {
            padding: 10px 18px;
            border: none;
            background: transparent;
            color: rgba(255, 255, 255, 0.7);
            font-family: inherit;
            font-size: 13px;
            font-weight: 600;
            border-radius: 10px;
            cursor: pointer !important;
            transition: var(--transition);
        }
        .tipo-btn.active {
            background: var(--verde);
            color: var(--branco);
            box-shadow: 0 4px 12px rgba(0, 200, 83, 0.3);
        }

        /* BOTÃO PUNCH CLOCK INTERATIVO (COM CURSOR POINTER) */
        .ponto-btn-container { position: relative; display: flex; align-items: center; justify-content: center; margin-bottom: 32px; }
        .ponto-btn-ring {
            position: absolute; width: 210px; height: 210px; border-radius: 50%;
            border: 3px solid rgba(0, 200, 83, 0.25); animation: pulseRing 2.5s ease-in-out infinite;
            pointer-events: none;
        }
        @keyframes pulseRing { 0%, 100% { transform: scale(1); opacity: 1; } 50% { transform: scale(1.08); opacity: 0.5; } }

        .ponto-btn {
            width: 170px; height: 170px; border-radius: 50%;
            background: linear-gradient(135deg, var(--verde) 0%, #00a844 100%);
            border: none; color: var(--branco); font-family: inherit; font-size: 15px; font-weight: 700;
            cursor: pointer !important; display: flex; flex-direction: column; align-items: center; justify-content: center;
            gap: 6px; box-shadow: 0 8px 40px rgba(0, 200, 83, 0.4); transition: var(--transition);
            text-transform: uppercase; letter-spacing: 1px; user-select: none; z-index: 10;
        }
        .ponto-btn:hover { cursor: pointer !important; transform: scale(1.06) !important; box-shadow: 0 14px 55px rgba(0, 200, 83, 0.55) !important; }
        .ponto-btn:active { transform: scale(0.95) !important; }
        .ponto-btn-icon { font-size: 32px; pointer-events: none; }

        /* CARDS E CONTEÚDO PRINCIPAL */
        .grid-ponto-full { width: 100%; max-width: 1200px; margin-top: 20px; }
        .card-ponto {
            background: var(--azul-medio); border-radius: var(--radius); padding: 24px;
            border: 1px solid var(--cinza-borda); box-shadow: var(--sombra); width: 100%;
        }
        .card-header-flex { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; flex-wrap: wrap; gap: 10px; }
        .card-ponto h3 { font-size: 16px; font-weight: 700; color: var(--branco); display: flex; align-items: center; gap: 8px; margin: 0; }

        /* TABELA DE HISTÓRICO */
        .table-ponto { width: 100%; border-collapse: collapse; margin-top: 12px; }
        .table-ponto th { background: #0d2137; color: var(--branco); padding: 12px 16px; text-align: left; font-size: 12px; font-weight: 600; text-transform: uppercase; }
        .table-ponto td { padding: 12px 16px; border-bottom: 1px solid var(--cinza-borda); font-size: 13px; color: rgba(255, 255, 255, 0.85); }
        .table-ponto tr:hover { background: rgba(0, 200, 83, 0.08); }

        /* COMPROVANTE DO TRABALHADOR ESTILO RECEIPT */
        .comprovante-box {
            background: #ffffff; color: #1a1a2e; padding: 20px; border-radius: 12px; font-family: monospace; font-size: 12px; line-height: 1.6;
            border: 2px dashed #00c853; box-shadow: 0 8px 30px rgba(0,0,0,0.4); margin-bottom: 16px;
        }
        .comprovante-header { text-align: center; border-bottom: 1px solid #ddd; padding-bottom: 10px; margin-bottom: 10px; font-weight: bold; }
        .comprovante-row { display: flex; justify-content: space-between; margin-bottom: 4px; }
        .comprovante-hash { background: #f0f2f5; padding: 6px; word-break: break-all; font-size: 10px; border-radius: 4px; margin-top: 8px; border: 1px solid #ccc; }

        /* BOTÕES DE AÇÃO */
        .btn-action-view {
            background: rgba(0, 200, 83, 0.15); border: 1px solid rgba(0, 200, 83, 0.3);
            color: var(--verde); padding: 6px 12px; border-radius: 8px; font-size: 12px;
            font-weight: 600; cursor: pointer !important; transition: var(--transition);
        }
        .btn-action-view:hover { background: var(--verde); color: var(--branco); }

        .btn-folha-unificada {
            background: rgba(0, 200, 83, 0.15); border: 1px solid rgba(0, 200, 83, 0.3);
            color: var(--verde); padding: 8px 14px; border-radius: 10px; font-size: 12px;
            font-weight: 700; cursor: pointer !important; transition: var(--transition);
        }
        .btn-folha-unificada:hover { background: var(--verde); color: var(--branco); }

        /* ===== MODAL POPUP COMPROVANTE (CLICAR FORA FECHA) ===== */
        .modal-comprovante-overlay {
            position: fixed; top: 0; left: 0; width: 100vw; height: 100vh;
            background: rgba(10, 22, 40, 0.85); backdrop-filter: blur(8px);
            display: none; align-items: center; justify-content: center; z-index: 1000; padding: 20px;
        }
        .modal-comprovante-overlay.open {
            display: flex !important; animation: popModal 0.25s cubic-bezier(0.4, 0, 0.2, 1);
        }
        @keyframes popModal { from { opacity: 0; transform: scale(0.95); } to { opacity: 1; transform: scale(1); } }

        .modal-comprovante-card {
            background: var(--azul-medio); border: 1px solid var(--cinza-borda);
            border-radius: 20px; padding: 28px; max-width: 580px; width: 100%;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.6); color: var(--branco); position: relative;
        }
        .modal-comprovante-header {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 18px; border-bottom: 1px solid var(--cinza-borda); padding-bottom: 12px;
        }
        .modal-comprovante-header h3 { font-size: 17px; font-weight: 700; color: var(--branco); margin: 0; }
        .btn-close-modal {
            background: rgba(255, 255, 255, 0.1); border: none; color: var(--branco);
            width: 32px; height: 32px; border-radius: 50%; font-size: 16px; cursor: pointer !important;
            display: flex; align-items: center; justify-content: center; transition: var(--transition);
        }
        .btn-close-modal:hover { background: rgba(239, 68, 68, 0.3); color: var(--vermelho); }

        .modal-footer-btns { display: flex; gap: 10px; margin-top: 18px; }

        /* MODAL LGPD */
        .lgpd-overlay {
            position: fixed; top: 0; left: 0; width: 100vw; height: 100vh;
            background: rgba(10, 22, 40, 0.85); backdrop-filter: blur(8px);
            display: flex; align-items: center; justify-content: center; z-index: 999;
        }
        .lgpd-card {
            background: var(--azul-medio); border: 1px solid var(--cinza-borda);
            border-radius: 20px; padding: 32px; max-width: 540px; width: 90%; color: var(--branco);
        }
        .lgpd-card h3 { font-size: 20px; margin-bottom: 12px; color: var(--verde); }
        .lgpd-card p { font-size: 14px; color: var(--cinza-texto); line-height: 1.6; margin-bottom: 20px; }

        .btn-green { background: var(--verde); color: var(--branco); border: none; padding: 12px 20px; border-radius: 10px; font-weight: 700; cursor: pointer !important; transition: var(--transition); }
        .btn-green:hover { background: var(--verde-hover); }

        .btn-secondary-modal { background: rgba(255, 255, 255, 0.1); color: var(--branco); border: 1px solid var(--cinza-borda); padding: 12px 20px; border-radius: 10px; font-weight: 600; cursor: pointer !important; transition: var(--transition); }
        .btn-secondary-modal:hover { background: rgba(255, 255, 255, 0.2); }

        /* ESTILOS RESPONSIVOS PARA CELULARES */
        #tabela-historico-container {
            width: 100%;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        @media (max-width: 768px) {
            .ponto-topbar {
                padding: 10px;
            }
            .ponto-topbar-inner {
                flex-direction: column;
                gap: 14px;
                padding: 15px 10px;
                align-items: center;
                text-align: center;
            }
            .ponto-brand {
                justify-content: center;
            }
            .ponto-nav {
                width: 100%;
                justify-content: center;
                gap: 6px;
                flex-wrap: wrap;
            }
            .ponto-nav li {
                width: auto;
            }
            .ponto-nav li a {
                padding: 8px 12px;
                font-size: 12px;
                justify-content: center;
            }
            .ponto-user {
                width: 100%;
                justify-content: center;
                border-top: 1px solid var(--cinza-borda);
                padding-top: 12px;
            }
            .ponto-user-name {
                display: inline-block;
                font-size: 12px;
            }

            .ponto-main {
                padding: 10px 12px 24px;
            }

            .mte-badge {
                text-align: center;
                justify-content: center;
                width: 100%;
                font-size: 11px;
                padding: 6px 12px;
            }

            /* Abas do seletor em grade 2x2 para caber perfeitamente no celular */
            .tipo-selector {
                display: grid;
                grid-template-columns: repeat(2, 1fr);
                width: 100%;
                max-width: 450px;
                gap: 6px;
                padding: 6px;
            }
            .tipo-btn {
                width: 100%;
                padding: 10px 6px;
                text-align: center;
                font-size: 12px;
                border-radius: 8px;
            }

            .card-ponto {
                padding: 16px;
            }

            .card-header-flex {
                flex-direction: column;
                gap: 12px;
                align-items: center;
                text-align: center;
            }
            .btn-folha-unificada {
                width: 100%;
                text-align: center;
            }

            .modal-comprovante-card {
                padding: 16px;
                max-width: 100%;
            }
            
            .modal-footer-btns {
                flex-direction: column;
            }
        }

        @media (max-width: 600px) {
            /* Ocultar a coluna do Hash SHA-256 no celular para evitar espremer a tabela */
            .table-ponto th:nth-child(4),
            .table-ponto td:nth-child(4) {
                display: none;
            }
            .table-ponto th, .table-ponto td {
                padding: 10px 8px;
                font-size: 12px;
            }
        }
    </style>
</head>
<body>

    <!-- MODAL POPUP COMPROVANTE (CLICAR FORA FECHA) -->
    <div id="modal-comprovante-overlay" class="modal-comprovante-overlay" onclick="fecharModalComprovante(event)">
        <div class="modal-comprovante-card" onclick="event.stopPropagation()">
            <div class="modal-comprovante-header">
                <h3>🧾 Comprovante do Trabalhador (Portaria 671)</h3>
                <button type="button" class="btn-close-modal" onclick="fecharModalComprovanteDirect()" title="Fechar (ESC)">✖</button>
            </div>
            <div id="modal-comprovante-body">
                <!-- Conteúdo dinâmico do comprovante -->
            </div>
            <div class="modal-footer-btns">
                <button class="btn-green" style="flex: 1;" onclick="imprimirComprovante()">🖨️ Imprimir / Baixar PDF</button>
                <button class="btn-secondary-modal" onclick="fecharModalComprovanteDirect()">Fechar</button>
            </div>
        </div>
    </div>

    <!-- MODAL LGPD (Portaria MTE 671) -->
    <?php if (!$lgpd_aceito): ?>
    <div class="lgpd-overlay">
        <div class="lgpd-card">
            <h3>🔒 Termo de Consentimento LGPD</h3>
            <p>Em atendimento à Lei Geral de Proteção de Dados (Lei nº 13.709/2018) e às diretrizes do Ministério do Trabalho e Emprego (Portaria MTE nº 671/2021), informamos que os seus dados de registro de ponto (horário, IP de origem e identificação) são coletados exclusivamente para fins de controle de jornada de trabalho e emissão de comprovantes fiscais inalteráveis.</p>
            <form method="POST">
                <input type="hidden" name="acao" value="aceitar_lgpd">
                <button type="submit" class="btn-green" style="width: 100%;">Concordar e Acessar o Ponto</button>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- BARRA SUPERIOR -->
    <header class="ponto-topbar">
        <div class="ponto-topbar-inner">
            <div class="ponto-brand">
                <img src="img-10.webp" alt="Logo Vixmed">
                <h1>Vix<span>med</span> Ponto</h1>
            </div>

            <ul class="ponto-nav">
                <li><a href="folhadeponto.php" class="<?= $view === 'marcar' ? 'active' : '' ?>"><span class="nav-icon">⏰</span> Marcar Ponto</a></li>
                <li><a href="folhadeponto.php?view=ajustes" class="<?= $view === 'ajustes' ? 'active' : '' ?>"><span class="nav-icon">🔧</span> Solicitações de Ajuste</a></li>
                <li><a href="folhadeponto.php?view=banco" class="<?= $view === 'banco' ? 'active' : '' ?>"><span class="nav-icon">📊</span> Banco de Horas</a></li>
                <?php if (($_SESSION['usuario_tipo'] ?? '') === 'master'): ?>
                <li><a href="folhadeponto.php?view=monitor" class="<?= $view === 'monitor' ? 'active' : '' ?>"><span class="nav-icon">📊</span> Monitor de Presença</a></li>
                <li><a href="folhadeponto.php?export=afd" title="Arquivo Fonte de Dados para Fiscalização do Trabalho"><span class="nav-icon">📥</span> Exportar AFD (MTE)</a></li>
                <li><a href="folhadeponto.php?export=aej" title="Arquivo Eletrônico de Jornada"><span class="nav-icon">📊</span> Exportar AEJ (MTE)</a></li>
                <?php endif; ?>
                <li><a href="pagina.php"><span class="nav-icon">📊</span> Ir para Chamados</a></li>
            </ul>

            <div class="ponto-user">
                <div class="ponto-user-avatar"><?= $iniciais ?></div>
                <span class="ponto-user-name"><?= htmlspecialchars($nome) ?></span>
                <a href="logout.php" class="ponto-user-logout">🚪 Sair</a>
            </div>
        </div>
    </header>

    <!-- CONTEÚDO PRINCIPAL -->
    <main class="ponto-main">

        <!-- SELO CONFORMIDADE MTE -->
        <div class="mte-badge">
            <span>🛡️</span> Sistema de Ponto Homologado REP-P — Portaria MTE nº 671/2021
        </div>

        <!-- MENSAGEM SUCESSO -->
        <div id="mensagem-banner" style="display: <?= $mensagem_sucesso ? 'block' : 'none' ?>; background: rgba(0, 200, 83, 0.15); border: 1px solid rgba(0, 200, 83, 0.3); color: var(--verde); padding: 12px 24px; border-radius: 12px; margin-bottom: 20px; font-weight: 600;">
            ✅ <?= htmlspecialchars($mensagem_sucesso) ?>
        </div>

        <!-- MENSAGEM ERRO -->
        <?php if (!empty($mensagem_erro)): ?>
        <div id="mensagem-erro-banner" style="background: rgba(239, 68, 68, 0.15); border: 1px solid rgba(239, 68, 68, 0.3); color: var(--vermelho); padding: 12px 24px; border-radius: 12px; margin-bottom: 20px; font-weight: 600;">
            ❌ <?= htmlspecialchars($mensagem_erro) ?>
        </div>
        <?php endif; ?>

        <?php if ($view === 'marcar'): 
            // ====== CÁLCULO COMPLETO E AO VIVO DA JORNADA DO MÊS VIGENTE ======
            $email_logado = $dados_user['email'] ?? $_SESSION['usuario_email'] ?? '';
            $mes_atual_str = date('Y-m');
            $hoje_dia_num = intval(date('d'));
            $now_ts = time();

            $stmt_calc_usr = $pdo->prepare("
                SELECT DATE(data_hora) as data, tipo_registro, data_hora 
                FROM $tabela_registros 
                WHERE usuario_id = :uid AND YEAR(data_hora) = :ano AND MONTH(data_hora) = :mes
                ORDER BY data_hora ASC
            ");
            $stmt_calc_usr->execute([
                ':uid' => $usuario_id,
                ':ano' => date('Y'),
                ':mes' => date('m')
            ]);
            $registros_usr_mes = $stmt_calc_usr->fetchAll(PDO::FETCH_ASSOC);

            $dias_reg_usr = [];
            foreach ($registros_usr_mes as $r) {
                $dias_reg_usr[$r['data']][$r['tipo_registro']] = $r['data_hora'];
            }

            $tot_min_trabalhados_usr = 0;
            $tot_min_esperados_usr = 0;
            $inconsistencias_usr_cnt = 0;
            $tabela_diaria_usr = [];

            for ($d = 1; $d <= $hoje_dia_num; $d++) {
                $dia_st = sprintf("%s-%02d", $mes_atual_str, $d);
                $dia_semana_n = intval(date('N', strtotime($dia_st)));
                $dias_trad = [1 => 'Segunda', 2 => 'Terça', 3 => 'Quarta', 4 => 'Quinta', 5 => 'Sexta', 6 => 'Sábado', 7 => 'Domingo'];
                $dia_nome_st = $dias_trad[$dia_semana_n];
                
                $esp_dia = getMinutosEsperadosDia($email_logado, $dia_semana_n);
                $trab_dia = 0;
                $is_today = ($d == $hoje_dia_num);
                $status_label = "—";
                $badge_bg = "rgba(255,255,255,0.05)";
                $badge_color = "var(--cinza-texto)";
                
                $ent_st = "—";
                $sa_st = "—";
                $ra_st = "—";
                $sai_st = "—";

                if (isset($dias_reg_usr[$dia_st])) {
                    $bats = $dias_reg_usr[$dia_st];
                    $ent_st = isset($bats['entrada']) ? date('H:i', strtotime($bats['entrada'])) : "—";
                    $sa_st = isset($bats['saida_almoco']) ? date('H:i', strtotime($bats['saida_almoco'])) : "—";
                    $ra_st = isset($bats['retorno_almoco']) ? date('H:i', strtotime($bats['retorno_almoco'])) : "—";
                    $sai_st = isset($bats['saida']) ? date('H:i', strtotime($bats['saida'])) : "—";

                    $has_e = isset($bats['entrada']);
                    $has_sa = isset($bats['saida_almoco']);
                    $has_ra = isset($bats['retorno_almoco']);
                    $has_s = isset($bats['saida']);

                    if ($has_e && $has_sa && $has_ra && $has_s) {
                        $t1 = strtotime($bats['entrada']);
                        $t2 = strtotime($bats['saida_almoco']);
                        $t3 = strtotime($bats['retorno_almoco']);
                        $t4 = strtotime($bats['saida']);
                        $trab_dia = max(0, (($t2 - $t1) + ($t4 - $t3)) / 60);
                        $status_label = "✅ OK";
                        $badge_bg = "rgba(0, 200, 83, 0.15)";
                        $badge_color = "#00c853";
                    } elseif ($has_e && $has_s && !$has_sa && !$has_ra) {
                        $t1 = strtotime($bats['entrada']);
                        $t4 = strtotime($bats['saida']);
                        $trab_dia = max(0, ($t4 - $t1) / 60);
                        if ($trab_dia > 360) $trab_dia -= 60;
                        $status_label = "✅ OK";
                        $badge_bg = "rgba(0, 200, 83, 0.15)";
                        $badge_color = "#00c853";
                    } else {
                        if ($is_today) {
                            if ($has_e && $has_sa && $has_ra) {
                                $t1 = strtotime($bats['entrada']);
                                $t2 = strtotime($bats['saida_almoco']);
                                $t3 = strtotime($bats['retorno_almoco']);
                                $manha = max(0, ($t2 - $t1) / 60);
                                $tarde_ao_vivo = max(0, ($now_ts - $t3) / 60);
                                $trab_dia = $manha + $tarde_ao_vivo;
                                $status_label = "⏳ Em Andamento ⏱️";
                            } elseif ($has_e && $has_sa) {
                                $t1 = strtotime($bats['entrada']);
                                $t2 = strtotime($bats['saida_almoco']);
                                $trab_dia = max(0, ($t2 - $t1) / 60);
                                $status_label = "🥗 Horário de Almoço";
                            } elseif ($has_e) {
                                $t1 = strtotime($bats['entrada']);
                                $trab_dia = max(0, ($now_ts - $t1) / 60);
                                $status_label = "⏳ Em Andamento ⏱️";
                            }
                            $badge_bg = "rgba(59, 130, 246, 0.15)";
                            $badge_color = "#60a5fa";
                        } else {
                            if ($has_e && $has_sa) {
                                $t1 = strtotime($bats['entrada']);
                                $t2 = strtotime($bats['saida_almoco']);
                                $trab_dia = max(0, ($t2 - $t1) / 60);
                            }
                            $inconsistencias_usr_cnt++;
                            $status_label = "⚠️ Inconsistência";
                            $badge_bg = "rgba(245, 158, 11, 0.15)";
                            $badge_color = "#fbbf24";
                        }
                    }
                } else {
                    if (!$is_today && $dia_semana_n <= 5) {
                        $inconsistencias_usr_cnt++;
                        $status_label = "❌ Falta Sem Ponto";
                        $badge_bg = "rgba(239, 68, 68, 0.15)";
                        $badge_color = "#fca5a5";
                    } elseif ($is_today && $dia_semana_n <= 5) {
                        $status_label = "⏳ Aguardando Entrada";
                        $badge_bg = "rgba(59, 130, 246, 0.15)";
                        $badge_color = "#60a5fa";
                    } elseif ($dia_semana_n >= 6) {
                        $status_label = "☕ Folga";
                    }
                }

                $tot_min_trabalhados_usr += $trab_dia;

                if ($dia_semana_n <= 5) {
                    if ($is_today) {
                        if (isset($bats['saida'])) {
                            $tot_min_esperados_usr += $esp_dia;
                        } else {
                            $tot_min_esperados_usr += min($trab_dia, $esp_dia);
                        }
                    } else {
                        $tot_min_esperados_usr += $esp_dia;
                    }
                }

                $saldo_dia_m = $trab_dia - $esp_dia;

                $tabela_diaria_usr[] = [
                    'data' => date('d/m', strtotime($dia_st)),
                    'dia_nome' => $dia_nome_st,
                    'entrada' => $ent_st,
                    'saida_almoco' => $sa_st,
                    'retorno_almoco' => $ra_st,
                    'saida' => $sai_st,
                    'trabalhado_str' => sprintf("%02dh %02dmin", floor($trab_dia / 60), round($trab_dia) % 60),
                    'saldo_dia_min' => $saldo_dia_m,
                    'saldo_dia_str' => ($saldo_dia_m >= 0 ? "+" : "-") . sprintf("%02dh %02dmin", floor(abs($saldo_dia_m) / 60), round(abs($saldo_dia_m)) % 60),
                    'status_label' => $status_label,
                    'badge_bg' => $badge_bg,
                    'badge_color' => $badge_color,
                    'is_today' => $is_today,
                    'is_weekend' => ($dia_semana_n >= 6)
                ];
            }

            $saldo_usr_min = $tot_min_trabalhados_usr - $tot_min_esperados_usr;
            $abs_saldo_usr = abs(round($saldo_usr_min));
            $h_usr = floor($abs_saldo_usr / 60);
            $m_usr = $abs_saldo_usr % 60;
            $m_usr_str = $m_usr > 0 ? "{$h_usr}h {$m_usr}min" : "{$h_usr}h";

            if ($saldo_usr_min < -1) {
                $str_saldo_usr = "🔴 Devendo {$m_usr_str}";
                $color_saldo_usr = "#ef4444";
                $bg_saldo_usr = "rgba(239, 68, 68, 0.15)";
                $border_saldo_usr = "rgba(239, 68, 68, 0.3)";
            } elseif ($saldo_usr_min > 1) {
                $str_saldo_usr = "🟢 Sobrando +{$m_usr_str}";
                $color_saldo_usr = "#00c853";
                $bg_saldo_usr = "rgba(0, 200, 83, 0.15)";
                $border_saldo_usr = "rgba(0, 200, 83, 0.3)";
            } else {
                $str_saldo_usr = "⚪ Saldo em Dia (00:00h)";
                $color_saldo_usr = "#9ca3af";
                $bg_saldo_usr = "rgba(156, 163, 175, 0.15)";
                $border_saldo_usr = "rgba(156, 163, 175, 0.3)";
            }
        ?>
            <!-- RELÓGIO EM TEMPO REAL (HORÁRIO DE BRASÍLIA) -->
            <div class="ponto-clock">
                <div class="ponto-clock-time" id="relogio">00:00:00</div>
                <div class="ponto-clock-date" id="data-hoje"></div>
            </div>

            <?php if ($almoco_em_andamento && $hora_saida_almoco): ?>
                <!-- CARD DE ALMOÇO EM ANDAMENTO -->
                <div id="almoco-timer-container" class="almoco-timer-card">
                    <span class="timer-icon">🥗</span>
                    <div>
                        <div class="timer-title">Almoço em Andamento</div>
                        <div class="timer-countdown" id="tempo-restante-almoco">Carregando tempo...</div>
                        <div class="timer-subtitle">Seu intervalo iniciou às <?= date('H:i:s', strtotime($hora_saida_almoco)) ?></div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- FORMULÁRIO DE MARCAÇÃO (AJAX NATIVO) -->
            <form method="POST" action="folhadeponto.php" id="form-ponto" onsubmit="return submeterPonto(event)" style="display: flex; flex-direction: column; align-items: center;">
                <input type="hidden" name="acao" value="marcar_ponto">
                <input type="hidden" name="tipo_registro" id="input-tipo-registro" value="entrada">

                <!-- ABAS SELEÇÃO TIPO -->
                <div class="tipo-selector">
                    <button type="button" class="tipo-btn active" onclick="setTipo('entrada', this)">📥 Entrada</button>
                    <button type="button" class="tipo-btn" onclick="setTipo('saida_almoco', this)">☕ Saída Almoço</button>
                    <button type="button" class="tipo-btn" onclick="setTipo('retorno_almoco', this)">🥗 Retorno Almoço</button>
                    <button type="button" class="tipo-btn" onclick="setTipo('saida', this)">📤 Saída</button>
                </div>

                <!-- BOTÃO CENTRAL MARCAR PONTO -->
                <div class="ponto-btn-container">
                    <div class="ponto-btn-ring"></div>
                    <button type="submit" class="ponto-btn" id="btn-marcar-ponto">
                        <span class="ponto-btn-icon">👆</span>
                        Registrar
                    </button>
                </div>
            </form>

            <!-- REGISTROS RECENTES DO MÊS E ACESSO AOS COMPROVANTES -->
            <?php 
            // CÁLCULO DO SALDO DO MÊS VIGENTE PARA O COLABORADOR LOGADO NO SAAS
            $email_logado = $dados_user['email'] ?? $_SESSION['usuario_email'] ?? '';
            $mes_atual_str = date('Y-m');
            $hoje_dia_num = intval(date('d'));

            $stmt_calc_usr = $pdo->prepare("
                SELECT DATE(data_hora) as data, tipo_registro, data_hora 
                FROM $tabela_registros 
                WHERE usuario_id = :uid AND YEAR(data_hora) = :ano AND MONTH(data_hora) = :mes
                ORDER BY data_hora ASC
            ");
            $stmt_calc_usr->execute([
                ':uid' => $usuario_id,
                ':ano' => date('Y'),
                ':mes' => date('m')
            ]);
            $registros_usr_mes = $stmt_calc_usr->fetchAll(PDO::FETCH_ASSOC);

            $dias_reg_usr = [];
            foreach ($registros_usr_mes as $r) {
                $dias_reg_usr[$r['data']][$r['tipo_registro']] = $r['data_hora'];
            }

            $tot_min_trabalhados_usr = 0;
            $tot_min_esperados_usr = 0;
            $inconsistencias_usr_cnt = 0;
            $tabela_diaria_usr = [];

            for ($d = 1; $d <= $hoje_dia_num; $d++) {
                $dia_st = sprintf("%s-%02d", $mes_atual_str, $d);
                $dia_semana_n = intval(date('N', strtotime($dia_st)));
                $dias_trad = [1 => 'Segunda', 2 => 'Terça', 3 => 'Quarta', 4 => 'Quinta', 5 => 'Sexta', 6 => 'Sábado', 7 => 'Domingo'];
                $dia_nome_st = $dias_trad[$dia_semana_n];
                
                $esp_dia = getMinutosEsperadosDia($email_logado, $dia_semana_n);
                $trab_dia = 0;
                $is_today = ($d == $hoje_dia_num);
                $status_label = "—";
                $badge_bg = "rgba(255,255,255,0.05)";
                $badge_color = "var(--cinza-texto)";
                
                $ent_st = "—";
                $sa_st = "—";
                $ra_st = "—";
                $sai_st = "—";

                if (isset($dias_reg_usr[$dia_st])) {
                    $bats = $dias_reg_usr[$dia_st];
                    $ent_st = isset($bats['entrada']) ? date('H:i', strtotime($bats['entrada'])) : "—";
                    $sa_st = isset($bats['saida_almoco']) ? date('H:i', strtotime($bats['saida_almoco'])) : "—";
                    $ra_st = isset($bats['retorno_almoco']) ? date('H:i', strtotime($bats['retorno_almoco'])) : "—";
                    $sai_st = isset($bats['saida']) ? date('H:i', strtotime($bats['saida'])) : "—";

                    $has_e = isset($bats['entrada']);
                    $has_sa = isset($bats['saida_almoco']);
                    $has_ra = isset($bats['retorno_almoco']);
                    $has_s = isset($bats['saida']);

                    if ($has_e && $has_sa && $has_ra && $has_s) {
                        $t1 = strtotime($bats['entrada']);
                        $t2 = strtotime($bats['saida_almoco']);
                        $t3 = strtotime($bats['retorno_almoco']);
                        $t4 = strtotime($bats['saida']);
                        $trab_dia = (($t2 - $t1) + ($t4 - $t3)) / 60;
                        $status_label = "✅ OK";
                        $badge_bg = "rgba(0, 200, 83, 0.15)";
                        $badge_color = "#00c853";
                    } elseif ($has_e && $has_s && !$has_sa && !$has_ra) {
                        $t1 = strtotime($bats['entrada']);
                        $t4 = strtotime($bats['saida']);
                        $trab_dia = ($t4 - $t1) / 60;
                        if ($trab_dia > 360) $trab_dia -= 60;
                        $status_label = "✅ OK";
                        $badge_bg = "rgba(0, 200, 83, 0.15)";
                        $badge_color = "#00c853";
                    } else {
                        if ($is_today) {
                            $now = time();
                            if ($has_e) {
                                if ($has_sa && $has_ra) {
                                    $t1 = strtotime($bats['entrada']);
                                    $t2 = strtotime($bats['saida_almoco']);
                                    $t3 = strtotime($bats['retorno_almoco']);
                                    $trab_dia = (($t2 - $t1) + ($now - $t3)) / 60;
                                } elseif ($has_sa) {
                                    $t1 = strtotime($bats['entrada']);
                                    $t2 = strtotime($bats['saida_almoco']);
                                    $trab_dia = ($t2 - $t1) / 60;
                                } else {
                                    $t1 = strtotime($bats['entrada']);
                                    $trab_dia = ($now - $t1) / 60;
                                }
                            }
                            $status_label = "⏳ Em Andamento";
                            $badge_bg = "rgba(59, 130, 246, 0.15)";
                            $badge_color = "#60a5fa";
                        } else {
                            $inconsistencias_usr_cnt++;
                            $status_label = "⚠️ Inconsistência";
                            $badge_bg = "rgba(245, 158, 11, 0.15)";
                            $badge_color = "#fbbf24";
                        }
                    }
                } else {
                    if (!$is_today && $dia_semana_n <= 5) {
                        $inconsistencias_usr_cnt++;
                        $status_label = "❌ Falta Sem Ponto";
                        $badge_bg = "rgba(239, 68, 68, 0.15)";
                        $badge_color = "#fca5a5";
                    } elseif ($is_today && $dia_semana_n <= 5) {
                        $status_label = "⏳ Aguardando Entrada";
                        $badge_bg = "rgba(59, 130, 246, 0.15)";
                        $badge_color = "#60a5fa";
                    } elseif ($dia_semana_n >= 6) {
                        $status_label = "☕ Folga";
                    }
                }

                $tot_min_trabalhados_usr += $trab_dia;

                if ($dia_semana_n <= 5) {
                    if ($is_today) {
                        if (isset($bats['saida'])) {
                            $tot_min_esperados_usr += $esp_dia;
                        } else {
                            $tot_min_esperados_usr += min($trab_dia, $esp_dia);
                        }
                    } else {
                        $tot_min_esperados_usr += $esp_dia;
                    }
                }

                $saldo_dia_m = $trab_dia - $esp_dia;

                $tabela_diaria_usr[] = [
                    'data' => date('d/m', strtotime($dia_st)),
                    'dia_nome' => $dia_nome_st,
                    'entrada' => $ent_st,
                    'saida_almoco' => $sa_st,
                    'retorno_almoco' => $ra_st,
                    'saida' => $sai_st,
                    'trabalhado_str' => sprintf("%02dh %02dmin", floor($trab_dia / 60), $trab_dia % 60),
                    'saldo_dia_min' => $saldo_dia_m,
                    'saldo_dia_str' => ($saldo_dia_m >= 0 ? "+" : "-") . sprintf("%02dh %02dmin", floor(abs($saldo_dia_m) / 60), abs($saldo_dia_m) % 60),
                    'status_label' => $status_label,
                    'badge_bg' => $badge_bg,
                    'badge_color' => $badge_color,
                    'is_today' => $is_today,
                    'is_weekend' => ($dia_semana_n >= 6)
                ];
            }

            $saldo_usr_min = $tot_min_trabalhados_usr - $tot_min_esperados_usr;
            $abs_saldo_usr = abs(round($saldo_usr_min));
            $h_usr = floor($abs_saldo_usr / 60);
            $m_usr = $abs_saldo_usr % 60;
            $m_usr_str = $m_usr > 0 ? "{$h_usr}h {$m_usr}min" : "{$h_usr}h";

            if ($saldo_usr_min < -1) {
                $str_saldo_usr = "🔴 Devendo {$m_usr_str}";
                $color_saldo_usr = "#ef4444";
                $bg_saldo_usr = "rgba(239, 68, 68, 0.15)";
                $border_saldo_usr = "rgba(239, 68, 68, 0.3)";
            } elseif ($saldo_usr_min > 1) {
                $str_saldo_usr = "🟢 Sobrando +{$m_usr_str}";
                $color_saldo_usr = "#00c853";
                $bg_saldo_usr = "rgba(0, 200, 83, 0.15)";
                $border_saldo_usr = "rgba(0, 200, 83, 0.3)";
            } else {
                $str_saldo_usr = "⚪ Saldo em Dia (00:00h)";
                $color_saldo_usr = "#9ca3af";
                $bg_saldo_usr = "rgba(156, 163, 175, 0.15)";
                $border_saldo_usr = "rgba(156, 163, 175, 0.3)";
            }
            ?>

            <div class="grid-ponto-full">

                <!-- CARD DE DESTAQUE DO SALDO NO BANCO DE HORAS -->
                <div class="card-saldo-banco" style="background: <?= $bg_saldo_usr ?>; border: 1px solid <?= $border_saldo_usr ?>; border-radius: 16px; padding: 20px 24px; margin: 20px 0 25px 0; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 16px; width: 100%; box-sizing: border-box;">
                    <div style="display: flex; align-items: center; gap: 16px;">
                        <div style="font-size: 36px;">📊</div>
                        <div>
                            <div style="font-size: 12px; color: rgba(255, 255, 255, 0.7); text-transform: uppercase; font-weight: 700; letter-spacing: 0.5px;">Seu Saldo Atual no Banco de Horas (<?= date('m/Y') ?>)</div>
                            <div style="font-size: 26px; font-weight: 800; color: <?= $color_saldo_usr ?>; margin-top: 4px;">
                                <?= $str_saldo_usr ?>
                            </div>
                        </div>
                    </div>
                    <div style="text-align: right; font-size: 13px; color: rgba(255, 255, 255, 0.85); line-height: 1.6;">
                        <div>Trabalhado até hoje: <strong><?= sprintf("%02dh %02dmin", floor($tot_min_trabalhados_usr / 60), $tot_min_trabalhados_usr % 60) ?></strong></div>
                        <div>Esperado até hoje: <strong><?= sprintf("%02dh %02dmin", floor($tot_min_esperados_usr / 60), $tot_min_esperados_usr % 60) ?></strong></div>
                        <?php if ($inconsistencias_usr_cnt > 0): ?>
                            <div style="color: #fbbf24; font-weight: 700; margin-top: 4px;">⚠️ <?= $inconsistencias_usr_cnt ?> dia(s) com inconsistência / ponto pendente</div>
                        <?php else: ?>
                            <div style="color: #00c853; font-weight: 600; margin-top: 4px;">✅ Nenhuma inconsistência encontrada</div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- TABELA DE RESUMO DIÁRIO DA JORNADA -->
                <div class="card-ponto" style="margin-bottom: 24px;">
                    <div class="card-header-flex">
                        <h3>📋 Marcações e Jornada do Mês Vigente (<?= date('m/Y') ?>)</h3>
                    </div>

                    <div id="tabela-jornada-container" style="overflow-x: auto;">
                        <table class="table-ponto" style="width: 100%; border-collapse: collapse;">
                            <thead>
                                <tr>
                                    <th>Data</th>
                                    <th>Dia</th>
                                    <th>Entrada</th>
                                    <th>S. Almoço</th>
                                    <th>R. Almoço</th>
                                    <th>Saída</th>
                                    <th>Trabalhado</th>
                                    <th>Saldo Dia</th>
                                    <th>Status / Observação</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach (array_reverse($tabela_diaria_usr) as $row_d): ?>
                                    <tr style="<?= $row_d['is_today'] ? 'background: rgba(59, 130, 246, 0.08);' : '' ?>">
                                        <td><strong><?= $row_d['data'] ?></strong></td>
                                        <td><?= $row_d['dia_nome'] ?></td>
                                        <td><?= $row_d['entrada'] ?></td>
                                        <td><?= $row_d['saida_almoco'] ?></td>
                                        <td><?= $row_d['retorno_almoco'] ?></td>
                                        <td><?= $row_d['saida'] ?></td>
                                        <td><strong><?= $row_d['trabalhado_str'] ?></strong></td>
                                        <td style="font-weight: 700; color: <?= $row_d['saldo_dia_min'] >= 0 ? 'var(--verde)' : 'var(--vermelho)' ?>;">
                                            <?= $row_d['saldo_dia_str'] ?>
                                        </td>
                                        <td>
                                            <span style="background: <?= $row_d['badge_bg'] ?>; color: <?= $row_d['badge_color'] ?>; padding: 4px 10px; border-radius: 8px; font-weight: 700; font-size: 11px; display: inline-block;">
                                                <?= $row_d['status_label'] ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- COMPROVANTES INDIVIDUAIS REP-P -->
                <div class="card-ponto">
                    <div class="card-header-flex">
                        <h3>🧾 Comprovantes Individuais por Batida (Portaria 671)</h3>
                    </div>

                    <div id="tabela-historico-container">
                    <?php if (count($historico) > 0): ?>
                        <table class="table-ponto">
                            <thead>
                                <tr>
                                    <th>NSR</th>
                                    <th>Data/Hora</th>
                                    <th>Tipo</th>
                                    <th>Hash SHA-256</th>
                                    <th>Ação</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($historico as $h): 
                                    $dados_comp = [
                                        'nsr' => str_pad($h['nsr'], 9, "0", STR_PAD_LEFT),
                                        'trabalhador' => $nome,
                                        'cpf' => $cpf_trabalhador,
                                        'data_hora' => date('d/m/Y H:i:s', strtotime($h['data_hora'])),
                                        'tipo' => strtoupper(str_replace('_', ' ', $h['tipo_registro'])),
                                        'hash' => $h['hash_comprovante']
                                    ];
                                ?>
                                    <tr>
                                        <td><strong>#<?= str_pad($h['nsr'], 6, "0", STR_PAD_LEFT) ?></strong></td>
                                        <td><?= date('d/m/Y H:i:s', strtotime($h['data_hora'])) ?></td>
                                        <td>
                                            <span style="color: var(--verde); font-weight: 600;">
                                                <?= str_replace('_', ' ', ucfirst($h['tipo_registro'])) ?>
                                            </span>
                                        </td>
                                        <td title="<?= htmlspecialchars($h['hash_comprovante']) ?>">
                                            <code><?= substr($h['hash_comprovante'], 0, 8) ?>...</code>
                                        </td>
                                        <td>
                                            <button class="btn-action-view" onclick='abrirModalComprovante(<?= json_encode($dados_comp, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>🧾 Comprovante</button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <p style="color: var(--cinza-texto); font-size: 13px; padding: 20px 0;">Nenhuma marcação de ponto registrada este mês.</p>
                    <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($view === 'ajustes'): ?>
            <!-- VISÃO DE AJUSTES DE PONTO -->
            <div class="grid-ponto-full" style="max-width: 800px; width: 100%; text-align: center; display: flex; flex-direction: column; align-items: center;">
                
                <?php if (($_SESSION['usuario_tipo'] ?? '') === 'master'): ?>
                    <!-- FORMULÁRIO DE AJUSTE EM LOTE POR PERÍODO -->
                    <div class="card-ponto" style="text-align: left; width: 100%; margin-bottom: 24px;">
                        <h3>📅 Ajuste de Ponto em Lote (Diretoria)</h3>
                        <p style="color: var(--cinza-texto); font-size: 13px; margin: 8px 0 20px;">Use esta ferramenta para preencher de forma automatizada batidas faltantes por período e colaborador.</p>
                        
                        <form method="POST" class="form-ajuste-box" style="max-width: 100%;">
                            <input type="hidden" name="acao" value="ajustar_ponto_periodo">
                            
                            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; width: 100%;">
                                <div class="form-control-group">
                                    <label>Colaborador</label>
                                    <select name="usuario_id" required>
                                        <option value="todos">Todos os Colaboradores</option>
                                        <?php 
                                        $tabela_usuarios_select = $is_saas ? 'saas_usuarios' : 'usuarios';
                                        if ($is_saas) {
                                            $stmt_us_sel = $pdo->prepare("SELECT id, nome FROM $tabela_usuarios_select WHERE empresa_id = :empresa_id ORDER BY nome ASC");
                                            $stmt_us_sel->execute([':empresa_id' => $_SESSION['usuario_empresa_id']]);
                                        } else {
                                            $stmt_us_sel = $pdo->query("SELECT id, nome FROM $tabela_usuarios_select WHERE email NOT IN ('tecnico1@vixmed.com.br', 'comercial@vixmed.com.br') ORDER BY nome ASC");
                                            $stmt_us_sel->execute();
                                        }
                                        $usuarios_select = $stmt_us_sel->fetchAll(PDO::FETCH_ASSOC);
                                        foreach ($usuarios_select as $us_item): 
                                        ?>
                                            <option value="<?= $us_item['id'] ?>"><?= htmlspecialchars($us_item['nome']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-control-group">
                                    <label>Data de Início</label>
                                    <input type="date" name="data_inicio" required max="<?= date('Y-m-d') ?>">
                                </div>
                                <div class="form-control-group">
                                    <label>Data de Fim</label>
                                    <input type="date" name="data_fim" required max="<?= date('Y-m-d') ?>">
                                </div>
                            </div>
                            
                            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(110px, 1fr)); gap: 16px; width: 100%; margin-top: 10px;">
                                <div class="form-control-group">
                                    <label>Entrada</label>
                                    <input type="time" name="hora_entrada" value="08:00" required>
                                </div>
                                <div class="form-control-group">
                                    <label>Saída Almoço</label>
                                    <input type="time" name="hora_saida_almoco" value="12:00" required>
                                </div>
                                <div class="form-control-group">
                                    <label>Retorno Almoço</label>
                                    <input type="time" name="hora_retorno_almoco" value="13:00" required>
                                </div>
                                <div class="form-control-group">
                                    <label>Saída</label>
                                    <input type="time" name="hora_saida" value="17:00" required>
                                </div>
                            </div>

                            <div style="margin-top: 10px; display: flex; align-items: center; gap: 8px;">
                                <input type="checkbox" name="ajustar_fim_semana" id="ajustar_fim_semana" value="1" style="width: 16px; height: 16px; cursor: pointer;">
                                <label for="ajustar_fim_semana" style="font-size: 13px; color: var(--cinza-texto); cursor: pointer; user-select: none;">Ajustar também fins de semana (Sábado e Domingo)</label>
                            </div>
                            
                            <button type="submit" class="btn-green" style="margin-top: 10px; align-self: flex-start; background: var(--verde);">⚡ Executar Ajuste em Lote</button>
                        </form>
                    </div>

                    <!-- PAINEL DO ADMINISTRADOR (MASTER) -->
                    <div class="card-ponto" style="text-align: left; width: 100%;">
                        <div class="card-header-flex">
                            <h3>🔧 Painel de Solicitações de Ajuste</h3>
                        </div>

                        <!-- Filtros -->
                        <div class="admin-ajustes-tabs">
                            <button class="admin-tab-btn active" onclick="filtrarSolicitacoes('todos', this)">Todas</button>
                            <button class="admin-tab-btn" onclick="filtrarSolicitacoes('pendente', this)">Pendentes</button>
                            <button class="admin-tab-btn" onclick="filtrarSolicitacoes('aprovado', this)">Aprovadas</button>
                            <button class="admin-tab-btn" onclick="filtrarSolicitacoes('rejeitado', this)">Rejeitadas</button>
                        </div>

                        <div id="solicitacoes-container">
                            <?php if (count($solicitacoes_ajuste) > 0): ?>
                                <?php foreach ($solicitacoes_ajuste as $s): ?>
                                    <div class="ajuste-card-solicitacao" data-status="<?= $s['status'] ?>">
                                        <div class="ajuste-card-header">
                                            <span class="ajuste-card-title">👤 <?= htmlspecialchars($s['funcionario_nome']) ?> (<?= htmlspecialchars($s['funcionario_email']) ?>)</span>
                                            <span class="badge-status <?= $s['status'] ?>"><?= $s['status'] ?></span>
                                        </div>
                                        <div class="ajuste-card-body">
                                            <div class="ajuste-card-meta">
                                                <div class="ajuste-meta-item">Data Solicitada: <strong><?= date('d/m/Y', strtotime($s['data_solicitada'])) ?></strong></div>
                                                <div class="ajuste-meta-item">Hora Solicitada: <strong><?= date('H:i', strtotime($s['horario_solicitado'])) ?></strong></div>
                                                <div class="ajuste-meta-item">Tipo: <strong><?= str_replace('_', ' ', ucfirst($s['tipo_registro'])) ?></strong></div>
                                                <div class="ajuste-meta-item">Solicitado em: <strong><?= date('d/m/Y H:i', strtotime($s['criado_em'])) ?></strong></div>
                                            </div>
                                            <div class="ajuste-card-reason">
                                                <strong>Motivo/Justificativa:</strong> <?= htmlspecialchars($s['motivo']) ?>
                                            </div>
                                            <?php if (!empty($s['observacao_admin'])): ?>
                                                <div class="ajuste-card-admin-obs" style="border-left: 3px solid <?= $s['status'] === 'aprovado' ? 'var(--verde)' : 'var(--vermelho)' ?>; background: rgba(255,255,255,0.03); color: var(--branco);">
                                                    <strong>Observação da Diretoria:</strong> <?= htmlspecialchars($s['observacao_admin']) ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>

                                        <?php if ($s['status'] === 'pendente'): ?>
                                            <div class="ajuste-actions">
                                                <form method="POST" style="margin: 0; display: inline;">
                                                    <input type="hidden" name="acao" value="atualizar_status_ajuste">
                                                    <input type="hidden" name="solicitacao_id" value="<?= $s['id'] ?>">
                                                    <input type="hidden" name="status" value="aprovado">
                                                    <button type="submit" class="btn-green" style="padding: 8px 16px; font-size: 12px;">✔️ Aprovar</button>
                                                </form>
                                                <button type="button" class="btn-secondary-modal" style="padding: 8px 16px; font-size: 12px; background: rgba(239, 68, 68, 0.15); color: var(--vermelho); border-color: rgba(239, 68, 68, 0.25);" onclick="abrirModalRejeitar(<?= $s['id'] ?>)">❌ Rejeitar</button>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <p style="color: var(--cinza-texto); padding: 10px 0;">Nenhuma solicitação de ajuste encontrada.</p>
                            <?php endif; ?>
                        </div>
                    </div>

                <?php else: ?>
                    <!-- VISÃO DO COLABORADOR (SOLICITAR E LISTAR) -->
                    <div class="card-ponto" style="text-align: left; width: 100%; margin-bottom: 24px;">
                        <h3>📝 Nova Solicitação de Ajuste de Ponto</h3>
                        <p style="color: var(--cinza-texto); font-size: 13px; margin: 8px 0 20px;">Preencha os dados abaixo justificando o ajuste necessário para aprovação da diretoria.</p>

                        <form method="POST" class="form-ajuste-box">
                            <input type="hidden" name="acao" value="solicitar_ajuste">

                            <div class="form-control-group">
                                <label>Data do Ajuste</label>
                                <input type="date" name="data_solicitada" required max="<?= date('Y-m-d') ?>">
                            </div>

                            <div class="form-control-group">
                                <label>Horário da Batida</label>
                                <input type="time" name="horario_solicitado" required>
                            </div>

                            <div class="form-control-group">
                                <label>Tipo de Batida</label>
                                <select name="tipo_registro" required>
                                    <option value="entrada">📥 Entrada</option>
                                    <option value="saida_almoco">☕ Saída Almoço</option>
                                    <option value="retorno_almoco">🥗 Retorno Almoço</option>
                                    <option value="saida">📤 Saída</option>
                                </select>
                            </div>

                            <div class="form-control-group">
                                <label>Motivo / Justificativa do Atraso ou Esquecimento</label>
                                <textarea name="motivo" rows="4" placeholder="Descreva o motivo da solicitação de ajuste..." required></textarea>
                            </div>

                            <button type="submit" class="btn-green" style="align-self: flex-start;">Enviar Solicitação</button>
                        </form>
                    </div>

                    <div class="card-ponto" style="text-align: left; width: 100%;">
                        <h3>📋 Minhas Solicitações de Ajuste</h3>
                        <p style="color: var(--cinza-texto); font-size: 13px; margin: 8px 0 20px;">Abaixo estão as suas solicitações de ajuste e o status de aprovação de cada uma.</p>

                        <div id="solicitacoes-colaborador-container">
                            <?php if (count($solicitacoes_ajuste) > 0): ?>
                                <?php foreach ($solicitacoes_ajuste as $s): ?>
                                    <div class="ajuste-card-solicitacao">
                                        <div class="ajuste-card-header">
                                            <span class="ajuste-card-title">📅 Solicitação para dia: <?= date('d/m/Y', strtotime($s['data_solicitada'])) ?> às <?= date('H:i', strtotime($s['horario_solicitado'])) ?></span>
                                            <span class="badge-status <?= $s['status'] ?>"><?= $s['status'] ?></span>
                                        </div>
                                        <div class="ajuste-card-body">
                                            <div class="ajuste-card-meta">
                                                <div class="ajuste-meta-item">Tipo: <strong><?= str_replace('_', ' ', ucfirst($s['tipo_registro'])) ?></strong></div>
                                                <div class="ajuste-meta-item">Solicitado em: <strong><?= date('d/m/Y H:i', strtotime($s['criado_em'])) ?></strong></div>
                                            </div>
                                            <div class="ajuste-card-reason">
                                                <strong>Seu Motivo:</strong> <?= htmlspecialchars($s['motivo']) ?>
                                            </div>
                                            <?php if (!empty($s['observacao_admin'])): ?>
                                                <div class="ajuste-card-admin-obs" style="border-left: 3px solid <?= $s['status'] === 'aprovado' ? 'var(--verde)' : 'var(--vermelho)' ?>; background: rgba(255,255,255,0.03); color: var(--branco);">
                                                    <strong>Observação da Diretoria:</strong> <?= htmlspecialchars($s['observacao_admin']) ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <p style="color: var(--cinza-texto); padding: 10px 0;">Você ainda não fez nenhuma solicitação de ajuste.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>

            </div>
        <?php endif; ?>

        <?php if ($view === 'monitor' && ($_SESSION['usuario_tipo'] ?? '') === 'master'): ?>
            <!-- VISÃO DE MONITORAMENTO DE PRESENÇA (APENAS MASTER) -->
            <div class="grid-ponto-full" style="max-width: 1000px; width: 100%;">
                <div class="card-ponto" style="text-align: left;">
                    <div class="card-header-flex" style="align-items: center; justify-content: space-between;">
                        <h3>📊 Monitoramento de Presença - Dia <?= date('d/m/Y', strtotime($data_filtro)) ?></h3>
                        
                        <div style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
                            <?php if ($data_filtro === date('Y-m-d')): ?>
                                <form method="POST" style="margin: 0;">
                                    <input type="hidden" name="acao" value="ajustar_ponto_hoje">
                                    <input type="hidden" name="usuario_id" value="todos">
                                    <button type="submit" class="btn-folha-unificada" style="background: var(--amarelo); color: var(--azul-escuro); border-color: var(--amarelo); font-size: 11px; padding: 6px 12px;">⚡ Ajustar Hoje (Todos Ausentes)</button>
                                </form>
                            <?php endif; ?>
                            
                            <form method="GET" action="folhadeponto.php" style="margin: 0; display: flex; align-items: center; gap: 8px;">
                                <input type="hidden" name="view" value="monitor">
                                <label style="font-size: 13px; color: var(--cinza-texto); font-weight: 600;">Data:</label>
                                <input type="date" name="data_filtro" value="<?= htmlspecialchars($data_filtro) ?>" onchange="this.form.submit()" style="background: rgba(255,255,255,0.05); border: 1px solid var(--cinza-borda); border-radius: 8px; padding: 6px 12px; color: var(--branco); font-family: inherit; font-size: 13px; outline: none;">
                            </form>
                        </div>
                    </div>
                    <p style="color: var(--cinza-texto); font-size: 13px; margin: 8px 0 20px;">Lista de todos os colaboradores cadastrados e o status das suas batidas de ponto na data selecionada.</p>

                    <div id="tabela-historico-container">
                        <table class="table-ponto">
                            <thead>
                                <tr>
                                    <th>Funcionário</th>
                                    <th>📥 Entrada</th>
                                    <th>☕ Saída Almoço</th>
                                    <th>🥗 Retorno Almoço</th>
                                    <th>📤 Saída</th>
                                    <th>Situação</th>
                                    <th>Ação</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($todos_usuarios as $u): 
                                    $uid = $u['id'];
                                    $p_entrada = $mapeamento_pontos[$uid]['entrada'] ?? null;
                                    $p_saida_almoco = $mapeamento_pontos[$uid]['saida_almoco'] ?? null;
                                    $p_retorno_almoco = $mapeamento_pontos[$uid]['retorno_almoco'] ?? null;
                                    $p_saida = $mapeamento_pontos[$uid]['saida'] ?? null;

                                    // Calcular Situação
                                    if (!$p_entrada && !$p_saida_almoco && !$p_retorno_almoco && !$p_saida) {
                                        $situacao = '<span class="badge-status rejeitado" style="background: rgba(239, 68, 68, 0.15); color: var(--vermelho);">🔴 Ausente</span>';
                                    } elseif ($p_saida) {
                                        $situacao = '<span class="badge-status" style="background: rgba(255, 255, 255, 0.1); color: var(--branco);">⚪ Finalizado</span>';
                                    } elseif ($p_saida_almoco && !$p_retorno_almoco) {
                                        $situacao = '<span class="badge-status pendente" style="background: rgba(245, 158, 11, 0.15); color: var(--amarelo);">🟡 Em Almoço</span>';
                                    } elseif ($p_retorno_almoco && !$p_saida) {
                                        $situacao = '<span class="badge-status aprovado" style="background: rgba(0, 200, 83, 0.15); color: var(--verde);">🟢 Trabalhando</span>';
                                    } else {
                                        $situacao = '<span class="badge-status aprovado" style="background: rgba(0, 200, 83, 0.15); color: var(--verde);">🟢 Trabalhando</span>';
                                    }
                                ?>
                                    <tr>
                                        <td>
                                            <strong><?= htmlspecialchars($u['nome']) ?></strong><br>
                                            <span style="font-size: 11px; color: var(--cinza-texto);"><?= htmlspecialchars($u['email']) ?></span>
                                        </td>
                                        <td><?= $p_entrada ? "✔️ <strong>$p_entrada</strong>" : "❌" ?></td>
                                        <td><?= $p_saida_almoco ? "✔️ <strong>$p_saida_almoco</strong>" : "❌" ?></td>
                                        <td><?= $p_retorno_almoco ? "✔️ <strong>$p_retorno_almoco</strong>" : "❌" ?></td>
                                        <td><?= $p_saida ? "✔️ <strong>$p_saida</strong>" : "❌" ?></td>
                                        <td><?= $situacao ?></td>
                                        <td>
                                            <?php if ($data_filtro === date('Y-m-d') && (!$p_entrada || !$p_saida_almoco || !$p_retorno_almoco || !$p_saida)): ?>
                                                <form method="POST" style="margin: 0;">
                                                    <input type="hidden" name="acao" value="ajustar_ponto_hoje">
                                                    <input type="hidden" name="usuario_id" value="<?= $uid ?>">
                                                    <button type="submit" class="btn-action-view" style="padding: 4px 8px; font-size: 11px;">⚡ Ajustar Hoje</button>
                                                </form>
                                            <?php else: ?>
                                                <span style="color: var(--cinza-texto); font-size: 11px;">—</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($view === 'banco'): ?>
            <!-- VISÃO DO BANCO DE HORAS (MASTER E FUNCIONÁRIO) -->
            <div class="grid-ponto-full" style="max-width: 1000px; width: 100%;">
                <div class="card-ponto" style="text-align: left;">
                    <div class="card-header-flex" style="align-items: center; justify-content: space-between;">
                        <h3>📊 Banco de Horas Acumulado</h3>
                        <form method="GET" action="folhadeponto.php" style="margin: 0; display: flex; align-items: center; gap: 8px;">
                            <input type="hidden" name="view" value="banco">
                            <label style="font-size: 13px; color: var(--cinza-texto); font-weight: 600;">Mês/Ano:</label>
                            <input type="month" name="mes_filtro" value="<?= htmlspecialchars($mes_filtro) ?>" onchange="this.form.submit()" style="background: rgba(255,255,255,0.05); border: 1px solid var(--cinza-borda); border-radius: 8px; padding: 6px 12px; color: var(--branco); font-family: inherit; font-size: 13px; outline: none;">
                        </form>
                    </div>
                    <p style="color: var(--cinza-texto); font-size: 13px; margin: 8px 0 20px;">
                        Cálculo baseado nos dias úteis do período selecionado com carga horária padrão de 8h diárias. Faltas sem justificativa/ajuste geram saldo negativo.
                    </p>

                    <div id="tabela-historico-container">
                        <table class="table-ponto">
                            <thead>
                                <tr>
                                    <th>Colaborador</th>
                                    <th>Dias Úteis</th>
                                    <th>Faltas</th>
                                    <th>Incompletos</th>
                                    <th>Horas Esperadas</th>
                                    <th>Total Trabalhado</th>
                                    <th>Saldo Atual</th>
                                    <?php if (($_SESSION['usuario_tipo'] ?? '') === 'master'): ?>
                                    <th>Relatório</th>
                                    <?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($banco_dados as $b): 
                                    $is_negativo = ($b['saldo_raw'] < 0);
                                    $is_zero = ($b['saldo_raw'] == 0);
                                    $color_saldo = $is_zero ? 'var(--branco)' : ($is_negativo ? 'var(--vermelho)' : 'var(--verde)');
                                    $bg_saldo = $is_zero ? 'rgba(255,255,255,0.05)' : ($is_negativo ? 'rgba(239, 68, 68, 0.12)' : 'rgba(0, 200, 83, 0.12)');
                                ?>
                                    <tr>
                                        <td>
                                            <strong><?= htmlspecialchars($b['nome']) ?></strong><br>
                                            <span style="font-size: 11px; color: var(--cinza-texto);"><?= htmlspecialchars($b['email']) ?></span>
                                        </td>
                                        <td><strong><?= $b['dias_uteis'] ?> dias</strong></td>
                                        <td>
                                            <?php if ($b['dias_ausentes'] > 0): ?>
                                                <span class="badge-status rejeitado" style="background: rgba(239, 68, 68, 0.12); color: var(--vermelho); font-size: 11px; padding: 2px 6px; border-radius: 4px;"><?= $b['dias_ausentes'] ?> faltas</span>
                                            <?php else: ?>
                                                <span style="color: var(--cinza-texto); font-size: 11px;">Nenhuma</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($b['dias_incompletos'] > 0): ?>
                                                <span class="badge-status pendente" style="background: rgba(245, 158, 11, 0.12); color: var(--amarelo); font-size: 11px; padding: 2px 6px; border-radius: 4px;"><?= $b['dias_incompletos'] ?> incompleto(s) ⚠️</span>
                                            <?php else: ?>
                                                <span style="color: var(--cinza-texto); font-size: 11px;">Nenhum</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= $b['horas_esperadas'] ?></td>
                                        <td><?= $b['horas_trabalhadas'] ?></td>
                                        <td>
                                            <span class="badge-status" style="background: <?= $bg_saldo ?>; color: <?= $color_saldo ?>; font-weight: 700; font-size: 12px; padding: 4px 8px; border-radius: 6px;">
                                                <?= $b['saldo'] ?>
                                            </span>
                                        </td>
                                        <?php if (($_SESSION['usuario_tipo'] ?? '') === 'master'): ?>
                                        <td>
                                            <a href="folhadeponto.php?view=espelho&usuario_id=<?= $b['id'] ?>&mes_filtro=<?= $mes_filtro ?>" class="btn-action-view" style="padding: 6px 12px; font-size: 11px; background: var(--azul-medio); border-color: var(--azul-medio);">📄 Espelho</a>
                                            <a href="folhadeponto.php?view=banco&usuario_id=<?= $b['id'] ?>&mes_filtro=<?= $mes_filtro ?>#diagnostico-detalhado" class="btn-action-view" style="padding: 6px 12px; font-size: 11px; background: #2563eb; border-color: #2563eb; color: #fff; margin-left: 6px;">📊 Diagnóstico</a>
                                        </td>
                                        <?php endif; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <?php 
                $target_diag_id = isset($_GET['usuario_id']) ? intval($_GET['usuario_id']) : 0;
                if ($target_diag_id > 0 && ($_SESSION['usuario_tipo'] ?? '') === 'master'):
                    // Buscar dados do usuário selecionado
                    $stmt_du = $pdo->prepare("SELECT id, nome, email FROM $tabela_usuarios WHERE id = :id");
                    $stmt_du->execute([':id' => $target_diag_id]);
                    $usr_diag = $stmt_du->fetch(PDO::FETCH_ASSOC);

                    if ($usr_diag):
                        $email_diag = $usr_diag['email'];
                        $ano_diag = intval(substr($mes_filtro, 0, 4));
                        $mes_diag = intval(substr($mes_filtro, 5, 2));
                        $ultimo_dia_diag = cal_days_in_month(CAL_GREGORIAN, $mes_diag, $ano_diag);

                        // Buscar registros do mês
                        $stmt_dr = $pdo->prepare("
                            SELECT DATE(data_hora) as data, tipo_registro, data_hora 
                            FROM $tabela_registros 
                            WHERE usuario_id = :uid AND YEAR(data_hora) = :ano AND MONTH(data_hora) = :mes
                            ORDER BY data_hora ASC
                        ");
                        $stmt_dr->execute([':uid' => $target_diag_id, ':ano' => $ano_diag, ':mes' => $mes_diag]);
                        $regs_diag = $stmt_dr->fetchAll(PDO::FETCH_ASSOC);

                        $dias_dr = [];
                        foreach ($regs_diag as $r) {
                            $dias_dr[$r['data']][$r['tipo_registro']] = $r['data_hora'];
                        }
                ?>
                <div class="card-ponto" id="diagnostico-detalhado" style="text-align: left; margin-top: 24px;">
                    <div class="card-header-flex" style="align-items: center; justify-content: space-between;">
                        <h3>📊 Diagnóstico Diário & Análise de Ponto: <?= htmlspecialchars($usr_diag['nome']) ?></h3>
                        <span style="font-size: 13px; color: var(--cinza-texto); font-weight: 600;"><?= date('m/Y', strtotime($mes_filtro . '-01')) ?></span>
                    </div>

                    <div style="overflow-x: auto; margin-top: 15px;">
                        <table class="table-ponto" style="width: 100%; border-collapse: collapse;">
                            <thead>
                                <tr>
                                    <th>Data</th>
                                    <th>Dia</th>
                                    <th>Entrada</th>
                                    <th>S. Almoço</th>
                                    <th>R. Almoço</th>
                                    <th>Saída</th>
                                    <th>Trabalhado</th>
                                    <th>Esperado</th>
                                    <th>Saldo Dia</th>
                                    <th>Diagnóstico Inteligente</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                for ($d = 1; $d <= $ultimo_dia_diag; $d++):
                                    $dia_st = sprintf("%s-%02d", $mes_filtro, $d);
                                    $dia_sem_n = intval(date('N', strtotime($dia_st)));
                                    $dias_trad = [1 => 'Segunda', 2 => 'Terça', 3 => 'Quarta', 4 => 'Quinta', 5 => 'Sexta', 6 => 'Sábado', 7 => 'Domingo'];
                                    $dia_nome_st = $dias_trad[$dia_sem_n];

                                    $esp_min = getMinutosEsperadosDia($email_diag, $dia_sem_n);
                                    $trab_min = 0;
                                    $ent_st = "—";
                                    $sa_st = "—";
                                    $ra_st = "—";
                                    $sai_st = "—";
                                    $diagnostico = [];

                                    if (isset($dias_dr[$dia_st])) {
                                        $bats = $dias_dr[$dia_st];
                                        $ent_st = isset($bats['entrada']) ? date('H:i', strtotime($bats['entrada'])) : "—";
                                        $sa_st = isset($bats['saida_almoco']) ? date('H:i', strtotime($bats['saida_almoco'])) : "—";
                                        $ra_st = isset($bats['retorno_almoco']) ? date('H:i', strtotime($bats['retorno_almoco'])) : "—";
                                        $sai_st = isset($bats['saida']) ? date('H:i', strtotime($bats['saida'])) : "—";

                                        $has_e = isset($bats['entrada']);
                                        $has_sa = isset($bats['saida_almoco']);
                                        $has_ra = isset($bats['retorno_almoco']);
                                        $has_s = isset($bats['saida']);

                                        if ($has_e && $has_sa && $has_ra && $has_s) {
                                            $t1 = strtotime($bats['entrada']);
                                            $t2 = strtotime($bats['saida_almoco']);
                                            $t3 = strtotime($bats['retorno_almoco']);
                                            $t4 = strtotime($bats['saida']);
                                            $trab_min = (($t2 - $t1) + ($t4 - $t3)) / 60;
                                        } elseif ($has_e && $has_s && !$has_sa && !$has_ra) {
                                            $t1 = strtotime($bats['entrada']);
                                            $t4 = strtotime($bats['saida']);
                                            $trab_min = ($t4 - $t1) / 60;
                                            if ($trab_min > 360) $trab_min -= 60;
                                        }

                                        // Diagnóstico da entrada
                                        if ($has_e && $dia_sem_n <= 5) {
                                            $h_ent = date('H:i', strtotime($bats['entrada']));
                                            $h_ent_esperada = (strtolower($email_diag) === 'dayana.mendes@vixmed.com.br') ? '08:00' : '07:00';
                                            if (strtotime($h_ent) > strtotime($h_ent_esperada) + 300) {
                                                $diagnostico[] = "<span style='color: #ef4444; font-weight: 600;'>🔴 Entrada Atrasada ($h_ent)</span>";
                                            }
                                        }
                                        // Diagnóstico da saída
                                        if ($has_s && $dia_sem_n <= 5) {
                                            $h_sai = date('H:i', strtotime($bats['saida']));
                                            $h_sai_esp = (strtolower($email_diag) === 'ti@vixmed.com.br') ? ($dia_sem_n == 5 ? '16:30' : '17:30') : ($dia_sem_n == 5 ? '16:00' : '17:00');
                                            if (strtotime($h_sai) < strtotime($h_sai_esp) - 300) {
                                                $diagnostico[] = "<span style='color: #ef4444; font-weight: 600;'>🔴 Saída Antecipada ($h_sai)</span>";
                                            }
                                        }
                                        if (!$has_s && $dia_sem_n <= 5 && $dia_st < date('Y-m-d')) {
                                            $diagnostico[] = "<span style='color: #fbbf24; font-weight: 600;'>⚠️ Saída Pendente</span>";
                                        }
                                    } else {
                                        if ($dia_sem_n <= 5 && $dia_st < date('Y-m-d')) {
                                            $diagnostico[] = "<span style='color: #ef4444; font-weight: 600;'>❌ Falta Sem Registro</span>";
                                        } elseif ($dia_sem_n >= 6) {
                                            $diagnostico[] = "<span style='color: var(--cinza-texto);'>☕ Folga</span>";
                                        }
                                    }

                                    if (empty($diagnostico)) {
                                        $diagnostico[] = ($dia_sem_n >= 6) ? "<span style='color: var(--cinza-texto);'>☕ Folga</span>" : "<span style='color: #00c853; font-weight: 600;'>✅ Jornada Cumprida</span>";
                                    }

                                    $saldo_dia_m = $trab_min - $esp_min;
                                    $abs_sd = abs(round($saldo_dia_m));
                                    $str_sd = ($saldo_dia_m >= 0 ? "+" : "-") . sprintf("%02dh %02dmin", floor($abs_sd / 60), $abs_sd % 60);
                                ?>
                                    <tr>
                                        <td><strong><?= date('d/m/Y', strtotime($dia_st)) ?></strong></td>
                                        <td><?= $dia_nome_st ?></td>
                                        <td><?= $ent_st ?></td>
                                        <td><?= $sa_st ?></td>
                                        <td><?= $ra_st ?></td>
                                        <td><?= $sai_st ?></td>
                                        <td><strong><?= sprintf("%02dh %02dmin", floor($trab_min / 60), round($trab_min) % 60) ?></strong></td>
                                        <td><?= sprintf("%02dh %02dmin", floor($esp_min / 60), $esp_min % 60) ?></td>
                                        <td style="font-weight: 700; color: <?= $saldo_dia_m >= 0 ? 'var(--verde)' : 'var(--vermelho)' ?>;">
                                            <?= $str_sd ?>
                                        </td>
                                        <td><?= implode(" • ", $diagnostico) ?></td>
                                    </tr>
                                <?php endfor; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; endif; ?>
            </div>
        <?php endif; ?>
 
        <?php if ($view === 'espelho'): 
            // Acesso restrito apenas para administradores master
            if (($_SESSION['usuario_tipo'] ?? '') !== 'master') {
                echo "<div class='card-ponto' style='color: var(--vermelho); font-weight: bold;'>⚠️ Acesso restrito apenas para administradores.</div>";
            } else {
                $target_uid = intval($_GET['usuario_id'] ?? 0);
                $stmt_target = $pdo->prepare("SELECT nome, email FROM $tabela_usuarios WHERE id = :id");
                $stmt_target->execute([':id' => $target_uid]);
                $target_user = $stmt_target->fetch(PDO::FETCH_ASSOC);
 
                if (!$target_user) {
                    echo "<div class='card-ponto' style='color: var(--vermelho); font-weight: bold;'>⚠️ Colaborador não encontrado.</div>";
                } else {
                // Obter filtro de mês/ano
                $mes_filtro = $_GET['mes_filtro'] ?? date('Y-m');
                $ano_mes = explode('-', $mes_filtro);
                $ano_calc = intval($ano_mes[0] ?? date('Y'));
                $mes_calc = intval($ano_mes[1] ?? date('m'));

                $ultimo_dia = intval(date('t', strtotime("$mes_filtro-01")));

                // Buscar registros do mês
                $stmt_p = $pdo->prepare("
                    SELECT DATE(data_hora) as data, tipo_registro, data_hora 
                    FROM $tabela_registros 
                    WHERE usuario_id = :uid AND YEAR(data_hora) = :ano AND MONTH(data_hora) = :mes
                    ORDER BY data_hora ASC
                ");
                $stmt_p->execute([
                    ':uid' => $target_uid,
                    ':ano' => $ano_calc,
                    ':mes' => $mes_calc
                ]);
                $registros = $stmt_p->fetchAll(PDO::FETCH_ASSOC);

                $dias_registros = [];
                foreach ($registros as $r) {
                    $dias_registros[$r['data']][$r['tipo_registro']] = $r['data_hora'];
                }

                $empresa_nome = "VIXMED SOLUÇÕES MÉDICAS LTDA";
                if ($is_saas && isset($_SESSION['usuario_empresa_id'])) {
                    $stmt_emp = $pdo->prepare("SELECT nome_fantasia FROM saas_empresas WHERE id = :id");
                    $stmt_emp->execute([':id' => $_SESSION['usuario_empresa_id']]);
                    $empresa_nome = $stmt_emp->fetchColumn() ?: "Vixmed SaaS Portal";
                }
            ?>
                <!-- VISÃO DO ESPELHO DE PONTO INDIVIDUAL -->
                <div class="espelho-container" style="max-width: 1000px; width: 100%; margin: 0 auto; padding: 30px; background: #fff; color: #000; border-radius: 12px; font-family: 'Courier New', Courier, monospace; box-shadow: 0 4px 15px rgba(0,0,0,0.1); border: 1px solid #ddd;">
                    
                    <!-- Botoes de Controle (ocultos na impressão) -->
                    <div class="no-print" style="margin-bottom: 25px; display: flex; justify-content: space-between; align-items: center; background: #f8f9fa; padding: 15px; border-radius: 8px; border: 1px solid #e9ecef;">
                        <div>
                            <a href="folhadeponto.php?view=banco&mes_filtro=<?= $mes_filtro ?>" class="btn-folha-unificada" style="background: #4a5568; color: #fff; text-decoration: none; padding: 10px 18px; border-radius: 6px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size: 13px; font-weight: 500; display: inline-block;">⬅️ Voltar ao Banco de Horas</a>
                        </div>
                        <button onclick="window.print()" class="btn-green" style="background: #2f855a; color: #fff; border: none; padding: 10px 22px; border-radius: 6px; cursor: pointer; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-weight: bold; font-size: 14px; display: flex; align-items: center; gap: 8px;">
                            🖨️ Imprimir / Salvar PDF
                        </button>
                    </div>

                    <!-- Cabeçalho do Relatório -->
                    <div style="text-align: center; border-bottom: 2px solid #000; padding-bottom: 15px; margin-bottom: 25px;">
                        <h2 style="margin: 0; font-size: 22px; font-weight: bold; text-transform: uppercase;"><?= htmlspecialchars($empresa_nome) ?></h2>
                        <h3 style="margin: 8px 0 0 0; font-size: 16px; font-weight: bold; letter-spacing: 1px;">RELATÓRIO ESPELHO DE PONTO INDIVIDUAL</h3>
                        <p style="margin: 6px 0 0 0; font-size: 13px; font-weight: bold;">Referência: <?= date('m/Y', strtotime("$mes_filtro-01")) ?></p>
                    </div>

                    <!-- Informações do Funcionário -->
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 25px; border: 1px solid #000; padding: 15px; border-radius: 6px; font-size: 13px; line-height: 1.6; background: #fafafa;">
                        <div>
                            <strong>Colaborador:</strong> <?= htmlspecialchars($target_user['nome']) ?><br>
                            <strong>E-mail:</strong> <?= htmlspecialchars($target_user['email']) ?>
                        </div>
                        <div>
                            <strong>Carga Horária Padrão:</strong> 08:00h Diárias (Segunda a Sexta)<br>
                            <strong>Período:</strong> 01/<?= date('m/Y', strtotime("$mes_filtro-01")) ?> a <?= $ultimo_dia ?>/<?= date('m/Y', strtotime("$mes_filtro-01")) ?>
                        </div>
                    </div>

                    <!-- Tabela Espelho de Ponto -->
                    <table style="width: 100%; border-collapse: collapse; margin-bottom: 35px; font-size: 12px; text-align: center;">
                        <thead>
                            <tr style="border-bottom: 2px solid #000; border-top: 2px solid #000; font-weight: bold; height: 32px; background: #f1f1f1;">
                                <th style="border-right: 1px solid #000; width: 12%;">Data</th>
                                <th style="border-right: 1px solid #000; width: 12%;">Dia</th>
                                <th style="border-right: 1px solid #000; width: 14%;">Entrada</th>
                                <th style="border-right: 1px solid #000; width: 14%;">S. Almoço</th>
                                <th style="border-right: 1px solid #000; width: 14%;">R. Almoço</th>
                                <th style="border-right: 1px solid #000; width: 14%;">Saída</th>
                                <th style="border-right: 1px solid #000; width: 10%;">Trabalhado</th>
                                <th style="width: 10%;">Saldo</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $total_minutos_trabalhados = 0;
                            $total_minutos_esperados = 0;
                            $total_saldo = 0;

                            for ($d = 1; $d <= $ultimo_dia; $d++):
                                $dia_str = sprintf("%s-%02d", $mes_filtro, $d);
                                $dia_semana_num = intval(date('N', strtotime($dia_str)));
                                $dias_traduzidos = [1 => 'Segunda', 2 => 'Terça', 3 => 'Quarta', 4 => 'Quinta', 5 => 'Sexta', 6 => 'Sábado', 7 => 'Domingo'];
                                $dia_semana_nome = $dias_traduzidos[$dia_semana_num];

                                $entrada = "—";
                                $saida_almoco = "—";
                                $retorno_almoco = "—";
                                $saida = "—";
                                $horas_trab_dia = "00:00";
                                $saldo_dia = "—";
                                $saldo_minutos_dia = 0;

                                $minutos_trab_dia = 0;
                                $minutos_esp_dia = getMinutosEsperadosDia($target_user['email'], $dia_semana_num);

                                if (isset($dias_registros[$dia_str])) {
                                    $batidas = $dias_registros[$dia_str];
                                    $entrada = isset($batidas['entrada']) ? date('H:i', strtotime($batidas['entrada'])) : "—";
                                    $saida_almoco = isset($batidas['saida_almoco']) ? date('H:i', strtotime($batidas['saida_almoco'])) : "—";
                                    $retorno_almoco = isset($batidas['retorno_almoco']) ? date('H:i', strtotime($batidas['retorno_almoco'])) : "—";
                                    $saida = isset($batidas['saida']) ? date('H:i', strtotime($batidas['saida'])) : "—";

                                    if (isset($batidas['entrada']) && isset($batidas['saida_almoco']) && isset($batidas['retorno_almoco']) && isset($batidas['saida'])) {
                                        $t1 = strtotime($batidas['entrada']);
                                        $t2 = strtotime($batidas['saida_almoco']);
                                        $t3 = strtotime($batidas['retorno_almoco']);
                                        $t4 = strtotime($batidas['saida']);

                                        $manha = ($t2 - $t1) / 60;
                                        $tarde = ($t4 - $t3) / 60;
                                        $minutos_trab_dia = $manha + $tarde;
                                    } elseif (isset($batidas['entrada']) && isset($batidas['saida']) && !isset($batidas['saida_almoco']) && !isset($batidas['retorno_almoco'])) {
                                        $t1 = strtotime($batidas['entrada']);
                                        $t4 = strtotime($batidas['saida']);
                                        $minutos_trab_dia = ($t4 - $t1) / 60;
                                        if ($minutos_trab_dia > 360) {
                                            $minutos_trab_dia -= 60;
                                        }
                                    }

                                    if ($minutos_trab_dia > 0) {
                                        $horas_trab_dia = sprintf("%02d:%02d", floor($minutos_trab_dia / 60), $minutos_trab_dia % 60);
                                    }
                                }

                                if ($minutos_esp_dia > 0 || $minutos_trab_dia > 0) {
                                    $saldo_minutos_dia = $minutos_trab_dia - $minutos_esp_dia;
                                    $prefixo = $saldo_minutos_dia >= 0 ? "+" : "-";
                                    $saldo_dia = $prefixo . sprintf("%02d:%02d", floor(abs($saldo_minutos_dia) / 60), abs($saldo_minutos_dia) % 60);
                                    
                                    $total_minutos_trabalhados += $minutos_trab_dia;
                                    $total_minutos_esperados += $minutos_esp_dia;
                                    $total_saldo += $saldo_minutos_dia;
                                }
                            ?>
                                <tr style="border-bottom: 1px solid #eee; height: 28px;">
                                    <td style="border-right: 1px solid #000;"><?= date('d/m', strtotime($dia_str)) ?></td>
                                    <td style="border-right: 1px solid #000;"><?= $dia_semana_nome ?></td>
                                    <td style="border-right: 1px solid #000;"><?= $entrada ?></td>
                                    <td style="border-right: 1px solid #000;"><?= $saida_almoco ?></td>
                                    <td style="border-right: 1px solid #000;"><?= $retorno_almoco ?></td>
                                    <td style="border-right: 1px solid #000;"><?= $saida ?></td>
                                    <td style="border-right: 1px solid #000; font-weight: bold;"><?= $horas_trab_dia ?></td>
                                    <td style="font-weight: bold; color: <?= $saldo_minutos_dia >= 0 ? '#276749' : '#9b2c2c' ?>;"><?= $saldo_dia ?></td>
                                </tr>
                            <?php endfor; ?>

                            <!-- Totais de Resumo -->
                            <tr style="border-top: 2px solid #000; border-bottom: 2px solid #000; font-weight: bold; height: 34px; background: #f7fafc;">
                                <td colspan="6" style="text-align: right; padding-right: 15px; border-right: 1px solid #000;">TOTAIS ACUMULADOS NO PERÍODO:</td>
                                <td style="border-right: 1px solid #000;"><?= sprintf("%02d:%02d", floor($total_minutos_trabalhados / 60), $total_minutos_trabalhados % 60) ?>h</td>
                                <td style="color: <?= $total_saldo >= 0 ? '#276749' : '#9b2c2c' ?>;"><?= ($total_saldo >= 0 ? "+" : "-") . sprintf("%02d:%02d", floor(abs($total_saldo) / 60), abs($total_saldo) % 60) ?>h</td>
                            </tr>
                        </tbody>
                    </table>

                    <!-- Área de Declaração e Assinaturas -->
                    <div style="font-size: 11px; line-height: 1.6; margin-bottom: 60px; text-align: justify; border-top: 1px dashed #ccc; padding-top: 15px;">
                        Declaro para os devidos fins que as informações constantes neste relatório espelho de ponto são a expressão da verdade e correspondem fielmente aos horários por mim laborados no período de referência.
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 50px; text-align: center; margin-top: 70px; font-size: 13px;">
                        <div>
                            ________________________________________________<br>
                            <strong>Assinatura do Colaborador</strong><br>
                            Data: ____/____/______
                        </div>
                        <div>
                            ________________________________________________<br>
                            <strong>Assinatura do Empregador</strong><br>
                            Representante Autorizado
                        </div>
                    </div>

                </div>

                <!-- Estilos CSS Especiais para Impressão -->
                <style>
                    @media print {
                        body {
                            background: #fff !important;
                            color: #000 !important;
                            margin: 0 !important;
                            padding: 0 !important;
                        }
                        .ponto-topbar, .ponto-nav, .ponto-user, .no-print, .mte-badge, header, footer, sidebar, .sidebar {
                            display: none !important;
                        }
                        .espelho-container {
                            box-shadow: none !important;
                            border-radius: 0 !important;
                            padding: 0 !important;
                            max-width: 100% !important;
                            margin: 0 !important;
                            border: none !important;
                        }
                        .ponto-main {
                            padding: 0 !important;
                            background: none !important;
                        }
                    }
                </style>
            <?php 
                } 
            } 
            ?>
        <?php endif; ?>

    </main>

    <script>
        const historicoCompleto = <?= json_encode(array_map(function($h) use ($nome, $cpf_trabalhador) {
            return [
                'nsr' => str_pad($h['nsr'], 9, "0", STR_PAD_LEFT),
                'trabalhador' => $nome,
                'cpf' => $cpf_trabalhador,
                'data_hora' => date('d/m/Y H:i:s', strtotime($h['data_hora'])),
                'tipo' => strtoupper(str_replace('_', ' ', $h['tipo_registro'])),
                'hash' => $h['hash_comprovante']
            ];
        }, $historico), JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

        // Relógio em Tempo Real (Horário Oficial de Brasília)
        function atualizarRelogio() {
            const agora = new Date();
            const horas = String(agora.getHours()).padStart(2, '0');
            const minutos = String(agora.getMinutes()).padStart(2, '0');
            const segundos = String(agora.getSeconds()).padStart(2, '0');
            document.getElementById('relogio').textContent = `${horas}:${minutos}:${segundos}`;

            const opcoes = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' };
            document.getElementById('data-hoje').textContent = agora.toLocaleDateString('pt-BR', opcoes);
        }

        setInterval(atualizarRelogio, 1000);
        atualizarRelogio();

        // Seleção do Tipo de Registro
        function setTipo(tipo, btn) {
            document.getElementById('input-tipo-registro').value = tipo;
            document.querySelectorAll('.tipo-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
        }

        // Submeter Ponto via AJAX
        function submeterPonto(e) {
            if (e) e.preventDefault();
            const btn = document.getElementById('btn-marcar-ponto');
            const inputTipo = document.getElementById('input-tipo-registro').value;

            btn.style.background = 'linear-gradient(135deg, #00a844 0%, #007a33 100%)';
            btn.innerHTML = '<span class="ponto-btn-icon">⏳</span>Registrando...';

            const formData = new FormData();
            formData.append('acao', 'marcar_ponto');
            formData.append('tipo_registro', inputTipo);
            formData.append('ajax', '1');

            fetch('folhadeponto.php', {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(data => {
                if (data.sucesso) {
                    btn.style.background = 'linear-gradient(135deg, #00c853 0%, #00a844 100%)';
                    btn.innerHTML = '<span class="ponto-btn-icon">✅</span>Registrado!';

                    // Abre o comprovante direto no Modal Popup
                    abrirModalComprovante(data.comprovante);

                    // Banner de sucesso
                    const banner = document.getElementById('mensagem-banner');
                    if (banner) {
                        banner.style.display = 'block';
                        banner.innerHTML = '✅ ' + data.mensagem;
                    }

                    setTimeout(() => {
                        window.location.reload();
                    }, 1200);
                } else {
                    document.getElementById('form-ponto').submit();
                }
            })
            .catch(err => {
                document.getElementById('form-ponto').submit();
            });

            return false;
        }

        // ABRIR E FECHAR MODAL COMPROVANTE (CLICAR FORA FECHA)
        function abrirModalComprovante(comp) {
            const body = document.getElementById('modal-comprovante-body');
            if (body) {
                body.innerHTML = `
                    <div class="comprovante-box" id="comprovante-print">
                        <div class="comprovante-header">
                            VIXMED SOLUÇÕES MÉDICAS LTDA<br>
                            CNPJ: 00.000.000/0001-00<br>
                            COMPROVANTE DE REGISTRO DE PONTO DO TRABALHADOR
                        </div>
                        <div class="comprovante-row"><span>NSR:</span> <strong>${comp.nsr}</strong></div>
                        <div class="comprovante-row"><span>Trabalhador:</span> <strong>${comp.trabalhador}</strong></div>
                        <div class="comprovante-row"><span>CPF:</span> <strong>${comp.cpf}</strong></div>
                        <div class="comprovante-row"><span>Data/Hora:</span> <strong>${comp.data_hora}</strong></div>
                        <div class="comprovante-row"><span>Tipo Batida:</span> <strong>${comp.tipo}</strong></div>
                        <div class="comprovante-hash">
                            <strong>Assinatura SHA-256:</strong><br>
                            ${comp.hash}
                        </div>
                    </div>
                `;
            }

            const overlay = document.getElementById('modal-comprovante-overlay');
            if (overlay) {
                overlay.classList.add('open');
            }
        }

        function fecharModalComprovante(e) {
            if (e.target.id === 'modal-comprovante-overlay') {
                fecharModalComprovanteDirect();
            }
        }

        function fecharModalComprovanteDirect() {
            const overlay = document.getElementById('modal-comprovante-overlay');
            if (overlay) {
                overlay.classList.remove('open');
            }
        }

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                fecharModalComprovanteDirect();
            }
        });

        // Imprimir Comprovante Individual
        function imprimirComprovante() {
            const conteudo = document.getElementById('comprovante-print').outerHTML;
            const jan = window.open('', '', 'width=600,height=500');
            jan.document.write('<html><head><title>Comprovante de Ponto MTE</title></head><body style="font-family:monospace;">' + conteudo + '</body></html>');
            jan.document.close();
            jan.print();
        }

        // Imprimir Folha Completa Unificada de Todos os Comprovantes do Mês
        function imprimirFolhaCompleta() {
            if (!historicoCompleto || historicoCompleto.length === 0) {
                alert('Nenhum comprovante disponível para impressão.');
                return;
            }

            let html = `<html><head><title>Folha Unificada de Comprovantes - Vixmed Ponto</title>
            <style>
                body { font-family: monospace; font-size: 11px; padding: 24px; color: #1a1a2e; background: #fff; }
                .folha-header { text-align: center; margin-bottom: 24px; border-bottom: 2px solid #00c853; padding-bottom: 12px; }
                .folha-header h2 { margin: 0 0 4px 0; font-size: 16px; }
                .folha-header h3 { margin: 0 0 4px 0; font-size: 13px; color: #555; }
                .comprovante-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px; }
                .comprovante-item { border: 1px dashed #00c853; padding: 12px; border-radius: 8px; background: #fafafa; page-break-inside: avoid; }
                .comp-title { font-weight: bold; border-bottom: 1px solid #eee; padding-bottom: 4px; margin-bottom: 8px; text-align: center; font-size: 10px; }
                .comp-row { display: flex; justify-content: space-between; margin-bottom: 3px; }
                .comp-hash { background: #f0f2f5; padding: 4px; font-size: 8px; word-break: break-all; margin-top: 6px; border-radius: 4px; border: 1px solid #ddd; }
                @media print { .comprovante-grid { grid-template-columns: repeat(2, 1fr); } }
            </style></head><body>
            <div class="folha-header">
                <h2>VIXMED SOLUÇÕES MÉDICAS LTDA — CNPJ: 00.000.000/0001-00</h2>
                <h3>FOLHA UNIFICADA DE COMPROVANTES DE REGISTRO DE PONTO (REP-P PORTARIA 671)</h3>
                <p>Trabalhador: <strong>${historicoCompleto[0].trabalhador}</strong> | CPF: <strong>${historicoCompleto[0].cpf}</strong></p>
            </div>
            <div class="comprovante-grid">`;

            historicoCompleto.forEach(item => {
                html += `
                <div class="comprovante-item">
                    <div class="comp-title">COMPROVANTE REGISTRO DE PONTO — NSR: ${item.nsr}</div>
                    <div class="comp-row"><span>Data/Hora:</span> <strong>${item.data_hora}</strong></div>
                    <div class="comp-row"><span>Tipo Batida:</span> <strong>${item.tipo}</strong></div>
                    <div class="comp-hash"><strong>Assinatura SHA-256:</strong><br>${item.hash}</div>
                </div>`;
            });

            html += `</div></body></html>`;

            const jan = window.open('', '', 'width=900,height=700');
            jan.document.write(html);
            jan.document.close();
            jan.print();
        }

        // Lógica do cronômetro de almoço
        <?php if ($almoco_em_andamento && $hora_saida_almoco): ?>
        (function() {
            const saidaAlmoco = new Date("<?= date('Y-m-d\TH:i:s', strtotime($hora_saida_almoco)) ?>");
            const retornoPrevisto = new Date(saidaAlmoco.getTime() + 60 * 60 * 1000);

            function atualizarContagemAlmoco() {
                const agora = new Date();
                const diferenca = retornoPrevisto.getTime() - agora.getTime();

                const container = document.getElementById('tempo-restante-almoco');
                if (!container) return;

                if (diferenca <= 0) {
                    container.innerHTML = "<span style='color: var(--verde); font-weight: 800;'>Prazo encerrado! Pode registrar o retorno.</span>";
                    const card = document.getElementById('almoco-timer-container');
                    if (card) {
                        card.style.borderColor = "var(--verde)";
                        card.style.background = "rgba(0, 200, 83, 0.08)";
                        const title = card.querySelector('.timer-title');
                        if (title) {
                            title.style.color = "var(--verde)";
                            title.innerHTML = "Intervalo Concluído";
                        }
                    }
                    return;
                }

                const minutos = Math.floor((diferenca % (1000 * 60 * 60)) / (1000 * 60));
                const segundos = Math.floor((diferenca % (1000 * 60)) / 1000);

                const minStr = String(minutos).padStart(2, '0');
                const segStr = String(segundos).padStart(2, '0');

                container.innerHTML = `Faltam <span class="timer-highlight">${minStr}:${segStr}</span> para retornar`;
            }

            atualizarContagemAlmoco();
            setInterval(atualizarContagemAlmoco, 1000);
        })();
        <?php endif; ?>

        // Lógica de abas do administrador para filtrar solicitações
        function filtrarSolicitacoes(status, btn) {
            const btns = document.querySelectorAll('.admin-tab-btn');
            btns.forEach(b => b.classList.remove('active'));
            if (btn) btn.classList.add('active');

            const cards = document.querySelectorAll('.ajuste-card-solicitacao');
            cards.forEach(card => {
                if (status === 'todos' || card.dataset.status === status) {
                    card.style.display = 'flex';
                } else {
                    card.style.display = 'none';
                }
            });
        }

        // Lógica do Modal de Rejeição de Ajuste
        function abrirModalRejeitar(id) {
            const inputId = document.getElementById('rejeitar-solicitacao-id');
            if (inputId) inputId.value = id;
            const overlay = document.getElementById('modal-rejeitar-overlay');
            if (overlay) overlay.classList.add('open');
        }
        function fecharModalRejeitar(e) {
            if (e.target.id === 'modal-rejeitar-overlay') {
                fecharModalRejeitarDirect();
            }
        }
        function fecharModalRejeitarDirect() {
            const overlay = document.getElementById('modal-rejeitar-overlay');
            if (overlay) overlay.classList.remove('open');
        }
    </script>

    <!-- MODAL DE REJEITAR SOLICITAÇÃO (Apenas Master) -->
    <?php if (($_SESSION['usuario_tipo'] ?? '') === 'master'): ?>
    <div id="modal-rejeitar-overlay" class="modal-rejeitar-overlay" onclick="fecharModalRejeitar(event)">
        <div class="modal-comprovante-card" onclick="event.stopPropagation()">
            <div class="modal-comprovante-header">
                <h3>❌ Rejeitar Solicitação de Ajuste</h3>
                <button type="button" class="btn-close-modal" onclick="fecharModalRejeitarDirect()">✖</button>
            </div>
            <form method="POST">
                <input type="hidden" name="acao" value="atualizar_status_ajuste">
                <input type="hidden" name="solicitacao_id" id="rejeitar-solicitacao-id" value="">
                <input type="hidden" name="status" value="rejeitado">
                
                <div class="form-control-group" style="margin-bottom: 16px;">
                    <label style="color: var(--branco); margin-bottom: 8px;">Motivo da Rejeição / Justificativa</label>
                    <textarea name="observacao_admin" rows="3" placeholder="Informe ao colaborador o motivo da rejeição..." required style="width: 100%;"></textarea>
                </div>
                
                <div class="modal-footer-btns">
                    <button type="submit" class="btn-green" style="flex: 1; background: var(--vermelho);">Confirmar Rejeição</button>
                    <button type="button" class="btn-secondary-modal" onclick="fecharModalRejeitarDirect()">Cancelar</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

</body>
</html>
