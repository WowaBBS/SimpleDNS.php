<?
/* ============================================================
 * RDATA: базовый класс и конкретные типы записей
 * ============================================================ */

abstract class DnsRdata
{
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
  abstract public static function fromReader(DnsReader $r, int $rdLen): static;
  
  /**
   * –азбор RDATA. $off - абсолютное смещение начала RDATA в сообщении
   * (нужно целиком сообщение, т.к. имена внутри RDATA могут быть сжаты указател€ми).
   */
  public static final function deserialize(string $msg, int $off, int $rdLen): static
  {
    $r = DnsReader::of($msg, $off);
    $start = $r->tell();
    $obj = static::fromReader($r, $rdLen);
    if ($r->tell() - $start > $rdLen)
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

    /* ---------- RDATA: ридер ловит выход за RDLENGTH ---------- */
    
    // TXT с длиной куска больше rdLen
    $t->throws(fn() => TxtRecord::deserialize("\xFFhello", 0, 3), 'TxtRecord: кусок за границей rdLen -> исключение');
    
    // A-запись с rdLen=3
    $t->throws(fn() => ARecord::fromReader(DnsReader::of("\x01\x02\x03"), 3), 'ARecord: rdLen=3 -> исключение');
    
    // RDATA, потребивша€ больше за€вленного: MX с rdLen=3, но им€ литеральное (больше 1 байта)
    $t->throws(fn() => MxRecord::deserialize("\x00\x0A\x07example\x03com\x00", 0, 3),
      'MxRecord: rdata длиннее rdLen -> исключение');
  }
  
//****************************************************************
// Json
  
  /** RDATA в презентационном формате (RFC 8427, поле Data) */
  abstract public function toJson(): string;

  /** —борка RDATA из презентационного формата */
  abstract public static function fromJson(string $data): static;
  
//****************************************************************
}
