<?
/* --- неизвестный тип: RDATA как есть --- */
final class UnknownRdata extends DnsRdata
{
  public function __construct(public string $raw) {}

  public function serialize(): string { return $this->raw; }

  public static function deserialize(string $msg, int $off, int $rdLen): static
  {
    return new static(substr($msg, $off, $rdLen));
  }

  public function __toString(): string { return bin2hex($this->raw); }

  static function test(TestRunner $t)
  {
    $unk = new UnknownRdata("\xDE\xAD\xBE\xEF");
    $t->eq(UnknownRdata::deserialize($unk->serialize(), 0, 4)->raw, "\xDE\xAD\xBE\xEF", 'UnknownRdata: байты сохраняются как есть');
  }

//****************************************************************
// Json

  public function toJson(): string
  {
    return $this->raw === '' ? "\# 0" : "\# " . strlen($this->raw) . ' ' . strtoupper(bin2hex($this->raw));
  }

  public static function fromJson(string $data): static
  {
    return new static(self::decodeHash($data));
  }

  /** Разбор "\# <длина> <hex>" (RFC 3597) */
  public static function decodeHash(string $data): string
  {
    if (!preg_match('/^\\\\#\s+(\d+)\s*([0-9A-Fa-f]*)$/', trim($data), $m))
      throw new RuntimeException("Некорректное \\#-представление: $data");
    
    $hex = $m[2];
    if ((int)$m[1] !== (int)(strlen($hex) / 2))
      throw new RuntimeException("Длина не совпадает с hex: $data");
    
    return hex2bin($hex);
  }

//****************************************************************
}
