<?
/* ============================================================
 * Ресурсная запись (общая обёртка name/type/class/ttl/rdata)
 * ============================================================ */

final class DnsRecord
{
  use JsonPacket;
  /** Код типа выводится из класса RDATA; явно задаётся только для UnknownRdata */
  public int $type;

  public function __construct(
    public string   $name ,
    public DnsRdata $rdata,
    public int      $ttl   = 0,
    public int      $class = 1,
    ?int            $type  = null,
  ) {
    $type ??= RdataRegistry::typeFor($rdata::class)
      ?? throw new InvalidArgumentException('Для UnknownRdata код типа нужно задать явно');

    if (RdataRegistry::classFor($type) !== $rdata::class)
      throw new InvalidArgumentException(sprintf('Тип %d не соответствует %s', $type, $rdata::class));

    $this->type = $type;
  }

  /** Фабрика OPT-записи для EDNS0 */
  public static function opt(int $udpSize = 4096, bool $dnssec = false): self
  {
    return new self('', new OptRecord($udpSize, do: $dnssec), 0, $udpSize);
  }

  public function writeTo(DnsWriter $w): void
  {
    $w->writeName($this->name);

    if ($this->rdata instanceof OptRecord)
    {
      $class =  $this->rdata->udpSize;
      $ttl   = ($this->rdata->extRcode << 24)
            | (($this->rdata->version & 0xFF) << 16)
            |  ($this->rdata->do ? 1 << 15 : 0);
    }
    else
    {
      $class = $this->class ;
      $ttl   = $this->ttl   ;
    }

    $w->pack('nnNn', $this->type, $class, $ttl, 0);

    $lenOff = $w->tell();    // здесь будет RDLENGTH

    $this->rdata->writeTo($w);

    $w->writeU16At($lenOff-2, $w->tell() - $lenOff); // латаем реальной длиной
  }
  
  public function serialize(): string
  {
    $rdata = $this->rdata->serialize();

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
      .pack('nnNn', $this->type, $class, $ttl, strlen($rdata))
      .$rdata;
  }

  public function serializeWith(NameTable $table, string &$buf): void
  {
    NameCodec::encodeWith($this->name, $table, $buf);

    // у OPT класс-поле несёт UDP-размер, TTL - extRcode/version/DO
    if ($this->rdata instanceof OptRecord)
    {
      $class = $this->rdata->udpSize;
      $ttl   = ( $this->rdata->extRcode << 24)
             | (($this->rdata->version & 0xFF) << 16)
             | ( $this->rdata->do ? 1 << 15 : 0);
    }
    else
    {
      $class = $this->class ;
      $ttl   = $this->ttl   ;
    }

    $lenPos = strlen($buf) + 8;         // где будет RDLENGTH
    $buf .= pack('nnN', $this->type, $class, $ttl) . "\0\0";

    $this->rdata->serializeWith($table, $buf); // RDATA - сразу в общий буфер

    $buf = substr_replace($buf, pack('n', strlen($buf) - $lenPos - 2), $lenPos, 2);
  }
  
  /** @return array{0:self, 1:int} */
//public static function deserialize(string $msg, int $off = 0): array { return self::fromReader(DnsReader::of($msg, $off)); }
  
  /** @return array{0:self, 1:int} */
  public static function fromReader(DnsReader $r): array
  {
    $name  = $r->readName();
    ['tp'=>$type, 'c'=>$class, 'tl'=>$ttl, 'l'=>$rdLen] = $r->unpack('ntp/nc/Ntl/nl');

    $start = $r->tell();
    $rdata = RdataRegistry::classFor($type)::fromReader($r->sub($rdLen));
    if ($r->tell() - $start > $rdLen)
      throw new RuntimeException("RDATA типа $type прочитано больше заявленной длины");
    
    $record = new self($name, $rdata, $ttl, $class, $type);

    if ($type === 41 && $rdata instanceof OptRecord)
    {
      $rdata->udpSize  = $class;
      $rdata->extRcode =  ($ttl >> 24) & 0xFF;
      $rdata->version  =  ($ttl >> 16) & 0xFF;
      $rdata->do = (bool)(($ttl >> 15) & 1);
    }

    return [$record, $r->tell()];
  }

  public function __toString(): string
  {
    return sprintf('%-6s %-32s TTL=%-6d %s',
      DnsMessage::typeName($this->type), $this->name, $this->ttl, (string)$this->rdata);
  }

//****************************************************************
// Json

  public function writeToJson(JsonWriter $w): void
  {
    $w->openObject();
    $w->put('Name', NameCodec  ::toPresentation($this->name));
    $w->put('Type', DnsMessage ::typeName      ($this->type));
    $w->put('TTL' , $this->ttl);
    $w->put('Data', $this->rdata->toText());

    if ($this->rdata instanceof OptRecord)
    {
      // EDNS: свои поля (RFC 8427, 2.4.2)
      $w->put('UDPSize' , $this->rdata->udpSize  );
      $w->put('ExtRcode', $this->rdata->extRcode );
      $w->put('Version' , $this->rdata->version  );
      $w->put('DO'      , $this->rdata->do       );
    }
    $w->close();
  }

  public static function fromJsonReader(JsonReader $j): self
  {
    $type  = DnsMessage  ::typeFromName((string)$j->strOrInt('Type'));
    $cls   = RdataRegistry::classFor  ($type);
    $rdata = $cls::fromText($j->str('Data'));

    if ($type === 41 && $rdata instanceof OptRecord)
    {
      $rdata->udpSize  = $j->intOr ('UDPSize' , 4096 );
      $rdata->extRcode = $j->intOr ('ExtRcode', 0    );
      $rdata->version  = $j->intOr ('Version' , 0    );
      $rdata->do       = $j->boolOr('DO'      , false);
    }

    return new self(
      NameCodec::fromPresentation($j->str('Name')),
      $rdata,
      $j->intOr('TTL'  , 0),
      $j->intOr('Class', 1),
      $type,
    );
  }
//****************************************************************

  static function testJson(TestRunner $t)
  {
    /* ---------- JSON: запись целиком ---------- */

    $a = new DnsRecord('example.com', new ARecord('93.184.216.34'), 300);
    $aj = $a->toJson();
    $t->eq($aj, ['Name' => 'example.com.', 'Type' => 'A', 'TTL' => 300, 'Data' => '93.184.216.34'],
      'DnsRecord->toJson: точная структура');
    $back = DnsRecord::fromJson($aj);
    $t->eq($back->serialize(), $a->serialize(), 'DnsRecord JSON roundtrip (байты)');

    // OPT: нестандартные поля EDNS переносятся
    $optJson = ['Name' => '.', 'Type' => 'OPT', 'Data' => "\# 0",
          'UDPSize' => 1232, 'ExtRcode' => 1, 'Version' => 0, 'DO' => true];
    $optRec = DnsRecord::fromJson($optJson);
    $t->eq($optRec->rdata->udpSize, 1232, 'OPT JSON: UDPSize');
    $t->eq($optRec->rdata->extRcode, 1, 'OPT JSON: ExtRcode');
    $t->eq($optRec->rdata->do, true, 'OPT JSON: DO');
  }
}
