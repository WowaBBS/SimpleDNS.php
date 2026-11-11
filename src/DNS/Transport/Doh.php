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
}
