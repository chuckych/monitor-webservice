<?php
/**
 * WebService Ping Monitor Agent
 * Version: 1.0.0
 * PHP: 7.4.33
 */

error_reporting(E_ALL);
date_default_timezone_set('America/Argentina/Buenos_Aires');

define('SCRIPT_NAME', 'ping-monitor');
define('SCRIPT_VERSION', '1.0.1');
define('CONFIG_FILE', __DIR__ . '/config.ini');

define('EXIT_SUCCESS', 0);
define('EXIT_CONFIG_ERROR', 1);
define('EXIT_PING_FAILED_EMAIL_SENT', 2);
define('EXIT_CRITICAL_ERROR', 3);
define('EXIT_PING_FAILED_EMAIL_SKIPPED', 4);

require_once __DIR__ . '/functions/mail-functions.php';

class WebServiceMonitor
{
    private $config;
    private $logFile;
    private $debug = false;

    private $status = [
        'success' => false,
        'attempts' => 0,
        'lastError' => '',
        'httpCode' => 0,
    ];

    private $wsLogTail = null;

    /**
     * @param string $configFile Ruta al archivo de configuración
     * @param bool   $debug      Habilita logs de nivel DEBUG
     */
    public function __construct($configFile, $debug = false)
    {
        $this->debug = (bool) $debug;
        $this->log('INFO', 'Starting ' . SCRIPT_NAME . ' v' . SCRIPT_VERSION);

        $this->config = $this->loadConfig($configFile);
        if ($this->config === null) {
            exit(EXIT_CONFIG_ERROR);
        }

        $logFolder = !empty($this->config['logFolder'])
            ? $this->config['logFolder']
            : __DIR__ . '/logs';
        $this->logFile = rtrim($logFolder, '/\\') . '/monitor_' . date('Y-m-d') . '.log';

        $this->log('INFO', 'Config loaded successfully from: ' . basename($configFile));
        $this->log('INFO', 'Log file: ' . $this->logFile);
    }

    /**
     * Carga y valida la configuración desde el archivo .ini.
     *
     * @param string $configFile Ruta al archivo .ini
     * @return array|null Configuración válida o null si hay errores
     */
    private function loadConfig($configFile)
    {
        if (!file_exists($configFile)) {
            $this->log('ERROR', 'Configuration file not found: ' . $configFile);
            return null;
        }

        $ini = @parse_ini_file($configFile, true);
        if ($ini === false) {
            $this->log('ERROR', 'Failed to parse configuration file: ' . $configFile);
            return null;
        }

        $required = [
            'urlPing' => ['WEBSERVICE', 'urlPing'],
            'pathLogWebservice' => ['WEBSERVICE', 'pathLogWebservice'],
            'apiMailUrl' => ['EMAIL', 'apiMailUrl'],
            'apiToken' => ['EMAIL', 'apiToken'],
            'recipient' => ['EMAIL', 'recipient'],
            'replyTo' => ['EMAIL', 'replyTo'],
            'maxRetries' => ['SETTINGS', 'maxRetries'],
            'retryDelay' => ['SETTINGS', 'retryDelay'],
            'connectTimeout' => ['SETTINGS', 'connectTimeout'],
            'executionTimeout' => ['SETTINGS', 'executionTimeout'],
            'dnsCacheTimeout' => ['SETTINGS', 'dnsCacheTimeout'],
            'minEmailInterval' => ['SETTINGS', 'minEmailInterval'],
        ];

        $config = [];
        foreach ($required as $key => $path) {
            list($section, $field) = $path;
            if (!isset($ini[$section][$field]) || $ini[$section][$field] === '') {
                $this->log('ERROR', "Missing required config: [{$section}] {$field}");
                return null;
            }
            $config[$key] = $ini[$section][$field];
        }

        $config['emailSubject'] = isset($ini['EMAIL']['emailSubject'])
            ? $ini['EMAIL']['emailSubject']
            : '[MONITOR] WebService Ping Failed - {time}';
        $config['logFolder'] = isset($ini['EMAIL']['logFolder'])
            ? $ini['EMAIL']['logFolder']
            : '';
        $config['lockFile'] = isset($ini['SETTINGS']['lockFile']) && $ini['SETTINGS']['lockFile'] !== ''
            ? $ini['SETTINGS']['lockFile']
            : __DIR__ . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'monitor.lock';

        $emailTemplate = isset($ini['EMAIL']['emailTemplate'])
            ? $ini['EMAIL']['emailTemplate']
            : 'email-template.txt';
        if (!preg_match('#^([A-Za-z]:[\\\\/]|[\\\\/])#', $emailTemplate)) {
            $emailTemplate = dirname($configFile) . DIRECTORY_SEPARATOR . $emailTemplate;
        }
        $config['emailTemplate'] = $emailTemplate;

        $config['maxRetries'] = (int) $config['maxRetries'];
        $config['retryDelay'] = (int) $config['retryDelay'];
        $config['connectTimeout'] = (int) $config['connectTimeout'];
        $config['executionTimeout'] = (int) $config['executionTimeout'];
        $config['dnsCacheTimeout'] = (int) $config['dnsCacheTimeout'];
        $config['minEmailInterval'] = (int) $config['minEmailInterval'];
        $config['logRetentionDays'] = isset($ini['SETTINGS']['logRetentionDays'])
            ? (int) $ini['SETTINGS']['logRetentionDays']
            : 7;

        return $config;
    }

