<?
/* ============================================================
 * Транспорт DNS: общий контракт.
 * Любая реализация получает готовое сообщение и возвращает
 * проверенный ответ - с тем же ID и без усечения.
 * ============================================================ */

interface DnsTransport
{
  public function exchange(DnsMessage $request): DnsMessage;

  /** Адрес транспорта для логов и сообщений об ошибках */
  public function name(): string;
}

trait TransportChecks
{
  /** Ответ должен относиться к этому запросу */
  private function check(DnsMessage $request, DnsMessage $reply): DnsMessage
  {
    if ($reply->header->id !== $request->header->id)
      throw new RuntimeException($this->name() . ': ID ответа не совпадает с запросом');

    return $reply;
  }
}