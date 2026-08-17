<?php
class ImapClient {
    private $host;
    private $port;
    private $secure;
    private $username;
    private $password;
    private $socket;
    private $tagCount = 1;
    private $logs = [];

    public function __construct($host, $port, $secure, $username, $password) {
        $this->host = $host;
        $this->port = intval($port);
        $this->secure = strtolower($secure);
        $this->username = $username;
        $this->password = $password;
    }

    private function getTag() {
        return "A" . ($this->tagCount++);
    }

    private function readResponse($tag) {
        $response = '';
        while ($str = fgets($this->socket, 8192)) {
            $response .= $str;
            if (strpos($str, "$tag OK") !== false) {
                break;
            }
            if (strpos($str, "$tag NO") !== false || strpos($str, "$tag BAD") !== false) {
                throw new Exception("Erro IMAP: $str");
            }
            $info = stream_get_meta_data($this->socket);
            if ($info['timed_out']) {
                throw new Exception("Timeout na leitura da resposta do servidor IMAP.");
            }
        }
        $this->logs[] = "S: " . trim($response);
        return $response;
    }

    public function getEmails($mailbox = 'INBOX', $limit = null) {
        $protocol = ($this->secure === 'ssl') ? 'ssl://' : '';
        $this->socket = @stream_socket_client($protocol . $this->host . ':' . $this->port, $errno, $errstr, 15, STREAM_CLIENT_CONNECT);

        if (!$this->socket) {
            throw new Exception("Falha na conexão IMAP: $errstr ($errno)");
        }

        stream_set_timeout($this->socket, 5);

        fgets($this->socket, 1024); // Greeting

        $tag = $this->getTag();
        fwrite($this->socket, "$tag LOGIN " . $this->username . " " . $this->password . "\r\n");
        $this->readResponse($tag);

        $tag = $this->getTag();
        // Converter nomes de pasta comuns se necessário
        $folderName = $mailbox;
        if ($mailbox === 'INBOX') $folderName = 'INBOX';
        else if ($mailbox === 'sent') $folderName = 'INBOX.Sent';
        else if ($mailbox === 'spam') $folderName = 'INBOX.Spam';
        else if ($mailbox === 'trash') $folderName = 'INBOX.Trash';

        fwrite($this->socket, "$tag SELECT $folderName\r\n");
        try {
            $selectRes = $this->readResponse($tag);
        } catch (Exception $e) {
            // Fallback se a pasta específica não existir (tentar sem prefixo INBOX)
            $tag = $this->getTag();
            $folderName = ($mailbox === 'sent') ? 'Sent' : (($mailbox === 'spam') ? 'Spam' : (($mailbox === 'trash') ? 'Trash' : $mailbox));
            fwrite($this->socket, "$tag SELECT $folderName\r\n");
            $selectRes = $this->readResponse($tag);
        }

        preg_match('/\* (\d+) EXISTS/i', $selectRes, $matches);
        $exists = isset($matches[1]) ? intval($matches[1]) : 0;

        $emails = [];
        if ($exists > 0) {
            $start = ($limit === null || $limit <= 0) ? 1 : max(1, $exists - $limit + 1);
            $end = $exists;

            $tag = $this->getTag();
            fwrite($this->socket, "$tag FETCH $start:$end (BODY[HEADER.FIELDS (SUBJECT FROM DATE)])\r\n");
            
            $fetchRes = '';
            while ($str = fgets($this->socket, 8192)) {
                $fetchRes .= $str;
                if (strpos($str, "$tag OK") !== false) {
                    break;
                }
                $info = stream_get_meta_data($this->socket);
                if ($info['timed_out']) {
                    break;
                }
            }

            $parts = preg_split('/\* \d+ FETCH/i', $fetchRes);
            array_shift($parts); // remover a primeira parte vazia

            $index = 0;
            foreach ($parts as $part) {
                $subject = "Sem Assunto";
                $from = "Desconhecido";
                $date = "";

                if (preg_match('/Subject:\s*(.*)/i', $part, $subMatch)) {
                    $subject = trim($subMatch[1]);
                }
                if (preg_match('/From:\s*(.*)/i', $part, $fromMatch)) {
                    $from = trim($fromMatch[1]);
                    $from = preg_replace('/^"|"$/', '', $from);
                }
                if (preg_match('/Date:\s*(.*)/i', $part, $dateMatch)) {
                    $date = trim($dateMatch[1]);
                }

                // Decodificar cabeçalhos MIME
                if (function_exists('mb_decode_mimeheader')) {
                    $subject = mb_decode_mimeheader($subject);
                    $from = mb_decode_mimeheader($from);
                }

                $uid = $start + $index;
                $emails[] = [
                    'id' => $uid,
                    'remetente' => $from,
                    'assunto' => $subject,
                    'data' => (strtotime($date) !== false) ? date('d/m/Y H:i', strtotime($date)) : date('d/m/Y H:i'),
                    'conteudo' => "Carregando conteúdo..."
                ];
                $index++;
            }

            $emails = array_reverse($emails);
        }

        $tag = $this->getTag();
        fwrite($this->socket, "$tag LOGOUT\r\n");
        fgets($this->socket, 1024);
        fclose($this->socket);

        return $emails;
    }

