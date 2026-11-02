<?php
/**
 * Servidor de sinalizacao para a Sala de Tela (WebRTC).
 * Ele NAO transporta video: apenas repassa mensagens de conexao
 * (SDP / ICE) entre o apresentador e os espectadores.
 *
 * Rodar pelo terminal:  php -f server.php
 */
require __DIR__ . '/vendor/autoload.php';

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;

class Sinalizacao implements MessageComponentInterface
{
    const MAX_VIEWERS = 5; // 5 espectadores + 1 apresentador = 6 pessoas

    /** @var SplObjectStorage conn => array(id, room, role) */
    protected $clients;
    protected $nextId = 1;

    /** room => array('presenter' => conn|null, 'viewers' => array(id => conn)) */
    protected $rooms = array();

    public function __construct()
    {
        $this->clients = new SplObjectStorage();
    }

    public function onOpen(ConnectionInterface $conn)
    {
        $this->clients[$conn] = array(
            'id'   => $this->nextId++,
            'room' => null,
            'role' => null,
        );
    }

    public function onMessage(ConnectionInterface $from, $msg)
    {
        $m = json_decode($msg, true);
        if (!is_array($m) || !isset($m['type'])) {
            return;
        }
        $info = $this->clients[$from];

        if ($m['type'] === 'join') {
            $this->join($from, $info, $m);
        } elseif ($m['type'] === 'signal' && $info['room'] !== null) {
            $this->relay($info, $m);
        }
    }

    protected function join($conn, $info, $m)
    {
        if ($info['room'] !== null) {
            return; // ja esta em uma sala
        }

        $room = isset($m['room']) ? preg_replace('/[^A-Za-z0-9_-]/', '', (string) $m['room']) : '';
        $room = substr($room, 0, 32);
        if ($room === '' || $room === false) {
            $this->send($conn, array('type' => 'error', 'reason' => 'Nome de sala invalido'));
            return;
        }
        $role = (isset($m['role']) && $m['role'] === 'presenter') ? 'presenter' : 'viewer';

        if (!isset($this->rooms[$room])) {
            $this->rooms[$room] = array('presenter' => null, 'viewers' => array());
        }

        if ($role === 'presenter') {
            if ($this->rooms[$room]['presenter'] !== null) {
                $this->send($conn, array('type' => 'error', 'reason' => 'Ja existe alguem transmitindo nesta sala'));
                return;
            }
            $this->rooms[$room]['presenter'] = $conn;
            $this->setInfo($conn, $info, $room, 'presenter');

            $this->send($conn, array('type' => 'joined', 'id' => $info['id'], 'role' => 'presenter'));
            // Espectadores que ja estavam esperando
            $this->send($conn, array(
                'type' => 'viewers',
                'ids'  => array_keys($this->rooms[$room]['viewers']),
            ));
        } else {
            if (count($this->rooms[$room]['viewers']) >= self::MAX_VIEWERS) {
                $this->send($conn, array('type' => 'error', 'reason' => 'Sala cheia'));
                return;
            }
            $this->rooms[$room]['viewers'][$info['id']] = $conn;
            $this->setInfo($conn, $info, $room, 'viewer');

            $this->send($conn, array(
                'type'            => 'joined',
                'id'              => $info['id'],
                'role'            => 'viewer',
                'presenterOnline' => $this->rooms[$room]['presenter'] !== null,
            ));
            if ($this->rooms[$room]['presenter'] !== null) {
                $this->send($this->rooms[$room]['presenter'], array('type' => 'viewer-joined', 'id' => $info['id']));
            }
        }
    }

    protected function setInfo($conn, $info, $room, $role)
    {
        $info['room'] = $room;
        $info['role'] = $role;
        $this->clients[$conn] = $info;
    }

    protected function relay($info, $m)
    {
        $room = $info['room'];
        if (!isset($this->rooms[$room])) {
            return;
        }
        $data = isset($m['data']) ? $m['data'] : null;
        $target = null;

        if ($info['role'] === 'viewer') {
            $target = $this->rooms[$room]['presenter'];
        } else {
            $to = isset($m['to']) ? (int) $m['to'] : 0;
            if (isset($this->rooms[$room]['viewers'][$to])) {
                $target = $this->rooms[$room]['viewers'][$to];
            }
        }

        if ($target !== null) {
            $this->send($target, array('type' => 'signal', 'from' => $info['id'], 'data' => $data));
        }
    }

    public function onClose(ConnectionInterface $conn)
    {
        $info = $this->clients[$conn];
        $room = $info['room'];

        if ($room !== null && isset($this->rooms[$room])) {
            if ($info['role'] === 'presenter') {
                $this->rooms[$room]['presenter'] = null;
                foreach ($this->rooms[$room]['viewers'] as $v) {
                    $this->send($v, array('type' => 'presenter-left'));
                }
            } else {
                unset($this->rooms[$room]['viewers'][$info['id']]);
                if ($this->rooms[$room]['presenter'] !== null) {
                    $this->send($this->rooms[$room]['presenter'], array('type' => 'viewer-left', 'id' => $info['id']));
                }
            }
            if ($this->rooms[$room]['presenter'] === null && count($this->rooms[$room]['viewers']) === 0) {
                unset($this->rooms[$room]);
            }
        }

        $this->clients->detach($conn);
    }

    public function onError(ConnectionInterface $conn, \Exception $e)
    {
        $conn->close();
    }

    protected function send($conn, $arr)
    {
        $conn->send(json_encode($arr));
    }
}

$porta = 8080;
$server = IoServer::factory(new HttpServer(new WsServer(new Sinalizacao())), $porta, '0.0.0.0');
echo "Servidor de sinalizacao ouvindo na porta {$porta}\n";
$server->run();
