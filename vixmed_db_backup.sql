-- MySQL dump 10.13  Distrib 8.4.10, for Linux (x86_64)
--
-- Host: localhost    Database: vixmed_db
-- ------------------------------------------------------
-- Server version	8.4.10-0ubuntu0.26.04.1

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `chamados`
--

DROP TABLE IF EXISTS `chamados`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `chamados` (
  `id` int NOT NULL AUTO_INCREMENT,
  `descricao` text NOT NULL,
  `setor` varchar(100) NOT NULL DEFAULT '',
  `prioridade` enum('baixa','media','alta') DEFAULT 'media',
  `usuario_id` int NOT NULL,
  `assunto` varchar(200) NOT NULL DEFAULT '',
  `status` enum('aberto','em_andamento','resolvido','fechado') DEFAULT 'aberto',
  `criado_em` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `atualizado_em` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `imagem` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `usuario_id` (`usuario_id`),
  CONSTRAINT `chamados_ibfk_1` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `chamados`
--

LOCK TABLES `chamados` WRITE;
/*!40000 ALTER TABLE `chamados` DISABLE KEYS */;
INSERT INTO `chamados` VALUES (1,'A impressora do setor de recepção parou de funcionar hoje de manhã','Administrativo','media',1,'Teste impressora não funciona','fechado','2026-07-03 17:05:07','2026-07-03 16:15:27',NULL),(2,'gostaria de ajuda com a impressora se possivel vir na sala de segurança do trabalho','Segurança Do Trabalho','baixa',2,'impressora com defeito','fechado','2026-07-03 17:14:19','2026-07-03 16:15:15',NULL),(3,'estou tentando salvar um documento no sigo e esta dando este problema','TI','alta',3,'Sigo','fechado','2026-07-03 18:23:58','2026-07-06 14:07:08',NULL),(4,'problemas da maquina do medico, \r\n\r\nCOMPUTADOR ESTA LIGADO POREM SOMENTE O PONTEIRO APARECE','Médico','alta',1,'COMPUTADOR NAO ESTA LIGANDO','fechado','2026-07-07 11:53:27','2026-07-14 16:36:24',NULL),(5,'Estava acessando o sigo e de repente varias partes do acessos sumiram, os acessos iam ate embaixo','Segurança Do Trabalho','media',3,'SIGO COM FALTA DE ACESSO','fechado','2026-07-09 17:39:24','2026-07-09 15:46:56','uploads/chamado_1783618764_065c770f.png'),(6,'não escaneia','Recepção','alta',5,'impressora não escaneia','fechado','2026-07-17 17:26:39','2026-07-17 15:57:49',NULL);
/*!40000 ALTER TABLE `chamados` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `mensagens`
--

DROP TABLE IF EXISTS `mensagens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `mensagens` (
  `id` int NOT NULL AUTO_INCREMENT,
  `chamado_id` int NOT NULL,
  `usuario_id` int NOT NULL,
  `mensagem` text NOT NULL,
  `arquivo` varchar(255) DEFAULT NULL,
  `criado_em` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `imagem` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `chamado_id` (`chamado_id`),
  KEY `usuario_id` (`usuario_id`),
  CONSTRAINT `mensagens_ibfk_1` FOREIGN KEY (`chamado_id`) REFERENCES `chamados` (`id`) ON DELETE CASCADE,
  CONSTRAINT `mensagens_ibfk_2` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=17 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `mensagens`
--

LOCK TABLES `mensagens` WRITE;
/*!40000 ALTER TABLE `mensagens` DISABLE KEYS */;
INSERT INTO `mensagens` VALUES (1,2,1,'do que se trata a dificuldade ?',NULL,'2026-07-03 17:14:46',NULL),(2,3,1,'boa tarde, somente para regesito eu passei o numero do anydesk para o atendimento e suporte da sigo, os mesmos entraram em contato com a tecnica para auxiliar no suporte',NULL,'2026-07-03 18:57:06',NULL),(3,3,1,'segue anexo da conversa',NULL,'2026-07-03 18:58:09',NULL),(4,3,1,'',NULL,'2026-07-03 19:12:27','uploads/msg_1783105947_57db91f7.png'),(5,3,1,'como nao tive resposta se foi solucionado pelo tempo do chamado acredita se que tenha se resolvido',NULL,'2026-07-06 17:06:56',NULL),(6,3,1,'vou fechar o chamado',NULL,'2026-07-06 17:07:02',NULL),(7,4,1,'MAQUINA FOI REINICIADA E ESTA FUNCIONAL',NULL,'2026-07-07 11:54:05',NULL),(8,4,1,'CHAMADO SERÁ ENCERRADO',NULL,'2026-07-07 11:54:15',NULL),(9,5,1,'boa tarde vai ser apurado o seu atendimento com os acessos masters',NULL,'2026-07-09 17:43:31',NULL),(10,5,1,'se eles nao fizeram nenhuma mudança no sistema, abrirei chamado junto a sigo para averiguar',NULL,'2026-07-09 17:44:04',NULL),(11,5,1,'login para usar o sigo...',NULL,'2026-07-09 18:44:28',NULL),(12,5,1,'USUARIO: Marianna.s8054',NULL,'2026-07-09 18:45:57',NULL),(13,5,1,'Senha:Ms@vix2026',NULL,'2026-07-09 18:46:14',NULL),(14,4,1,'📦 **EQUIPAMENTO DISPENSADO DO ESTOQUE**\nRetirado(s) **2x Mouse USB Básico Dell** (Cód: `MSE-001`) e vinculado(s) a este chamado.',NULL,'2026-07-14 19:36:24',NULL),(15,6,1,'chamado foi tratado e foi ate o presente momento resolvido, a impressora nao estava fazendo o scan e nao estava configurada pois apresentava falha de firmware, a impressora foi devidamente atualizada e posto um novo sistema de scan que melhora a qualidade da imagem do scan',NULL,'2026-07-17 18:56:49',NULL),(16,6,1,'chamado será dado como encerrado!',NULL,'2026-07-17 18:57:28',NULL);
/*!40000 ALTER TABLE `mensagens` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `movimentacao_estoque`
--

DROP TABLE IF EXISTS `movimentacao_estoque`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `movimentacao_estoque` (
  `id` int NOT NULL AUTO_INCREMENT,
  `produto_id` int NOT NULL,
  `tipo` enum('entrada','saida') NOT NULL,
  `quantidade` int NOT NULL,
  `motivo` varchar(255) NOT NULL,
  `usuario_id` int NOT NULL,
  `chamado_id` int DEFAULT NULL,
  `criado_em` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `produto_id` (`produto_id`),
  KEY `usuario_id` (`usuario_id`),
  KEY `chamado_id` (`chamado_id`),
  CONSTRAINT `movimentacao_estoque_ibfk_1` FOREIGN KEY (`produto_id`) REFERENCES `produtos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `movimentacao_estoque_ibfk_2` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`),
  CONSTRAINT `movimentacao_estoque_ibfk_3` FOREIGN KEY (`chamado_id`) REFERENCES `chamados` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `movimentacao_estoque`
--

LOCK TABLES `movimentacao_estoque` WRITE;
/*!40000 ALTER TABLE `movimentacao_estoque` DISABLE KEYS */;
INSERT INTO `movimentacao_estoque` VALUES (4,6,'entrada',4,'Estoque inicial cadastrado',1,NULL,'2026-07-14 19:42:55'),(5,6,'saida',1,'Transferido para funcionário id 4 (CLI)',1,NULL,'2026-07-14 19:51:17'),(6,6,'entrada',1,'Devolvido pelo funcionário Bianca Teixeira Lima Rocha (CLI)',1,NULL,'2026-07-14 19:51:17');
/*!40000 ALTER TABLE `movimentacao_estoque` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `ponto_lgpd_termos`
--

DROP TABLE IF EXISTS `ponto_lgpd_termos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ponto_lgpd_termos` (
  `id` int NOT NULL AUTO_INCREMENT,
  `usuario_id` int NOT NULL,
  `aceito` tinyint(1) DEFAULT '1',
  `data_aceite` datetime DEFAULT CURRENT_TIMESTAMP,
  `ip` varchar(45) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `usuario_id` (`usuario_id`),
  CONSTRAINT `ponto_lgpd_termos_ibfk_1` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `ponto_lgpd_termos`
--

LOCK TABLES `ponto_lgpd_termos` WRITE;
/*!40000 ALTER TABLE `ponto_lgpd_termos` DISABLE KEYS */;
INSERT INTO `ponto_lgpd_termos` VALUES (1,1,1,'2026-07-28 10:44:47','192.168.1.38'),(2,5,1,'2026-07-28 10:53:57','192.168.1.38');
/*!40000 ALTER TABLE `ponto_lgpd_termos` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `ponto_registros`
--

DROP TABLE IF EXISTS `ponto_registros`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ponto_registros` (
  `id` int NOT NULL AUTO_INCREMENT,
  `usuario_id` int NOT NULL,
  `nsr` int NOT NULL,
  `cpf` varchar(14) DEFAULT NULL,
  `tipo_registro` enum('entrada','saida_almoco','retorno_almoco','saida') NOT NULL,
  `data_hora` datetime NOT NULL,
  `hash_comprovante` varchar(64) NOT NULL,
  `ip_origem` varchar(45) DEFAULT NULL,
  `criado_em` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `nsr` (`nsr`),
  KEY `usuario_id` (`usuario_id`),
  CONSTRAINT `ponto_registros_ibfk_1` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `ponto_registros`
--

LOCK TABLES `ponto_registros` WRITE;
/*!40000 ALTER TABLE `ponto_registros` DISABLE KEYS */;
INSERT INTO `ponto_registros` VALUES (1,1,1,'000.000.000-00','entrada','2026-07-28 11:53:31','49e296d0ac44c634c2c939fefa0f84857733b84c273c81b46ecda74583398975','192.168.1.38','2026-07-28 14:53:31'),(2,1,2,'000.000.000-00','saida_almoco','2026-07-28 11:53:51','ccff9a24c84d61e5b275836de60829a3dabf4cfb0a9cd228e0aa39a7c0b7ab3f','192.168.1.38','2026-07-28 14:53:51'),(3,1,3,'000.000.000-00','retorno_almoco','2026-07-28 11:53:54','63872b92d1101d676e513da39fb9bb536368e87ef476cea61d0f3a2e025885c6','192.168.1.38','2026-07-28 14:53:54'),(4,1,4,'000.000.000-00','saida','2026-07-28 11:53:57','6301feea5fffbf00acae8168c89d8b42b3385e89075b8ba40ea56cae72a9cddd','192.168.1.38','2026-07-28 14:53:57');
/*!40000 ALTER TABLE `ponto_registros` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `produtos`
--

DROP TABLE IF EXISTS `produtos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `produtos` (
  `id` int NOT NULL AUTO_INCREMENT,
  `codigo` varchar(50) NOT NULL,
  `nome` varchar(150) NOT NULL,
  `codigo_barras` varchar(100) DEFAULT NULL,
  `categoria` varchar(50) NOT NULL,
  `quantidade` int NOT NULL DEFAULT '0',
  `estoque_minimo` int NOT NULL DEFAULT '5',
  `criado_em` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `atualizado_em` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `codigo` (`codigo`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `produtos`
--

LOCK TABLES `produtos` WRITE;
/*!40000 ALTER TABLE `produtos` DISABLE KEYS */;
INSERT INTO `produtos` VALUES (6,'3053-1','TECLADO B-MAX/BM-T02','7893595903053','Periféricos',4,10,'2026-07-14 19:42:55','2026-07-14 19:51:17');
/*!40000 ALTER TABLE `produtos` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `saas_chamados`
--

DROP TABLE IF EXISTS `saas_chamados`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `saas_chamados` (
  `id` int NOT NULL AUTO_INCREMENT,
  `empresa_id` int NOT NULL,
  `usuario_id` int NOT NULL,
  `assunto` varchar(200) NOT NULL DEFAULT '',
  `descricao` text NOT NULL,
  `setor` varchar(100) NOT NULL DEFAULT '',
  `prioridade` enum('baixa','media','alta') DEFAULT 'media',
  `status` enum('aberto','em_andamento','resolvido','fechado') DEFAULT 'aberto',
  `imagem` varchar(255) DEFAULT NULL,
  `criado_em` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `atualizado_em` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `empresa_id` (`empresa_id`),
  KEY `usuario_id` (`usuario_id`),
  CONSTRAINT `saas_chamados_ibfk_1` FOREIGN KEY (`empresa_id`) REFERENCES `saas_empresas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `saas_chamados_ibfk_2` FOREIGN KEY (`usuario_id`) REFERENCES `saas_usuarios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `saas_chamados`
--

LOCK TABLES `saas_chamados` WRITE;
/*!40000 ALTER TABLE `saas_chamados` DISABLE KEYS */;
/*!40000 ALTER TABLE `saas_chamados` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `saas_empresas`
--

DROP TABLE IF EXISTS `saas_empresas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `saas_empresas` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nome_fantasia` varchar(150) NOT NULL,
  `email_financeiro` varchar(150) NOT NULL,
  `status_assinatura` enum('ativo','suspenso','teste') DEFAULT 'teste',
  `data_expiracao` date NOT NULL,
  `cobranca_automatica` tinyint(1) DEFAULT '1',
  `mp_preapproval_id` varchar(100) DEFAULT NULL,
  `recurso_estoque` tinyint(1) DEFAULT '0',
  `recurso_transferencias` tinyint(1) DEFAULT '0',
  `criado_em` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `saas_empresas`
--

LOCK TABLES `saas_empresas` WRITE;
/*!40000 ALTER TABLE `saas_empresas` DISABLE KEYS */;
INSERT INTO `saas_empresas` VALUES (1,'Empresa Alfa TI','financeiro@alfa.com','ativo','2028-12-31',0,NULL,1,1,'2026-07-22 18:24:32'),(2,'Empresa Beta Construções','financeiro@beta.com','ativo','2026-08-22',1,'sub_beta_12345_test',0,0,'2026-07-22 18:24:32'),(3,'Clínica Sorriso Feliz','financeiro@sorriso.com','teste','2026-08-05',1,NULL,1,0,'2026-07-22 18:24:32'),(4,'Empresa de Teste 6a6219d7215ad','financeiro@6a6219d7215b1.com','teste','2026-08-06',1,NULL,0,0,'2026-07-23 13:40:39'),(5,'Empresa de Teste 6a621a115eaef','financeiro@6a621a115eaf1.com','teste','2026-08-06',1,NULL,0,0,'2026-07-23 13:41:37');
/*!40000 ALTER TABLE `saas_empresas` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `saas_mensagens`
--

DROP TABLE IF EXISTS `saas_mensagens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `saas_mensagens` (
  `id` int NOT NULL AUTO_INCREMENT,
  `chamado_id` int NOT NULL,
  `usuario_id` int NOT NULL,
  `mensagem` text NOT NULL,
  `imagem` varchar(255) DEFAULT NULL,
  `criado_em` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `chamado_id` (`chamado_id`),
  KEY `usuario_id` (`usuario_id`),
  CONSTRAINT `saas_mensagens_ibfk_1` FOREIGN KEY (`chamado_id`) REFERENCES `saas_chamados` (`id`) ON DELETE CASCADE,
  CONSTRAINT `saas_mensagens_ibfk_2` FOREIGN KEY (`usuario_id`) REFERENCES `saas_usuarios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `saas_mensagens`
--

LOCK TABLES `saas_mensagens` WRITE;
/*!40000 ALTER TABLE `saas_mensagens` DISABLE KEYS */;
/*!40000 ALTER TABLE `saas_mensagens` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `saas_movimentacao_estoque`
--

DROP TABLE IF EXISTS `saas_movimentacao_estoque`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `saas_movimentacao_estoque` (
  `id` int NOT NULL AUTO_INCREMENT,
  `produto_id` int NOT NULL,
  `tipo` enum('entrada','saida') NOT NULL,
  `quantidade` int NOT NULL,
  `motivo` varchar(255) NOT NULL,
  `usuario_id` int NOT NULL,
  `chamado_id` int DEFAULT NULL,
  `criado_em` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `produto_id` (`produto_id`),
  KEY `usuario_id` (`usuario_id`),
  KEY `chamado_id` (`chamado_id`),
  CONSTRAINT `saas_movimentacao_estoque_ibfk_1` FOREIGN KEY (`produto_id`) REFERENCES `saas_produtos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `saas_movimentacao_estoque_ibfk_2` FOREIGN KEY (`usuario_id`) REFERENCES `saas_usuarios` (`id`) ON DELETE CASCADE,
  CONSTRAINT `saas_movimentacao_estoque_ibfk_3` FOREIGN KEY (`chamado_id`) REFERENCES `saas_chamados` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `saas_movimentacao_estoque`
--

LOCK TABLES `saas_movimentacao_estoque` WRITE;
/*!40000 ALTER TABLE `saas_movimentacao_estoque` DISABLE KEYS */;
/*!40000 ALTER TABLE `saas_movimentacao_estoque` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `saas_ponto_registros`
--

DROP TABLE IF EXISTS `saas_ponto_registros`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `saas_ponto_registros` (
  `id` int NOT NULL AUTO_INCREMENT,
  `empresa_id` int NOT NULL,
  `usuario_id` int NOT NULL,
  `nsr` int NOT NULL,
  `cpf` varchar(14) DEFAULT NULL,
  `tipo_registro` enum('entrada','saida_almoco','retorno_almoco','saida') NOT NULL,
  `data_hora` datetime NOT NULL,
  `hash_comprovante` varchar(64) NOT NULL,
  `ip_origem` varchar(45) DEFAULT NULL,
  `criado_em` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `saas_ponto_registros`
--

LOCK TABLES `saas_ponto_registros` WRITE;
/*!40000 ALTER TABLE `saas_ponto_registros` DISABLE KEYS */;
/*!40000 ALTER TABLE `saas_ponto_registros` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `saas_produtos`
--

DROP TABLE IF EXISTS `saas_produtos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `saas_produtos` (
  `id` int NOT NULL AUTO_INCREMENT,
  `empresa_id` int NOT NULL,
  `codigo` varchar(50) NOT NULL,
  `nome` varchar(150) NOT NULL,
  `codigo_barras` varchar(100) DEFAULT NULL,
  `categoria` varchar(50) NOT NULL,
  `quantidade` int NOT NULL DEFAULT '0',
  `estoque_minimo` int NOT NULL DEFAULT '5',
  `criado_em` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `atualizado_em` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_empresa_codigo` (`empresa_id`,`codigo`),
  CONSTRAINT `saas_produtos_ibfk_1` FOREIGN KEY (`empresa_id`) REFERENCES `saas_empresas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `saas_produtos`
--

LOCK TABLES `saas_produtos` WRITE;
/*!40000 ALTER TABLE `saas_produtos` DISABLE KEYS */;
/*!40000 ALTER TABLE `saas_produtos` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `saas_setores`
--

DROP TABLE IF EXISTS `saas_setores`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `saas_setores` (
  `id` int NOT NULL AUTO_INCREMENT,
  `empresa_id` int NOT NULL,
  `nome` varchar(100) NOT NULL,
  `criado_em` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `empresa_id` (`empresa_id`),
  CONSTRAINT `saas_setores_ibfk_1` FOREIGN KEY (`empresa_id`) REFERENCES `saas_empresas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `saas_setores`
--

LOCK TABLES `saas_setores` WRITE;
/*!40000 ALTER TABLE `saas_setores` DISABLE KEYS */;
/*!40000 ALTER TABLE `saas_setores` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `saas_transferencias`
--

DROP TABLE IF EXISTS `saas_transferencias`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `saas_transferencias` (
  `id` int NOT NULL AUTO_INCREMENT,
  `produto_id` int NOT NULL,
  `funcionario_id` int NOT NULL,
  `quantidade` int NOT NULL DEFAULT '1',
  `data_transferencia` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `status` enum('entregue','devolvido') DEFAULT 'entregue',
  `data_devolucao` timestamp NULL DEFAULT NULL,
  `observacao` varchar(255) DEFAULT NULL,
  `usuario_id` int NOT NULL,
  PRIMARY KEY (`id`),
  KEY `produto_id` (`produto_id`),
  KEY `funcionario_id` (`funcionario_id`),
  KEY `usuario_id` (`usuario_id`),
  CONSTRAINT `saas_transferencias_ibfk_1` FOREIGN KEY (`produto_id`) REFERENCES `saas_produtos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `saas_transferencias_ibfk_2` FOREIGN KEY (`funcionario_id`) REFERENCES `saas_usuarios` (`id`) ON DELETE CASCADE,
  CONSTRAINT `saas_transferencias_ibfk_3` FOREIGN KEY (`usuario_id`) REFERENCES `saas_usuarios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `saas_transferencias`
--

LOCK TABLES `saas_transferencias` WRITE;
/*!40000 ALTER TABLE `saas_transferencias` DISABLE KEYS */;
/*!40000 ALTER TABLE `saas_transferencias` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `saas_usuarios`
--

DROP TABLE IF EXISTS `saas_usuarios`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `saas_usuarios` (
  `id` int NOT NULL AUTO_INCREMENT,
  `empresa_id` int NOT NULL,
  `email` varchar(100) NOT NULL,
  `senha` varchar(255) NOT NULL,
  `nome` varchar(100) NOT NULL DEFAULT '',
  `tipo` enum('master','funcionario') DEFAULT 'funcionario',
  `setor_id` int DEFAULT NULL,
  `funcao` varchar(100) DEFAULT '',
  `criado_em` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `super_admin` tinyint(1) DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  KEY `empresa_id` (`empresa_id`),
  KEY `setor_id` (`setor_id`),
  CONSTRAINT `saas_usuarios_ibfk_1` FOREIGN KEY (`empresa_id`) REFERENCES `saas_empresas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `saas_usuarios_ibfk_2` FOREIGN KEY (`setor_id`) REFERENCES `saas_setores` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `saas_usuarios`
--

LOCK TABLES `saas_usuarios` WRITE;
/*!40000 ALTER TABLE `saas_usuarios` DISABLE KEYS */;
INSERT INTO `saas_usuarios` VALUES (1,1,'admin@alfa.com','123456','Admin Alfa','master',NULL,'Diretor de TI','2026-07-22 18:24:32',1),(2,1,'funcionario@alfa.com','123456','Funcionario Alfa','funcionario',NULL,'Suporte N1','2026-07-22 18:24:32',0),(3,2,'admin@beta.com','123456','Admin Beta','master',NULL,'Dono','2026-07-22 18:24:33',0),(4,3,'dentista@sorriso.com','123456','Dra. Ana','master',NULL,'Dentista Chefe','2026-07-22 18:24:33',0),(5,4,'admin@6a6219e0089df.com','123456','Dono Teste','master',NULL,'','2026-07-23 13:40:48',0),(6,5,'admin@6a621a11eb76b.com','123456','Dono Teste','master',NULL,'','2026-07-23 13:41:37',0);
/*!40000 ALTER TABLE `saas_usuarios` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `setores`
--

DROP TABLE IF EXISTS `setores`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `setores` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nome` varchar(100) NOT NULL,
  `criado_em` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `nome` (`nome`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `setores`
--

LOCK TABLES `setores` WRITE;
/*!40000 ALTER TABLE `setores` DISABLE KEYS */;
INSERT INTO `setores` VALUES (1,'Recepção','2026-07-03 11:53:51'),(2,'Financeiro','2026-07-03 11:53:51'),(3,'Enfermagem','2026-07-03 11:53:51'),(4,'Médico','2026-07-03 11:53:51'),(5,'TI','2026-07-03 11:53:51'),(6,'Administrativo','2026-07-03 11:53:51'),(7,'Segurança Do Trabalho','2026-07-03 12:01:06');
/*!40000 ALTER TABLE `setores` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `transferencias`
--

DROP TABLE IF EXISTS `transferencias`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `transferencias` (
  `id` int NOT NULL AUTO_INCREMENT,
  `produto_id` int NOT NULL,
  `funcionario_id` int NOT NULL,
  `quantidade` int NOT NULL DEFAULT '1',
  `data_transferencia` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `status` enum('entregue','devolvido') DEFAULT 'entregue',
  `data_devolucao` timestamp NULL DEFAULT NULL,
  `observacao` varchar(255) DEFAULT NULL,
  `usuario_id` int NOT NULL,
  PRIMARY KEY (`id`),
  KEY `produto_id` (`produto_id`),
  KEY `funcionario_id` (`funcionario_id`),
  KEY `usuario_id` (`usuario_id`),
  CONSTRAINT `transferencias_ibfk_1` FOREIGN KEY (`produto_id`) REFERENCES `produtos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `transferencias_ibfk_2` FOREIGN KEY (`funcionario_id`) REFERENCES `usuarios` (`id`),
  CONSTRAINT `transferencias_ibfk_3` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `transferencias`
--

LOCK TABLES `transferencias` WRITE;
/*!40000 ALTER TABLE `transferencias` DISABLE KEYS */;
INSERT INTO `transferencias` VALUES (2,6,4,1,'2026-07-14 19:51:17','devolvido','2026-07-14 19:51:17','Teste de transferência via CLI',1);
/*!40000 ALTER TABLE `transferencias` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `usuarios`
--

DROP TABLE IF EXISTS `usuarios`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `usuarios` (
  `id` int NOT NULL AUTO_INCREMENT,
  `email` varchar(100) NOT NULL,
  `senha` varchar(255) NOT NULL,
  `criado_em` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `nome` varchar(100) NOT NULL DEFAULT '',
  `tipo` enum('master','funcionario') DEFAULT 'funcionario',
  `setor_id` int DEFAULT NULL,
  `funcao` varchar(100) DEFAULT '',
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `usuarios`
--

LOCK TABLES `usuarios` WRITE;
/*!40000 ALTER TABLE `usuarios` DISABLE KEYS */;
INSERT INTO `usuarios` VALUES (1,'ti@vixmed.com.br','123456','2026-07-03 13:23:34','TI Vixmed','master',NULL,'Administrador de TI'),(2,'tecnico1@vixmed.com.br','Do@vix24!','2026-07-03 15:02:45','Tecnico1','funcionario',6,'Segurança Do Trabalho'),(3,'tecnico2@vixmed.com.br','Ml@vix24','2026-07-03 17:50:40','marianna lima dos santos','funcionario',7,'Segurança Do Trabalho'),(4,'comercial@vixmed.com.br','Bt@vix24!','2026-07-06 17:04:40','Bianca Teixeira Lima Rocha','funcionario',6,'Comercial'),(5,'liberacao@vixmed.com.br','123456','2026-07-17 17:22:24','Stephany Luzia Pereira Albino','funcionario',6,'Gerente');
/*!40000 ALTER TABLE `usuarios` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-07-30 13:43:18
