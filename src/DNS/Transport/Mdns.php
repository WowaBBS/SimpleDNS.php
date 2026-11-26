<?
/* ============================================================
 * mDNS (RFC 6762): multicast DNS на 224.0.0.251:5353.
 * Имена - в зоне .local, ID запроса 0, EDNS не используется,
 * QU-бит в классе вопроса просит ответить юникастом.
 * ============================================================ */

final class MdnsTransport extends MulticastTransport
{
  public const GROUP = '224.0.0.251';
  public const PORT  = 5353;

  /** QU-бит в поле класса вопроса: ответить юникастом */
  private const QU = 0x8000;

  public function __construct(string $group = self::GROUP, int $port = self::PORT, int $timeout = 2)
  {
    parent::__construct($group, $port, $timeout);
  }

  protected function scheme(): string { return 'mdns'; }

  /** RFC 6762, 18.1: ID multicast-запроса равен нулю */
  protected function id(): int { return 0; }

  public function prepare(DnsMessage $message): DnsMessage
  {
    $message->header->id = 0;
    $this->stripOpt($message);

    foreach ($message->questions as $question)
    {
      $question->name   = $this->localName($question->name);
      $question->class |= self::QU;
    }

    return $message;
  }

  private function localName(string $name): string
  {
    $name = rtrim($name, '.');

    return str_ends_with(strtolower($name), '.local') ? $name : $name . '.local';
  }

  static function test(TestRunner $t)
  {
    $mdns = new MdnsTransport();
    $t->eq($mdns->name(), 'mdns://224.0.0.251:5353', 'MdnsTransport: адрес по умолчанию');
    $t->eq($mdns instanceof DnsTransport, true, 'MdnsTransport: реализует контракт транспорта');

    $request = $mdns->request('printer', 1);
    $t->eq($request->header->id, 0, 'MdnsTransport: ID запроса равен нулю');
    $t->eq($request->questions[0]->name, 'printer.local', 'MdnsTransport: имя дополнено зоной .local');
    $t->eq($request->questions[0]->class, 0x8001, 'MdnsTransport: QU-бит выставлен');
    $t->eq(count($request->additional), 0, 'MdnsTransport: EDNS в запросе отсутствует');

    $t->eq($mdns->request('printer.local')->questions[0]->name, 'printer.local', 'MdnsTransport: зона не дублируется');

    // Обычный запрос приводится к mDNS-виду
    $normalized = $mdns->prepare(DnsMessage::query('printer.local', 1));
    $t->eq([$normalized->header->id, count($normalized->additional), $normalized->questions[0]->class],
      [0, 0, 0x8001], 'MdnsTransport: обычный запрос нормализуется');
  }
}
