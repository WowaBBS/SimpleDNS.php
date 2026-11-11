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
    $t->eq(UnknownRdata::deserialize($unk->serialize(), 0, 4)->raw, "\xDE\xAD\xBE\xEF", 'UnknownRdata: байты сохран€ютс€ как есть');
  }
}
