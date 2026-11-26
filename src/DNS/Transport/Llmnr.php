<?
/* ============================================================
 * LLMNR (RFC 4795): multicast-разрешение имён на 224.0.0.252:5355.
 * Формат - DNS-wire без EDNS; на запрос с эфемерного порта
 * отвечают юникастом (RFC 4795, 2.4), поэтому вступать в группу
 * не нужно.
 * ============================================================ */

final class LlmnrTransport extends MulticastTransport
{
  public const GROUP = '224.0.0.252';
  public const PORT  = 5355;

  public function __construct(string $group = self::GROUP, int $port = self::PORT, int $timeout = 2)
  {
    parent::__construct($group, $port, $timeout);
  }

  protected function scheme(): string { return 'llmnr'; }

  public function prepare(DnsMessage $message): DnsMessage
  {
    $this->stripOpt($message);

    if ($message->header->id === 0)
      $message->header->id = $this->id();

    return $message;
  }

  static function test(TestRunner $t)
  {
    $llmnr = new LlmnrTransport();
    $t->eq($llmnr->name(), 'llmnr://224.0.0.252:5355', 'LlmnrTransport: адрес по умолчанию');
    $t->eq($llmnr instanceof DnsTransport, true, 'LlmnrTransport: реализует контракт транспорта');

    $request = $llmnr->request('workstation', 1);
    $t->eq(count($request->additional), 0, 'LlmnrTransport: EDNS в запросе отсутствует');
    $t->eq($request->header->id !== 0, true, 'LlmnrTransport: ID не нулевой');
    $t->eq($request->questions[0]->class, 1, 'LlmnrTransport: класс вопроса IN');

    // EDNS убирается, ID сохраняется
    $query = DnsMessage::query('workstation', 1);
    $id    = $query->header->id;
    $normalized = $llmnr->prepare($query);
    $t->eq([count($normalized->additional), $normalized->header->id], [0, $id], 'LlmnrTransport: EDNS убран, ID сохранён');

    // Нулевой ID заменяется на случайный
    $zero = new DnsMessage(new DnsHeader(id: 0));
    $zero->questions[] = new DnsQuestion('workstation', 1);
    $t->eq($llmnr->prepare($zero)->header->id !== 0, true, 'LlmnrTransport: нулевой ID заменяется');
  }
}