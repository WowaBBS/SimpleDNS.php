<?
/* ============================================================
 * TCP (RFC 1035, 4.2.2): сообщение идёт с префиксом из двух
 * байт длины. Тот же кадр использует DoT, поэтому соединение
 * и чтение вынесены в защищённые методы.
 * ============================================================ */

class TcpTransport implements DnsTransport // не final: DotTransport переиспользует кадрирование
{
  use TransportChecks;

  public function __construct(
    public string $server,
    public int    $port    = 53,
    public int    $timeout = 5,
    public string $scheme  = 'tcp',
  ) {}

  public function name(): string { return "{$this->scheme}://{$this->server}:{$this->port}"; }

  public function exchange(DnsMessage $request): DnsMessage
  {
    $fp = $this->connect();

    try
    {
      $this->write($fp, self::frame($request->serialize()));
      return $this->check($request, DnsMessage::deserialize($this->read($fp)));
    }
    finally { fclose($fp); }
  }

  /* ---------- Кадр: два байта длины и сообщение ---------- */

  public static function frame(string $wire): string
  {
    if (strlen($wire) > 0xFFFF)
      throw new InvalidArgumentException('Сообщение длиннее 65535 байт');

    return pack('n', strlen($wire)) . $wire;
  }

  /** Разбор кадра - обратная операция к frame(), используется и при чтении */
  public static function unframe(string $frame): string
  {
    if (strlen($frame) < 2)
      throw new RuntimeException('Кадр короче двух байт длины');

    $length = unpack('n', $frame)[1];
    $body   = substr($frame, 2);

    if (strlen($body) !== $length)
      throw new RuntimeException("Длина кадра $length не совпадает с телом " . strlen($body));

    return $body;
  }

  /* ---------- Соединение ---------- */

  /** @return resource */
  protected function connect()
  {
    $context = stream_context_create(['ssl' => [
      'verify_peer'      => true,
      'verify_peer_name' => true,
      'peer_name'        => $this->server,
      'SNI_enabled'      => true,
    ]]);

    $fp = @stream_socket_client(
      "{$this->scheme}://{$this->server}:{$this->port}",
      $errno, $errstr, $this->timeout, STREAM_CLIENT_CONNECT, $context);

    if ($fp === false)
      throw new RuntimeException($this->name() . ": не удалось соединиться: $errstr ($errno)");

    stream_set_timeout($fp, $this->timeout);

    return $fp;
  }

  /** @param resource $fp */
  private function write($fp, string $bytes): void
  {
    while ($bytes !== '')
    {
      $sent = @fwrite($fp, $bytes);
      if ($sent === false || $sent === 0)
        throw new RuntimeException($this->name() . ': не удалось отправить данные');

      $bytes = substr($bytes, $sent);
    }
  }

  /** @param resource $fp */
  private function read($fp): string
  {
    $length = unpack('n', $this->readExactly($fp, 2))[1];

    if ($length < 12)
      throw new RuntimeException($this->name() . ": некорректная длина ответа $length");

    return $this->readExactly($fp, $length);
  }

  /** @param resource $fp */
  private function readExactly($fp, int $length): string
  {
    $buffer = '';

    while (strlen($buffer) < $length)
    {
      $chunk = @fread($fp, $length - strlen($buffer));
      if ($chunk === false || $chunk === '')
      {
        $meta = stream_get_meta_data($fp);

        throw new RuntimeException($this->name() . ($meta['timed_out']
          ? ": нет данных за {$this->timeout} с"
          : ': соединение закрыто до конца ответа'));
      }

      $buffer .= $chunk;
    }

    return $buffer;
  }

  static function test(TestRunner $t)
  {
    $tcp = new TcpTransport('8.8.8.8');
    $t->eq($tcp->name(), 'tcp://8.8.8.8:53', 'TcpTransport: адрес по умолчанию');

    // Кадр: два байта длины и тело
    $frame = TcpTransport::frame("\x12\x34\x01\x00");
    $t->eq(hexs($frame), '00 04 12 34 01 00', 'TcpTransport: кадр с префиксом длины');
    $t->eq(TcpTransport::unframe($frame), "\x12\x34\x01\x00", 'TcpTransport: кадр разбирается обратно');

    $t->throws(fn() => TcpTransport::unframe("\x00"), 'TcpTransport: кадр короче префикса -> исключение');
    $t->throws(fn() => TcpTransport::unframe("\x00\x05abc"), 'TcpTransport: длина не совпадает с телом -> исключение');
    $t->throws(fn() => TcpTransport::frame(str_repeat('x', 65536)), 'TcpTransport: тело больше 64 КБ -> исключение');
  }
}