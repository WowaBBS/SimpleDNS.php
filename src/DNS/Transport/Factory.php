<?
/* ============================================================
 * Выбор транспорта по адресу:
 *   udp://1.1.1.1        tcp://1.1.1.1:53
 *   tls://dns.google     dot://dns.google
 *   https://dns.google/dns-query
 * ============================================================ */

final class TransportFactory
{
  public static function fromUrl(string $url, int $timeout = 5): DnsTransport
  {
    $parts = parse_url($url);

    if ($parts === false || empty($parts['scheme']) || empty($parts['host']))
      throw new InvalidArgumentException("Не удалось разобрать адрес транспорта: $url");

    $host = $parts['host'];

    return match (strtolower($parts['scheme']))
    {
      'udp'        => new UdpTransport($host, $parts['port'] ?? 53, $timeout),
      'tcp'        => new TcpTransport($host, $parts['port'] ?? 53, $timeout),
      'tls', 'dot' => new DotTransport($host, $parts['port'] ?? 853, $timeout),
      'doq'        => new DoqTransport($host, $parts['port'] ?? 853, $timeout),
      'https', 'doh' => new DohTransport($url, $timeout),
      default      => throw new InvalidArgumentException("Неизвестный транспорт: {$parts['scheme']}"),
    };
  }

  static function test(TestRunner $t)
  {
    $t->eq(TransportFactory::fromUrl('udp://1.1.1.1')->name(), 'udp://1.1.1.1:53', 'TransportFactory: udp');
    $t->eq(TransportFactory::fromUrl('tcp://8.8.8.8:5353')->name(), 'tcp://8.8.8.8:5353', 'TransportFactory: tcp с портом');
    $t->eq(TransportFactory::fromUrl('tls://dns.google')->name(), 'tls://dns.google:853', 'TransportFactory: tls');
    $t->eq(TransportFactory::fromUrl('dot://dns.google')->name(), 'tls://dns.google:853', 'TransportFactory: dot как tls');
    $t->eq(TransportFactory::fromUrl('doq://dns.adguard-dns.com')->name(), 'doq://dns.adguard-dns.com:853', 'TransportFactory: doq');
    $t->eq(TransportFactory::fromUrl('https://dns.google/dns-query')->name(), 'https://dns.google/dns-query', 'TransportFactory: https -> DoH');

    $udp = TransportFactory::fromUrl('udp://1.1.1.1', 2);
    $t->eq($udp instanceof UdpTransport ? $udp->timeout : null, 2, 'TransportFactory: таймаут передаётся');

    $t->throws(fn() => TransportFactory::fromUrl('icmp://1.1.1.1'), 'TransportFactory: неизвестная схема -> исключение');
    $t->throws(fn() => TransportFactory::fromUrl('просто строка'), 'TransportFactory: мусор -> исключение');
  }
}