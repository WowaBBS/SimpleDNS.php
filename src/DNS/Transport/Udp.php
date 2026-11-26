<?
/* ============================================================
 * UDP (RFC 1035, 4.2.1) - обычный DNS-запрос на 53 порт.
 * При усечении ответа (TC=1) повторяет запрос по TCP.
 * ============================================================ */

final class UdpTransport implements DnsTransport
{
  use TransportChecks;

  public function __construct(
    public string $server,
    public int    $port        = 53,
    public int    $timeout     = 5,
    public bool   $tcpFallback = true,
  ) {}

  public function name(): string { return "udp://{$this->server}:{$this->port}"; }

  public function exchange(DnsMessage $request): DnsMessage
  {
    $reply = DnsMessage::deserialize($this->send($request->serialize()));

    // Усечённый ответ бесполезен: те же данные запрашиваем по TCP
    if ($reply->header->tc && $this->tcpFallback)
      return (new TcpTransport($this->server, $this->port, $this->timeout))->exchange($request);

    return $this->check($request, $reply);
  }

  private function send(string $wire): string
  {
    $fp = @stream_socket_client("udp://{$this->server}:{$this->port}", $errno, $errstr, $this->timeout);
    if ($fp === false)
      throw new RuntimeException($this->name() . ": не удалось открыть сокет: $errstr ($errno)");

    try
    {
      stream_set_timeout($fp, $this->timeout);

      if (@fwrite($fp, $wire) !== strlen($wire))
        throw new RuntimeException($this->name() . ': не удалось отправить запрос');

      $reply = @fread($fp, 65535);
      $meta  = stream_get_meta_data($fp);

      if ($meta['timed_out'])
        throw new RuntimeException($this->name() . ": нет ответа за {$this->timeout} с");

      if ($reply === false || $reply === '')
        throw new RuntimeException($this->name() . ': пустой ответ');

      return $reply;
    }
    finally { fclose($fp); }
  }

  static function test(TestRunner $t)
  {
    $udp = new UdpTransport('1.1.1.1');
    $t->eq($udp->name(), 'udp://1.1.1.1:53', 'UdpTransport: адрес по умолчанию');
    $t->eq([$udp->port, $udp->timeout, $udp->tcpFallback], [53, 5, true], 'UdpTransport: параметры по умолчанию');

    $custom = new UdpTransport('8.8.8.8', 5353, 2, false);
    $t->eq($custom->name(), 'udp://8.8.8.8:5353', 'UdpTransport: свой порт');
    $t->eq($custom->tcpFallback, false, 'UdpTransport: fallback отключается');
    $t->eq($custom instanceof DnsTransport, true, 'UdpTransport: реализует контракт транспорта');
  }
}