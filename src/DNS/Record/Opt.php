<?
/* --- OPT (EDNS0) --- */
final class OptRecord extends DnsRdata
{
  public function __construct(
    public int    $udpSize  = 4096,
    public int    $extRcode = 0,
    public int    $version  = 0,
    public bool   $do      = false,    // флаг DNSSEC OK
    public string $options  = '',  // сырые EDNS-опции: OPTCODE(2) OPTLEN(2) DATA...
  ) {}

  public function serialize(): string
  {
    return $this->options;
  }

  public static function deserialize(string $msg, int $off, int $rdLen): static
  {
    // extRcode / version / DO лежат в TTL ресурсной записи - их
    // восстанавливает DnsRecord::deserialize(), здесь только опции
    return new static(options: substr($msg, $off, $rdLen));
  }

  public function __toString(): string
  {
    return '[EDNS0 options=' . strlen($this->options) . 'B]';
  }

  static function test(TestRunner $t)
  {
    // OPT: класс несёт UDP-размер, TTL - extRcode/version/DO
    $buf = "\0" . pack('nnNn', 41, 1232, 1 << 15, 0);
    [$optRec] = DnsRecord::deserialize($buf, 0);
    $t->eq($optRec->rdata instanceof OptRecord, true, 'OptRecord: тип распознан реестром');
    $t->eq($optRec->rdata->udpSize, 1232, 'OptRecord: udpSize из поля class');
    $t->eq($optRec->rdata->do, true, 'OptRecord: флаг DO из TTL');
    
    // Расширенный rcode: extRcode=1 (BADVERS), header rcode=0 -> итог 16
    $m = new DnsMessage(new DnsHeader(id: 1));
    $m->additional[] = new DnsRecord('', 41, 4096, 0, new OptRecord(4096, 1, 0, false));
    $t->eq($m->rcode(), 16, 'DnsMessage::rcode(): расширение через EDNS');
  }
}
