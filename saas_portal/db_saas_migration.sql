-- Migração para Banco de Dados SaaS Multi-tenant (usando base vixmed_db com prefixo saas_)
USE `vixmed_db`;

-- 1. Tabela de Empresas
CREATE TABLE IF NOT EXISTS `saas_empresas` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `nome_fantasia` VARCHAR(150) NOT NULL,
  `email_financeiro` VARCHAR(150) NOT NULL,
  `status_assinatura` ENUM('ativo', 'suspenso', 'teste') DEFAULT 'teste',
  `data_expiracao` DATE NOT NULL,
  `cobranca_automatica` TINYINT(1) DEFAULT 1,
  `mp_preapproval_id` VARCHAR(100) NULL,
  `recurso_estoque` TINYINT(1) DEFAULT 0,
  `recurso_transferencias` TINYINT(1) DEFAULT 0,
  `criado_em` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- 2. Tabela de Setores
CREATE TABLE IF NOT EXISTS `saas_setores` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `empresa_id` INT NOT NULL,
  `nome` VARCHAR(100) NOT NULL,
  `criado_em` DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`empresa_id`) REFERENCES `saas_empresas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- 3. Tabela de Usuários
CREATE TABLE IF NOT EXISTS `saas_usuarios` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `empresa_id` INT NOT NULL,
  `email` VARCHAR(100) NOT NULL UNIQUE,
  `senha` VARCHAR(255) NOT NULL,
  `nome` VARCHAR(100) NOT NULL DEFAULT '',
  `tipo` ENUM('master', 'funcionario') DEFAULT 'funcionario',
  `setor_id` INT DEFAULT NULL,
  `funcao` VARCHAR(100) DEFAULT '',
  `criado_em` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`empresa_id`) REFERENCES `saas_empresas` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`setor_id`) REFERENCES `saas_setores` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- 4. Tabela de Chamados
CREATE TABLE IF NOT EXISTS `saas_chamados` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `empresa_id` INT NOT NULL,
  `usuario_id` INT NOT NULL,
  `assunto` VARCHAR(200) NOT NULL DEFAULT '',
  `descricao` TEXT NOT NULL,
  `setor` VARCHAR(100) NOT NULL DEFAULT '',
  `prioridade` ENUM('baixa', 'media', 'alta') DEFAULT 'media',
  `status` ENUM('aberto', 'em_andamento', 'resolvido', 'fechado') DEFAULT 'aberto',
  `imagem` VARCHAR(255) DEFAULT NULL,
  `criado_em` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `atualizado_em` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`empresa_id`) REFERENCES `saas_empresas` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`usuario_id`) REFERENCES `saas_usuarios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- 5. Tabela de Mensagens
CREATE TABLE IF NOT EXISTS `saas_mensagens` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `chamado_id` INT NOT NULL,
  `usuario_id` INT NOT NULL,
  `mensagem` TEXT NOT NULL,
  `imagem` VARCHAR(255) DEFAULT NULL,
  `criado_em` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`chamado_id`) REFERENCES `saas_chamados` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`usuario_id`) REFERENCES `saas_usuarios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- 6. Tabela de Produtos (Estoque)
CREATE TABLE IF NOT EXISTS `saas_produtos` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `empresa_id` INT NOT NULL,
  `codigo` VARCHAR(50) NOT NULL,
  `nome` VARCHAR(150) NOT NULL,
  `codigo_barras` VARCHAR(100) DEFAULT NULL,
  `categoria` VARCHAR(50) NOT NULL,
  `quantidade` INT NOT NULL DEFAULT 0,
  `estoque_minimo` INT NOT NULL DEFAULT 5,
  `criado_em` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `atualizado_em` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`empresa_id`) REFERENCES `saas_empresas` (`id`) ON DELETE CASCADE,
  UNIQUE KEY `idx_empresa_codigo` (`empresa_id`, `codigo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- 7. Tabela de Movimentações de Estoque
