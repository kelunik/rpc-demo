<?php

namespace Demo;

use function Amp\cancel;
use Amp\Deferred;
use Amp\Failure;
use function Amp\onReadable;
use function Amp\onWritable;
use function Amp\Socket\listen;
use Exception;

class Server {
    private $callback;
    private $clients;

    public function __construct(callable $callback) {
        $this->callback = $callback;
    }

    public function bind(string $addr) {
        $serverSocket = listen($addr);

        onReadable($serverSocket, function ($watcherId, $serverSocket) {
            if (!$client = @\stream_socket_accept($serverSocket, 0, $peerName)) {
                return;
            }

            $portStartPos = strrpos($peerName, ":");
            $ip = substr($peerName, 0, $portStartPos);
            $port = substr($peerName, $portStartPos + 1);

            $this->clients[$peerName] = $client;

            print "Accepting client from {$ip} on port {$port}" . PHP_EOL;

            \stream_set_blocking($client, false);

            onReadable($client, function ($watcherId, $client) use ($ip, $port) {
                static $buffer = "";

                $data = \fread($client, 8192);

                if ($data === "" || $data === false) {
                    if (!\is_resource($client) || @\feof($client)) {
                        cancel($watcherId);
                    }

                    return;
                }

                $buffer .= $data;

                while (($pos = \strpos($buffer, "\n")) !== false) {
                    $line = \trim(\substr($buffer, 0, $pos));
                    $buffer = substr($buffer, $pos + 1);

                    $callback = $this->callback;
                    $callback($line, $ip, $port);
                }
            });
        });
    }

    public function sendTo(string $ip, string $port, string $payload) {
        if (!isset($this->clients[$ip . ":" . $port])) {
            return new Failure(new Exception("Client already disconnected."));
        }

        $client = $this->clients[$ip . ":" . $port];

        $deferred = new Deferred;

        onWritable($client, function ($watcherId) use ($client, $payload) {
            static $buffer = null;

            if ($buffer === null) {
                $buffer = $payload;
            }

            $bytes = fwrite($client, $payload);

            if ($bytes === false) {
                cancel($watcherId);

                return;
            }

            $buffer = substr($buffer, $bytes);

            if (!strlen($buffer)) {
                cancel($watcherId);
            }
        });

        return $deferred->promise();
    }
}