<?
/* ============================================================
 * Ресурсная запись (общая обёртка name/type/class/ttl/rdata)
 * ============================================================ */

final class DnsRecord
{
  public function __construct(
    public string    $name  ,
    public int       $type  ,
    public int       $class = 1,
    public int       $ttl   = 0,
    public ?DnsRdata $rdata = null,
  ) {}

  /** Фабрика OPT-записи для EDNS0 */
  public static function opt(int $udpSize = 4096, bool $dnssec = false): self
  {
    return new self('', 41, $udpSize, 0, new OptRecord(0, 0, $dnssec));
  }

  public function serialize(): string
  {
    $rdata = $this->rdata?->serialize() ?? '';

    // у OPT класс-поле несёт UDP-размер, TTL - extRcode/version/DO
    $class = $this->class;
    $ttl   = $this->ttl;
    if ($this->rdata instanceof OptRecord)
    {
      $class =  $this->rdata->udpSize;
      $ttl   =( $this->rdata->extRcode << 24)
            | (($this->rdata->version & 0xFF) << 16)
            | ( $this->rdata->do ? 1 << 15 : 0);
    }

    return NameCodec::encode($this->name)
      . pack('nnNn', $this->type, $class, $ttl, strlen($rdata))
      . $rdata;
  }

  /** @return array{0:self, 1:int} */
  public static function deserialize(string $msg, int $off): array
  {
    [$name, $off] = NameCodec::decode($msg, $off);
    [$type, $class, $ttl, $rdLen] = array_values(unpack('ntype/nclass/Nttl/nrdLen', $msg, $off));
    $off += 10;

    $cls   = RdataRegistry::classFor($type);
    $rdata = $cls::deserialize($msg, $off, $rdLen);

    $record = new self($name, $type, $class, $ttl, $rdata);

    if ($type === 41 && $rdata instanceof OptRecord)
    {
      $rdata->udpSize  = $class;
      $rdata->extRcode =  ($ttl >> 24) & 0xFF;
      $rdata->version  =  ($ttl >> 16) & 0xFF;
      $rdata->do = (bool)(($ttl >> 15) & 1);
    }

    return [$record, $off + $rdLen];
  }

  public function __toString(): string
  {
    return sprintf('%-6s %-32s TTL=%-6d %s',
      DnsMessage::typeName($this->type), $this->name, $this->ttl, (string)$this->rdata);
  }
}
