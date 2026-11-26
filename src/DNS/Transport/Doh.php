<?
/* ============================================================
 * DoH (RFC 8484): тот же wire-формат внутри HTTPS.
 * POST - бинарное сообщение, GET - JSON (dns+json).
 * ============================================================ */

final class DohTransport implements DnsTransport
{
  use TransportChecks;

  public const ENDPOINT = 'https://cloudflare-dns.com/dns-query';

  public function __construct(
    public string $endpoint = self::ENDPOINT,
    public int    $timeout  = 5,
    public bool   $compress = false,
  ) {}

  public function name(): string { return $this->endpoint; }

  /** Умеет ли libcurl этой сборки HTTP/3 (нужен QUIC-бэкенд: ngtcp2, quiche, msh3) */
  public static function supportsHttp3(): bool
  {
    return defined('CURL_VERSION_HTTP3')
      && (bool)(curl_version()['features'] & CURL_VERSION_HTTP3);
  }

  /* ---------- POST: application/dns-message ---------- */

  public function exchange(DnsMessage $request): DnsMessage
  {
    $context = stream_context_create([
      'http' => [
        'method'  => 'POST',
        'header'  => "Content-Type: application/dns-message\r\nAccept: application/dns-message\r\n",
        'content' => $this->compress ? $request->serializeWith(new NameTable()) : $request->serialize(),
        'timeout' => $this->timeout,
        'ignore_errors' => true,
      ],
      'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);

    $reply = DnsMessage::deserialize($this->fetch($this->endpoint, $context));

    if ($reply->header->tc)
      throw new RuntimeException($this->name() . ': ответ усечён (TC=1)');

    return $this->check($request, $reply);
  }

  /* ---------- GET: application/dns+json ---------- */

  public function exchangeJson(DnsMessage $request): DnsMessage
  {
    $path = parse_url($this->endpoint, PHP_URL_PATH) ?: '';

    if (str_ends_with($path, '/resolve'))
    {
      // JSON-API Google: параметры name и type
      $question = $request->questions[0] ?? throw new RuntimeException('В запросе нет вопросов');
      $url = $this->endpoint . '?' . http_build_query([
        'name' => $question->name,
        'type' => DnsMessage::typeName($question->type),
      ]);
    }
    else
    {
      // RFC 8484: wire-запрос в base64url
      $dns = rtrim(strtr(base64_encode($request->serialize()), '+/', '-_'), '=');
      $url = $this->endpoint . '?dns=' . $dns;
    }

    $context = stream_context_create([
      'http' => [
        'method'  => 'GET',
        'header'  => "Accept: application/dns+json\r\n",
        'timeout' => $this->timeout,
        'ignore_errors' => true,
      ],
      'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);

    $reply = DnsMessage::fromJson($this->fetch($url, $context));

    if ($reply->header->tc)
      throw new RuntimeException($this->name() . ': ответ усечён (TC=1)');

    return $reply;
  }

  /* ---------- Общий путь запроса ---------- */

  /** @param resource $context */
  private function fetch(string $url, $context): string
  {
    $response = @file_get_contents($url, false, $context);
    if ($response === false)
      throw new RuntimeException($this->name() . ': не удалось соединиться: '
        . (error_get_last()['message'] ?? '?'));

    if (!preg_match('/^HTTP\/\S+\s+2\d\d/', $http_response_header[0] ?? ''))
      throw new RuntimeException($this->name() . ': сервер вернул ошибку: '
        . ($http_response_header[0] ?? 'нет заголовков'));

    return $response;
  }

  static function test(TestRunner $t)
  {
    $doh = new DohTransport();
    $t->eq($doh->name(), DohTransport::ENDPOINT, 'DohTransport: адрес по умолчанию');
    $t->eq([$doh->timeout, $doh->compress], [5, false], 'DohTransport: параметры по умолчанию');
    $t->eq((new DohTransport('https://dns.google/dns-query'))->name(), 'https://dns.google/dns-query', 'DohTransport: свой адрес');
    $t->eq($doh instanceof DnsTransport, true, 'DohTransport: реализует контракт транспорта');
    $t->eq(DohTransport::supportsHttp3(),
      defined('CURL_VERSION_HTTP3') && (bool)(curl_version()['features'] & CURL_VERSION_HTTP3),
      'DohTransport: supportsHttp3 отражает возможности libcurl');
  }
}