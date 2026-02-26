<?php

ini_set('display_errors', '0');
ob_start();

register_shutdown_function(function () {
    $error = error_get_last();
    if ($error === null) {
        return;
    }

    $errorType = isset($error['type']) ? $error['type'] : 0;
    $fatalTypes = array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR);
    if (!in_array($errorType, $fatalTypes, true)) {
        return;
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    }

    echo json_encode(array(
        'success' => false,
        'message' => 'Erro interno no envio de email.'
    ));
});

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(array(
        'success' => false,
        'message' => 'Metodo nao permitido.'
    ));
    exit;
}

$rawInput = file_get_contents('php://input');
$decoded = json_decode($rawInput ? $rawInput : '', true);
$data = is_array($decoded) ? $decoded : $_POST;

$name = isset($data['name']) ? trim((string)$data['name']) : '';
$email = isset($data['email']) ? trim((string)$data['email']) : '';
$phone = isset($data['phone']) ? trim((string)$data['phone']) : '';
$message = isset($data['message']) ? trim((string)$data['message']) : '';

if ($name === '' || $email === '' || $message === '') {
    http_response_code(422);
    echo json_encode(array(
        'success' => false,
        'message' => 'Preencha nome, email e mensagem.'
    ));
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(422);
    echo json_encode(array(
        'success' => false,
        'message' => 'Informe um email valido.'
    ));
    exit;
}

// Credenciais SMTP atuais.
$smtpHost = 'email-ssl.com.br';
$smtpPort = 587;
$smtpSecure = 'tls';
$smtpUser = 'noreply@equipedigital.com';
$smtpPass = '@Noreply2023*';
$smtpFrom = $smtpUser;
//$smtpTo = 'suporte@equipedigital.com.br';
$smtpTo = 'prevenir@prevenirsst.com.br';

$subject = 'Novo contato - Site Prevenir';
$bodyText = "Novo contato pelo site:\r\n\r\n"
    . "Nome: {$name}\r\n"
    . "Email: {$email}\r\n"
    . "Telefone: " . ($phone !== '' ? $phone : 'Nao informado') . "\r\n\r\n"
    . "Mensagem:\r\n{$message}\r\n";

$result = sendBySmtp(array(
    'host' => $smtpHost,
    'port' => $smtpPort,
    'secure' => $smtpSecure,
    'username' => $smtpUser,
    'password' => $smtpPass,
    'from' => $smtpFrom,
    'to' => $smtpTo,
    'replyTo' => $email,
    'subject' => $subject,
    'bodyText' => $bodyText
));

if (!isset($result['success']) || !$result['success']) {
    http_response_code(500);
}

echo json_encode($result);

