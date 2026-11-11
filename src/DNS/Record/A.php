<?
/* --- A --- */
final class ARecord extends DnsRdata
{
  public function __construct(public string $ip) {}

  public function serialize(): string
  {
    return inet_pton($this->ip) ?: throw new InvalidArgumentException("Некорректный IPv4: {$this->ip}");
  }

  public static function deserialize(string $msg, int $off, int $rdLen): static
  {
    if ($rdLen !== 4)
      throw new RuntimeException("Некорректная длина A-записи: $rdLen");
    
    return new static(inet_ntop(substr($msg, $off, 4)));
  }

  public function __toString(): string { return $this->ip; }

  static function test(TestRunner $t)
  {
    $t->eq(hexs((new ARecord('192.168.1.1'))->serialize()), 'C0 A8 01 01', 'ARecord: точные байты 192.168.1.1');
    $t->eq((ARecord::deserialize("\xC0\xA8\x01\x01", 0, 4))->ip, '192.168.1.1', 'ARecord: deserialize');
    $t->throws(fn() => (new ARecord('999.1.1.1'))->serialize(), 'ARecord: некорректный IP -> исключение');
  }
}
