<?php
/**
 * Mail Functions - WebService Ping Monitor Agent
 * Version: 1.0.0
 * PHP: 7.4.33
 *
 * Funciones de envío de email vía API HTTP.
 */

if (!function_exists('utf8Normalize')) {
    /**
     * Garantiza que un string sea UTF-8 válido.
     * Si no lo es, asume que viene en Windows-1252 (típico de archivos
     * guardados en ANSI) y lo convierte.
     *
     * @param string $value
     * @return string
     */
    function utf8Normalize($value)
    {
        if (!is_string($value) || $value === '') {
            return $value;
        }

        if (function_exists('mb_check_encoding') && mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }

        if (function_exists('mb_convert_encoding')) {
            return mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
        }

        return preg_replace_callback('/[\x80-\xFF]/', function ($m) {
            $c = ord($m[0]);
            return chr(0xC0 | ($c >> 6)) . chr(0x80 | ($c & 0x3F));
        }, $value);
    }
}

if (!function_exists('sendEmail')) {
    /**
     * Envía un email de notificación mediante la API de correo configurada.
     *
     * @param array  $config Configuración cargada desde config.ini
     * @param string $subject Asunto del email
     * @param string $body    Cuerpo del email en texto plano
     *
     * @return array ['success' => bool, 'httpCode' => int, 'error' => string, 'requestId' => string]
     */
    function sendEmail($config, $subject, $body)
    {
        $requestId = function_exists('random_bytes') ? bin2hex(random_bytes(8)) : uniqid('mail_', true);

        $ch = curl_init();

        $payload = array_map('utf8Normalize', [
            'subject' => $subject,
            'subjet' => $subject,
            'body' => $body,
            'to' => $config['recipient'],
            'replyTo' => $config['replyTo'],
            'requestId' => $requestId,
        ]);

        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($payloadJson === false) {
            logMailEvent($config, 'ERROR', 'Email payload JSON encode failed: ' . json_last_error_msg());
            return [
                'success' => false,
                'httpCode' => 0,
                'error' => 'JSON encode failed: ' . json_last_error_msg(),
                'errorNo' => 0,
                'requestId' => $requestId,
            ];
        }

        curl_setopt($ch, CURLOPT_URL, $config['apiMailUrl']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, 1500);
        curl_setopt($ch, CURLOPT_TIMEOUT_MS, 10000);
        curl_setopt($ch, CURLOPT_NOSIGNAL, 1);
        curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
        curl_setopt($ch, CURLOPT_DNS_CACHE_TIMEOUT, 60); // Cache DNS por 60 segundos
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payloadJson);

        if (!empty($config['sslVerify'])) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            if (!empty($config['caBundle'])) {
                curl_setopt($ch, CURLOPT_CAINFO, $config['caBundle']);
            }
        } else {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        }

        $headers = [
            'Content-Type:application/json',
            'Token:' . trim($config['apiToken']),
            "X-Request-Id:$requestId",
            'Expect:',
            'Connection: close'
        ];

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $response = curl_exec($ch);
        // error_log(json_encode($response, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));

        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        $curlErrno = curl_errno($ch);

        curl_close($ch);

        $success = ($httpCode >= 200 && $httpCode < 300 && $curlErrno === 0);

        if (!$success) {
            $detail = 'Email send failed - HTTP Code: ' . $httpCode . ', Error: ' . $curlError;
            if ($response !== false && $response !== '') {
                $detail .= ', Response: ' . json_encode($response);
            }
            logMailEvent($config, 'ERROR', $detail);
        }

        if ($response !== false) {
            $decoded = json_decode($response, true);
            if (is_array($decoded) && isset($decoded['requestId'])) {
                $requestId = $decoded['requestId'];
            }
        }

        return [
            'success' => $success,
            'httpCode' => $httpCode,
            'error' => $curlError,
            'errorNo' => $curlErrno,
            'requestId' => $requestId,
        ];
    }
}

if (!function_exists('logMailEvent')) {
    /**
     * Escribe una entrada en el log diario del monitor.
     *
     * @param array  $config  Configuración cargada desde config.ini
     * @param string $level   Nivel de log (INFO, ERROR, WARN)
     * @param string $message Mensaje a registrar
     */
    function logMailEvent($config, $level, $message)
    {
        $logFolder = !empty($config['logFolder'])
            ? $config['logFolder']
            : dirname(__DIR__) . '/logs';
        $logFile = rtrim($logFolder, '/\\') . '/monitor_' . date('Y-m-d') . '.log';

        $logDir = dirname($logFile);
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0777, true);
        }

        $logEntry = '[' . date('Y-m-d H:i:s') . "] [{$level}] {$message}" . PHP_EOL;
        @file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
    }
}