function sendBySmtp($config)
{
    $secure = isset($config['secure']) ? strtolower((string)$config['secure']) : '';
    $host = isset($config['host']) ? $config['host'] : '';
    $port = isset($config['port']) ? (int)$config['port'] : 0;

    $remoteSocket = ($secure === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
    $context = stream_context_create(array(
        'ssl' => array(
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true,
            'SNI_enabled' => true,
            'peer_name' => $host
        )
    ));
    $socket = @stream_socket_client($remoteSocket, $errno, $errstr, 20, STREAM_CLIENT_CONNECT, $context);

    if (!$socket) {
        return array(
            'success' => false,
            'message' => 'Nao foi possivel conectar ao servidor SMTP.'
        );
    }

    stream_set_timeout($socket, 20);

    $greeting = smtpRead($socket);
    if (!smtpOk($greeting, array('220'))) {
        fclose($socket);
        return array(
            'success' => false,
            'message' => 'Resposta inicial invalida do SMTP.'
        );
    }

    $hostname = function_exists('gethostname') ? gethostname() : 'localhost';
    if (!$hostname) {
        $hostname = 'localhost';
    }

    $ehlo = smtpCommand($socket, 'EHLO ' . $hostname);
    if (!smtpOk($ehlo, array('250'))) {
        $helo = smtpCommand($socket, 'HELO ' . $hostname);
        if (!smtpOk($helo, array('250'))) {
            fclose($socket);
            return array(
                'success' => false,
                'message' => 'Falha no handshake com SMTP.'
            );
        }
    }

    if ($secure === 'tls') {
        $startTls = smtpCommand($socket, 'STARTTLS');
        if (!smtpOk($startTls, array('220'))) {
            fclose($socket);
            return array(
                'success' => false,
                'message' => 'Servidor nao aceitou STARTTLS.'
            );
        }

        $cryptoEnabled = enableTlsCrypto($socket);
        if ($cryptoEnabled !== true) {
            fclose($socket);
            return array(
                'success' => false,
                'message' => 'Falha ao habilitar criptografia TLS.'
            );
        }

        $ehloTls = smtpCommand($socket, 'EHLO ' . $hostname);
        if (!smtpOk($ehloTls, array('250'))) {
            fclose($socket);
            return array(
                'success' => false,
                'message' => 'Falha no EHLO apos STARTTLS.'
            );
        }
    }

    $auth = smtpCommand($socket, 'AUTH LOGIN');
    if (!smtpOk($auth, array('334'))) {
        fclose($socket);
        return array(
            'success' => false,
            'message' => 'Servidor nao aceitou autenticacao SMTP.'
        );
    }

    $username = isset($config['username']) ? (string)$config['username'] : '';
    $userStep = smtpCommand($socket, base64_encode($username));
    if (!smtpOk($userStep, array('334'))) {
        fclose($socket);
        return array(
            'success' => false,
            'message' => 'Usuario SMTP rejeitado.'
        );
    }

    $password = isset($config['password']) ? (string)$config['password'] : '';
    $passStep = smtpCommand($socket, base64_encode($password));
    if (!smtpOk($passStep, array('235'))) {
        fclose($socket);
        return array(
            'success' => false,
            'message' => 'Senha SMTP rejeitada.'
        );
    }

    $from = isset($config['from']) ? $config['from'] : '';
    $mailFrom = smtpCommand($socket, 'MAIL FROM:<' . $from . '>');
    if (!smtpOk($mailFrom, array('250'))) {
        fclose($socket);
        return array(
            'success' => false,
            'message' => 'Erro no remetente do email.'
        );
    }

    $to = isset($config['to']) ? $config['to'] : '';
    $rcptTo = smtpCommand($socket, 'RCPT TO:<' . $to . '>');
    if (!smtpOk($rcptTo, array('250', '251'))) {
        fclose($socket);
        return array(
            'success' => false,
            'message' => 'Erro no destinatario do email.'
        );
    }

    $dataStart = smtpCommand($socket, 'DATA');
    if (!smtpOk($dataStart, array('354'))) {
        fclose($socket);
        return array(
            'success' => false,
            'message' => 'Servidor nao aceitou corpo do email.'
        );
    }

    $replyTo = isset($config['replyTo']) ? $config['replyTo'] : '';
    $subject = isset($config['subject']) ? (string)$config['subject'] : '';
    $bodyText = isset($config['bodyText']) ? (string)$config['bodyText'] : '';

    $headers = array();
    $headers[] = 'From: Prevenir Site <' . $from . '>';
    $headers[] = 'To: <' . $to . '>';
    $headers[] = 'Reply-To: <' . $replyTo . '>';
    $headers[] = 'Subject: ' . encodeHeader($subject);
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-Type: text/plain; charset=UTF-8';
    $headers[] = 'Content-Transfer-Encoding: 8bit';
    $headers[] = 'Date: ' . date(DATE_RFC2822);

    $payload = implode("\r\n", $headers) . "\r\n\r\n" . normalizeBody($bodyText) . "\r\n.";
    fwrite($socket, $payload . "\r\n");
    $dataEnd = smtpRead($socket);

    smtpCommand($socket, 'QUIT');
    fclose($socket);

    if (!smtpOk($dataEnd, array('250'))) {
        return array(
            'success' => false,
            'message' => 'Servidor SMTP rejeitou o envio.'
        );
    }

    return array(
        'success' => true,
        'message' => 'Mensagem enviada com sucesso.'
    );
}

function smtpCommand($socket, $command)
{
    fwrite($socket, $command . "\r\n");
    return smtpRead($socket);
}

function smtpRead($socket)
{
    $response = '';
    while (!feof($socket)) {
        $line = fgets($socket, 515);
        if ($line === false) {
            break;
        }
        $response .= $line;
        if (preg_match('/^\d{3}\s/', $line) === 1) {
            break;
        }
    }
    return $response;
}

function smtpOk($response, $expectedCodes)
{
    $code = substr(trim((string)$response), 0, 3);
    return in_array($code, $expectedCodes, true);
}

function normalizeBody($text)
{
    $text = str_replace(array("\r\n", "\r"), "\n", (string)$text);
    $fixed = preg_replace('/^\./m', '..', $text);
    if ($fixed !== null) {
        $text = $fixed;
    }
    return str_replace("\n", "\r\n", $text);
}

function encodeHeader($value)
{
    return '=?UTF-8?B?' . base64_encode((string)$value) . '?=';
}

function enableTlsCrypto($socket)
{
    $methods = array();

    if (defined('STREAM_CRYPTO_METHOD_TLS_CLIENT')) {
        $methods[] = STREAM_CRYPTO_METHOD_TLS_CLIENT;
    }
    if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
        $methods[] = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
    }
    if (defined('STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT')) {
        $methods[] = STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT;
    }
    if (defined('STREAM_CRYPTO_METHOD_TLSv1_0_CLIENT')) {
        $methods[] = STREAM_CRYPTO_METHOD_TLSv1_0_CLIENT;
    }

    if (empty($methods)) {
        return false;
    }

    $methods = array_unique($methods);
    foreach ($methods as $method) {
        $enabled = @stream_socket_enable_crypto($socket, true, $method);
        if ($enabled === true) {
            return true;
        }
    }

    return false;
}
