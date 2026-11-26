<?
/* ============================================================
 * DoQ (RFC 9250) - заготовка на будущее.
 *
 * В PHP нет QUIC: ни в потоках, ни в curl (там только HTTP/3,
 * и то при QUIC-сборке libcurl). Единственный путь - расширение
 * php-quic, которое поднимает QUIC из OpenSSL 3.5+:
 *
 *   pie install mikepultz/php-quic      # затем extension=quic
 *
 * Пока расширения нет, exchange() объясняет это исключением;
 * когда появится - заработает без правок вызывающего кода.
 * Живой проверки для этого пути в тестах нет: она возможна
 * только после установки расширения.
 * ============================================================ */

final class DoqTransport implements DnsTransport
{
  use TransportChecks;

  public function __construct(
    public string $server,
    public int    $port    = 853,
    public int    $timeout = 5,
  ) {}

  /** Доступен ли QUIC в этой сборке PHP */
  public static function supported(): bool { return extension_loaded('quic'); }

  public function name(): string { return "doq://{$this->server}:{$this->port}"; }

  public function exchange(DnsMessage $request): DnsMessage
  {
    if (!self::supported())
      throw new RuntimeException($this->name() . ': DoQ недоступен - в этой сборке PHP нет QUIC. '
        . 'Нужно расширение php-quic: pie install mikepultz/php-quic, затем extension=quic');

    // RFC 9250: один запрос на двунаправленный поток, префикс из двух байт длины
    $wire = $request->serialize();
    $wire[0] = "\0";
    $wire[1] = "\0";                 // ID сообщения в DoQ всегда 0

    $connection = new Quic\Connection($this->server, $this->port, [
      'alpn'               => 'doq',
      'peer_name'          => $this->server,
      'connect_timeout_ms' => $this->timeout * 1000,
    ]);

    try
    {
      $stream = $connection->openStream();
      $stream->write(pack('n', strlen($wire)) . $wire, true);

      $length = unpack('n', $this->read($stream, 2))[1];
      $reply  = DnsMessage::deserialize($this->read($stream, $length));
    }
    finally { $connection->close(); }

    if ($reply->header->id !== 0)
      throw new RuntimeException($this->name() . ': в DoQ ID ответа должен быть 0');

    return $reply;
  }

  private function read(Quic\Stream $stream, int $length): string
  {
    $buffer = '';

    while (strlen($buffer) < $length)
    {
      $chunk = $stream->read($length - strlen($buffer));
      if ($chunk === null)
        throw new RuntimeException($this->name() . ': поток закрыт до конца ответа');

      $buffer .= $chunk;
    }

    return $buffer;
  }

  static function test(TestRunner $t)
  {
    $doq = new DoqTransport('dns.adguard-dns.com');
    $t->eq($doq->name(), 'doq://dns.adguard-dns.com:853', 'DoqTransport: адрес по умолчанию');
    $t->eq([$doq->port, $doq->timeout], [853, 5], 'DoqTransport: параметры по умолчанию');
    $t->eq((new DoqTransport('dns.adguard-dns.com', 8853))->name(), 'doq://dns.adguard-dns.com:8853', 'DoqTransport: свой порт');
    $t->eq($doq instanceof DnsTransport, true, 'DoqTransport: реализует контракт транспорта');

    // Без QUIC-расширения запрос обязан падать с внятной подсказкой
    $t->eq(DoqTransport::supported(), extension_loaded('quic'), 'DoqTransport: supported() отражает наличие расширения');

    try
    {
      $doq->exchange(DnsMessage::query('example.com', 1));
      $t->ok(false, 'DoqTransport: без QUIC -> исключение');
    }
    catch (RuntimeException $e)
    {
      $t->ok(str_contains($e->getMessage(), 'php-quic'), 'DoqTransport: подсказка про php-quic в тексте ошибки');
    }
  }
}