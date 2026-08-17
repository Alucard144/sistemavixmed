<?php
class SmtpClient {
    private $host;
    private $port;
    private $secure;
    private $username;
    private $password;
    private $socket;
    private $logs = [];

    public function __construct($host, $port, $secure, $username, $password) {
        $this->host = $host;
        $this->port = intval($port);
        $this->secure = strtolower($secure);
        $this->username = $username;
        $this->password = $password;
    }

    private function read() {
        $data = '';
        while ($str = fgets($this->socket, 515)) {
            $data .= $str;
            if (substr($str, 3, 1) == ' ') {
                break;
            }
        }
        $this->logs[] = "S: " . trim($data);
        return $data;
    }

    private function write($data) {
        $this->logs[] = "C: " . trim($data);
        fwrite($this->socket, $data . "\r\n");
    }

    public function send($to, $subject, $body, $fromName = 'Vixmed CRM') {
        $protocol = ($this->secure === 'ssl') ? 'ssl://' : '';
        $this->socket = @stream_socket_client(
            $protocol . $this->host . ':' . $this->port,
            $errno,
            $errstr,
            15,
            STREAM_CLIENT_CONNECT
        );

        if (!$this->socket) {
            throw new Exception("Falha na conexão SMTP: $errstr ($errno)");
        }

        $this->read(); // Greeting

        $this->write("EHLO " . ($_SERVER['SERVER_NAME'] ?? 'localhost'));
        $this->read();

        if ($this->secure === 'tls') {
            $this->write("STARTTLS");
            $res = $this->read();
            if (strpos($res, '220') === false) {
                throw new Exception("STARTTLS falhou: " . $res);
            }
            if (!stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new Exception("Falha ao habilitar criptografia TLS.");
            }
            $this->write("EHLO " . ($_SERVER['SERVER_NAME'] ?? 'localhost'));
            $this->read();
        }

        $this->write("AUTH LOGIN");
        $res = $this->read();
        if (strpos($res, '334') === false) {
            throw new Exception("Erro de autenticação SMTP AUTH LOGIN: " . $res);
        }

        $this->write(base64_encode($this->username));
        $res = $this->read();
        if (strpos($res, '334') === false) {
            throw new Exception("Erro no usuário SMTP: " . $res);
        }

        $this->write(base64_encode($this->password));
        $res = $this->read();
        if (strpos($res, '235') === false) {
            throw new Exception("Falha de autenticação SMTP: " . $res);
        }

        $this->write("MAIL FROM: <" . $this->username . ">");
        $this->read();

        $this->write("RCPT TO: <" . $to . ">");
        $res = $this->read();
        if (strpos($res, '250') === false && strpos($res, '251') === false) {
            throw new Exception("Destinatário rejeitado: " . $res);
        }

        $this->write("DATA");
        $res = $this->read();
        if (strpos($res, '354') === false) {
            throw new Exception("Falha no comando DATA: " . $res);
        }

        // Check if body contains the logo-esquerda image reference
        $hasLogo = (strpos($body, 'logo-esquerda.png') !== false);
        $boundary = "----=_Part_" . md5(uniqid(time()));

        if ($hasLogo) {
            // Replace image source with cid reference
            $body = preg_replace('/src="[^"]*logo-esquerda\.png[^"]*"/i', 'src="cid:logo_vixmed"', $body);

            // Read image and encode in base64
            $logoPath = dirname(__FILE__) . '/logo-esquerda.png';
            if (file_exists($logoPath)) {
                $logoData = base64_encode(file_get_contents($logoPath));
            } else {
                $hasLogo = false; // logo not found locally
            }
        }

        $headers = [
            "To: <$to>",
            "From: =?UTF-8?B?" . base64_encode($fromName) . "?= <" . $this->username . ">",
            "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=",
            "Date: " . date('r'),
            "Message-ID: <" . time() . "-" . md5($this->username . $to) . "@vixmed.com.br>",
            "X-Mailer: Vixmed CRM SMTP Client",
            "MIME-Version: 1.0"
        ];

        if ($hasLogo) {
            $headers[] = "Content-Type: multipart/related; boundary=\"$boundary\"";
            $headers[] = ""; // Header end
            
            // Text part
            $headers[] = "--$boundary";
            $headers[] = "Content-Type: text/html; charset=utf-8";
            $headers[] = "Content-Transfer-Encoding: 8bit";
            $headers[] = "";
            $headers[] = $body;
            $headers[] = "";
            
            // Image part
            $headers[] = "--$boundary";
            $headers[] = "Content-Type: image/png; name=\"logo-esquerda.png\"";
            $headers[] = "Content-Transfer-Encoding: base64";
            $headers[] = "Content-ID: <logo_vixmed>";
            $headers[] = "Content-Disposition: inline; filename=\"logo-esquerda.png\"";
            $headers[] = "";
            $headers[] = chunk_split($logoData);
            $headers[] = "";
            $headers[] = "--$boundary--";
        } else {
            $headers[] = "Content-Type: text/html; charset=utf-8";
            $headers[] = "";
            $headers[] = $body;
        }

        $this->write(implode("\r\n", $headers));
        $this->write(".");
        $res = $this->read();
        if (strpos($res, '250') === false) {
            throw new Exception("Falha no envio da mensagem: " . $res);
        }

        $this->write("QUIT");
        $this->read();

        fclose($this->socket);
        return true;
    }

    public function getLogs() {
        return $this->logs;
    }
}
?>
