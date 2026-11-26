<?
/* ============================================================
 * Секция QUESTION
 * ============================================================ */

final class DnsQuestion
{
  use JsonPacket;
  public function __construct(
    public string $name,
    public int    $type,
    public int    $class = 1, // IN
  ) {}

  public function writeTo(DnsWriter $w): void
  {
    $w->writeName($this->name);
    $w->pack('nn', $this->type, $this->class);
  }
  
  public function serialize(): string
  {
    return NameCodec::encode($this->name) . pack('nn', $this->type, $this->class);
  }

  public function serializeWith(NameTable $table, string &$buf): void
  {
    NameCodec::encodeWith($this->name, $table, $buf);
    $buf .= pack('nn', $this->type, $this->class);
  }

  /** @return array{0:self, 1:int} */
//public static function deserialize(string $msg, int $off): array { return self::fromReader(DnsReader::of($msg, $off)); }

  /** @return array{0:self, 1:int} */
  public static function fromReader(DnsReader $r): array
  {
    $name  = $r->readName();
    [1=>$type, 2=>$class] = $r->unpack('n2');
    return [new self($name, $type, $class), $r->tell()];
  }

//****************************************************************
// Json

  public function writeToJson(JsonWriter $w): void
  {
    $w->openObject();
    $w->put('Name', NameCodec  ::toPresentation($this->name));
    $w->put('Type', DnsMessage ::typeName      ($this->type));
    $w->close();
  }

  public static function fromJsonReader(JsonReader $j): self
  {
    return new self(
      NameCodec  ::fromPresentation($j->str('Name')),
      DnsMessage ::typeFromName    ((string)$j->strOrInt('Type')),
    );
  }
//****************************************************************
}
