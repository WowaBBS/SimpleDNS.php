<?
/* ============================================================
 * DoT (RFC 7858) - тот же TCP-кадр, но поверх TLS (порт 853).
 * ============================================================ */

final class DotTransport extends TcpTransport
{
  public function __construct(string $server, int $port = 853, int $timeout = 5)
  {
    parent::__construct($server, $port, $timeout, 'tls');
  }

  static function test(TestRunner $t)
  {
    $dot = new DotTransport('dns.google');
    $t->eq($dot->name(), 'tls://dns.google:853', 'DotTransport: адрес по умолчанию');
    $t->eq($dot instanceof TcpTransport, true, 'DotTransport: использует TCP-кадрирование');
    $t->eq((new DotTransport('dns.google', 8853))->name(), 'tls://dns.google:8853', 'DotTransport: свой порт');
    $t->eq($dot instanceof DnsTransport, true, 'DotTransport: реализует контракт транспорта');
  }
}