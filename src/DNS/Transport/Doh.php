<?
/* ============================================================
 * DoH (RFC 8484): DNS-сообщение внутри HTTPS.
 *
 *   exchange()     - POST application/dns-message, основной путь
 *   exchangeJson() - GET в JSON-представлении, два способа:
 *     params - ?name=&type=   (Google /resolve, Cloudflare, dns.sb)
 *     get    - ?dns=<base64url> (RFC 8484; сервер должен уметь JSON)
 *
 * Про Accept: серверы ждут application/dns-json (так у Cloudflare
 * и Google), и Cloudflare отвечает 400 даже на Accept: *\/*,
 * поэтому заголовок задаётся одним значением.
 * ============================================================ */

final class DohTransport implements DnsTransport
{
  use TransportChecks;

  public const ENDPOINT = 'https://cloudflare-dns.com/dns-query';

  /** Медиатип, который фактически понимают серверы */
  public const JSON_ACCEPT = 'application/dns-json';

  /** Способы запросить JSON: параметрами name/type или RFC 8484-строкой */
  public const JSON_PARAMS = 'params';
  public const JSON_GET    = 'get';

  public function __construct(
    public string $endpoint = self::ENDPOINT,
    public int    $timeout  = 5,
    public bool   $compress = false,
    public string $jsonMode = self::JSON_PARAMS,
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

  /* ---------- GET: application/dns-json ---------- */

  /** Адрес JSON-запроса - вынесен отдельно, чтобы его можно было проверить без сети */
  public function jsonUrl(DnsMessage $request): string
  {
    if ($this->jsonMode === self::JSON_GET)
      return $this->endpoint . '?dns=' . rtrim(strtr(base64_encode($request->serialize()), '+/', '-_'), '=');

    if (count($request->questions) !== 1)
      throw new RuntimeException($this->name() . ': JSON-режим name/type требует ровно один вопрос');

    $question = $request->questions[0];

    $params = [
      'name' => $question->name === '' ? '.' : $question->name,
      'type' => DnsMessage::typeName($question->type),
    ];

    // DNSSEC в JSON-канале запрашивается параметром do=1,
    // а не битом в OPT, как в wire-запросе
    if ($request->additional->opt()?->do)
      $params['do'] = 1;

    return $this->endpoint . '?' . http_build_query($params);
  }

  public function exchangeJson(DnsMessage $request): DnsMessage
  {
    $context = stream_context_create([
      'http' => [
        'method'  => 'GET',
        'header'  => 'Accept: ' . self::JSON_ACCEPT . "\r\n",
        'timeout' => $this->timeout,
        'ignore_errors' => true,
      ],
      'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);

    $reply = DnsMessage::fromJson($this->fetch($this->jsonUrl($request), $context));

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
    $t->eq([$doh->timeout, $doh->compress, $doh->jsonMode], [5, false, DohTransport::JSON_PARAMS], 'DohTransport: параметры по умолчанию');
    $t->eq((new DohTransport('https://dns.google/dns-query'))->name(), 'https://dns.google/dns-query', 'DohTransport: свой адрес');
    $t->eq($doh instanceof DnsTransport, true, 'DohTransport: реализует контракт транспорта');
    $t->eq(DohTransport::supportsHttp3(),
      defined('CURL_VERSION_HTTP3') && (bool)(curl_version()['features'] & CURL_VERSION_HTTP3),
      'DohTransport: supportsHttp3 отражает возможности libcurl');

    /* ---------- JSON-запрос: адрес без сети ---------- */

    $query = DnsMessage::query('example.com', 1);

    $t->eq((new DohTransport('https://cf/dns-query'))->jsonUrl($query),
      'https://cf/dns-query?name=example.com&type=A', 'DohTransport: JSON-URL в режиме name/type');

    $t->eq((new DohTransport('https://cf/dns-query'))->jsonUrl(DnsMessage::query('example.com', 1, dnssec: true)),
      'https://cf/dns-query?name=example.com&type=A&do=1', 'DohTransport: DNSSEC добавляет do=1 в JSON-URL');
    $t->eq((new DohTransport('https://cf/dns-query'))->jsonUrl(DnsMessage::query('example.com', 1)),
      'https://cf/dns-query?name=example.com&type=A', 'DohTransport: без DNSSEC параметра do нет');

    $get  = new DohTransport('https://cf/dns-query', jsonMode: DohTransport::JSON_GET);
    $want = 'https://cf/dns-query?dns=' . rtrim(strtr(base64_encode($query->serialize()), '+/', '-_'), '=');
    $t->eq($get->jsonUrl($query), $want, 'DohTransport: JSON-URL в режиме ?dns= (base64url без паддинга)');

    $two = DnsMessage::query('example.com', 1);
    $two->questions[] = new DnsQuestion('example.org', 1);
    $t->throws(fn() => (new DohTransport('https://cf/dns-query'))->jsonUrl($two),
      'DohTransport: два вопроса в режиме name/type -> исключение');
  }
}