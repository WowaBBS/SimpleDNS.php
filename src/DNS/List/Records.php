<?
/* ============================================================
 * DnsRecordList - секции ANSWER / AUTHORITY / ADDITIONAL.
 * ============================================================ */

final class DnsRecordList extends DnsList
{
  protected static function itemClass(): string { return DnsRecord::class; }

  public function byType(int $type): static
  {
    return $this->filter(fn(DnsRecord $r) => $r->type === $type);
  }

  public function byName(string $name): static
  {
    $name = NameCodec::fromPresentation($name);

    return $this->filter(fn(DnsRecord $r) => strcasecmp($r->name, $name) === 0);
  }

  /** Первая запись по имени (и типу, если задан) */
  public function find(string $name, ?int $type = null): ?DnsRecord
  {
    $name = NameCodec::fromPresentation($name);

    foreach ($this->toArray() as $record)
      if (strcasecmp($record->name, $name) === 0 && ($type === null || $record->type === $type))
        return $record;

    return null;
  }

  /** OPT-запись секции ADDITIONAL (EDNS) */
  public function opt(): ?OptRecord
  {
    foreach ($this->toArray() as $record)
      if ($record->rdata instanceof OptRecord)
        return $record->rdata;

    return null;
  }

  /** @return string[] */
  public function names(): array
  {
    return array_map(fn(DnsRecord $r) => $r->name, $this->toArray());
  }

  /** @return int[] */
  public function ttls(): array
  {
    return array_map(fn(DnsRecord $r) => $r->ttl, $this->toArray());
  }

  static function test(TestRunner $t)
  {
    $list = new DnsRecordList();
    $list[] = new DnsRecord('example.com',      1, 1, 60, new ARecord('1.2.3.4'));
    $list[] = new DnsRecord('example.com',     16, 1, 60, new TxtRecord(['x']));
    $list[] = new DnsRecord('www.example.com',  1, 1, 30, new ARecord('5.6.7.8'));

    $t->eq(count($list->byType(1)), 2, 'DnsRecordList: byType');
    $t->eq(count($list->byName('example.com.')), 2, 'DnsRecordList: byName с точкой');
    $t->eq($list->find('EXAMPLE.com', 16)->rdata->texts, ['x'], 'DnsRecordList: find по имени и типу');
    $t->eq($list->find('example.com', 999), null, 'DnsRecordList: find без совпадений');
    $t->eq($list->names(), ['example.com', 'example.com', 'www.example.com'], 'DnsRecordList: names');
    $t->eq($list->ttls(), [60, 60, 30], 'DnsRecordList: ttls');
    $t->eq($list->byType(1)->names(), ['example.com', 'www.example.com'], 'DnsRecordList: byType -> names (цепочка)');
    $t->eq($list->opt(), null, 'DnsRecordList: opt отсутствует');

    $list[] = new DnsRecord('', 41, 4096, 0, new OptRecord(1232));
    $t->eq($list->opt()->udpSize, 1232, 'DnsRecordList: opt находит EDNS-запись');
    $t->eq($list->opt() instanceof OptRecord, true, 'DnsRecordList: opt возвращает OptRecord');
  }
}