    /**
     * Ejecuta el flujo principal de monitoreo.
     *
     * @return int Código de retorno (ver sección 9.1 de la especificación)
     */
    public function run()
    {
        $this->status['attempts'] = 0;

        $this->cleanupOldLogs();

        do {
            $this->status['attempts']++;
            $this->debugLog('Ping attempt #' . $this->status['attempts'] . ' to: ' . $this->config['urlPing']);

            $result = $this->pingWebService();

            if ($result['success']) {
                $this->log('INFO', 'Ping successful - HTTP Code: ' . $result['httpCode']);
                $this->status['success'] = true;
                $this->resetEmailLock();
                $this->log('INFO', 'Script completed successfully (Code: ' . EXIT_SUCCESS . ')');
                return EXIT_SUCCESS;
            }

            $this->status['lastError'] = $result['error'];
            $this->status['httpCode']  = $result['httpCode'];
            $this->log('ERROR', 'Ping failed - HTTP Code: ' . $result['httpCode'] . ', Error: ' . $result['error']);
            // $this->logWebServiceLogTail();

            if ($this->status['attempts'] < $this->config['maxRetries'] + 1) {
                $this->debugLog('Waiting ' . $this->config['retryDelay'] . ' seconds before retry');
                sleep($this->config['retryDelay']);
            }
        } while ($this->status['attempts'] < $this->config['maxRetries'] + 1);

        return $this->handleFinalFailure();
    }