CREATE TABLE IF NOT EXISTS `saas_movimentacao_estoque` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `produto_id` INT NOT NULL,
  `tipo` ENUM('entrada', 'saida') NOT NULL,
  `quantidade` INT NOT NULL,
  `motivo` VARCHAR(255) NOT NULL,
  `usuario_id` INT NOT NULL,
  `chamado_id` INT DEFAULT NULL,
  `criado_em` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`produto_id`) REFERENCES `saas_produtos` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`usuario_id`) REFERENCES `saas_usuarios` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`chamado_id`) REFERENCES `saas_chamados` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- 8. Tabela de Transferências
CREATE TABLE IF NOT EXISTS `saas_transferencias` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `produto_id` INT NOT NULL,
  `funcionario_id` INT NOT NULL,
  `quantidade` INT NOT NULL DEFAULT 1,
  `data_transferencia` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `status` ENUM('entregue', 'devolvido') DEFAULT 'entregue',
  `data_devolucao` TIMESTAMP NULL DEFAULT NULL,
  `observacao` VARCHAR(255) DEFAULT NULL,
  `usuario_id` INT NOT NULL,
  FOREIGN KEY (`produto_id`) REFERENCES `saas_produtos` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`funcionario_id`) REFERENCES `saas_usuarios` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`usuario_id`) REFERENCES `saas_usuarios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;


-- INSERÇÕES DE TESTE (EMPRESAS DEMO)
-- 1. Empresa A (Ativa com Estoque e Transferências)
INSERT INTO `saas_empresas` (`id`, `nome_fantasia`, `email_financeiro`, `status_assinatura`, `data_expiracao`, `cobranca_automatica`, `recurso_estoque`, `recurso_transferencias`)
VALUES (1, 'Empresa Alfa TI', 'financeiro@alfa.com', 'ativo', '2028-12-31', 0, 1, 1)
ON DUPLICATE KEY UPDATE `id`=`id`;

-- 2. Empresa B (Suspensa / Expirada para testar bloqueio)
INSERT INTO `saas_empresas` (`id`, `nome_fantasia`, `email_financeiro`, `status_assinatura`, `data_expiracao`, `cobranca_automatica`, `recurso_estoque`, `recurso_transferencias`)
VALUES (2, 'Empresa Beta Construções', 'financeiro@beta.com', 'suspenso', '2026-01-01', 1, 0, 0)
ON DUPLICATE KEY UPDATE `id`=`id`;

-- 3. Empresa C (Em período de Testes com Estoque mas sem Transferências)
INSERT INTO `saas_empresas` (`id`, `nome_fantasia`, `email_financeiro`, `status_assinatura`, `data_expiracao`, `cobranca_automatica`, `recurso_estoque`, `recurso_transferencias`)
VALUES (3, 'Clínica Sorriso Feliz', 'financeiro@sorriso.com', 'teste', DATE_ADD(CURRENT_DATE(), INTERVAL 14 DAY), 1, 1, 0)
ON DUPLICATE KEY UPDATE `id`=`id`;


-- USUÁRIOS DE TESTE
-- Empresa Alfa (Ativa)
INSERT INTO `saas_usuarios` (`id`, `empresa_id`, `email`, `senha`, `nome`, `tipo`, `funcao`)
VALUES (1, 1, 'admin@alfa.com', '123456', 'Admin Alfa', 'master', 'Diretor de TI')
ON DUPLICATE KEY UPDATE `id`=`id`;
INSERT INTO `saas_usuarios` (`id`, `empresa_id`, `email`, `senha`, `nome`, `tipo`, `funcao`)
VALUES (2, 1, 'funcionario@alfa.com', '123456', 'Funcionario Alfa', 'funcionario', 'Suporte N1')
ON DUPLICATE KEY UPDATE `id`=`id`;

-- Empresa Beta (Suspensa)
INSERT INTO `saas_usuarios` (`id`, `empresa_id`, `email`, `senha`, `nome`, `tipo`, `funcao`)
VALUES (3, 2, 'admin@beta.com', '123456', 'Admin Beta', 'master', 'Dono')
ON DUPLICATE KEY UPDATE `id`=`id`;

-- Empresa Clinica (Teste)
INSERT INTO `saas_usuarios` (`id`, `empresa_id`, `email`, `senha`, `nome`, `tipo`, `funcao`)
VALUES (4, 3, 'dentista@sorriso.com', '123456', 'Dra. Ana', 'master', 'Dentista Chefe')
ON DUPLICATE KEY UPDATE `id`=`id`;
