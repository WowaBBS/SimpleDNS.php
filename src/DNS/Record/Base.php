<?
/* ============================================================
 * RDATA: базовый класс и конкретные типы записей
 * ============================================================ */

abstract class DnsRdata
{
  /** Бинарное представление RDATA (имена - без сжатия) */
  abstract public function serialize(): string;

  /**
   * Разбор RDATA. $off - абсолютное смещение начала RDATA в сообщении
   * (нужно целиком сообщение, т.к. имена внутри RDATA могут быть сжаты указателями).
   */
  abstract public static function deserialize(string $msg, int $off, int $rdLen): static;

  /**
   * Дописывает RDATA в &$buf. Базовая реализация - без имён,
   * просто переносит результат serialize().
   */
  public function serializeWith(NameTable $table, string &$buf): void
  {
    $buf .= $this->serialize();
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
  }
  
//****************************************************************
// Json
  
  /** RDATA в презентационном формате (RFC 8427, поле Data) */
  abstract public function toJson(): string;

  /** Сборка RDATA из презентационного формата */
  abstract public static function fromJson(string $data): static;
  
//****************************************************************
}