    /**
     * Realiza el ping HTTP al webservice.
     *
     * @return array ['success' => bool, 'httpCode' => int, 'error' => string, 'errorNo' => int]
     */
    private function pingWebService()
    {
        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $this->config['urlPing']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_NOSIGNAL, 1);
        curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, $this->config['connectTimeout']);
        curl_setopt($ch, CURLOPT_TIMEOUT_MS, $this->config['executionTimeout']);
        curl_setopt($ch, CURLOPT_DNS_CACHE_TIMEOUT, $this->config['dnsCacheTimeout']);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        $curlErrno = curl_errno($ch);

        curl_close($ch);

        return [
            'success' => ($httpCode === 201 && $curlErrno === 0),
            'httpCode' => $httpCode,
            'error' => $curlError !== '' ? $curlError : 'HTTP Code: ' . $httpCode,
            'errorNo' => $curlErrno,
        ];
    }

    /**
     * Devuelve la ruta completa del log del webservice con la fecha de hoy.
     *
     * @return string
     */
    private function getWebServiceLogPath()
    {
        return rtrim($this->config['pathLogWebservice'], '/\\')
            . DIRECTORY_SEPARATOR . 'LogWebService' . date('Ymd') . '.txt';
    }

    /**
     * Lee las últimas N líneas de un archivo de forma eficiente (sin cargar el archivo completo).
     *
     * @param string $filePath Ruta del archivo
     * @param int    $numLines Cantidad de líneas a leer
     * @return string[]
     */
    private function readLastLines($filePath, $numLines)
    {
        if (!file_exists($filePath) || !is_readable($filePath)) {
            return [];
        }

        $lines = [];
        $fp = @fopen($filePath, 'r');
        if ($fp === false) {
            return [];
        }

        fseek($fp, 0, SEEK_END);
        $position = ftell($fp);
        $buffer = '';

        while ($position > 0 && count($lines) < $numLines) {
            $readSize = min(4096, $position);
            fseek($fp, $position - $readSize, SEEK_SET);
            $chunk = fread($fp, $readSize);
            $position -= $readSize;
            $buffer = $chunk . $buffer;

            while (count($lines) < $numLines && ($pos = strrpos($buffer, "\n")) !== false) {
                $line = substr($buffer, $pos + 1);
                if ($line !== '') {
                    array_unshift($lines, $line);
                }
                $buffer = substr($buffer, 0, $pos);
            }
        }

        if ($buffer !== '' && count($lines) < $numLines) {
            array_unshift($lines, $buffer);
        }

        fclose($fp);

        return array_slice($lines, 0, $numLines);
    }

    /**
     * Obtiene las últimas líneas del log del webservice (cacheado).
     *
     * @param int $numLines Cantidad de líneas a leer
     * @return string[]
     */
    private function getWebServiceLogTail($numLines = 10)
    {
        if ($this->wsLogTail === null) {
            $this->wsLogTail = $this->readLastLines($this->getWebServiceLogPath(), $numLines);
            if (empty($this->wsLogTail)) {
                $this->log('WARN', 'WebService log not found or unreadable: ' . $this->getWebServiceLogPath());
            }
        }

        return $this->wsLogTail;
    }

    /**
     * Registra en el log del monitor las últimas líneas del log del webservice.
     */
    private function logWebServiceLogTail()
    {
        foreach ($this->getWebServiceLogTail() as $line) {
            $this->log('INFO', 'WSLOG: ' . utf8Normalize(rtrim($line, "\r\n")));
        }
    }

    /**
     * Elimina los logs del monitor más antiguos que la retención configurada (en días).
     */
    private function cleanupOldLogs()
    {
        $retentionDays = (int) $this->config['logRetentionDays'];
        if ($retentionDays <= 0) {
            return;
        }

        $logDir = dirname($this->logFile);
        $cutoff = time() - ($retentionDays * 86400);
        $deleted = 0;

        foreach (glob($logDir . DIRECTORY_SEPARATOR . 'monitor_*.log') ?: [] as $file) {
            if (is_file($file) && filemtime($file) < $cutoff) {
                if (@unlink($file)) {
                    $deleted++;
                }
            }
        }

        if ($deleted > 0) {
            $this->log('INFO', 'Log retention: deleted ' . $deleted . ' old log(s) (older than ' . $retentionDays . ' days)');
        }
    }

    /**
     * Maneja el fallo final tras agotar los reintentos: decide si envía email.
     *
     * @return int Código de retorno
     */
    private function handleFinalFailure()
    {
        $this->log('INFO', 'Max retries reached. Evaluating email notification.');

        if (!$this->shouldSendEmail()) {
            $this->log('INFO', 'Notification email skipped - sent recently (interval active)');
            $this->log('INFO', 'Script completed with errors (Code: ' . EXIT_PING_FAILED_EMAIL_SKIPPED . ')');
            return EXIT_PING_FAILED_EMAIL_SKIPPED;
        }

        $subject = strtr($this->config['emailSubject'], [
            '{status}' => 'FAILED',
            '{time}' => date('Y-m-d H:i:s'),
        ]);

        $body = $this->buildEmailBody();

        $result = sendEmail($this->config, $subject, $body);
        if ($result['success']) {
            $this->writeEmailLock();
            $requestId = $result['requestId'] !== '' ? ' - requestId: ' . $result['requestId'] : '';
            $this->log('INFO', 'Email sent successfully' . $requestId);
            $this->log('INFO', 'Script completed with errors (Code: ' . EXIT_PING_FAILED_EMAIL_SENT . ')');
            return EXIT_PING_FAILED_EMAIL_SENT;
        }

        $this->log('ERROR', 'Email sending failed - HTTP Code: ' . $result['httpCode'] . ', Error: ' . json_encode($result['error']));
        $this->log('INFO', 'Script completed with errors (Code: ' . EXIT_CRITICAL_ERROR . ')');
        return EXIT_CRITICAL_ERROR;
    }

    /**
     * Construye el cuerpo del email desde la plantilla en archivo plano.
     *
     * @return string
     */
    private function buildEmailBody()
    {
        $template = @file_get_contents($this->config['emailTemplate']);
        if ($template === false) {
            $this->log('WARN', 'Email template not found: ' . $this->config['emailTemplate'] . ' - using default');
            $template = "ALERTA DE MONITOREO\n\n"
                . "El webservice no está respondiendo correctamente.\n\n"
                . "Timestamp: {timestamp}\n"
                . "URL: {url}\n"
                . "Intentos: {attempts}\n"
                . "Último código HTTP: {httpCode}\n"
                . "Último error: {error}\n\n"
                . "Últimas líneas del log del webservice:\n{wslog}\n\n"
                . "Por favor revise el servicio.\n\n"
                . "--\n"
                . "{agentName} Agent v{agentVersion}\n";
        }

        $wsLog = implode("\n", array_map(function ($line) {
            return utf8Normalize(rtrim($line, "\r\n"));
        }, $this->getWebServiceLogTail()));

        if ($wsLog === '') {
            $wsLog = '(sin contenido disponible)';
        }

        return strtr($template, [
            '{timestamp}' => date('Y-m-d H:i:s'),
            '{url}' => $this->config['urlPing'],
            '{attempts}' => $this->status['attempts'],
            '{httpCode}' => $this->status['httpCode'],
            '{error}' => $this->status['lastError'],
            '{agentName}' => SCRIPT_NAME,
            '{agentVersion}' => SCRIPT_VERSION,
            '{wslog}' => $wsLog,
        ]);
    }

    /**
     * Determina si debe enviarse el email de notificación según el lock file.
     *
     * @return bool
     */
    private function shouldSendEmail()
    {
        $lockFile = $this->config['lockFile'];

        if (!file_exists($lockFile)) {
            return true;
        }

        $lockTime = (int) file_get_contents($lockFile);
        $currentTime = time();

        return ($currentTime - $lockTime) >= $this->config['minEmailInterval'];
    }

    /**
     * Crea el lock file con el timestamp actual.
     */
    private function writeEmailLock()
    {
        $lockFile = $this->config['lockFile'];
        $lockDir = dirname($lockFile);

        if (!is_dir($lockDir)) {
            @mkdir($lockDir, 0777, true);
        }

        if (@file_put_contents($lockFile, time(), LOCK_EX) === false) {
            $this->log('WARN', 'Could not write lock file: ' . $lockFile);
        }
    }

    /**
     * Elimina el lock file cuando el ping vuelve a ser exitoso.
     */
    private function resetEmailLock()
    {
        $lockFile = $this->config['lockFile'];
        if (file_exists($lockFile)) {
            if (@unlink($lockFile)) {
                $this->debugLog('Email lock reset: ' . $lockFile);
            } else {
                $this->log('WARN', 'Could not remove lock file: ' . $lockFile);
            }
        }
    }

    /**
     * Escribe una entrada de log con nivel.
     *
     * @param string $level Nivel de log (INFO, ERROR, WARN, DEBUG)
     * @param string $message Mensaje a registrar
     */
    private function log($level, $message)
    {
        $logEntry = '[' . date('Y-m-d H:i:s') . "] [{$level}] {$message}" . PHP_EOL;

        $logDir = dirname($this->logFile);
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0777, true);
        }

        @file_put_contents($this->logFile, $logEntry, FILE_APPEND | LOCK_EX);

        if ($this->debug) {
            fwrite(STDOUT, $logEntry);
        }
    }

    /**
     * Registra mensajes de nivel DEBUG solo si el modo debug está activo.
     *
     * @param string $message
     */
    private function debugLog($message)
    {
        if ($this->debug) {
            $this->log('DEBUG', $message);
        }
    }
}

// ---------------------------------------------------------------
// Punto de entrada CLI
// ---------------------------------------------------------------

$configFile = CONFIG_FILE;
$debug = false;

foreach ($argv as $arg) {
    if (strpos($arg, '--config=') === 0) {
        $configFile = substr($arg, strlen('--config='));
    } elseif ($arg === '--debug') {
        $debug = true;
    } elseif ($arg === '--help' || $arg === '-h') {
        echo 'Usage: php ' . basename(__FILE__) . " [--config=config.ini] [--debug]" . PHP_EOL;
        exit(EXIT_SUCCESS);
    }
}

$monitor = new WebServiceMonitor($configFile, $debug);
exit($monitor->run());