    public function getEmailBody($uid, $mailbox = 'INBOX') {
        $protocol = ($this->secure === 'ssl') ? 'ssl://' : '';
        $this->socket = @stream_socket_client($protocol . $this->host . ':' . $this->port, $errno, $errstr, 15, STREAM_CLIENT_CONNECT);

        if (!$this->socket) {
            throw new Exception("Falha na conexão IMAP: $errstr ($errno)");
        }

        stream_set_timeout($this->socket, 5);

        fgets($this->socket, 1024); // Greeting

        $tag = $this->getTag();
        fwrite($this->socket, "$tag LOGIN " . $this->username . " " . $this->password . "\r\n");
        $this->readResponse($tag);

        $tag = $this->getTag();
        $folderName = $mailbox;
        if ($mailbox === 'INBOX') $folderName = 'INBOX';
        else if ($mailbox === 'sent') $folderName = 'INBOX.Sent';
        else if ($mailbox === 'spam') $folderName = 'INBOX.Spam';
        else if ($mailbox === 'trash') $folderName = 'INBOX.Trash';

        fwrite($this->socket, "$tag SELECT $folderName\r\n");
        try {
            $this->readResponse($tag);
        } catch (Exception $e) {
            $tag = $this->getTag();
            $folderName = ($mailbox === 'sent') ? 'Sent' : (($mailbox === 'spam') ? 'Spam' : (($mailbox === 'trash') ? 'Trash' : $mailbox));
            fwrite($this->socket, "$tag SELECT $folderName\r\n");
            $this->readResponse($tag);
        }

        // Tentar obter corpo em HTML ou Texto
        $tag = $this->getTag();
        fwrite($this->socket, "$tag FETCH $uid (BODY[1])\r\n");
        
        $fetchRes = '';
        while ($str = fgets($this->socket, 8192)) {
            $fetchRes .= $str;
            if (strpos($str, "$tag OK") !== false) {
                break;
            }
            $info = stream_get_meta_data($this->socket);
            if ($info['timed_out']) {
                break;
            }
        }

        $body = $fetchRes;
        if (preg_match('/\{\d+\}\r\n(.*)\)\r\n' . $tag . ' OK/is', $fetchRes, $matches)) {
            $body = $matches[1];
        } else {
            $lines = explode("\n", $fetchRes);
            if (count($lines) > 2) {
                array_shift($lines);
                array_pop($lines);
                array_pop($lines);
                $body = implode("\n", $lines);
            }
        }

        if (function_exists('quoted_printable_decode')) {
            $body = quoted_printable_decode($body);
        }

        // Se o corpo retornar vazio, tentar buscar o BODY completo
        if (empty(trim($body))) {
            $tag = $this->getTag();
            fwrite($this->socket, "$tag FETCH $uid (BODY[TEXT])\r\n");
            $fetchRes = '';
            while ($str = fgets($this->socket, 8192)) {
                $fetchRes .= $str;
                if (strpos($str, "$tag OK") !== false) {
                    break;
                }
                $info = stream_get_meta_data($this->socket);
                if ($info['timed_out']) {
                    break;
                }
            }
            if (preg_match('/\{\d+\}\r\n(.*)\)\r\n' . $tag . ' OK/is', $fetchRes, $matches)) {
                $body = $matches[1];
            }
            if (function_exists('quoted_printable_decode')) {
                $body = quoted_printable_decode($body);
            }
        }

        $tag = $this->getTag();
        fwrite($this->socket, "$tag LOGOUT\r\n");
        fgets($this->socket, 1024);
        fclose($this->socket);

        return $body;
    }
}
?>
