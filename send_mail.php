<?php
declare(strict_types=1);

ini_set('display_errors', '0');
ob_start();

register_shutdown_function(static function (): void {
    $error = error_get_last();
    if ($error === null) {
        return;
    }

    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if (!in_array($error['type'] ?? 0, $fatalTypes, true)) {
        return;
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }

    echo json_encode([
        'success' => false,
        'message' => 'Erro interno no envio de email.'
    ]);
});

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Metodo nao permitido.'
    ]);
    exit;
}

$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput ?: '', true);
if (!is_array($data)) {
    $data = $_POST;
}

$name = trim((string)($data['name'] ?? ''));
$email = trim((string)($data['email'] ?? ''));
$phone = trim((string)($data['phone'] ?? ''));
$message = trim((string)($data['message'] ?? ''));

if ($name === '' || $email === '' || $message === '') {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => 'Preencha nome, email e mensagem.'
    ]);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => 'Informe um email valido.'
    ]);
    exit;
}

// Credenciais SMTP (igual ao sistema antigo).
$smtpHost = 'email-ssl.com.br';
$smtpPort = 587;
$smtpSecure = 'tls';
$smtpUser = 'noreply@equipedigital.com';
$smtpPass = '@Noreply2023*';
$smtpFrom = $smtpUser;
$smtpTo = 'suporte@equipedigital.com.br';
// $smtpTo = 'prevenir@prevenirsst.com.br';

$subject = 'Novo contato - Site Prevenir';
$bodyText = "Novo contato pelo site:\r\n\r\n"
    . "Nome: {$name}\r\n"
    . "Email: {$email}\r\n"
    . "Telefone: " . ($phone !== '' ? $phone : 'Nao informado') . "\r\n\r\n"
    . "Mensagem:\r\n{$message}\r\n";

$result = sendBySmtp([
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
]);

if (!$result['success']) {
    http_response_code(500);
}

echo json_encode($result);

function sendBySmtp(array $config): array
{
    $secure = strtolower((string)($config['secure'] ?? ''));
    $remoteSocket = ($secure === 'ssl' ? 'ssl://' : '') . $config['host'] . ':' . (int)$config['port'];
    $socket = @stream_socket_client($remoteSocket, $errno, $errstr, 20);

    if (!$socket) {
        return [
            'success' => false,
            'message' => 'Nao foi possivel conectar ao servidor SMTP.'
        ];
    }

    stream_set_timeout($socket, 20);

    $greeting = smtpRead($socket);
    if (!smtpOk($greeting, ['220'])) {
        fclose($socket);
        return [
            'success' => false,
            'message' => 'Resposta inicial invalida do SMTP.'
        ];
    }

    $hostname = gethostname() ?: 'localhost';
    $ehlo = smtpCommand($socket, 'EHLO ' . $hostname);
    if (!smtpOk($ehlo, ['250'])) {
        $helo = smtpCommand($socket, 'HELO ' . $hostname);
        if (!smtpOk($helo, ['250'])) {
            fclose($socket);
            return [
                'success' => false,
                'message' => 'Falha no handshake com SMTP.'
            ];
        }
    }

    if ($secure === 'tls') {
        $startTls = smtpCommand($socket, 'STARTTLS');
        if (!smtpOk($startTls, ['220'])) {
            fclose($socket);
            return [
                'success' => false,
                'message' => 'Servidor nao aceitou STARTTLS.'
            ];
        }

        $cryptoEnabled = @stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        if ($cryptoEnabled !== true) {
            fclose($socket);
            return [
                'success' => false,
                'message' => 'Falha ao habilitar criptografia TLS.'
            ];
        }

        $ehloTls = smtpCommand($socket, 'EHLO ' . $hostname);
        if (!smtpOk($ehloTls, ['250'])) {
            fclose($socket);
            return [
                'success' => false,
                'message' => 'Falha no EHLO apos STARTTLS.'
            ];
        }
    }

    $auth = smtpCommand($socket, 'AUTH LOGIN');
    if (!smtpOk($auth, ['334'])) {
        fclose($socket);
        return [
            'success' => false,
            'message' => 'Servidor nao aceitou autenticacao SMTP.'
        ];
    }

    $userStep = smtpCommand($socket, base64_encode((string)$config['username']));
    if (!smtpOk($userStep, ['334'])) {
        fclose($socket);
        return [
            'success' => false,
            'message' => 'Usuario SMTP rejeitado.'
        ];
    }

    $passStep = smtpCommand($socket, base64_encode((string)$config['password']));
    if (!smtpOk($passStep, ['235'])) {
        fclose($socket);
        return [
            'success' => false,
            'message' => 'Senha SMTP rejeitada.'
        ];
    }

    $mailFrom = smtpCommand($socket, 'MAIL FROM:<' . $config['from'] . '>');
    if (!smtpOk($mailFrom, ['250'])) {
        fclose($socket);
        return [
            'success' => false,
            'message' => 'Erro no remetente do email.'
        ];
    }

    $rcptTo = smtpCommand($socket, 'RCPT TO:<' . $config['to'] . '>');
    if (!smtpOk($rcptTo, ['250', '251'])) {
        fclose($socket);
        return [
            'success' => false,
            'message' => 'Erro no destinatario do email.'
        ];
    }

    $dataStart = smtpCommand($socket, 'DATA');
    if (!smtpOk($dataStart, ['354'])) {
        fclose($socket);
        return [
            'success' => false,
            'message' => 'Servidor nao aceitou corpo do email.'
        ];
    }

    $headers = [];
    $headers[] = 'From: Prevenir Site <' . $config['from'] . '>';
    $headers[] = 'To: <' . $config['to'] . '>';
    $headers[] = 'Reply-To: <' . $config['replyTo'] . '>';
    $headers[] = 'Subject: ' . encodeHeader((string)$config['subject']);
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-Type: text/plain; charset=UTF-8';
    $headers[] = 'Content-Transfer-Encoding: 8bit';
    $headers[] = 'Date: ' . date(DATE_RFC2822);

    $payload = implode("\r\n", $headers) . "\r\n\r\n" . normalizeBody((string)$config['bodyText']) . "\r\n.";
    fwrite($socket, $payload . "\r\n");
    $dataEnd = smtpRead($socket);

    smtpCommand($socket, 'QUIT');
    fclose($socket);

    if (!smtpOk($dataEnd, ['250'])) {
        return [
            'success' => false,
            'message' => 'Servidor SMTP rejeitou o envio.'
        ];
    }

    return [
        'success' => true,
        'message' => 'Mensagem enviada com sucesso.'
    ];
}

function smtpCommand($socket, string $command): string
{
    fwrite($socket, $command . "\r\n");
    return smtpRead($socket);
}

function smtpRead($socket): string
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

function smtpOk(string $response, array $expectedCodes): bool
{
    $code = substr(trim($response), 0, 3);
    return in_array($code, $expectedCodes, true);
}

function normalizeBody(string $text): string
{
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $text = preg_replace('/^\./m', '..', $text) ?? $text;
    return str_replace("\n", "\r\n", $text);
}

function encodeHeader(string $value): string
{
    return '=?UTF-8?B?' . base64_encode($value) . '?=';
}
