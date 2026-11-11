<?
/* ============================================================
 * Транспорт DoH (RFC 8484) - минимум логики
 * ============================================================ */

final class DohTransport
{
  public const ENDPOINT = 'https://dns.google/dns-query'; // или https://cloudflare-dns.com/dns-query

  public static function exchange(DnsMessage $request, int $timeout = 5): DnsMessage
  {
    $context = stream_context_create([
      'http' => [
        'method'  => 'POST',
        'header'  => "Content-Type: application/dns-message\r\nAccept: application/dns-message\r\n",
        'content' => $request->serialize(),
        'timeout' => $timeout,
        'ignore_errors' => true,
      ],
      'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);

    $response = @file_get_contents(self::ENDPOINT, false, $context);
    if ($response === false)
      throw new RuntimeException('Не удалось соединиться с DoH-сервером: '
        . (error_get_last()['message'] ?? '?'));
    
    if (!preg_match('/^HTTP\/\S+\s+2\d\d/', $http_response_header[0] ?? ''))
      throw new RuntimeException('DoH-сервер вернул ошибку: ' . ($http_response_header[0] ?? 'нет заголовков'));
    
    $reply = DnsMessage::deserialize($response);

    if ($reply->header->id !== $request->header->id)
      throw new RuntimeException('ID ответа не совпадает с ID запроса');
    
    if ($reply->header->tc)
      throw new RuntimeException('Ответ усечён (TC=1)');
    
    return $reply;
  }
//****************************************************************
// Json

  //Здесь есть практическая деталь: у Google JSON-эндпоинт (`/resolve`) принимает GET-параметры `name`/`type`, 
  //а обычные DoH-серверы в JSON-режиме ждут тот же GET с `?dns=<base64url>` и заголовком `Accept: application/dns+json`:

  public static function exchangeJson(DnsMessage $request, int $timeout = 5): DnsMessage
  {
    $path = parse_url(self::ENDPOINT, PHP_URL_PATH) ?: '';
    if (str_ends_with($path, '/resolve'))
    {
      // JSON-API Google: параметры name и type
      $q = $request->questions[0] ?? throw new RuntimeException('Нет вопросов в запросе');
      $url = self::ENDPOINT . '?' . http_build_query([
        'name' => $q->name, 'type' => DnsMessage::typeName($q->type),
      ]);
    }
    else
    {
      // RFC 8484 GET: wire-запрос в base64url
      $dns = rtrim(strtr(base64_encode($request->serialize()), '+/', '-_'), '=');
      $url = self::ENDPOINT . '?dns=' . $dns;
    }

    $context = stream_context_create([
      'http' => [
        'method'  => 'GET',
        'header'  => "Accept: application/dns+json\r\n",
        'timeout' => $timeout,
        'ignore_errors' => true,
      ],
      'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);

    $response = @file_get_contents($url, false, $context);
    if ($response === false)
      throw new RuntimeException('Не удалось соединиться с DoH-сервером: '
        . (error_get_last()['message'] ?? '?'));
    
    if (!preg_match('/^HTTP\/\S+\s+2\d\d/', $http_response_header[0] ?? ''))
      throw new RuntimeException('DoH-сервер вернул ошибку: ' . ($http_response_header[0] ?? 'нет заголовков'));

    $reply = DnsMessage::fromJson($response);

    if ($reply->header->tc)
      throw new RuntimeException('Ответ усечён (TC=1)');

    return $reply;
  }

//****************************************************************
}
