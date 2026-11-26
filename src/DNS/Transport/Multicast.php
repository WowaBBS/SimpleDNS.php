<?
/* ============================================================
 * Multicast-транспорт: общая механика для mDNS и LLMNR.
 *
 * Оба протокола - это DNS-wire в multicast, но с своими правилами
 * подготовки запроса и с несколькими ответами от разных хостов.
 *
 * В группу вступать не нужно: запрос уходит с эфемерного порта,
 * mDNS просит юникаст-ответ QU-битом (RFC 6762, 5.4), LLMNR сам
 * отвечает юникастом, если порт источника не 5355
 * (RFC 4795, 2.4). Поэтому достаточно обычного UDP-сокета.
 * ============================================================ */

abstract class MulticastTransport implements DnsTransport
{
  use TransportChecks;

  public function __construct(
    public string $group,
    public int    $port,
    public int    $timeout = 2,
  ) {}

  public function name(): string { return $this->scheme() . "://{$this->group}:{$this->port}"; }

  /** Готовый запрос по имени (правила протокола уже применены) */
  public function request(string $name, int $type = 1): DnsMessage
  {
    $message = new DnsMessage(new DnsHeader(id: $this->id()));
    $message->questions[] = new DnsQuestion($name, $type, $this->qclass());

    return $this->prepare($message);
  }

  /** Запрос по имени: собрать, отправить, объединить ответы */
  public function ask(string $name, int $type = 1): DnsMessage
  {
    return $this->exchange($this->request($name, $type));
  }

  public function exchange(DnsMessage $request): DnsMessage
  {
    $replies = $this->responses($this->prepare($request));

    if ($replies === [])
      throw new RuntimeException($this->name() . ": нет ответов за {$this->timeout} с");

    return self::mergeMessages($replies);
  }

  /* ---------- Сеть ---------- */

  /** @return DnsMessage[] все ответы, полученные за окно ожидания */
  public function responses(DnsMessage $request): array
  {
    // Сокет не соединяется с группой: иначе ядро отбросит юникаст-ответы,
    // пришедшие с адреса отвечающего хоста, а не с адреса группы
    $socket = @stream_socket_server('udp://0.0.0.0:0', $errno, $errstr, STREAM_SERVER_BIND);
    if ($socket === false)
      throw new RuntimeException($this->name() . ": не удалось открыть сокет: $errstr ($errno)");

    try
    {
      $sent = @stream_socket_sendto($socket, $request->serialize(), 0, "{$this->group}:{$this->port}");
      if ($sent === false)
        throw new RuntimeException($this->name() . ': не удалось отправить запрос');

      $replies  = [];
      $deadline = microtime(true) + $this->timeout;

      while (($left = $deadline - microtime(true)) > 0.001)
      {
        // Ждём готовность через stream_select: на stream_socket_recvfrom
        // stream_set_timeout не действует, и вызов блокируется навсегда
        $read   = [$socket];
        $write  = null;
        $except = null;

        if (@stream_select($read, $write, $except, (int)$left, (int)(($left - floor($left)) * 1000000)) === 0)
          break;                     // окно ожидания закончилось

        $data = @stream_socket_recvfrom($socket, 65535, 0, $peer);
        if ($data === false || $data === '')
          continue;

        try { $replies[] = DnsMessage::deserialize($data); }
        catch (Throwable) { /* чужой или битый пакет - пропускаем */ }
      }

      return $replies;
    }
    finally { fclose($socket); }
  }

  /* ---------- Объединение ответов ---------- */

  /** @param DnsMessage[] $replies */
  public static function mergeMessages(array $replies): DnsMessage
  {
    if ($replies === [])
      throw new InvalidArgumentException('Нет ответов для объединения');

    $merged = array_shift($replies);

    foreach ($replies as $reply)
    {
      foreach ($reply->answers    as $record) self::addUnique($merged->answers   , $record);
      foreach ($reply->additional as $record) self::addUnique($merged->additional, $record);
    }

    return $merged;
  }

  private static function addUnique(DnsRecordList $list, DnsRecord $record): void
  {
    $key = self::recordKey($record);

    foreach ($list as $existing)
      if (self::recordKey($existing) === $key)
        return;

    $list[] = $record;
  }

  private static function recordKey(DnsRecord $record): string
  {
    return $record->name . '|' . $record->type . '|' . bin2hex($record->rdata->serialize());
  }

  /* ---------- Правила протокола ---------- */

  abstract protected function scheme(): string;

  /** Привести сообщение к требованиям протокола */
  abstract public function prepare(DnsMessage $message): DnsMessage;

  protected function id(): int { return random_int(1, 65535); }

  protected function qclass(): int { return DnsMessage::CLASS_IN; }

  /* ------------------------------------------------------------
   * Тесты
   * ------------------------------------------------------------ */

  static function test(TestRunner $t)
  {
    $one = new DnsMessage(new DnsHeader(id: 1, qr: true));
    $one->answers[] = new DnsRecord('printer.local', new ARecord('192.168.1.10'), 120);

    $two = new DnsMessage(new DnsHeader(id: 2, qr: true));
    $two->answers[] = new DnsRecord('printer.local', new ARecord('192.168.1.10'), 120);   // дубль
    $two->answers[] = new DnsRecord('printer.local', new ARecord('192.168.1.11'), 120);
    $two->additional[] = new DnsRecord('printer.local', new ARecord('192.168.1.10'), 120);

    $merged = MulticastTransport::mergeMessages([$one, $two]);

    $t->eq(count($merged->answers), 2, 'MulticastTransport: ответы объединены без дублей');
    $t->eq($merged->answers[1]->rdata->ip, '192.168.1.11', 'MulticastTransport: второй хост добавлен');
    $t->eq(count($merged->additional), 1, 'MulticastTransport: additional тоже собран');

    $t->throws(fn() => MulticastTransport::mergeMessages([]), 'MulticastTransport: пустой список -> исключение');
  }

  /** Убрать EDNS: в mDNS и LLMNR его нет */
  protected function stripOpt(DnsMessage $message): DnsMessage
  {
    $message->additional = $message->additional->filter(fn(DnsRecord $r) => !$r->rdata instanceof OptRecord);
    return $message;
  }
}