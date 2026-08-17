<?php
session_start();
unset($_SESSION['crm_logado']);
unset($_SESSION['crm_usuario_id']);
unset($_SESSION['crm_usuario_nome']);
unset($_SESSION['crm_usuario_email']);
header("Location: index.php");
exit();
?>
