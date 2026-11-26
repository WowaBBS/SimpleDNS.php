<?
/* ============================================================
 * RDATA: базовый класс и конкретные типы записей
 * ============================================================ */

abstract class DnsRdata
{
  /** «апись RDATA в писател€. ѕо умолчанию - несжатые байты из serialize(). */
  public function writeTo(DnsWriter $w): void
  {
    $w->writeRaw($this->serialize());
  }
  
  /** Ѕинарное представление RDATA (имена - без сжати€) */
  abstract public function serialize(): string;

  /**
   * ƒописывает RDATA в &$buf. Ѕазова€ реализаци€ - без имЄн,
   * просто переносит результат serialize().
   */
  public function serializeWith(NameTable $table, string &$buf): void
  {
    $buf .= $this->serialize();
  }

  /** „тение RDATA из ридера, сто€щего на начале RDATA (ридер - над всем сообщением) */
  abstract public static function fromReader(DnsReader $r): static;
  
  /**
   * –азбор RDATA. $off - абсолютное смещение начала RDATA в сообщении
   * (нужно целиком сообщение, т.к. имена внутри RDATA могут быть сжаты указател€ми).
   */
  public static final function deserialize(string $msg, int $off=0, ?int $rdLen=null): static
  {
    $r = DnsReader::of($msg, $off, $rdLen);
    $obj = static::fromReader($r);
    if ($r->remaining() > 0)
      throw new RuntimeException('RDATA прочитано больше, чем за€влено в RDLENGTH');
    
    return $obj;
  }

  abstract public function __toString(): string;

  static function test(TestRunner $t)
  {
    ARecord      ::test($t);
    AaaaRecord   ::test($t);
    MxRecord     ::test($t);
    TxtRecord    ::test($t);
    SoaRecord    ::test($t);
    SrvRecord    ::test($t);
    CaaRecord    ::test($t);
    UnknownRdata ::test($t);
    RrsigRecord  ::test($t);

    /* ---------- RDATA: ридер ловит выход за RDLENGTH ---------- */
    
    // TXT с длиной куска больше rdLen
    $t->throws(fn() => TxtRecord::fromReader(DnsReader::of("\xFFhello", 0, 3)), 'TxtRecord: кусок за границей rdLen -> исключение');
    
    // A-запись с rdLen=3
    $t->throws(fn() => ARecord::fromReader(DnsReader::of("\x01\x02\x03", 3)), 'ARecord: rdLen=3 -> исключение');
    
    // RDATA, потребивша€ больше за€вленного: MX с rdLen=3, но им€ литеральное (больше 1 байта)
    $t->throws(fn() => MxRecord::fromReader(DnsReader::of("\x00\x0A\x07example\x03com\x00", 0, 3)),
      'MxRecord: rdata длиннее rdLen -> исключение');
  }
  
//****************************************************************
// Json
  
  /** RDATA в презентационном формате (RFC 8427, поле Data) */
  abstract public function toText(): string;

  /** —борка RDATA из презентационного формата */
  abstract public static function fromText(string $data): static;
  
//****************************************************************

  static function testJson(TestRunner $t)
  {
    foreach ([
      [new ARecord     ('93.184.216.34'), '93.184.216.34'],
      [new AaaaRecord  ('2001:db8::1'), '2001:db8::1'],
      [new CnameRecord ('cname.example.com'), 'cname.example.com.'],
      [new MxRecord    (10, 'mail.example.com'), '10 mail.example.com.'],
      [new SoaRecord   ('ns1.example.com', 'admin.example.com', 2024010101, 7200, 900, 1209600, 3600),
       'ns1.example.com. admin.example.com. 2024010101 7200 900 1209600 3600'],
      [new SrvRecord(1, 5, 5061, 'sip.example.com'), '1 5 5061 sip.example.com.'],
    ] as [$rec, $want]) {
      $t->eq($rec->toText(), $want, 'Rdata->toText: ' . (string)$rec);
      $clone = $rec::fromText($want);
      $t->eq($clone->serialize(), $rec->serialize(), 'Rdata JSON roundtrip (байты): ' . (string)$rec);
    }
  }
}
