<?php

namespace Demo;

use Amp\Deferred;
use Amp\Failure;
use Exception;
use function Amp\cancel;
use function Amp\disable;
use function Amp\enable;
use function Amp\onReadable;
use function Amp\onWritable;
use function Amp\Socket\listen;

class Server {
    private $callback;
    private $clients = [];
    private $clientWriteQueue = [];
    private $clientWriteWatchers = [];

    public function __construct(callable $callback) {
        $this->callback = $callback;
    }

    public function bind(string $addr) {
        $serverSocket = listen($addr);
        $this->setupClientAcceptor($serverSocket);
    }

    private function setupClientAcceptor($serverSocket) {
        onReadable($serverSocket, function () use ($serverSocket) {
            if (!$clientSocket = @\stream_socket_accept($serverSocket, 0, $peerName)) {
                return;
            }

            $this->loadClient($clientSocket, $peerName);
        });
    }

    private function loadClient($clientSocket, $peerName) {
        $portStartPos = strrpos($peerName, ":");
        $ip = substr($peerName, 0, $portStartPos);
        $port = (int) substr($peerName, $portStartPos + 1);

        print "Accepting client from {$ip} on port {$port}" . PHP_EOL;

        $this->clients[$peerName] = $clientSocket;
        \stream_set_blocking($clientSocket, false);

        $this->setupClientWatchers($clientSocket, $ip, $port);
    }

    private function setupClientWatchers($clientSocket, $ip, $port) {
        onReadable($clientSocket, function ($watcherId, $clientSocket) use ($ip, $port) {
            static $buffer = "";

            $data = \fread($clientSocket, 8192);

            if ($data === "" || $data === false) {
                if (!\is_resource($clientSocket) || @\feof($clientSocket)) {
                    cancel($watcherId);

                    print "Client closed connection from {$ip} on port {$port}" . PHP_EOL;

                    $this->unloadClient($ip, $port);
                }

                return;
            }

            // Buffer data and emit line by line once we have a line feed

            $buffer .= $data;

            while (($pos = \strpos($buffer, "\n")) !== false) {
                $line = \trim(\substr($buffer, 0, $pos));
                $buffer = substr($buffer, $pos + 1);

                $callback = $this->callback;
                $callback($line, $ip, $port);
            }
        });

        $writeWatcher = onWritable($clientSocket, function ($watcherId, $clientSocket) {
            $item = array_shift($this->clientWriteQueue[(int) $clientSocket]);
            $bytes = fwrite($clientSocket, $item[1]);

            if ($bytes === false) {
                cancel($watcherId);

                // TODO: Unload client

                return;
            }

            $item[1] = substr($item[1], $bytes);

            if (!strlen($item[1])) {
                $item[0]->succeed();

                if (empty($this->clientWriteQueue[(int) $clientSocket])) {
                    unset($this->clientWriteQueue[(int) $clientSocket]);
                    disable($watcherId);
                }
            } else {
                array_unshift($this->clientWriteQueue[(int) $clientSocket], $item);
            }
        });

        $this->clientWriteWatchers[(int) $clientSocket] = $writeWatcher;

        disable($writeWatcher);
    }

    private function unloadClient(string $ip, int $port) {
        unset($this->clients[$ip . ":" . $port]);
    }

    public function send(string $ip, int $port, string $payload) {
        if (!isset($this->clients[$ip . ":" . $port])) {
            return new Failure(new Exception("Client already disconnected."));
        }

        $deferred = new Deferred;

        $this->clientWriteQueue[(int) $this->clients[$ip . ":" . $port]][] = [
            $deferred,
            $payload,
        ];

        enable($this->clientWriteWatchers[(int) $this->clients[$ip . ":" . $port]]);

        return $deferred->promise();
    }
}